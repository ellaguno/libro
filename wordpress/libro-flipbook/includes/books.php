<?php
/**
 * Acceso a los libros publicados: carpetas en uploads/libro/<slug>/
 * con el mismo formato que la versión standalone (book.json + pages/ + thumbs/).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Ruta absoluta de la carpeta de libros (wp-content/uploads/libro). */
function libro_flipbook_books_dir(): string
{
    $upload = wp_upload_dir();
    return trailingslashit($upload['basedir']) . 'libro';
}

/** URL pública de la carpeta de libros. */
function libro_flipbook_books_url(): string
{
    $upload = wp_upload_dir();
    return trailingslashit($upload['baseurl']) . 'libro';
}

/** Lee los metadatos (book.json) de un libro publicado; null si no existe. */
function libro_flipbook_read_book(string $slug): ?array
{
    $slug = sanitize_title($slug);
    if ($slug === '') {
        return null;
    }
    $file = libro_flipbook_books_dir() . '/' . $slug . '/book.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Lista todos los libros publicados, más reciente primero. */
function libro_flipbook_list_books(): array
{
    $books = [];
    foreach (glob(libro_flipbook_books_dir() . '/*/book.json') ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && isset($data['slug'], $data['pages'])) {
            $books[] = $data;
        }
    }
    usort($books, static fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
    return $books;
}
