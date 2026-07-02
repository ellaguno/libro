/**
 * Visor de publicaciones: flipbook con StPageFlip + controles
 * (navegación, zoom, miniaturas, pantalla completa, descarga).
 */

import { PageFlip } from '../vendor/page-flip';
import { ZoomOverlay } from './zoom';
import './viewer.css';

interface BookConfig {
    slug: string;
    title: string;
    pages: number;
    width: number;
    height: number;
    ext: string;
    hasPdf: boolean;
    hardCover: boolean;
}

/** Páginas por delante/detrás de la actual cuyas imágenes se precargan. */
const PRELOAD_AHEAD = 6;
const PRELOAD_BEHIND = 3;

function readConfig(el: HTMLElement): BookConfig {
    const d = el.dataset;
    return {
        slug: d.slug ?? '',
        title: d.title ?? '',
        pages: Number(d.pages ?? 0),
        width: Number(d.width ?? 0),
        height: Number(d.height ?? 0),
        ext: d.ext ?? 'webp',
        hasPdf: d.hasPdf === '1',
        hardCover: d.hardCover === '1',
    };
}

class BookViewer {
    private readonly root: HTMLElement;
    private readonly cfg: BookConfig;
    private pageFlip!: PageFlip;
    private zoom!: ZoomOverlay;
    private pageLabel!: HTMLElement;
    private thumbsDrawer!: HTMLElement;
    private thumbButtons: HTMLButtonElement[] = [];
    private gutter: HTMLElement | null = null;

    constructor(root: HTMLElement, cfg: BookConfig) {
        this.root = root;
        this.cfg = cfg;
        this.buildDom();
        this.initPageFlip();
        this.bindKeyboard();
    }

    private pageUrl(n: number): string {
        return `books/${this.cfg.slug}/pages/page-${String(n).padStart(3, '0')}.${this.cfg.ext}`;
    }

    private thumbUrl(n: number): string {
        return `books/${this.cfg.slug}/thumbs/thumb-${String(n).padStart(3, '0')}.${this.cfg.ext}`;
    }

    // ------------------------------------------------------------- DOM

    private buildDom(): void {
        this.root.innerHTML = `
          <header class="vw-toolbar">
            <a class="vw-back" href="./" title="Volver a la biblioteca">‹</a>
            <h1 class="vw-title"></h1>
            <div class="vw-actions">
              <button class="vw-btn" data-action="zoom" title="Zoom (doble clic en la página)">🔍</button>
              <button class="vw-btn" data-action="thumbs" title="Miniaturas">▦</button>
              <button class="vw-btn" data-action="fullscreen" title="Pantalla completa">⛶</button>
              ${this.cfg.hasPdf
                ? `<a class="vw-btn" href="books/${this.cfg.slug}/original.pdf" download title="Descargar PDF">⬇</a>`
                : ''}
            </div>
          </header>
          <main class="vw-stage">
            <button class="vw-nav vw-nav-prev" title="Página anterior">‹</button>
            <div class="vw-book-wrap"><div class="vw-book"></div></div>
            <button class="vw-nav vw-nav-next" title="Página siguiente">›</button>
          </main>
          <footer class="vw-footer">
            <span class="vw-pagenum"></span>
          </footer>
          <div class="vw-thumbs" hidden></div>
        `;

        (this.root.querySelector('.vw-title') as HTMLElement).textContent = this.cfg.title;
        this.pageLabel = this.root.querySelector('.vw-pagenum') as HTMLElement;
        this.thumbsDrawer = this.root.querySelector('.vw-thumbs') as HTMLElement;

        this.root.querySelector('.vw-nav-prev')!.addEventListener('click', () => this.pageFlip.flipPrev());
        this.root.querySelector('.vw-nav-next')!.addEventListener('click', () => this.pageFlip.flipNext());
        this.root.querySelector('[data-action="zoom"]')!.addEventListener('click', () => this.openZoom());
        this.root.querySelector('[data-action="thumbs"]')!.addEventListener('click', () => this.toggleThumbs());
        this.root.querySelector('[data-action="fullscreen"]')!.addEventListener('click', () => this.toggleFullscreen());

        this.zoom = new ZoomOverlay(this.root);
        this.buildThumbs();
    }

    /** Crea los divs de página con carga diferida (data-src → src). */
    private buildPages(): HTMLElement[] {
        const pages: HTMLElement[] = [];
        for (let n = 1; n <= this.cfg.pages; n++) {
            const div = document.createElement('div');
            div.className = 'vw-page';
            // Pasta dura en portada/contraportada solo si el libro lo pide;
            // por defecto todas las páginas son flexibles.
            div.dataset.density =
                this.cfg.hardCover && (n === 1 || n === this.cfg.pages) ? 'hard' : 'soft';
            const img = document.createElement('img');
            img.dataset.src = this.pageUrl(n);
            img.alt = `Página ${n}`;
            img.draggable = false;
            div.appendChild(img);
            pages.push(div);
        }
        return pages;
    }

    private buildThumbs(): void {
        for (let n = 1; n <= this.cfg.pages; n++) {
            const btn = document.createElement('button');
            btn.className = 'vw-thumb';
            btn.innerHTML = `<img src="${this.thumbUrl(n)}" alt="Página ${n}" loading="lazy"><span>${n}</span>`;
            btn.addEventListener('click', () => {
                this.pageFlip.flip(n - 1);
                this.toggleThumbs(false);
            });
            this.thumbButtons.push(btn);
            this.thumbsDrawer.appendChild(btn);
        }
    }

    // ------------------------------------------------------- PageFlip

    private initPageFlip(): void {
        const bookEl = this.root.querySelector('.vw-book') as HTMLElement;
        // Enlace profundo: ?p=N abre el libro en esa página.
        const startParam = Number(new URLSearchParams(location.search).get('p') ?? 1);
        const startPage = Math.min(this.cfg.pages, Math.max(1, startParam || 1)) - 1;
        this.pageFlip = new PageFlip(bookEl, {
            startPage,
            width: this.cfg.width,
            height: this.cfg.height,
            size: 'stretch',
            // Bajo 2×minWidth de ancho disponible se pasa a una sola página (móvil).
            minWidth: 320,
            maxWidth: 1200,
            minHeight: 280,
            maxHeight: 1600,
            showCover: true,
            usePortrait: true,
            maxShadowOpacity: 0.4,
            flippingTime: 800,
            mobileScrollSupport: false,
        });

        this.pageFlip.loadFromHTML(this.buildPages());

        this.pageFlip.on('flip', () => this.onPageChange());
        this.onPageChange();

        // Doble clic sobre el libro abre el zoom.
        bookEl.addEventListener('dblclick', () => this.openZoom());

        this.initGutter();
    }

    // -------------------------------------------- Sombra del canal central

    /** Crea la sombra sutil que emerge de la unión entre las dos páginas. */
    private initGutter(): void {
        const block = this.root.querySelector<HTMLElement>('.stf__block');
        if (!block) return;
        this.gutter = document.createElement('div');
        this.gutter.className = 'vw-gutter';
        block.appendChild(this.gutter);

        const update = (): void => this.updateGutter();
        this.pageFlip.on('changeOrientation', update);
        this.pageFlip.on('flip', update);
        window.addEventListener('resize', () => requestAnimationFrame(update));
        // Tras el primer render las páginas ya tienen posición.
        requestAnimationFrame(update);
    }

    private updateGutter(): void {
        const block = this.root.querySelector<HTMLElement>('.stf__block');
        if (!block || !this.gutter) return;

        // En modo una-página no hay unión central.
        const page = block.querySelector<HTMLElement>('.vw-page.--simple');
        if (!page || this.pageFlip.getOrientation() === 'portrait') {
            this.gutter.style.display = 'none';
            return;
        }

        // El lomo es el borde interior de cualquier página estática.
        const r = page.getBoundingClientRect();
        const b = block.getBoundingClientRect();
        const x = page.classList.contains('--left') ? r.right - b.left : r.left - b.left;
        this.gutter.style.cssText =
            `display: block; left: ${x}px; top: ${r.top - b.top}px; height: ${r.height}px;`;
    }

    /** Índices (base 1) de las páginas visibles en este momento. */
    private visiblePages(): number[] {
        const idx = this.pageFlip.getCurrentPageIndex(); // base 0
        const total = this.cfg.pages;
        if (this.pageFlip.getOrientation() === 'portrait' || idx === 0) {
            return [idx + 1];
        }
        const left = idx % 2 === 1 ? idx : idx - 1;
        const pages = [left + 1];
        if (left + 2 <= total) pages.push(left + 2);
        return pages;
    }

    private onPageChange(): void {
        const visible = this.visiblePages();
        this.pageLabel.textContent = `${visible.join('–')} / ${this.cfg.pages}`;
        this.preload(visible[0]);
        this.highlightThumb(visible[0]);
    }

    /** Materializa el src de las imágenes cercanas a la página actual. */
    private preload(current: number): void {
        const from = Math.max(1, current - PRELOAD_BEHIND);
        const to = Math.min(this.cfg.pages, current + PRELOAD_AHEAD);
        const imgs = this.root.querySelectorAll<HTMLImageElement>('.vw-page img');
        for (let n = from; n <= to; n++) {
            const img = imgs[n - 1];
            if (img && !img.src && img.dataset.src) {
                img.src = img.dataset.src;
            }
        }
    }

    private highlightThumb(current: number): void {
        this.thumbButtons.forEach((b, i) => b.classList.toggle('is-current', i === current - 1));
    }

    // ------------------------------------------------------ Controles

    private openZoom(): void {
        this.zoom.open(this.visiblePages().map((n) => this.pageUrl(n)));
    }

    private toggleThumbs(force?: boolean): void {
        const show = force ?? this.thumbsDrawer.hidden;
        this.thumbsDrawer.hidden = !show;
        if (show) {
            const current = this.thumbButtons.find((b) => b.classList.contains('is-current'));
            current?.scrollIntoView({ inline: 'center', block: 'nearest' });
        }
    }

    private toggleFullscreen(): void {
        if (document.fullscreenElement) {
            void document.exitFullscreen();
        } else {
            void this.root.requestFullscreen();
        }
    }

    private bindKeyboard(): void {
        document.addEventListener('keydown', (ev) => {
            if (this.zoom.isOpen) return; // el overlay gestiona sus propias teclas
            switch (ev.key) {
                case 'ArrowLeft':
                    this.pageFlip.flipPrev();
                    break;
                case 'ArrowRight':
                    this.pageFlip.flipNext();
                    break;
                case 'Escape':
                    this.toggleThumbs(false);
                    break;
                case 'f':
                case 'F':
                    this.toggleFullscreen();
                    break;
            }
        });
    }
}

const rootEl = document.getElementById('viewer');
if (rootEl) {
    const cfg = readConfig(rootEl);
    if (cfg.slug && cfg.pages > 0) {
        new BookViewer(rootEl, cfg);
    }
}
