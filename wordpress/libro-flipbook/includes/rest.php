<?php
/**
 * Endpoints REST del panel (mismo protocolo que la versión standalone):
 *
 *   POST /wp-json/libro-flipbook/v1/upload   action=init|page|pdf|finish|abort
 *   GET  /wp-json/libro-flipbook/v1/books    ?action=list
 *   POST /wp-json/libro-flipbook/v1/books    ?action=delete|rename
 *
 * Autenticación: cookie de wp-admin + nonce REST (X-WP-Nonce) + capacidad.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Límites (ajustables con filtros para instalaciones específicas).
function libro_flipbook_max_pdf_size(): int
{
    return (int) apply_filters('libro_flipbook_max_pdf_size', 200 * 1024 * 1024);
}

function libro_flipbook_max_page_size(): int
{
    return (int) apply_filters('libro_flipbook_max_page_size', 4 * 1024 * 1024);
}

function libro_flipbook_max_pages(): int
{
    return (int) apply_filters('libro_flipbook_max_pages', 1000);
}

add_action('rest_api_init', 'libro_flipbook_register_routes');

function libro_flipbook_register_routes(): void
{
    register_rest_route('libro-flipbook/v1', '/upload', [
        'methods'             => 'POST',
        'callback'            => 'libro_flipbook_rest_upload',
        'permission_callback' => 'libro_flipbook_can_manage',
    ]);
    register_rest_route('libro-flipbook/v1', '/books', [
        [
            'methods'             => 'GET',
            // Lectura: también autores/editores (el bloque Gutenberg lista
            // los libros al insertarlo); los datos son públicos de por sí.
            'callback'            => 'libro_flipbook_rest_books_list',
            'permission_callback' => 'libro_flipbook_can_edit',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'libro_flipbook_rest_books_modify',
            'permission_callback' => 'libro_flipbook_can_manage',
        ],
    ]);
}

function libro_flipbook_can_manage(): bool
{
    return current_user_can('manage_options');
}

/**
 * Sistema de archivos de WordPress (transporte directo en hostings normales).
 * Exigido por los estándares de wordpress.org en lugar de rename/rmdir/unlink.
 *
 * @return WP_Filesystem_Base|false
 */
function libro_flipbook_fs()
{
    global $wp_filesystem;
    if (!$wp_filesystem) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    return $wp_filesystem;
}

function libro_flipbook_can_edit(): bool
{
    return current_user_can('edit_posts');
}

/** Respuesta JSON con código de estado (mismo formato que el standalone). */
function libro_flipbook_json(array $data, int $status = 200): WP_REST_Response
{
    return new WP_REST_Response($data, $status);
}

// ------------------------------------------------------------- Subida

function libro_flipbook_rest_upload(WP_REST_Request $request): WP_REST_Response
{
    switch ($request->get_param('action')) {
        case 'init':
            return libro_flipbook_upload_init($request);
        case 'page':
            return libro_flipbook_upload_page($request);
        case 'pdf':
            return libro_flipbook_upload_pdf($request);
        case 'finish':
            return libro_flipbook_upload_finish($request);
        case 'abort':
            return libro_flipbook_upload_abort($request);
        default:
            return libro_flipbook_json(['error' => __('Acción desconocida', 'libro-flipbook')], 400);
    }
}

function libro_flipbook_upload_init(WP_REST_Request $request): WP_REST_Response
{
    $title = trim((string) $request->get_param('title'));
    $slug  = sanitize_title((string) $request->get_param('slug'));
    if ($slug === '') {
        $slug = sanitize_title($title);
    }
    $pages  = (int) $request->get_param('pages');
    $width  = (int) $request->get_param('width');
    $height = (int) $request->get_param('height');
    $format = $request->get_param('format') === 'jpeg' ? 'jpeg' : 'webp';

    if ($title === '' || $slug === '' || $pages < 1 || $pages > libro_flipbook_max_pages()
        || $width < 1 || $height < 1) {
        return libro_flipbook_json(['error' => __('Datos inválidos', 'libro-flipbook')], 400);
    }

    $dir = libro_flipbook_books_dir() . '/' . $slug;
    if (is_file($dir . '/book.json')) {
        return libro_flipbook_json([
            'error' => sprintf(
                /* translators: %s: slug del libro */
                __('Ya existe una publicación con el slug "%s"', 'libro-flipbook'),
                $slug
            ),
        ], 409);
    }

    foreach ([$dir, "$dir/pages", "$dir/thumbs"] as $d) {
        if (!is_dir($d) && !wp_mkdir_p($d)) {
            return libro_flipbook_json(['error' => __('No se pudo crear la carpeta del libro', 'libro-flipbook')], 500);
        }
    }

    $meta = [
        'title'     => $title,
        'slug'      => $slug,
        'pages'     => $pages,
        'width'     => $width,
        'height'    => $height,
        'format'    => $format,
        'hardCover' => $request->get_param('hardCover') === '1',
        'created'   => gmdate('c'),
    ];
    file_put_contents("$dir/pending.json", wp_json_encode($meta, JSON_UNESCAPED_UNICODE));

    return libro_flipbook_json(['ok' => true, 'slug' => $slug]);
}

function libro_flipbook_upload_page(WP_REST_Request $request): WP_REST_Response
{
    $pending = libro_flipbook_require_pending($request);
    if ($pending instanceof WP_REST_Response) {
        return $pending;
    }
    [$dir, $meta] = $pending;

    $index = (int) $request->get_param('index');
    if ($index < 1 || $index > $meta['pages']) {
        return libro_flipbook_json(['error' => __('Índice de página inválido', 'libro-flipbook')], 400);
    }

    $ext = $meta['format'] === 'jpeg' ? 'jpg' : 'webp';
    $files = $request->get_file_params();

    $err = libro_flipbook_save_image($files['page'] ?? null, sprintf('%s/pages/page-%03d.%s', $dir, $index, $ext));
    if ($err === null) {
        $err = libro_flipbook_save_image($files['thumb'] ?? null, sprintf('%s/thumbs/thumb-%03d.%s', $dir, $index, $ext));
    }
    if ($err === null && $index === 1 && isset($files['cover'])) {
        $err = libro_flipbook_save_image($files['cover'], "$dir/cover.$ext");
    }
    if ($err !== null) {
        return $err;
    }

    return libro_flipbook_json(['ok' => true, 'index' => $index]);
}

function libro_flipbook_upload_pdf(WP_REST_Request $request): WP_REST_Response
{
    $pending = libro_flipbook_require_pending($request);
    if ($pending instanceof WP_REST_Response) {
        return $pending;
    }
    [$dir] = $pending;

    $chunkIndex  = (int) $request->get_param('chunkIndex');
    $totalChunks = (int) $request->get_param('totalChunks');
    $files       = $request->get_file_params();
    $file        = $files['chunk'] ?? null;

    if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks || $file === null) {
        return libro_flipbook_json(['error' => __('Fragmento inválido', 'libro-flipbook')], 400);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $limit = ini_get('upload_max_filesize') ?: '?';
            return libro_flipbook_json([
                'error' => sprintf(
                    /* translators: %s: límite de PHP */
                    __('El fragmento supera el límite de subida de PHP (upload_max_filesize=%s)', 'libro-flipbook'),
                    $limit
                ),
            ], 413);
        }
        return libro_flipbook_json(['error' => __('Error al recibir el fragmento', 'libro-flipbook')], 400);
    }

    $part = "$dir/original.pdf.part";
    $current = is_file($part) ? filesize($part) : 0;
    if ($current + $file['size'] > libro_flipbook_max_pdf_size()) {
        wp_delete_file($part);
        return libro_flipbook_json(['error' => __('El PDF supera el tamaño máximo permitido', 'libro-flipbook')], 413);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return libro_flipbook_json(['error' => __('Fragmento inválido', 'libro-flipbook')], 400);
    }

    if ($chunkIndex === 0) {
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (strncmp($head, '%PDF', 4) !== 0) {
            return libro_flipbook_json(['error' => __('El archivo no es un PDF válido', 'libro-flipbook')], 400);
        }
        wp_delete_file($part);
    }

    // Los fragmentos son de ~1.5 MB: se anexan en memoria sin problema.
    $data = file_get_contents($file['tmp_name']);
    if ($data === false || file_put_contents($part, $data, FILE_APPEND) === false) {
        return libro_flipbook_json(['error' => __('No se pudo guardar el fragmento', 'libro-flipbook')], 500);
    }

    if ($chunkIndex === $totalChunks - 1) {
        $fs = libro_flipbook_fs();
        if (!$fs || !$fs->move($part, "$dir/original.pdf", true)) {
            return libro_flipbook_json(['error' => __('No se pudo guardar el PDF', 'libro-flipbook')], 500);
        }
    }

    return libro_flipbook_json(['ok' => true, 'chunkIndex' => $chunkIndex]);
}

function libro_flipbook_upload_finish(WP_REST_Request $request): WP_REST_Response
{
    $pending = libro_flipbook_require_pending($request);
    if ($pending instanceof WP_REST_Response) {
        return $pending;
    }
    [$dir, $meta] = $pending;

    $ext = $meta['format'] === 'jpeg' ? 'jpg' : 'webp';
    for ($i = 1; $i <= $meta['pages']; $i++) {
        if (!is_file(sprintf('%s/pages/page-%03d.%s', $dir, $i, $ext))) {
            return libro_flipbook_json([
                'error' => sprintf(
                    /* translators: %d: número de página */
                    __('Falta la página %d', 'libro-flipbook'),
                    $i
                ),
            ], 400);
        }
    }

    wp_delete_file("$dir/original.pdf.part"); // fragmentos huérfanos de una subida fallida
    $meta['hasPdf'] = is_file("$dir/original.pdf");
    file_put_contents("$dir/book.json", wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    wp_delete_file("$dir/pending.json");

    // Sin página dedicada en WP: el panel muestra el shortcode, no una URL.
    return libro_flipbook_json(['ok' => true, 'slug' => $meta['slug'], 'url' => '']);
}

function libro_flipbook_upload_abort(WP_REST_Request $request): WP_REST_Response
{
    $slug = sanitize_title((string) $request->get_param('slug'));
    $dir  = libro_flipbook_books_dir() . '/' . $slug;
    // Solo se puede abortar una subida pendiente, nunca un libro publicado.
    if ($slug !== '' && is_file("$dir/pending.json") && !is_file("$dir/book.json")) {
        libro_flipbook_delete_dir($dir);
    }
    return libro_flipbook_json(['ok' => true]);
}

// ------------------------------------------------------------- Libros

function libro_flipbook_rest_books_list(): WP_REST_Response
{
    $url = libro_flipbook_books_url();
    $dir = libro_flipbook_books_dir();
    $books = array_map(static function (array $book) use ($url, $dir): array {
        // Portada para las vistas previas (bloque Gutenberg, panel).
        $ext = ($book['format'] ?? 'webp') === 'jpeg' ? 'jpg' : 'webp';
        $slug = $book['slug'];
        $book['cover'] = is_file("$dir/$slug/cover.$ext")
            ? "$url/$slug/cover.$ext"
            : "$url/$slug/pages/page-001.$ext";
        return $book;
    }, libro_flipbook_list_books());
    return libro_flipbook_json(['books' => $books]);
}

function libro_flipbook_rest_books_modify(WP_REST_Request $request): WP_REST_Response
{
    $slug = sanitize_title((string) $request->get_param('slug'));
    $book = libro_flipbook_read_book($slug);

    switch ($request->get_param('action')) {
        case 'delete':
            if ($book === null) {
                return libro_flipbook_json(['error' => __('La publicación no existe', 'libro-flipbook')], 404);
            }
            if (!libro_flipbook_delete_dir(libro_flipbook_books_dir() . '/' . $slug)) {
                return libro_flipbook_json(['error' => __('No se pudo eliminar', 'libro-flipbook')], 500);
            }
            return libro_flipbook_json(['ok' => true]);

        case 'rename':
            if ($book === null) {
                return libro_flipbook_json(['error' => __('La publicación no existe', 'libro-flipbook')], 404);
            }
            $title = trim((string) $request->get_param('title'));
            if ($title === '') {
                return libro_flipbook_json(['error' => __('Título vacío', 'libro-flipbook')], 400);
            }
            $book['title'] = $title;
            file_put_contents(
                libro_flipbook_books_dir() . '/' . $slug . '/book.json',
                wp_json_encode($book, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            return libro_flipbook_json(['ok' => true]);

        default:
            return libro_flipbook_json(['error' => __('Acción desconocida', 'libro-flipbook')], 400);
    }
}

// ------------------------------------------------------------ Helpers

/** Valida que exista una subida pendiente; devuelve [dir, meta] o respuesta de error. */
function libro_flipbook_require_pending(WP_REST_Request $request)
{
    $slug = sanitize_title((string) $request->get_param('slug'));
    $dir  = libro_flipbook_books_dir() . '/' . $slug;
    if ($slug === '' || !is_file("$dir/pending.json")) {
        return libro_flipbook_json(['error' => __('No hay una subida en curso para este libro', 'libro-flipbook')], 400);
    }
    $meta = json_decode((string) file_get_contents("$dir/pending.json"), true);
    if (!is_array($meta)) {
        return libro_flipbook_json(['error' => __('Metadatos corruptos', 'libro-flipbook')], 500);
    }
    return [$dir, $meta];
}

/** Valida y mueve una imagen subida; null si todo bien, respuesta de error si no. */
function libro_flipbook_save_image(?array $file, string $dest): ?WP_REST_Response
{
    if ($file === null) {
        return libro_flipbook_json(['error' => __('Falta un archivo de imagen', 'libro-flipbook')], 400);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $limit = ini_get('upload_max_filesize') ?: '?';
            return libro_flipbook_json([
                'error' => sprintf(
                    /* translators: %s: límite de PHP */
                    __('La imagen supera el límite de subida de PHP (upload_max_filesize=%s)', 'libro-flipbook'),
                    $limit
                ),
            ], 413);
        }
        return libro_flipbook_json(['error' => __('Error al recibir la imagen', 'libro-flipbook')], 400);
    }
    if ($file['size'] > libro_flipbook_max_page_size()) {
        return libro_flipbook_json(['error' => __('Imagen demasiado grande', 'libro-flipbook')], 413);
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
    if (!in_array($mime, ['image/webp', 'image/jpeg'], true)) {
        return libro_flipbook_json(['error' => __('Tipo de imagen no permitido', 'libro-flipbook')], 400);
    }
    $fs = libro_flipbook_fs();
    if (!is_uploaded_file($file['tmp_name']) || !$fs || !$fs->move($file['tmp_name'], $dest, true)) {
        return libro_flipbook_json(['error' => __('No se pudo guardar la imagen', 'libro-flipbook')], 500);
    }
    return null;
}

/** Elimina una carpeta recursivamente, solo dentro de uploads/libro. */
function libro_flipbook_delete_dir(string $dir): bool
{
    $real = realpath($dir);
    $base = realpath(libro_flipbook_books_dir());
    if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    $fs = libro_flipbook_fs();
    return $fs ? $fs->rmdir($real, true) : false;
}
