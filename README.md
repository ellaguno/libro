# 📚 Libro — visor de PDF tipo flipbook

Aplicación web que muestra PDFs (libros, revistas, catálogos) con efecto realista de
pasar páginas. Pensada para **hosting compartido con cPanel**: solo necesita PHP ≥ 7.4
en el servidor; todo el procesamiento pesado (rasterizar el PDF) ocurre **en el
navegador del administrador** con PDF.js al subir la publicación. Funciona igual en la
raíz del dominio o en un subdirectorio (p. ej. `midominio.com/book/`).

## Características

- Efecto realista de pasado de página (StPageFlip, MIT, vendorizado en `src/vendor/`).
- Biblioteca con varias publicaciones (portada con rejilla de portadas).
- Panel de administración con login: sube un PDF y se convierte + publica solo.
- Visor con zoom (rueda, pellizco, doble clic), miniaturas, pantalla completa,
  descarga del PDF original, navegación con teclado, táctil y responsive.
- Enlaces profundos: `ver.php?libro=slug&p=7` abre el libro en la página 7.
- Plan B sin navegador: `tools/render_pdf.py` pre-renderiza localmente con poppler.

## Desarrollo local

Requisitos: Node ≥ 18, PHP ≥ 7.4.

```bash
cp public/config.example.php public/config.php   # y personaliza la contraseña
npm install
npm run build        # compila TypeScript → public/assets/
npm run serve        # php -S localhost:8080 -t public
# o durante el desarrollo:
npm run dev          # vite build --watch (en otra terminal, npm run serve)
```

Abre <http://localhost:8080> (portada) y <http://localhost:8080/admin/> (panel).

### Prueba end-to-end automatizada

Con el servidor local corriendo (y Chrome instalado):

```bash
node tools/test_admin_upload.mjs mi.pdf   # login → convierte con PDF.js → publica
```

## Despliegue en cPanel

1. Crea `public/config.php` a partir de `public/config.example.php` y **cambia la
   contraseña** (`ADMIN_PASSWORD`, o mejor, genera un hash y usa `ADMIN_PASSWORD_HASH`).
2. `npm run build`.
3. Sube **el contenido de `public/`** a `public_html/` (o a una subcarpeta) por
   FTP o el administrador de archivos de cPanel.
4. Asegúrate de que `public_html/books/` tenga permisos de escritura para PHP
   (normalmente 755 basta en cPanel porque PHP corre como tu usuario).
5. Entra a `https://tudominio.com/admin/`, inicia sesión y sube tu primer PDF.

No se necesita Node, Composer ni extensiones especiales en el servidor.

### Límites de subida de PHP

El PDF original se sube en fragmentos de 1.5 MB y cada página como imagen individual,
así que funciona incluso con el límite por defecto más estricto
(`upload_max_filesize = 2M`), sin tocar la configuración del hosting.
Si tu PDF pesa más de `MAX_PDF_SIZE` (200 MB), ajústalo en `config.php`.

## Plan B: pre-render local con Python

Para PDFs muy pesados (o si prefieres no convertir en el navegador):

```bash
pip install pdf2image pillow
sudo apt install poppler-utils        # Debian/Ubuntu

python3 tools/render_pdf.py revista.pdf --title "Mi revista"
# → genera out/mi-revista/ con book.json, pages/, thumbs/ y original.pdf
```

Sube la carpeta resultante a `public_html/books/mi-revista/` y aparecerá en la
portada automáticamente.

## Estructura

```
src/viewer/          Visor (TypeScript): flipbook, zoom, miniaturas…
src/admin/           Panel (TypeScript): PDF.js + subida AJAX
src/vendor/page-flip StPageFlip vendorizado (MIT)
public/              Lo que se sube al hosting
  index.php          Portada (biblioteca)
  ver.php            Visor: /ver.php?libro=<slug>
  admin/             Login + APIs (upload.php, books.php)
  books/<slug>/      Datos de cada publicación
  assets/            JS/CSS compilados (npm run build)
tools/render_pdf.py  Pre-render local (plan B)
```

## Formato de una publicación (`books/<slug>/book.json`)

```json
{
  "title": "Mi revista",
  "slug": "mi-revista",
  "pages": 48,
  "width": 1131,
  "height": 1600,
  "format": "webp",
  "created": "2026-07-01T12:00:00+00:00",
  "hasPdf": true
}
```

## Licencias

- Código propio: MIT.
- [StPageFlip](https://github.com/Nodlik/StPageFlip) © Nodlik — MIT (vendorizado en `src/vendor/page-flip`).
- [PDF.js](https://mozilla.github.io/pdf.js/) © Mozilla — Apache 2.0.
