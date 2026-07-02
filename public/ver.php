<?php
/**
 * Visor de una publicación: /ver.php?libro=<slug>
 */

require_once __DIR__ . '/lib.php';

$slug = sanitize_slug((string) ($_GET['libro'] ?? ''));
$book = $slug !== '' ? read_book($slug) : null;

if ($book === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><meta charset="utf-8">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📖</text></svg>"><title>No encontrado</title>'
        . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
        . '<h1>Publicación no encontrada</h1><p><a href="./">Volver a la biblioteca</a></p></body></html>';
    exit;
}

$ext = ($book['format'] ?? 'webp') === 'jpeg' ? 'jpg' : 'webp';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📖</text></svg>">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= e($book['title']) ?> — <?= e(SITE_TITLE) ?></title>
<link rel="stylesheet" href="<?= asset_url('viewer.css') ?>">
<?= site_theme_style() ?></head>
<body class="viewer-page">
<div id="viewer"
     data-slug="<?= e($book['slug']) ?>"
     data-title="<?= e($book['title']) ?>"
     data-pages="<?= (int) $book['pages'] ?>"
     data-width="<?= (int) $book['width'] ?>"
     data-height="<?= (int) $book['height'] ?>"
     data-ext="<?= e($ext) ?>"
     data-has-pdf="<?= !empty($book['hasPdf']) ? '1' : '0' ?>"
     data-hard-cover="<?= !empty($book['hardCover']) ? '1' : '0' ?>"
     data-logo="<?= e(SITE_LOGO) ?>"
     data-logo-url="<?= e(SITE_LOGO_URL) ?>"
     data-site-title="<?= e(SITE_TITLE) ?>">
</div>
<script type="module" src="<?= asset_url('viewer.js') ?>"></script>
</body>
</html>
