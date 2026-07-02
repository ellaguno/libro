/**
 * Prueba end-to-end del panel: login → subir PDF → conversión con PDF.js
 * en el navegador → publicación. Usa el Chrome del sistema en headless.
 *
 * Uso:
 *   node tools/test_admin_upload.mjs <ruta.pdf> [contraseña] [urlBase]
 *
 * Requiere: el servidor local corriendo (npm run serve) y Google Chrome.
 * Nota: crea una publicación real ("Revista Subida desde Navegador");
 * elimínala desde el panel después de la prueba.
 */
import puppeteer from 'puppeteer-core';

const PDF = process.argv[2];
const PASSWORD = process.argv[3] ?? 'cambiame';
const BASE = process.argv[4] ?? 'http://localhost:8080';

if (!PDF) {
    console.error('Uso: node tools/test_admin_upload.mjs <ruta.pdf> [contraseña] [urlBase]');
    process.exit(2);
}

const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: 'new',
    args: ['--no-sandbox', '--window-size=1280,900'],
});

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    page.on('console', (msg) => {
        if (msg.type() === 'error') console.log('CONSOLE ERROR:', msg.text());
    });
    page.on('pageerror', (err) => console.log('PAGE ERROR:', err.message));

    // 1. Login
    await page.goto(`${BASE}/admin/`, { waitUntil: 'networkidle0' });
    await page.type('#password', PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
    console.log('✓ Login correcto');

    // 2. Rellenar formulario y subir PDF
    await page.waitForSelector('#upload-form');
    await page.type('#title', 'Revista Subida desde Navegador');
    const input = await page.$('#pdf-file');
    await input.uploadFile(PDF);
    await page.click('#upload-btn');
    console.log('… convirtiendo y subiendo');

    // 3. Esperar a que el progreso llegue a "Publicado" (o error)
    await page.waitForFunction(
        () => {
            const t = document.getElementById('progress-text')?.textContent ?? '';
            return t.includes('Publicado') || t.startsWith('Error');
        },
        // polling por intervalo: con 'raf' (por defecto) el sondeo se congela
        // cuando window.open() roba el foco de la pestaña.
        { timeout: 120_000, polling: 1000 },
    );
    const status = await page.$eval('#progress-text', (el) => el.textContent);
    console.log('Resultado:', status);

    if (!status.includes('Publicado')) process.exitCode = 1;
} finally {
    await browser.close();
}
