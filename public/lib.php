<?php
/**
 * Funciones compartidas: libros, slugs, respuestas JSON.
 */

// config.php no se versiona (contiene la contraseña); se crea a partir del ejemplo.
if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Falta public/config.php — copia public/config.example.php a public/config.php y personalízalo.');
}
require_once __DIR__ . '/config.php';

// Valores por defecto de marca, para configs anteriores que no los definan.
if (!defined('SITE_TITLE')) {
    define('SITE_TITLE', 'Biblioteca');
}
if (!defined('SITE_LOGO')) {
    define('SITE_LOGO', '');
}
if (!defined('SITE_LOGO_URL')) {
    define('SITE_LOGO_URL', '');
}
if (!defined('SITE_BACKGROUND')) {
    define('SITE_BACKGROUND', '');
}
if (!defined('SITE_TEXT_COLOR')) {
    define('SITE_TEXT_COLOR', '');
}

// Polyfills para hostings con PHP 7.4 (str_starts_with/str_ends_with son de PHP 8.0).
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/** Sanea un slug: solo minúsculas, números y guiones. */
function sanitize_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
    $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
    return substr($slug, 0, 80);
}

/** Genera un slug a partir de un título (translitera acentos comunes). */
function slug_from_title(string $title): string
{
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];
    return sanitize_slug(strtr($title, $map));
}

/** Ruta absoluta de la carpeta de un libro; null si el slug es inválido. */
function book_dir(string $slug): ?string
{
    $slug = sanitize_slug($slug);
    if ($slug === '') {
        return null;
    }
    return BOOKS_DIR . '/' . $slug;
}

/** Lee book.json de un libro publicado; null si no existe. */
function read_book(string $slug): ?array
{
    $dir = book_dir($slug);
    if ($dir === null || !is_file($dir . '/book.json')) {
        return null;
    }
    $data = json_decode((string) file_get_contents($dir . '/book.json'), true);
    return is_array($data) ? $data : null;
}

/** Lista todos los libros publicados, más reciente primero. */
function list_books(): array
{
    $books = [];
    foreach (glob(BOOKS_DIR . '/*/book.json') ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && isset($data['slug'], $data['pages'])) {
            $books[] = $data;
        }
    }
    usort($books, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
    return $books;
}

/** Responde JSON y termina. */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * URL de un asset compilado con versión anti-caché (fecha de modificación).
 * Así el navegador recarga JS/CSS automáticamente tras cada despliegue.
 */
function asset_url(string $name, string $prefix = ''): string
{
    $file = __DIR__ . '/assets/' . $name;
    $version = is_file($file) ? (string) filemtime($file) : '0';
    return $prefix . 'assets/' . $name . '?v=' . $version;
}

/** Escapa HTML. */
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Bloque <style> con el fondo y color de texto de config.php.
 * Vacío si no se personalizó nada (aplica el CSS por defecto).
 */
function site_theme_style(): string
{
    $css = '';
    if (SITE_BACKGROUND !== '') {
        $css .= 'background:' . SITE_BACKGROUND . ';';
    }
    if (SITE_TEXT_COLOR !== '') {
        $css .= 'color:' . SITE_TEXT_COLOR . ';';
    }
    if ($css === '') {
        return '';
    }
    // El valor viene del admin (config.php), pero evita romper el bloque <style>.
    $css = str_replace(['<', '>'], '', $css);
    return "<style>body{{$css}}</style>\n";
}

/** HTML del logo del sitio (con liga opcional); cadena vacía si no hay logo. */
function site_logo_html(string $class): string
{
    if (SITE_LOGO === '') {
        return '';
    }
    $img = '<img src="' . e(SITE_LOGO) . '" alt="' . e(SITE_TITLE) . '">';
    if (SITE_LOGO_URL !== '') {
        return '<a class="' . e($class) . '" href="' . e(SITE_LOGO_URL)
            . '" target="_blank" rel="noopener">' . $img . '</a>';
    }
    return '<span class="' . e($class) . '">' . $img . '</span>';
}

/** Elimina una carpeta recursivamente (solo dentro de BOOKS_DIR). */
function delete_book_dir(string $slug): bool
{
    $dir = book_dir($slug);
    if ($dir === null || !is_dir($dir)) {
        return false;
    }
    $real = realpath($dir);
    $base = realpath(BOOKS_DIR);
    if ($real === false || $base === false || !str_starts_with($real, $base . '/')) {
        return false;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    return rmdir($real);
}
