/**
 * Depuración: congela un giro de pasta dura a la mitad y captura el estado.
 * Uso: node tools/debug_hardflip.mjs [urlLibro] [captura.png]
 */
import puppeteer from 'puppeteer-core';

const URL = process.argv[2] ?? 'http://localhost:8080/ver.php?libro=revista-demo-py';
const SHOT = process.argv[3] ?? '/tmp/hardflip.png';

const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: 'new',
    args: ['--no-sandbox', '--window-size=1280,800'],
});

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });
    await page.goto(URL, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 800));

    // Geometría del libro
    const rect = await page.$eval('.stf__block', (el) => {
        const r = el.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    });

    // Arrastrar desde el borde derecho hacia el centro-izquierda y NO soltar
    const startX = rect.x + rect.w - 8;
    const midY = rect.y + rect.h / 2;
    await page.mouse.move(startX, midY);
    await page.mouse.down();
    const endX = rect.x + rect.w * (Number(process.env.STOP_AT) || 0.32);
    for (let i = 1; i <= 12; i++) {
        await page.mouse.move(startX + ((endX - startX) * i) / 12, midY, { steps: 2 });
        await new Promise((r) => setTimeout(r, 30));
    }
    await new Promise((r) => setTimeout(r, 250));

    // Volcar estado de todas las páginas visibles del flipbook
    const state = await page.evaluate(() => {
        return [...document.querySelectorAll('.stf__item')].map((el) => {
            const cs = getComputedStyle(el);
            return {
                classes: el.className,
                alt: el.querySelector('img')?.alt ?? '',
                display: cs.display,
                zIndex: cs.zIndex,
                transform: cs.transform,
                backface: cs.backfaceVisibility,
                transformStyle: cs.transformStyle,
                overflow: cs.overflow,
                visibility: cs.visibility,
            };
        }).filter((s) => s.display !== 'none');
    });
    console.log(JSON.stringify(state, null, 2));

    await page.screenshot({ path: SHOT });
    console.log('captura:', SHOT);
    await page.mouse.up();
} finally {
    await browser.close();
}
