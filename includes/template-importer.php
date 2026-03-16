<?php

declare(strict_types=1);

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-manager.php';

function normalize_template_slug(string $slug): string
{
    $slug = trim(mb_strtolower($slug));
    $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');

    return $slug;
}

function import_backups_dir(): string
{
    return dirname(__DIR__) . '/data/template-imports';
}

function save_html_backup(string $slug, string $html): ?string
{
    $dir = import_backups_dir();
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

function import_template_from_html(string $slug, string $html): array
{
    $slug = normalize_template_slug($slug);
    if ($slug === '' || preg_match('/^[a-z0-9\-]+$/', $slug) !== 1) {
        throw new InvalidArgumentException('Slug inválido');
    }

    if (trim($html) === '') {
        throw new InvalidArgumentException('HTML vacío');
    }

    $backupPath = save_html_backup($slug, $html);
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
    $nodes = $xpath->query('//text()[normalize-space(.) != "" and not(ancestor::script) and not(ancestor::style)]');

    $defaults = [];
    $counter = 0;
    $slotCounter = 0;
    $slotMap = [];

    if ($nodes !== false) {
        /** @var DOMText $textNode */
        foreach ($nodes as $textNode) {
            $parent = $textNode->parentNode;
            if (!$parent instanceof DOMElement) {
                continue;
            }

            $text = trim($textNode->nodeValue ?? '');
            if ($text === '') {
                continue;
            }

            if ($parent->tagName === 'title') {
                $key = 'site.title';
            } else {
                $counter++;
                $key = 'imported.text_' . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
            }

            $parent->setAttribute('data-edit-key', $key);
            $parent->setAttribute('data-edit-type', 'text');
            $defaults[$key] = $text;

            $slotCounter++;
            $slot = '__PHP_SLOT_' . $slotCounter . '__';
            $slotMap[$slot] = sprintf('<?= esc(content_get($initialContent, %s, %s)) ?>', var_export($key, true), var_export($text, true));

            while ($parent->firstChild !== null) {
                $parent->removeChild($parent->firstChild);
            }

            $parent->appendChild($dom->createTextNode($slot));
        }
    }

    $templateDir = dirname(__DIR__) . '/templates/' . $slug;
    if (!is_dir($templateDir) && !mkdir($templateDir, 0775, true) && !is_dir($templateDir)) {
        throw new RuntimeException('No se pudo crear directorio de plantilla');
    }

    $normalizedHtml = $dom->saveHTML();
    if (!is_string($normalizedHtml) || trim($normalizedHtml) === '') {
        throw new RuntimeException('No se pudo generar HTML normalizado');
    }

    $normalizedHtml = str_replace('<?xml encoding="UTF-8">', '', $normalizedHtml);

    foreach ($slotMap as $slot => $phpExpression) {
        $normalizedHtml = str_replace($slot, $phpExpression, $normalizedHtml);
    }

    $phpTemplate = "<?php\n\ndeclare(strict_types=1);\n\nrequire_once __DIR__ . '/../../includes/content-repo.php';\nrequire_once __DIR__ . '/../../includes/template-helpers.php';\n\n\$initialContent = read_content_file();\n?>\n" . $normalizedHtml;

    $targetFile = $templateDir . '/index.php';
    if (file_put_contents($targetFile, $phpTemplate, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo escribir el archivo de plantilla');
    }

    if (!register_template_slug($slug)) {
        throw new RuntimeException('No se pudo registrar la plantilla');
    }

    return [
        'slug' => $slug,
        'backup_path' => $backupPath,
        'template_path' => $targetFile,
        'editable_nodes' => count($defaults),
    ];
}
