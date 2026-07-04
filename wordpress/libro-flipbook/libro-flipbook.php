<?php
/**
 * Plugin Name:       Libro Flipbook
 * Plugin URI:        https://github.com/ellaguno/libro
 * Description:       Publica PDFs como libros y revistas con paso de página realista. Inserta un libro en cualquier entrada con el shortcode [libro slug="mi-revista"].
 * Version:           0.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            SesoLibre
 * Author URI:        https://sesolibre.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       libro-flipbook
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LIBRO_FLIPBOOK_VERSION', '0.3.0');
define('LIBRO_FLIPBOOK_DIR', plugin_dir_path(__FILE__));
define('LIBRO_FLIPBOOK_URL', plugin_dir_url(__FILE__));

require_once LIBRO_FLIPBOOK_DIR . 'includes/books.php';
require_once LIBRO_FLIPBOOK_DIR . 'includes/shortcode.php';
require_once LIBRO_FLIPBOOK_DIR . 'includes/rest.php';
require_once LIBRO_FLIPBOOK_DIR . 'includes/admin-page.php';
require_once LIBRO_FLIPBOOK_DIR . 'includes/block.php';
require_once LIBRO_FLIPBOOK_DIR . 'includes/social-meta.php';

// Traducciones (languages/libro-flipbook-<locale>.mo). Nota: Plugin Check lo
// marca como innecesario para plugins alojados en wordpress.org, pero en
// distribución privada (zip) sigue siendo la única forma de cargar los .mo
// incluidos en el plugin. Quitar si algún día se publica en el directorio.
add_action('init', 'libro_flipbook_load_textdomain');
function libro_flipbook_load_textdomain(): void
{
    load_plugin_textdomain('libro-flipbook', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
