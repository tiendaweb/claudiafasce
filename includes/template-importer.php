<?php

declare(strict_types=1);

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-manager.php';
require_once __DIR__ . '/content-repo.php';

function normalize_template_slug(string $slug): string
{
    $slug = trim(mb_strtolower($slug));
    $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');

    return $slug;
}

function import_backups_dir(?string $tenantId = null): string
{
    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());

    return dirname(__DIR__) . '/data/template-imports/' . $tenantId;
}

function save_html_backup(string $slug, string $html, ?string $tenantId = null): ?string
{
    $dir = import_backups_dir($tenantId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $timestamp = (new DateTimeImmutable())->format('YmdHis');
    $filename = sprintf('%s-%s.html', $slug, $timestamp);
    $path = $dir . '/' . $filename;

    if (file_put_contents($path, $html, LOCK_EX) === false) {
        return null;
    }

    return $path;
}

function write_utf8_file_atomic(string $path, string $content, int $maxBytes, string $label): void
{
    if (!mb_check_encoding($content, 'UTF-8')) {
        throw new RuntimeException(sprintf('El contenido de %s no está codificado en UTF-8', $label));
    }

    $bytes = strlen($content);
    if ($bytes > $maxBytes) {
        throw new RuntimeException(sprintf('El contenido de %s excede el límite (%d bytes > %d bytes)', $label, $bytes, $maxBytes));
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('No se pudo crear directorio para %s', $label));
    }

    $tmp = $path . '.tmp.' . uniqid('', true);
    $handle = @fopen($tmp, 'wb');
    if ($handle === false) {
        throw new RuntimeException(sprintf('No se pudo abrir archivo temporal para %s', $label));
    }

    if (fwrite($handle, $content) === false) {
        fclose($handle);
        @unlink($tmp);
        throw new RuntimeException(sprintf('No se pudo escribir el contenido de %s', $label));
    }

    fflush($handle);
    fclose($handle);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException(sprintf('No se pudo completar escritura atómica de %s', $label));
    }
}

function import_template_from_html(string $slug, string $html, ?string $tenantId = null): array
{
    $maxInlineStyleBytes = 1024 * 1024;
    $maxInlineScriptBytes = 1024 * 1024;

    $slug = normalize_template_slug($slug);
    if ($slug === '' || preg_match('/^[a-z0-9\-]+$/', $slug) !== 1) {
        throw new InvalidArgumentException('Slug inválido');
    }

    if (trim($html) === '') {
        throw new InvalidArgumentException('HTML vacío');
    }

    if (!mb_check_encoding($html, 'UTF-8')) {
        throw new InvalidArgumentException('El HTML debe estar codificado en UTF-8');
    }

    $tenantId = sanitize_tenant_id($tenantId ?? resolve_tenant_id());

    $backupPath = save_html_backup($slug, $html, $tenantId);
    if ($backupPath === null) {
        throw new RuntimeException('No se pudo guardar respaldo HTML');
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    if ($loaded === false) {
        throw new RuntimeException('No se pudo parsear HTML');
    }

    $xpath = new DOMXPath($dom);
    $slotCounter = 0;
    $slotMap = [];
    $usedKeys = [];
    $conflicts = [];
    $created = [];
    $defaults = [];

    $slugify = static function (string $value): string {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        $value = trim($value, '_');

        return $value !== '' ? $value : 'item';
    };

    $allocateKey = static function (string $candidate) use (&$usedKeys, &$conflicts): string {
        if (!isset($usedKeys[$candidate])) {
            $usedKeys[$candidate] = 1;
            return $candidate;
        }

        $usedKeys[$candidate]++;
        $suffix = $usedKeys[$candidate];
        $resolved = $candidate . '_' . $suffix;
        $conflicts[] = ['base' => $candidate, 'resolved' => $resolved];
        return $resolved;
    };

    $setDetected = static function (string $key, $value) use (&$defaults, &$created): void {
        set_value_by_path($defaults, $key, $value);
        $created[] = $key;
    };

    $buildKey = static function (DOMElement $element, string $suffix) use ($slugify): string {
        if ($element->tagName === 'title') {
            return 'site.title';
        }

        $segments = [];
        $current = $element;
        while ($current instanceof DOMElement && !in_array($current->tagName, ['html', 'body'], true)) {
            $tag = $slugify($current->tagName);
            if ($current->hasAttribute('id')) {
                $label = $slugify((string) $current->getAttribute('id'));
            } else {
                $classes = preg_split('/\s+/', trim((string) $current->getAttribute('class'))) ?: [];
                $label = $tag;
                foreach ($classes as $className) {
                    $normalized = $slugify($className);
                    if ($normalized !== '' && !str_starts_with($normalized, 'w_') && !str_starts_with($normalized, 'text_')) {
                        $label = $normalized;
                        break;
                    }
                }

                $position = 1;
                if ($current->parentNode instanceof DOMElement) {
                    foreach ($current->parentNode->childNodes as $sibling) {
                        if (!$sibling instanceof DOMElement) {
                            continue;
                        }
                        if ($sibling->tagName === $current->tagName) {
                            if ($sibling->isSameNode($current)) {
                                break;
                            }
                            $position++;
                        }
                    }
                }
                $label .= '_' . $position;
            }

            array_unshift($segments, $tag . '_' . $label);
            $current = $current->parentNode;
        }

        return 'sections.' . implode('.', $segments) . '.' . $suffix;
    };

    $createSlot = static function (string $key, string $fallback) use (&$slotCounter, &$slotMap): string {
        $slotCounter++;
        $slot = '__PHP_SLOT_' . $slotCounter . '__';
        $slotMap[$slot] = sprintf('<?= esc(content_get($initialContent, %s, %s)) ?>', var_export($key, true), var_export($fallback, true));
        return $slot;
    };

    $nodes = $xpath->query('//text()[normalize-space(.) != "" and not(ancestor::script) and not(ancestor::style)]');
    if ($nodes !== false) {
        /** @var DOMText $textNode */
        foreach ($nodes as $textNode) {
            $parent = $textNode->parentNode;
            if (!$parent instanceof DOMElement) {
                continue;
            }

            $text = trim((string) $textNode->nodeValue);
            if ($text === '') {
                continue;
            }

            $suffix = match (strtolower($parent->tagName)) {
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => 'title',
                'button' => 'cta_label',
                'a' => 'link_label',
                default => 'text',
            };

            $key = $allocateKey($buildKey($parent, $suffix));
            $parent->setAttribute('data-edit-key', $key);
            $parent->setAttribute('data-edit-type', 'text');
            $setDetected($key, $text);

            while ($parent->firstChild !== null) {
                $parent->removeChild($parent->firstChild);
            }
            $parent->appendChild($dom->createTextNode($createSlot($key, $text)));
        }
    }

    $attrNodes = $xpath->query('//*[@alt or @href]');
    if ($attrNodes !== false) {
        /** @var DOMElement $node */
        foreach ($attrNodes as $node) {
            foreach (['alt' => 'alt', 'href' => 'href'] as $attr => $suffix) {
                if (!$node->hasAttribute($attr)) {
                    continue;
                }
                $value = trim((string) $node->getAttribute($attr));
                if ($value === '') {
                    continue;
                }

                $key = $allocateKey($buildKey($node, $suffix));
                if (!$node->hasAttribute('data-edit-key')) {
                    $node->setAttribute('data-edit-key', $key);
                    $node->setAttribute('data-edit-type', 'text');
                }
                $node->setAttribute('data-edit-key-' . $attr, $key);
                $node->setAttribute('data-edit-type-' . $attr, 'text');
                $setDetected($key, $value);
                $node->setAttribute($attr, $createSlot($key, $value));
            }
        }
    }

    $collectedInlineCss = [];
    $styles = $xpath->query('//style');
    if ($styles !== false) {
        /** @var DOMElement $styleNode */
        foreach ($styles as $styleNode) {
            $styleText = trim((string) $styleNode->textContent);
            if ($styleText !== '') {
                $collectedInlineCss[] = $styleText;
            }
            if ($styleNode->parentNode instanceof DOMNode) {
                $styleNode->parentNode->removeChild($styleNode);
            }
        }
    }

    $collectedInlineJs = [];
    $scripts = $xpath->query('//script[not(@src)]');
    if ($scripts !== false) {
        /** @var DOMElement $scriptNode */
        foreach ($scripts as $scriptNode) {
            $type = mb_strtolower(trim((string) $scriptNode->getAttribute('type')));
            $isExecutableInline = $type === '' || in_array($type, ['text/javascript', 'application/javascript', 'module'], true);

            if (!$isExecutableInline) {
                continue;
            }

            $scriptText = trim((string) $scriptNode->textContent);
            if ($scriptText !== '') {
                $collectedInlineJs[] = $scriptText;
            }

            if ($scriptNode->parentNode instanceof DOMNode) {
                $scriptNode->parentNode->removeChild($scriptNode);
            }
        }
    }

    $templateDir = dirname(__DIR__) . '/templates/' . $slug;
    if (!is_dir($templateDir) && !mkdir($templateDir, 0775, true) && !is_dir($templateDir)) {
        throw new RuntimeException('No se pudo crear directorio de plantilla');
    }

    $assetDir = $templateDir . '/assets';
    $assetCssPath = $assetDir . '/template.css';
    $assetJsPath = $assetDir . '/template.js';

    $inlineCssContent = trim(implode("\n\n", $collectedInlineCss));
    $inlineJsContent = trim(implode("\n\n", $collectedInlineJs));

    if ($inlineCssContent !== '') {
        write_utf8_file_atomic($assetCssPath, $inlineCssContent . PHP_EOL, $maxInlineStyleBytes, 'assets/template.css');
    } elseif (is_file($assetCssPath)) {
        @unlink($assetCssPath);
    }

    if ($inlineJsContent !== '') {
        write_utf8_file_atomic($assetJsPath, $inlineJsContent . PHP_EOL, $maxInlineScriptBytes, 'assets/template.js');
    } elseif (is_file($assetJsPath)) {
        @unlink($assetJsPath);
    }

    if ($inlineCssContent !== '') {
        $head = $dom->getElementsByTagName('head')->item(0);
        if (!$head instanceof DOMElement) {
            $htmlNode = $dom->getElementsByTagName('html')->item(0);
            if ($htmlNode instanceof DOMElement) {
                $head = $dom->createElement('head');
                $htmlNode->insertBefore($head, $htmlNode->firstChild);
            }
        }

        if ($head instanceof DOMElement) {
            $linkNode = $dom->createElement('link');
            $linkNode->setAttribute('rel', 'stylesheet');
            $linkNode->setAttribute('href', 'assets/template.css');
            $head->appendChild($linkNode);
        }
    }

    if ($inlineJsContent !== '') {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            $htmlNode = $dom->getElementsByTagName('html')->item(0);
            if ($htmlNode instanceof DOMElement) {
                $body = $dom->createElement('body');
                $htmlNode->appendChild($body);
            }
        }

        if ($body instanceof DOMElement) {
            $scriptNode = $dom->createElement('script');
            $scriptNode->setAttribute('src', 'assets/template.js');
            $body->appendChild($scriptNode);
        }
    }

    $normalizedHtml = $dom->saveHTML();
    if (!is_string($normalizedHtml) || trim($normalizedHtml) === '') {
        throw new RuntimeException('No se pudo generar HTML normalizado');
    }

    $normalizedHtml = str_replace('<?xml encoding="UTF-8">', '', $normalizedHtml);

    foreach ($slotMap as $slot => $phpExpression) {
        $normalizedHtml = str_replace($slot, $phpExpression, $normalizedHtml);
    }

    $phpTemplate = "<?php\n\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/../../includes/content-repo.php';\nrequire_once __DIR__ . '/../../includes/template-helpers.php';\n\n\$initialContent = read_content_file(null, " . var_export($slug, true) . ");\n?>\n" . $normalizedHtml;

    $targetFile = $templateDir . '/index.php';
    if (file_put_contents($targetFile, $phpTemplate, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir el archivo de plantilla');
    }

    if (!register_template_slug($slug, $tenantId)) {
        throw new RuntimeException('No se pudo registrar la plantilla');
    }

    if (!save_content_file($defaults, $tenantId, $slug)) {
        throw new RuntimeException('No se pudo actualizar contenido del tenant');
    }

    return [
        'slug' => $slug,
        'backup_path' => $backupPath,
        'template_path' => $targetFile,
        'editable_nodes' => count($created),
        'report' => [
            'variables_created' => count($created),
            'keys' => $created,
            'name_conflicts' => $conflicts,
        ],
    ];
}
