<?php
/**
 * Plugin Name:       Libro Flipbook
 * Plugin URI:        https://github.com/ellaguno/libro
 * Description:       Publica PDFs como libros y revistas con paso de página realista. Inserta un libro en cualquier entrada con el shortcode [libro slug="mi-revista"].
 * Version:           0.1.0
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

define('LIBRO_FLIPBOOK_VERSION', '0.1.0');
define('LIBRO_FLIPBOOK_DIR', plugin_dir_path(__FILE__));
define('LIBRO_FLIPBOOK_URL', plugin_dir_url(__FILE__));

require_once LIBRO_FLIPBOOK_DIR . 'includes/books.php';
require_once LIBRO_FLIPBOOK_DIR . 'includes/shortcode.php';
