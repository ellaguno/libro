/**
 * Overlay de zoom: muestra la(s) página(s) visibles a gran tamaño
 * con paneo por arrastre, rueda del ratón y pellizco (pinch).
 */

const MIN_SCALE = 1;
const MAX_SCALE = 5;

export class ZoomOverlay {
    private readonly overlay: HTMLElement;
    private readonly canvas: HTMLElement;
    private scale = 1;
    private tx = 0;
    private ty = 0;
    private pointers = new Map<number, PointerEvent>();
    private lastPinchDist = 0;

    constructor(parent: HTMLElement) {
        this.overlay = document.createElement('div');
        this.overlay.className = 'vw-zoom';
        this.overlay.hidden = true;
        this.overlay.innerHTML = `
          <div class="vw-zoom-canvas"></div>
          <div class="vw-zoom-controls">
            <button type="button" data-z="in" title="Acercar">＋</button>
            <button type="button" data-z="out" title="Alejar">－</button>
            <button type="button" data-z="reset" title="Tamaño original">1:1</button>
            <button type="button" data-z="close" title="Cerrar (Esc)">✕</button>
          </div>
        `;
        parent.appendChild(this.overlay);
        this.canvas = this.overlay.querySelector('.vw-zoom-canvas') as HTMLElement;
        this.bind();
    }

    get isOpen(): boolean {
        return !this.overlay.hidden;
    }

    open(imageUrls: string[]): void {
        this.canvas.innerHTML = '';
        for (const url of imageUrls) {
            const img = document.createElement('img');
            img.src = url;
            img.draggable = false;
            this.canvas.appendChild(img);
        }
        this.scale = 1.6; // entra ya con un acercamiento agradable
        this.tx = 0;
        this.ty = 0;
        this.apply();
        this.overlay.hidden = false;
    }

    close(): void {
        this.overlay.hidden = true;
        this.canvas.innerHTML = '';
        this.pointers.clear();
    }

    private apply(): void {
        this.clampPan();
        this.canvas.style.transform = `translate(${this.tx}px, ${this.ty}px) scale(${this.scale})`;
    }

    private zoomBy(factor: number, cx?: number, cy?: number): void {
        const prev = this.scale;
        this.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, this.scale * factor));
        const real = this.scale / prev;
        if (cx !== undefined && cy !== undefined) {
            // Mantiene el punto (cx, cy) bajo el cursor mientras se escala.
            const rect = this.overlay.getBoundingClientRect();
            const ox = cx - rect.width / 2;
            const oy = cy - rect.height / 2;
            this.tx = ox - (ox - this.tx) * real;
            this.ty = oy - (oy - this.ty) * real;
        }
        this.apply();
    }

    private clampPan(): void {
        // Evita que el contenido se pierda fuera de la pantalla.
        const maxX = (this.canvas.scrollWidth * this.scale) / 2;
        const maxY = (this.canvas.scrollHeight * this.scale) / 2;
        this.tx = Math.max(-maxX, Math.min(maxX, this.tx));
        this.ty = Math.max(-maxY, Math.min(maxY, this.ty));
    }

    private bind(): void {
        this.overlay.querySelector('[data-z="in"]')!.addEventListener('click', () => this.zoomBy(1.4));
        this.overlay.querySelector('[data-z="out"]')!.addEventListener('click', () => this.zoomBy(1 / 1.4));
        this.overlay.querySelector('[data-z="reset"]')!.addEventListener('click', () => {
            this.scale = 1;
            this.tx = 0;
            this.ty = 0;
            this.apply();
        });
        this.overlay.querySelector('[data-z="close"]')!.addEventListener('click', () => this.close());

        this.overlay.addEventListener('wheel', (ev) => {
            ev.preventDefault();
            this.zoomBy(ev.deltaY < 0 ? 1.15 : 1 / 1.15, ev.clientX, ev.clientY);
        }, { passive: false });

        this.overlay.addEventListener('dblclick', (ev) => {
            if ((ev.target as HTMLElement).closest('.vw-zoom-controls')) return;
            this.zoomBy(this.scale < 2.5 ? 1.8 : 0.2, ev.clientX, ev.clientY);
        });

        document.addEventListener('keydown', (ev) => {
            if (!this.isOpen) return;
            if (ev.key === 'Escape') this.close();
            if (ev.key === '+') this.zoomBy(1.3);
            if (ev.key === '-') this.zoomBy(1 / 1.3);
        });

        // Paneo con arrastre y zoom con pellizco.
        this.overlay.addEventListener('pointerdown', (ev) => {
            if ((ev.target as HTMLElement).closest('.vw-zoom-controls')) return;
            this.overlay.setPointerCapture(ev.pointerId);
            this.pointers.set(ev.pointerId, ev);
            this.lastPinchDist = 0;
        });

        this.overlay.addEventListener('pointermove', (ev) => {
            const prev = this.pointers.get(ev.pointerId);
            if (!prev) return;

            if (this.pointers.size === 2) {
                const [a, b] = [...this.pointers.values()];
                const other = a.pointerId === ev.pointerId ? b : a;
                const dist = Math.hypot(ev.clientX - other.clientX, ev.clientY - other.clientY);
                if (this.lastPinchDist > 0) {
                    const cx = (ev.clientX + other.clientX) / 2;
                    const cy = (ev.clientY + other.clientY) / 2;
                    this.zoomBy(dist / this.lastPinchDist, cx, cy);
                }
                this.lastPinchDist = dist;
            } else {
                this.tx += ev.clientX - prev.clientX;
                this.ty += ev.clientY - prev.clientY;
                this.apply();
            }
            this.pointers.set(ev.pointerId, ev);
        });

        const release = (ev: PointerEvent): void => {
            this.pointers.delete(ev.pointerId);
            this.lastPinchDist = 0;
        };
        this.overlay.addEventListener('pointerup', release);
        this.overlay.addEventListener('pointercancel', release);
    }
}
