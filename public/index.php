<?php
/**
 * Portada: rejilla de publicaciones.
 */

require_once __DIR__ . '/lib.php';

$books = list_books();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<?= site_favicon_html() ?><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(SITE_TITLE) ?></title>
<link rel="stylesheet" href="<?= asset_url('viewer.css') ?>">
<?= site_theme_style() ?></head>
<body class="library">
<?= site_logo_html('site-logo') ?>
<header class="library-header">
  <h1><?= e(trim(SITE_ICON . ' ' . SITE_TITLE)) ?></h1>
</header>

<main class="library-grid">
<?php if (!$books): ?>
  <p class="library-empty">Todavía no hay publicaciones.</p>
<?php else: ?>
  <?php foreach ($books as $book):
      $slug = e($book['slug']);
      $ext = ($book['format'] ?? 'webp') === 'jpeg' ? 'jpg' : 'webp';
      // Portada dedicada (640 px, nítida); los libros antiguos que no la tienen
      // usan la página completa como respaldo.
      $cover = is_file(BOOKS_DIR . "/$slug/cover.$ext")
          ? "books/$slug/cover.$ext"
          : "books/$slug/pages/page-001.$ext";
  ?>
  <a class="library-item" href="ver.php?libro=<?= rawurlencode($book['slug']) ?>">
    <span class="library-cover">
      <img src="<?= e($cover) ?>" alt="<?= e($book['title']) ?>" loading="lazy">
    </span>
    <span class="library-title"><?= e($book['title']) ?></span>
    <span class="library-pages"><?= (int) $book['pages'] ?> páginas</span>
  </a>
  <?php endforeach; ?>
<?php endif; ?>
</main>
</body>
</html>
