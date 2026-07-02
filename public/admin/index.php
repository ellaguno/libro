<?php
/**
 * Panel de administración: login + interfaz de gestión/subida.
 */

require_once __DIR__ . '/auth.php';

// Exige barra final (/admin/): sin ella, los fetch relativos del panel
// (upload.php, books.php) apuntarían a la carpeta padre y darían 404.
// Apache redirige solo; el servidor embebido de PHP (php -S) no.
$reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if ($reqPath !== '' && !str_ends_with($reqPath, '/') && !str_ends_with($reqPath, '.php')) {
    $query = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . $reqPath . '/' . $query, true, 301);
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['password'])) {
    if (login_locked()) {
        $error = 'Demasiados intentos. Espera unos minutos.';
    } elseif (check_password((string) $_POST['password'])) {
        register_login_attempt(true);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: ./');
        exit;
    } else {
        register_login_attempt(false);
        $error = 'Contraseña incorrecta.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📖</text></svg>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Administración — Biblioteca</title>
<link rel="stylesheet" href="<?= asset_url('admin.css', '../') ?>">
</head>
<body class="admin">
<?php if (!is_logged_in()): ?>
  <main class="login-box">
    <h1>📚 Administración</h1>
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required autofocus>
      <button type="submit">Entrar</button>
    </form>
  </main>
<?php else: ?>
  <header class="admin-header">
    <h1>📚 Biblioteca — Administración</h1>
    <nav>
      <a href="../">Ver portada</a>
      <a href="?logout=1">Salir</a>
    </nav>
  </header>

  <main class="admin-main"
        data-csrf="<?= e(csrf_token()) ?>"
        data-max-pdf="<?= (int) MAX_PDF_SIZE ?>">

    <section class="upload-section">
      <h2>Nueva publicación</h2>
      <form id="upload-form">
        <label for="title">Título</label>
        <input type="text" id="title" placeholder="Mi revista — número 1" required>

        <label for="pdf-file">Archivo PDF</label>
        <input type="file" id="pdf-file" accept="application/pdf" required>

        <label for="quality">Calidad de imagen</label>
        <select id="quality">
          <option value="1200">Normal (1200 px)</option>
          <option value="1600" selected>Alta (1600 px)</option>
          <option value="2000">Muy alta (2000 px)</option>
        </select>

        <label class="check-label">
          <input type="checkbox" id="hard-cover">
          Portada de pasta dura (la primera y última página giran rígidas)
        </label>

        <button type="submit" id="upload-btn">Convertir y publicar</button>
      </form>

      <div id="progress" class="progress" hidden>
        <div class="progress-bar"><div id="progress-fill" class="progress-fill"></div></div>
        <p id="progress-text"></p>
      </div>
    </section>

    <section class="books-section">
      <h2>Publicaciones</h2>
      <div id="book-list" class="book-list"><p>Cargando…</p></div>
    </section>
  </main>

  <script type="module" src="<?= asset_url('admin.js', '../') ?>"></script>
<?php endif; ?>
</body>
</html>
