<?php
/**
 * API de gestión de publicaciones (requiere sesión).
 *
 *   GET  ?action=list
 *   POST action=delete {slug}
 *   POST action=rename {slug, title}
 */

require_once __DIR__ . '/auth.php';

require_login();

$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    json_response(['books' => list_books()]);
}

// Acciones de escritura: POST + CSRF.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Método no permitido'], 405);
}
require_csrf();

$slug = sanitize_slug((string) ($_POST['slug'] ?? ''));

switch ($action) {
    case 'delete':
        if (read_book($slug) === null) {
            json_response(['error' => 'La publicación no existe'], 404);
        }
        if (!delete_book_dir($slug)) {
            json_response(['error' => 'No se pudo eliminar'], 500);
        }
        json_response(['ok' => true]);

    case 'rename':
        $book = read_book($slug);
        if ($book === null) {
            json_response(['error' => 'La publicación no existe'], 404);
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            json_response(['error' => 'Título vacío'], 400);
        }
        $book['title'] = $title;
        file_put_contents(
            book_dir($slug) . '/book.json',
            json_encode($book, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        json_response(['ok' => true]);

    default:
        json_response(['error' => 'Acción desconocida'], 400);
}
