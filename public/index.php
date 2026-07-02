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
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📖</text></svg>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Biblioteca</title>
<link rel="stylesheet" href="<?= asset_url('viewer.css') ?>">
</head>
<body class="library">
<header class="library-header">
  <h1>📚 Biblioteca</h1>
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
