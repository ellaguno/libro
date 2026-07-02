/**
 * Panel de administración: rasteriza el PDF en el navegador con PDF.js
 * y sube páginas + miniaturas + PDF original al servidor.
 */

import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import './admin.css';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

const THUMB_HEIGHT = 240;  // miniaturas de la tira del visor
const COVER_HEIGHT = 640;  // portada nítida para la rejilla de la biblioteca
// 1.5 MB: por debajo del upload_max_filesize por defecto de PHP (2M),
// para funcionar en cualquier hosting sin tocar la configuración.
const PDF_CHUNK_SIZE = 1.5 * 1024 * 1024;
// Peso máximo de una imagen de página subida, por la misma razón.
const IMG_MAX_UPLOAD = 1.8 * 1024 * 1024;

const main = document.querySelector<HTMLElement>('.admin-main');
const csrf = main?.dataset.csrf ?? '';
// Límite del servidor para conservar el PDF original (MAX_PDF_SIZE).
const maxPdfSize = Number(main?.dataset.maxPdf ?? 0) || Infinity;

// ---------------------------------------------------------------- API

async function api(url: string, data: FormData): Promise<any> {
    data.append('csrf', csrf);
    const res = await fetch(url, { method: 'POST', body: data, headers: { 'X-CSRF-Token': csrf } });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(json.error ?? `Error HTTP ${res.status}`);
    }
    return json;
}

function form(fields: Record<string, string | Blob>): FormData {
    const fd = new FormData();
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    return fd;
}

// ------------------------------------------------------- Conversión

/** ¿El navegador puede exportar canvas a WebP? (Safari no). */
function supportsWebp(): boolean {
    const c = document.createElement('canvas');
    c.width = c.height = 2;
    return c.toDataURL('image/webp').startsWith('data:image/webp');
}

function canvasToBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('No se pudo exportar la imagen'))),
            type,
            quality,
        );
    });
}

/**
 * Exporta el canvas garantizando un peso máximo: si el blob excede el límite
 * de subida baja la calidad por escalones y, en último caso, las dimensiones.
 * (Páginas escaneadas con mucho grano pueden exceder upload_max_filesize.)
 */
async function canvasToBoundedBlob(
    canvas: HTMLCanvasElement,
    mime: string,
    quality: number,
): Promise<Blob> {
    let blob = await canvasToBlob(canvas, mime, quality);
    for (const q of [0.7, 0.55, 0.4]) {
        if (blob.size <= IMG_MAX_UPLOAD) return blob;
        blob = await canvasToBlob(canvas, mime, q);
    }
    let current = canvas;
    while (blob.size > IMG_MAX_UPLOAD && current.width > 400) {
        const smaller = document.createElement('canvas');
        smaller.width = Math.round(current.width * 0.85);
        smaller.height = Math.round(current.height * 0.85);
        smaller.getContext('2d')!.drawImage(current, 0, 0, smaller.width, smaller.height);
        current = smaller;
        blob = await canvasToBlob(current, mime, 0.4);
    }
    return blob;
}

interface Progress {
    (done: number, total: number, text: string): void;
}

/** Convierte y sube todas las páginas del PDF; devuelve dimensiones de la página 1. */
async function convertAndUpload(
    doc: pdfjs.PDFDocumentProxy,
    slug: string,
    format: 'webp' | 'jpeg',
    targetHeight: number,
    onProgress: Progress,
): Promise<void> {
    const mime = format === 'webp' ? 'image/webp' : 'image/jpeg';
    const canvas = document.createElement('canvas');
    const thumbCanvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d')!;
    const thumbCtx = thumbCanvas.getContext('2d')!;

    for (let n = 1; n <= doc.numPages; n++) {
        const page = await doc.getPage(n);
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: targetHeight / base.height });

        canvas.width = Math.round(viewport.width);
        canvas.height = Math.round(viewport.height);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport }).promise;

        const thumbScale = THUMB_HEIGHT / canvas.height;
        thumbCanvas.width = Math.round(canvas.width * thumbScale);
        thumbCanvas.height = THUMB_HEIGHT;
        thumbCtx.drawImage(canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);

        const [pageBlob, thumbBlob] = await Promise.all([
            canvasToBoundedBlob(canvas, mime, 0.85),
            canvasToBlob(thumbCanvas, mime, 0.8),
        ]);

        const fields: Record<string, string | Blob> = {
            action: 'page',
            slug,
            index: String(n),
            page: pageBlob,
            thumb: thumbBlob,
        };

        // La página 1 genera además la portada para la rejilla de la biblioteca.
        if (n === 1) {
            const coverCanvas = document.createElement('canvas');
            const coverScale = COVER_HEIGHT / canvas.height;
            coverCanvas.width = Math.round(canvas.width * coverScale);
            coverCanvas.height = COVER_HEIGHT;
            coverCanvas.getContext('2d')!.drawImage(canvas, 0, 0, coverCanvas.width, coverCanvas.height);
            fields.cover = await canvasToBlob(coverCanvas, mime, 0.85);
        }

        await api('upload.php', form(fields));

        page.cleanup();
        onProgress(n, doc.numPages, `Página ${n} de ${doc.numPages}`);
    }
}

/** Sube el PDF original en fragmentos. */
async function uploadPdf(file: File, slug: string, onProgress: Progress): Promise<void> {
    const totalChunks = Math.ceil(file.size / PDF_CHUNK_SIZE);
    for (let i = 0; i < totalChunks; i++) {
        const chunk = file.slice(i * PDF_CHUNK_SIZE, (i + 1) * PDF_CHUNK_SIZE);
        await api('upload.php', form({
            action: 'pdf',
            slug,
            chunkIndex: String(i),
            totalChunks: String(totalChunks),
            chunk,
        }));
        onProgress(i + 1, totalChunks, `Subiendo PDF original (${i + 1}/${totalChunks})`);
    }
}

// ------------------------------------------------------------ UI

const uploadForm = document.getElementById('upload-form') as HTMLFormElement | null;
const progressBox = document.getElementById('progress') as HTMLElement | null;
const progressFill = document.getElementById('progress-fill') as HTMLElement | null;
const progressText = document.getElementById('progress-text') as HTMLElement | null;

function setProgress(fraction: number, text: string): void {
    if (!progressBox || !progressFill || !progressText) return;
    progressBox.hidden = false;
    progressFill.style.width = `${Math.round(fraction * 100)}%`;
    progressText.textContent = text;
    progressText.classList.toggle('is-error', text.startsWith('Error'));
}

uploadForm?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const titleInput = document.getElementById('title') as HTMLInputElement;
    const fileInput = document.getElementById('pdf-file') as HTMLInputElement;
    const qualityInput = document.getElementById('quality') as HTMLSelectElement;
    const button = document.getElementById('upload-btn') as HTMLButtonElement;

    const file = fileInput.files?.[0];
    const title = titleInput.value.trim();
    if (!file || !title) return;

    // Avisar ANTES de convertir si el original no cabrá en el servidor:
    // se puede publicar igual, solo que sin el botón de descarga del PDF.
    const mb = (n: number): number => Math.round(n / 1024 / 1024);
    const includePdf = file.size <= maxPdfSize;
    if (!includePdf && !confirm(
        `El PDF pesa ${mb(file.size)} MB y supera el límite de ${mb(maxPdfSize)} MB ` +
        `para conservar el original en el servidor (MAX_PDF_SIZE en config.php).\n\n` +
        `¿Publicar de todos modos SIN el botón de descarga del PDF?`,
    )) {
        return;
    }

    button.disabled = true;
    let slug = '';
    try {
        setProgress(0, 'Leyendo PDF…');
        const data = await file.arrayBuffer();
        const doc = await pdfjs.getDocument({ data }).promise;

        const format = supportsWebp() ? 'webp' : 'jpeg';
        const targetHeight = Number(qualityInput.value) || 1600;

        // Dimensiones de la primera página (para el visor).
        const first = await doc.getPage(1);
        const base = first.getViewport({ scale: 1 });
        const scale = targetHeight / base.height;
        const width = Math.round(base.width * scale);
        const height = Math.round(base.height * scale);
        first.cleanup();

        const hardCover = (document.getElementById('hard-cover') as HTMLInputElement | null)?.checked ?? false;

        const init = await api('upload.php', form({
            action: 'init',
            title,
            pages: String(doc.numPages),
            width: String(width),
            height: String(height),
            format,
            hardCover: hardCover ? '1' : '0',
        }));
        slug = init.slug;

        // Conversión + subida de páginas: 85 % del progreso; PDF: 15 %.
        await convertAndUpload(doc, slug, format, targetHeight, (done, total, text) =>
            setProgress((done / total) * 0.85, text));

        // Un fallo aquí no debe tirar las páginas ya subidas: se publica
        // sin el original y se avisa.
        let pdfNote = includePdf ? '' : ' (sin el PDF original: supera el límite)';
        if (includePdf) {
            try {
                await uploadPdf(file, slug, (done, total, text) =>
                    setProgress(0.85 + (done / total) * 0.15, text));
            } catch (err) {
                const msg = err instanceof Error ? err.message : String(err);
                pdfNote = ` (sin el PDF original: ${msg})`;
            }
        }

        const fin = await api('upload.php', form({ action: 'finish', slug }));
        setProgress(1, `¡Publicado! ✓${pdfNote}`);
        void loadBooks();
        uploadForm.reset();
        window.open(fin.url, '_blank');
    } catch (err) {
        if (slug) {
            await api('upload.php', form({ action: 'abort', slug })).catch(() => undefined);
        }
        setProgress(0, `Error: ${err instanceof Error ? err.message : err}`);
    } finally {
        button.disabled = false;
    }
});

// -------------------------------------------------- Lista de libros

interface Book {
    slug: string;
    title: string;
    pages: number;
    created?: string;
    format?: string;
}

async function loadBooks(): Promise<void> {
    const list = document.getElementById('book-list');
    if (!list) return;
    try {
        const res = await fetch('books.php?action=list');
        const { books } = (await res.json()) as { books: Book[] };
        list.innerHTML = '';
        if (!books.length) {
            list.innerHTML = '<p>No hay publicaciones todavía.</p>';
            return;
        }
        for (const book of books) {
            list.appendChild(bookRow(book));
        }
    } catch {
        list.innerHTML = '<p>Error al cargar la lista.</p>';
    }
}

function bookRow(book: Book): HTMLElement {
    const ext = book.format === 'jpeg' ? 'jpg' : 'webp';
    const row = document.createElement('div');
    row.className = 'book-row';
    row.innerHTML = `
      <img src="../books/${book.slug}/thumbs/thumb-001.${ext}" alt="" loading="lazy">
      <div class="book-info">
        <strong></strong>
        <span>${book.pages} páginas · <code>${book.slug}</code></span>
      </div>
      <div class="book-actions">
        <a href="../ver.php?libro=${encodeURIComponent(book.slug)}" target="_blank">Ver</a>
        <button data-act="rename">Renombrar</button>
        <button data-act="delete" class="danger">Eliminar</button>
      </div>
    `;
    (row.querySelector('strong') as HTMLElement).textContent = book.title;

    row.querySelector('[data-act="rename"]')!.addEventListener('click', async () => {
        const title = prompt('Nuevo título:', book.title);
        if (!title || title === book.title) return;
        try {
            await api('books.php?action=rename', form({ slug: book.slug, title }));
            void loadBooks();
        } catch (err) {
            alert(err instanceof Error ? err.message : String(err));
        }
    });

    row.querySelector('[data-act="delete"]')!.addEventListener('click', async () => {
        if (!confirm(`¿Eliminar "${book.title}" definitivamente?`)) return;
        try {
            await api('books.php?action=delete', form({ slug: book.slug }));
            void loadBooks();
        } catch (err) {
            alert(err instanceof Error ? err.message : String(err));
        }
    });

    return row;
}

void loadBooks();
