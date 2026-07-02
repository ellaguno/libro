/**
 * Visor de publicaciones: flipbook con StPageFlip + controles
 * (navegación, zoom, miniaturas, pantalla completa, descarga).
 */

import { PageFlip, FlippingState } from '../vendor/page-flip';
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
    logo: string;
    logoUrl: string;
    siteTitle: string;
    /** URL base de los archivos del libro (pages/, thumbs/, original.pdf). */
    base: string;
    /** Embebido (shortcode/iframe): oculta la flecha de volver a la biblioteca. */
    embed: boolean;
    /** Página dedicada (ver.php): lee/escribe ?p= en la URL y usa teclado/rueda. */
    deepLink: boolean;
    /** Página inicial explícita (shortcode); 0 = portada o última leída. */
    start: number;
}

/** Páginas por delante/detrás de la actual cuyas imágenes se precargan. */
const PRELOAD_AHEAD = 6;
const PRELOAD_BEHIND = 3;

/** Grosor máximo (px) del canto: la pila de hojas a los lados del libro. */
const EDGE_MAX_WIDTH = 14;

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
        logo: d.logo ?? '',
        logoUrl: d.logoUrl ?? '',
        siteTitle: d.siteTitle ?? '',
        base: d.base ?? `books/${d.slug ?? ''}/`,
        embed: d.embed === '1',
        deepLink: d.deepLink === '1',
        start: Number(d.start ?? 0),
    };
}

class BookViewer {
    private readonly root: HTMLElement;
    private readonly cfg: BookConfig;
    private pageFlip!: PageFlip;
    private zoom!: ZoomOverlay;
    private pageLabel!: HTMLElement;
    private progressFill!: HTMLElement;
    private thumbsDrawer!: HTMLElement;
    private thumbButtons: HTMLButtonElement[] = [];
    private gutter: HTMLElement | null = null;
    private edgeLeft: HTMLElement | null = null;
    private edgeRight: HTMLElement | null = null;

    constructor(root: HTMLElement, cfg: BookConfig) {
        this.root = root;
        this.cfg = cfg;
        this.buildDom();
        this.initPageFlip();
        // El teclado es global: solo lo captura la página dedicada del visor,
        // nunca un libro embebido dentro de un artículo.
        if (cfg.deepLink) {
            this.bindKeyboard();
        }
    }

    private pageUrl(n: number): string {
        return `${this.cfg.base}pages/page-${String(n).padStart(3, '0')}.${this.cfg.ext}`;
    }

    private thumbUrl(n: number): string {
        return `${this.cfg.base}thumbs/thumb-${String(n).padStart(3, '0')}.${this.cfg.ext}`;
    }

    // ------------------------------------------------------------- DOM

    private buildDom(): void {
        this.root.innerHTML = `
          <header class="vw-toolbar">
            ${this.cfg.embed ? '' : '<a class="vw-back" href="./" title="Volver a la biblioteca">‹</a>'}
            <h1 class="vw-title"></h1>
            <div class="vw-actions">
              <button class="vw-btn" data-action="zoom" title="Zoom (doble clic en la página)">🔍</button>
              <button class="vw-btn" data-action="thumbs" title="Miniaturas">▦</button>
              <button class="vw-btn" data-action="fullscreen" title="Pantalla completa">⛶</button>
              ${this.cfg.hasPdf
                ? `<a class="vw-btn" href="${this.cfg.base}original.pdf" download title="Descargar PDF">⬇</a>`
                : ''}
            </div>
          </header>
          <main class="vw-stage">
            <button class="vw-nav vw-nav-prev" title="Página anterior">‹</button>
            <div class="vw-book-wrap"><div class="vw-book"></div></div>
            <button class="vw-nav vw-nav-next" title="Página siguiente">›</button>
          </main>
          <footer class="vw-footer">
            <div class="vw-progress"><div class="vw-progress-fill"></div></div>
            <button class="vw-pagenum" title="Ir a una página…"></button>
          </footer>
          <div class="vw-thumbs" hidden></div>
        `;

        (this.root.querySelector('.vw-title') as HTMLElement).textContent = this.cfg.title;
        this.buildLogo();
        this.pageLabel = this.root.querySelector('.vw-pagenum') as HTMLElement;
        this.progressFill = this.root.querySelector('.vw-progress-fill') as HTMLElement;
        this.thumbsDrawer = this.root.querySelector('.vw-thumbs') as HTMLElement;

        this.pageLabel.addEventListener('click', () => this.showPageInput());

        this.root.querySelector('.vw-nav-prev')!.addEventListener('click', () => this.pageFlip.flipPrev());
        this.root.querySelector('.vw-nav-next')!.addEventListener('click', () => this.pageFlip.flipNext());
        this.root.querySelector('[data-action="zoom"]')!.addEventListener('click', () => this.openZoom());
        this.root.querySelector('[data-action="thumbs"]')!.addEventListener('click', () => this.toggleThumbs());
        this.root.querySelector('[data-action="fullscreen"]')!.addEventListener('click', () => this.toggleFullscreen());

        this.zoom = new ZoomOverlay(this.root);
        this.buildThumbs();
    }

    /** Logo del sitio (config.php) al final de la barra, con liga opcional. */
    private buildLogo(): void {
        if (!this.cfg.logo) return;
        const img = document.createElement('img');
        img.src = this.cfg.logo;
        img.alt = this.cfg.siteTitle;
        let holder: HTMLElement;
        if (this.cfg.logoUrl) {
            const a = document.createElement('a');
            a.href = this.cfg.logoUrl;
            a.target = '_blank';
            a.rel = 'noopener';
            holder = a;
        } else {
            holder = document.createElement('span');
        }
        holder.className = 'vw-logo';
        holder.appendChild(img);
        this.root.querySelector('.vw-toolbar')!.appendChild(holder);
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
        // Página inicial: ?p=N solo en la página dedicada (embebido en un
        // artículo, ?p pertenece a la página anfitriona); luego el atributo
        // del shortcode; al final, la última página leída (localStorage).
        let startParam = this.cfg.deepLink
            ? Number(new URLSearchParams(location.search).get('p') ?? 0)
            : 0;
        if (!startParam) {
            startParam = this.cfg.start;
        }
        if (!startParam) {
            startParam = this.savedPage();
        }
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

        // La rueda hojea solo en la página dedicada; dentro de un artículo
        // debe seguir haciendo scroll normal.
        if (this.cfg.deepLink) {
            this.bindWheel();
        }
        this.initGutter();
    }

    /** Rueda del ratón sobre el escenario: hojear (con freno anti-ráfaga). */
    private bindWheel(): void {
        const stage = this.root.querySelector('.vw-stage') as HTMLElement;
        let lastFlip = 0;
        stage.addEventListener(
            'wheel',
            (ev) => {
                ev.preventDefault();
                const delta = Math.abs(ev.deltaY) >= Math.abs(ev.deltaX) ? ev.deltaY : ev.deltaX;
                if (Math.abs(delta) < 4) return;
                // Los trackpads emiten ráfagas; un solo giro por gesto/animación.
                const now = performance.now();
                if (now - lastFlip < 400 || this.pageFlip.getState() !== FlippingState.READ) return;
                lastFlip = now;
                delta > 0 ? this.pageFlip.flipNext() : this.pageFlip.flipPrev();
            },
            { passive: false }
        );
    }

    // -------------------------------------------- Sombra del canal central

    /** Crea la sombra sutil que emerge de la unión entre las dos páginas
     *  y el canto (pila de hojas) a los lados del libro. */
    private initGutter(): void {
        const block = this.root.querySelector<HTMLElement>('.stf__block');
        if (!block) return;
        this.gutter = document.createElement('div');
        this.gutter.className = 'vw-gutter';
        block.appendChild(this.gutter);

        this.edgeLeft = document.createElement('div');
        this.edgeLeft.className = 'vw-edge --left';
        this.edgeRight = document.createElement('div');
        this.edgeRight.className = 'vw-edge --right';
        block.append(this.edgeLeft, this.edgeRight);

        const update = (): void => {
            this.updateGutter();
            this.updateEdges();
        };
        this.pageFlip.on('changeOrientation', update);
        this.pageFlip.on('flip', update);
        window.addEventListener('resize', () => requestAnimationFrame(update));
        // Tras el primer render las páginas ya tienen posición.
        requestAnimationFrame(update);
    }

    /**
     * Canto del libro: el grosor de cada pila es proporcional a las páginas
     * que quedan de ese lado (leídas a la izquierda, por leer a la derecha).
     */
    private updateEdges(): void {
        if (!this.edgeLeft || !this.edgeRight) return;

        if (this.pageFlip.getOrientation() === 'portrait') {
            this.edgeLeft.style.display = 'none';
            this.edgeRight.style.display = 'none';
            return;
        }

        const rect = this.pageFlip.getBoundsRect();
        const visible = this.visiblePages();
        const leftPages = visible[0] - 1;
        const rightPages = this.cfg.pages - visible[visible.length - 1];

        const apply = (el: HTMLElement, pages: number, leftOf: boolean): void => {
            if (pages < 1) {
                el.style.display = 'none';
                return;
            }
            const w = Math.max(2, Math.round((EDGE_MAX_WIDTH * pages) / this.cfg.pages));
            el.style.display = 'block';
            el.style.width = `${w}px`;
            el.style.left = `${leftOf ? rect.left - w : rect.left + rect.width}px`;
            el.style.top = `${rect.top + 1}px`;
            el.style.height = `${rect.height - 2}px`;
        };
        apply(this.edgeLeft, leftPages, true);
        apply(this.edgeRight, rightPages, false);
    }

    private updateGutter(): void {
        if (!this.gutter) return;

        // Sin unión central en modo una-página ni en pliegos de una sola
        // página (portada o contraportada solas).
        if (this.pageFlip.getOrientation() === 'portrait' || this.visiblePages().length < 2) {
            this.gutter.style.display = 'none';
            return;
        }

        // El lomo según la geometría del propio motor (coordenadas relativas
        // al bloque, igual que las páginas).
        const rect = this.pageFlip.getBoundsRect();
        this.gutter.style.cssText =
            `display: block; left: ${rect.left + rect.pageWidth}px; top: ${rect.top}px; height: ${rect.height}px;`;
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
        this.progressFill.style.width =
            `${(visible[visible.length - 1] / this.cfg.pages) * 100}%`;
        this.preload(visible[0]);
        this.highlightThumb(visible[0]);
        this.syncUrl(visible[0]);
        this.savePage(visible[0]);
    }

    // localStorage puede fallar (modo privado, cookies bloqueadas): mejor sin
    // "continuar donde me quedé" que un visor muerto.

    /** Última página leída de este libro; 0 si no hay registro. */
    private savedPage(): number {
        try {
            return Number(localStorage.getItem(`libro:${this.cfg.slug}:pagina`) ?? 0);
        } catch {
            return 0;
        }
    }

    private savePage(page: number): void {
        try {
            localStorage.setItem(`libro:${this.cfg.slug}:pagina`, String(page));
        } catch {
            /* sin persistencia */
        }
    }

    /** Convierte la etiqueta de página en un campo para saltar a un número. */
    private showPageInput(): void {
        if (this.pageLabel.querySelector('input')) return;
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'vw-goto';
        input.min = '1';
        input.max = String(this.cfg.pages);
        input.placeholder = `1–${this.cfg.pages}`;
        let finished = false;
        const done = (go: boolean): void => {
            if (finished) return;
            finished = true;
            const n = Math.min(this.cfg.pages, Math.max(1, Number(input.value)));
            this.onPageChange(); // restaura la etiqueta
            if (go && input.value !== '') {
                this.pageFlip.flip(n - 1);
            }
        };
        input.addEventListener('keydown', (ev) => {
            ev.stopPropagation(); // que las flechas no hojeen el libro
            if (ev.key === 'Enter') done(true);
            if (ev.key === 'Escape') done(false);
        });
        input.addEventListener('blur', () => done(false));
        this.pageLabel.textContent = '';
        this.pageLabel.appendChild(input);
        input.focus();
    }

    /** Refleja la página actual en la URL (?p=N) para poder compartirla. */
    private syncUrl(current: number): void {
        if (!this.cfg.deepLink) return; // embebido: la URL es del anfitrión
        const url = new URL(location.href);
        if (current > 1) {
            url.searchParams.set('p', String(current));
        } else {
            url.searchParams.delete('p');
        }
        history.replaceState(null, '', url);
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
            if ((ev.target as HTMLElement).tagName === 'INPUT') return;
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

// Arranque: la página dedicada (#viewer, en ver.php) o cualquier cantidad de
// libros embebidos (.libro-flipbook, shortcode de WordPress u otro CMS).
for (const rootEl of document.querySelectorAll<HTMLElement>('#viewer, .libro-flipbook')) {
    rootEl.classList.add('vw-app');
    const cfg = readConfig(rootEl);
    if (cfg.slug && cfg.pages > 0) {
        new BookViewer(rootEl, cfg);
    }
}
