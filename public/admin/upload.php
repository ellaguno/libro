<?php
/**
 * API de subida de publicaciones. Todas las acciones requieren sesión + CSRF.
 *
 * Protocolo (el navegador del administrador rasteriza el PDF con PDF.js):
 *   POST action=init   {title, slug?, pages, width, height, format}
 *   POST action=page   {slug, index, page: File, thumb: File}
 *   POST action=pdf    {slug, chunk: File, chunkIndex, totalChunks}
 *   POST action=finish {slug}
 *   POST action=abort  {slug}
 */

require_once __DIR__ . '/auth.php';

require_login();
require_csrf();

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'init':
        handle_init();
    case 'page':
        handle_page();
    case 'pdf':
        handle_pdf_chunk();
    case 'finish':
        handle_finish();
    case 'abort':
        handle_abort();
    default:
        json_response(['error' => 'Acción desconocida'], 400);
}

/** Crea la carpeta del libro y guarda los metadatos pendientes. */
function handle_init(): void
{
    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = sanitize_slug((string) ($_POST['slug'] ?? '')) ?: slug_from_title($title);
    $pages = (int) ($_POST['pages'] ?? 0);
    $width = (int) ($_POST['width'] ?? 0);
    $height = (int) ($_POST['height'] ?? 0);
    $format = ($_POST['format'] ?? '') === 'jpeg' ? 'jpeg' : 'webp';

    if ($title === '' || $slug === '' || $pages < 1 || $pages > MAX_PAGES || $width < 1 || $height < 1) {
        json_response(['error' => 'Datos inválidos'], 400);
    }

    $dir = book_dir($slug);
    if ($dir === null) {
        json_response(['error' => 'Slug inválido'], 400);
    }
    if (is_file($dir . '/book.json')) {
        json_response(['error' => "Ya existe una publicación con el slug \"$slug\""], 409);
    }

    foreach ([$dir, "$dir/pages", "$dir/thumbs"] as $d) {
        if (!is_dir($d) && !mkdir($d, 0755, true)) {
            json_response(['error' => 'No se pudo crear la carpeta del libro'], 500);
        }
    }

    // Metadatos pendientes hasta que llegue "finish".
    $meta = [
        'title' => $title,
        'slug' => $slug,
        'pages' => $pages,
        'width' => $width,
        'height' => $height,
        'format' => $format,
        'hardCover' => ($_POST['hardCover'] ?? '') === '1',
        'created' => date('c'),
    ];
    file_put_contents("$dir/pending.json", json_encode($meta, JSON_UNESCAPED_UNICODE));

    json_response(['ok' => true, 'slug' => $slug]);
}

/** Guarda la imagen de una página y su miniatura. */
function handle_page(): void
{
    [$dir, $meta] = require_pending_book();

    $index = (int) ($_POST['index'] ?? -1);
    if ($index < 1 || $index > $meta['pages']) {
        json_response(['error' => 'Índice de página inválido'], 400);
    }

    $ext = $meta['format'] === 'jpeg' ? 'jpg' : 'webp';
    save_uploaded_image('page', sprintf('%s/pages/page-%03d.%s', $dir, $index, $ext));
    save_uploaded_image('thumb', sprintf('%s/thumbs/thumb-%03d.%s', $dir, $index, $ext));

    // La página 1 trae además la portada para la rejilla de la biblioteca.
    if ($index === 1 && isset($_FILES['cover'])) {
        save_uploaded_image('cover', "$dir/cover.$ext");
    }

    json_response(['ok' => true, 'index' => $index]);
}

/** Recibe el PDF original en fragmentos y los concatena. */
function handle_pdf_chunk(): void
{
    [$dir] = require_pending_book();

    $chunkIndex = (int) ($_POST['chunkIndex'] ?? -1);
    $totalChunks = (int) ($_POST['totalChunks'] ?? 0);
    $file = $_FILES['chunk'] ?? null;

    if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks || $file === null) {
        json_response(['error' => 'Fragmento inválido'], 400);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $limit = ini_get('upload_max_filesize') ?: '?';
            json_response(['error' => "El fragmento supera el límite de subida de PHP (upload_max_filesize=$limit)"], 413);
        }
        json_response(['error' => 'Error al recibir el fragmento (código ' . $file['error'] . ')'], 400);
    }

    $part = "$dir/original.pdf.part";
    $current = is_file($part) ? filesize($part) : 0;
    if ($current + $file['size'] > MAX_PDF_SIZE) {
        @unlink($part);
        json_response(['error' => 'El PDF supera el tamaño máximo permitido'], 413);
    }

    // El primer fragmento debe empezar con la firma %PDF.
    if ($chunkIndex === 0) {
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (!str_starts_with($head, '%PDF')) {
            json_response(['error' => 'El archivo no es un PDF válido'], 400);
        }
        @unlink($part);
    }

    $out = fopen($part, 'ab');
    $in = fopen($file['tmp_name'], 'rb');
    if ($out === false || $in === false) {
        json_response(['error' => 'No se pudo guardar el fragmento'], 500);
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    if ($chunkIndex === $totalChunks - 1) {
        rename($part, "$dir/original.pdf");
    }

    json_response(['ok' => true, 'chunkIndex' => $chunkIndex]);
}

/** Publica el libro: valida que estén todas las páginas y escribe book.json. */
function handle_finish(): void
{
    [$dir, $meta] = require_pending_book();

    $ext = $meta['format'] === 'jpeg' ? 'jpg' : 'webp';
    for ($i = 1; $i <= $meta['pages']; $i++) {
        if (!is_file(sprintf('%s/pages/page-%03d.%s', $dir, $i, $ext))) {
            json_response(['error' => "Falta la página $i"], 400);
        }
    }

    $meta['hasPdf'] = is_file("$dir/original.pdf");
    file_put_contents("$dir/book.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @unlink("$dir/pending.json");

    json_response(['ok' => true, 'slug' => $meta['slug'], 'url' => '../ver.php?libro=' . rawurlencode($meta['slug'])]);
}

/** Cancela una subida pendiente y borra lo transferido. */
function handle_abort(): void
{
    $slug = sanitize_slug((string) ($_POST['slug'] ?? ''));
    $dir = book_dir($slug);
    // Solo se puede abortar una subida pendiente, nunca un libro publicado.
    if ($dir !== null && is_file("$dir/pending.json") && !is_file("$dir/book.json")) {
        delete_book_dir($slug);
    }
    json_response(['ok' => true]);
}

/** Valida que exista una subida pendiente para el slug recibido. */
function require_pending_book(): array
{
    $slug = sanitize_slug((string) ($_POST['slug'] ?? ''));
    $dir = book_dir($slug);
    if ($dir === null || !is_file("$dir/pending.json")) {
        json_response(['error' => 'No hay una subida en curso para este libro'], 400);
    }
    $meta = json_decode((string) file_get_contents("$dir/pending.json"), true);
    if (!is_array($meta)) {
        json_response(['error' => 'Metadatos corruptos'], 500);
    }
    return [$dir, $meta];
}

/** Valida y mueve una imagen subida (webp/jpeg reales, no solo extensión). */
function save_uploaded_image(string $field, string $dest): void
{
    $file = $_FILES[$field] ?? null;
    if ($file === null) {
        json_response(['error' => "Falta el archivo \"$field\""], 400);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $limit = ini_get('upload_max_filesize') ?: '?';
            json_response(['error' => "La imagen supera el límite de subida de PHP (upload_max_filesize=$limit); usa una calidad menor"], 413);
        }
        json_response(['error' => "Error al recibir \"$field\" (código " . $file['error'] . ')'], 400);
    }
    if ($file['size'] > MAX_PAGE_SIZE) {
        json_response(['error' => 'Imagen demasiado grande'], 413);
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/webp', 'image/jpeg'], true)) {
        json_response(['error' => "Tipo de imagen no permitido ($mime)"], 400);
    }
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_response(['error' => 'No se pudo guardar la imagen'], 500);
    }
}
