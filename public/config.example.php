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
