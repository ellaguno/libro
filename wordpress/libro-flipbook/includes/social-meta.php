<?php
/**
 * Metadatos para redes sociales: cuando una entrada o página contiene un libro
 * (shortcode [libro] o bloque libro-flipbook/libro), la liga compartida en
 * Facebook, WhatsApp, X, etc. muestra la portada de la publicación vía
 * Open Graph (og:image) y Twitter Card.
 *
 * Si la entrada tiene imagen destacada se usa esa (respeta la elección
 * editorial y lo que ya publiquen los plugins de SEO). Se puede desactivar
 * con: add_filter('libro_flipbook_social_meta', '__return_false');
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'libro_flipbook_social_meta', 4);

function libro_flipbook_social_meta(): void
{
    if (!is_singular() || !apply_filters('libro_flipbook_social_meta', true)) {
        return;
    }
    $post = get_post();
    if (!$post) {
        return;
    }
    // La imagen destacada tiene prioridad: el tema o plugin SEO ya la publica
    // como og:image (y si no hay plugin SEO, la publicamos nosotros).
    if (has_post_thumbnail($post)) {
        if (!libro_flipbook_seo_plugin_active()) {
            $img = wp_get_attachment_image_src(get_post_thumbnail_id($post), 'large');
            if ($img) {
                libro_flipbook_print_image_meta($img[0], (int) $img[1], (int) $img[2]);
            }
        }
        return;
    }

    $slug = libro_flipbook_first_book_slug((string) $post->post_content);
    if ($slug === '') {
        return;
    }
    $book = libro_flipbook_read_book($slug);
    if ($book === null) {
        return;
    }
    $cover = libro_flipbook_social_cover($book);
    if ($cover === null) {
        return;
    }
    $size = wp_getimagesize($cover['file']);
    libro_flipbook_print_image_meta(
        $cover['url'],
        is_array($size) ? (int) $size[0] : 0,
        is_array($size) ? (int) $size[1] : 0
    );
}

/** Imprime las etiquetas og:image / twitter:image. */
function libro_flipbook_print_image_meta(string $url, int $width = 0, int $height = 0): void
{
    echo '<meta property="og:image" content="' . esc_url($url) . '">' . "\n";
    if ($width > 0 && $height > 0) {
        echo '<meta property="og:image:width" content="' . (int) $width . '">' . "\n";
        echo '<meta property="og:image:height" content="' . (int) $height . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:image" content="' . esc_url($url) . '">' . "\n";
}

/** ¿Hay un plugin de SEO conocido que ya gestione las etiquetas sociales? */
function libro_flipbook_seo_plugin_active(): bool
{
    return defined('WPSEO_VERSION')      // Yoast SEO
        || defined('RANK_MATH_VERSION')  // Rank Math
        || defined('AIOSEO_VERSION')     // All in One SEO
        || defined('SEOPRESS_VERSION');  // SEOPress
}

/** Slug del primer libro insertado en el contenido; '' si no hay ninguno. */
function libro_flipbook_first_book_slug(string $content): string
{
    // Bloque Gutenberg: comentario <!-- wp:libro-flipbook/libro {"slug":"…"} -->.
    if (preg_match('/<!--\s*wp:libro-flipbook\/libro\s+({.*?})\s*\/?-->/s', $content, $m)) {
        $attrs = json_decode($m[1], true);
        if (is_array($attrs) && !empty($attrs['slug'])) {
            return sanitize_title((string) $attrs['slug']);
        }
    }
    // Shortcode [libro slug="mi-revista"].
    if (has_shortcode($content, 'libro')
        && preg_match('/\[libro\b[^\]]*\bslug=["\']?([a-z0-9-]+)/i', $content, $m)) {
        return sanitize_title($m[1]);
    }
    return '';
}

/**
 * Portada del libro para compartir: ['url' => …, 'file' => …]; null si no hay.
 * WhatsApp y otros scrapers no aceptan WebP en og:image, así que si la portada
 * es WebP se genera una copia JPEG (cover-og.jpg, cacheada junto al libro).
 */
function libro_flipbook_social_cover(array $book): ?array
{
    $slug = sanitize_title((string) ($book['slug'] ?? ''));
    if ($slug === '') {
        return null;
    }
    $dir = libro_flipbook_books_dir() . '/' . $slug;
    $url = libro_flipbook_books_url() . '/' . $slug;
    $ext = ($book['format'] ?? 'webp') === 'jpeg' ? 'jpg' : 'webp';
    // Portada dedicada; los libros antiguos usan la página 1 como respaldo.
    $src = is_file("$dir/cover.$ext") ? "cover.$ext"
        : (is_file("$dir/pages/page-001.$ext") ? "pages/page-001.$ext" : null);
    if ($src === null) {
        return null;
    }
    if ($ext === 'webp') {
        $jpg = "$dir/cover-og.jpg";
        if (!is_file($jpg)) {
            $editor = wp_get_image_editor("$dir/$src");
            if (!is_wp_error($editor)) {
                $editor->set_quality(85);
                $editor->save($jpg, 'image/jpeg');
            }
        }
        if (is_file($jpg)) {
            $src = 'cover-og.jpg';
        }
    }
    return ['url' => "$url/$src", 'file' => "$dir/$src"];
}
