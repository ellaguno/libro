<?php
/**
 * Bloque Gutenberg "Libro": dinámico, el servidor lo pinta con el mismo
 * código del shortcode (una sola fuente de verdad para el markup).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'libro_flipbook_register_block');

function libro_flipbook_register_block(): void
{
    register_block_type(LIBRO_FLIPBOOK_DIR . 'blocks/libro', [
        'render_callback' => 'libro_flipbook_render_block',
    ]);
}

function libro_flipbook_render_block(array $attrs): string
{
    return libro_flipbook_shortcode([
        'slug' => $attrs['slug'] ?? '',
        'p'    => (string) ($attrs['p'] ?? ''),
        'alto' => $attrs['alto'] ?? '75vh',
    ]);
}
