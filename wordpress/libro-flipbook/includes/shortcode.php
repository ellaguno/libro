<?php
/**
 * Shortcode [libro slug="mi-revista" p="12" alto="600px"]
 * Imprime el contenedor del visor; viewer.js (multi-instancia) lo inicializa.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('libro', 'libro_flipbook_shortcode');
add_action('wp_enqueue_scripts', 'libro_flipbook_register_assets');
add_filter('script_loader_tag', 'libro_flipbook_module_tag', 10, 2);

/** Registra (sin encolar) los assets del visor; el shortcode los encola. */
function libro_flipbook_register_assets(): void
{
    wp_register_style(
        'libro-flipbook-viewer',
        LIBRO_FLIPBOOK_URL . 'assets/viewer.css',
        [],
        LIBRO_FLIPBOOK_VERSION
    );
    wp_register_script(
        'libro-flipbook-viewer',
        LIBRO_FLIPBOOK_URL . 'assets/viewer.js',
        [],
        LIBRO_FLIPBOOK_VERSION,
        true
    );
}

/** viewer.js es un módulo ES: el <script> necesita type="module". */
function libro_flipbook_module_tag(string $tag, string $handle): string
{
    if ($handle === 'libro-flipbook-viewer') {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}

/**
 * Atributos: slug (obligatorio), p (página inicial), alto (CSS, ej. 600px/80vh).
 */
function libro_flipbook_shortcode($atts): string
{
    $atts = shortcode_atts([
        'slug' => '',
        'p'    => '',
        'alto' => '75vh',
    ], $atts, 'libro');

    $slug = sanitize_title($atts['slug']);
    $book = $slug !== '' ? libro_flipbook_read_book($slug) : null;
    if ($book === null) {
        return current_user_can('edit_posts')
            ? '<p><em>' . sprintf(
                /* translators: %s: slug del libro */
                esc_html__('[libro]: no existe la publicación "%s" en uploads/libro/.', 'libro-flipbook'),
                esc_html($slug)
            ) . '</em></p>'
            : '';
    }

    wp_enqueue_style('libro-flipbook-viewer');
    wp_enqueue_script('libro-flipbook-viewer');

    $ext = ($book['format'] ?? 'webp') === 'jpeg' ? 'jpg' : 'webp';
    $base = libro_flipbook_books_url() . '/' . $slug . '/';
    // Solo unidades CSS simples para la altura (número + unidad).
    $height = preg_match('/^\d+(\.\d+)?(px|vh|em|rem|%)$/', $atts['alto']) ? $atts['alto'] : '75vh';

    return sprintf(
        '<div class="libro-flipbook" style="height:%s"
              data-slug="%s" data-title="%s" data-pages="%d"
              data-width="%d" data-height="%d" data-ext="%s"
              data-has-pdf="%s" data-hard-cover="%s"
              data-base="%s" data-embed="1" data-deep-link="0" data-start="%d"></div>',
        esc_attr($height),
        esc_attr($book['slug']),
        esc_attr($book['title'] ?? $book['slug']),
        (int) $book['pages'],
        (int) ($book['width'] ?? 0),
        (int) ($book['height'] ?? 0),
        esc_attr($ext),
        !empty($book['hasPdf']) ? '1' : '0',
        !empty($book['hardCover']) ? '1' : '0',
        esc_url($base),
        max(0, (int) $atts['p'])
    );
}
