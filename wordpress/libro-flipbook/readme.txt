=== Libro Flipbook ===
Contributors: sesolibre
Tags: flipbook, pdf, revista, libro, visor
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publica PDFs como libros y revistas con paso de página realista.

== Description ==

Visor tipo flipbook para publicar PDFs como libros o revistas dentro de
cualquier entrada o página, con animación realista de paso de página,
zoom, miniaturas, pantalla completa y descarga opcional del original.

Inserta un libro con el bloque "Libro" del editor, o con el shortcode:

`[libro slug="mi-revista" p="12" alto="600px"]`

Sube tus PDFs desde el menú "Libros" de wp-admin: la conversión a
imágenes ocurre en tu navegador, sin sobrecargar el servidor.

Los libros viven como archivos en `wp-content/uploads/libro/<slug>/`
(imágenes de páginas + book.json), sin ocupar la base de datos.

== Changelog ==

= 0.2.0 =
* Bloque Gutenberg "Libro" con selector de publicación y vista previa.
* Panel de administración en wp-admin: subir, renombrar y eliminar.
* Endpoints REST con nonce y capacidades de WordPress.
* Blindaje CSS del visor contra estilos de temas.
* Plantilla de traducción (languages/libro-flipbook.pot).

= 0.1.0 =
* Versión inicial: shortcode [libro] con el visor completo.
