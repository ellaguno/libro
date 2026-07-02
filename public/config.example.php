<?php
/**
 * Configuración de la aplicación.
 * IMPORTANTE: cambia ADMIN_PASSWORD antes de subir a producción.
 */

// Contraseña del panel de administración.
// Opción simple: texto plano aquí (el archivo nunca se sirve como texto, PHP lo ejecuta).
const ADMIN_PASSWORD = 'cambiame';

// Opción más segura: genera un hash con:
//   php -r "echo password_hash('tu-contraseña', PASSWORD_DEFAULT), PHP_EOL;"
// y pégalo aquí. Si no está vacío, tiene prioridad sobre ADMIN_PASSWORD.
const ADMIN_PASSWORD_HASH = '';

// ---- Marca / personalización ----

// Título del sitio: aparece en la portada de la biblioteca y en la pestaña.
// SITE_ICON es el emoji junto al título y el favicon de la pestaña;
// vacío = sin icono junto al título (la pestaña usa 📖).
const SITE_ICON = '📚';

const SITE_TITLE = 'Biblioteca';

// Logo mostrado arriba a la derecha (en la biblioteca y en el visor).
// Ruta relativa a public/ (p. ej. 'logo.png') o URL completa. Vacío = sin logo.
const SITE_LOGO = '';

// Liga al hacer clic en el logo (p. ej. 'https://tusitio.com'). Vacío = sin liga.
const SITE_LOGO_URL = '';

// Fondo de la biblioteca y del visor. Acepta cualquier valor CSS de background:
//   un color:      '#f4efe6'
//   un degradado:  'linear-gradient(160deg, #2b3040, #16181f)'
//   una imagen:    "url('fondo.jpg') center / cover fixed #16181f"
// Vacío = el degradado azul oscuro por defecto.
const SITE_BACKGROUND = '';

// Color del texto sobre ese fondo (p. ej. '#222' para fondos claros).
// Vacío = gris claro por defecto (pensado para fondos oscuros).
const SITE_TEXT_COLOR = '';

// Carpeta donde se guardan las publicaciones (relativa a public/).
const BOOKS_DIR = __DIR__ . '/books';

// Tamaño máximo del PDF original (en bytes). 200 MB por defecto.
const MAX_PDF_SIZE = 200 * 1024 * 1024;

// Tamaño máximo por imagen de página (en bytes). 4 MB por defecto.
const MAX_PAGE_SIZE = 4 * 1024 * 1024;

// Máximo de páginas por publicación.
const MAX_PAGES = 1000;

// Intentos de login fallidos permitidos antes de bloquear temporalmente.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 300;
