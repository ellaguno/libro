<?php
/**
 * Página "Libros" en wp-admin: subir PDF (conversión en el navegador con
 * PDF.js) y gestionar publicaciones. Reutiliza admin.js del standalone.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'libro_flipbook_admin_menu');
add_action('admin_enqueue_scripts', 'libro_flipbook_admin_assets');

function libro_flipbook_admin_menu(): void
{
    add_menu_page(
        __('Libros', 'libro-flipbook'),
        __('Libros', 'libro-flipbook'),
        'manage_options',
        'libro-flipbook',
        'libro_flipbook_render_admin_page',
        'dashicons-book',
        26
    );
}

function libro_flipbook_admin_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_libro-flipbook') {
        return;
    }
    wp_enqueue_style(
        'libro-flipbook-admin',
        LIBRO_FLIPBOOK_URL . 'assets/admin.css',
        [],
        LIBRO_FLIPBOOK_VERSION
    );
    wp_enqueue_script(
        'libro-flipbook-admin',
        LIBRO_FLIPBOOK_URL . 'assets/admin.js',
        [],
        LIBRO_FLIPBOOK_VERSION,
        true
    );
}

// admin.js es un módulo ES (igual que viewer.js).
add_filter('script_loader_tag', 'libro_flipbook_admin_module_tag', 10, 2);
function libro_flipbook_admin_module_tag(string $tag, string $handle): string
{
    if ($handle === 'libro-flipbook-admin') {
        $tag = str_replace('<script ', '<script type="module" ', $tag);
    }
    return $tag;
}

function libro_flipbook_render_admin_page(): void
{
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Libros', 'libro-flipbook'); ?></h1>
      <p>
        <?php esc_html_e('Sube un PDF: se convierte a imágenes en tu navegador y se publica como flipbook.', 'libro-flipbook'); ?>
        <?php esc_html_e('Inserta un libro en cualquier entrada con su shortcode.', 'libro-flipbook'); ?>
      </p>

      <main class="admin-main"
            data-csrf=""
            data-wp-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
            data-api-upload="<?php echo esc_url(rest_url('libro-flipbook/v1/upload')); ?>"
            data-api-books="<?php echo esc_url(rest_url('libro-flipbook/v1/books')); ?>"
            data-books-base="<?php echo esc_url(libro_flipbook_books_url()); ?>"
            data-view-base=""
            data-worker="<?php echo esc_url(LIBRO_FLIPBOOK_URL . 'assets/pdf.worker.min.js'); ?>"
            data-max-pdf="<?php echo (int) libro_flipbook_max_pdf_size(); ?>">

        <section class="upload-section">
          <h2><?php esc_html_e('Nueva publicación', 'libro-flipbook'); ?></h2>
          <form id="upload-form">
            <label for="title"><?php esc_html_e('Título', 'libro-flipbook'); ?></label>
            <input type="text" id="title" placeholder="Mi revista — número 1" required>

            <label for="pdf-file"><?php esc_html_e('Archivo PDF', 'libro-flipbook'); ?></label>
            <input type="file" id="pdf-file" accept="application/pdf" required>

            <label for="quality"><?php esc_html_e('Calidad de imagen', 'libro-flipbook'); ?></label>
            <select id="quality">
              <option value="1200"><?php esc_html_e('Normal (1200 px)', 'libro-flipbook'); ?></option>
              <option value="1600" selected><?php esc_html_e('Alta (1600 px)', 'libro-flipbook'); ?></option>
              <option value="2000"><?php esc_html_e('Muy alta (2000 px)', 'libro-flipbook'); ?></option>
            </select>

            <label class="check-label">
              <input type="checkbox" id="hard-cover">
              <?php esc_html_e('Portada de pasta dura (la primera y última página giran rígidas)', 'libro-flipbook'); ?>
            </label>

            <button type="submit" id="upload-btn" class="button button-primary">
              <?php esc_html_e('Convertir y publicar', 'libro-flipbook'); ?>
            </button>
          </form>

          <div id="progress" class="progress" hidden>
            <div class="progress-bar"><div id="progress-fill" class="progress-fill"></div></div>
            <p id="progress-text"></p>
          </div>
        </section>

        <section class="books-section">
          <h2><?php esc_html_e('Publicaciones', 'libro-flipbook'); ?></h2>
          <div id="book-list" class="book-list"><p><?php esc_html_e('Cargando…', 'libro-flipbook'); ?></p></div>
        </section>
      </main>
    </div>
    <?php
}
