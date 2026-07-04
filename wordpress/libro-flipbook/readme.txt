=== Libro Flipbook ===
Contributors: sesolibre
Tags: flipbook, pdf, magazine, book, viewer
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish PDFs as books and magazines with a realistic page-turning viewer.

== Description ==

Flipbook viewer to publish PDFs as books or magazines inside any post or
page, with realistic page-turn animation, zoom, thumbnails, fullscreen
mode and optional download of the original file.

Insert a book with the "Libro" block in the editor, or with the shortcode:

`[libro slug="my-magazine" p="12" alto="600px"]`

Upload your PDFs from the "Libros" menu in wp-admin: the conversion to
page images happens in your browser, so the server is never overloaded
and no server-side PDF tools are required.

Books are stored as plain files in `wp-content/uploads/libro/<slug>/`
(page images + book.json); nothing is added to the database.

== Changelog ==

= 0.3.0 =
* Social sharing: when a post embeds a book (shortcode or block) and has no
  featured image, its cover is exposed as og:image / Twitter Card, so shared
  links show the cover on Facebook, WhatsApp, X, etc.
* WebP covers get an automatic JPEG copy (cover-og.jpg) for scrapers that do
  not accept WebP. Disable the tags with the libro_flipbook_social_meta filter.

= 0.2.1 =
* File operations migrated to the WordPress Filesystem API (Plugin Check compliance).
* readme.txt rewritten in English; tested up to WordPress 7.0.

= 0.2.0 =
* "Libro" Gutenberg block with book selector and cover preview.
* Admin page in wp-admin: upload, rename and delete books.
* REST endpoints secured with nonces and WordPress capabilities.
* Viewer CSS hardening against theme styles.
* Translation template (languages/libro-flipbook.pot).

= 0.1.0 =
* Initial release: [libro] shortcode with the full viewer.
