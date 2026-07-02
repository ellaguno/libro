#!/usr/bin/env python3
"""
Plan B: pre-renderiza un PDF localmente y genera la estructura de carpetas
que espera el visor, lista para subir por FTP/cPanel a public_html/books/.

Uso:
    python3 render_pdf.py revista.pdf --title "Mi revista" [--slug mi-revista]
                          [--height 1600] [--format webp] [--out ./out]

Requisitos:
    pip install pdf2image pillow
    y tener poppler instalado (Debian/Ubuntu: sudo apt install poppler-utils)

Resultado:
    out/<slug>/book.json
    out/<slug>/original.pdf
    out/<slug>/pages/page-001.webp ...
    out/<slug>/thumbs/thumb-001.webp ...
"""

import argparse
import json
import re
import shutil
import sys
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

try:
    from pdf2image import convert_from_path, pdfinfo_from_path
except ImportError:
    sys.exit("Falta pdf2image. Instala con: pip install pdf2image pillow")

THUMB_HEIGHT = 240  # miniaturas de la tira del visor
COVER_HEIGHT = 640  # portada nítida para la rejilla de la biblioteca
BATCH = 10  # páginas por lote para no agotar la memoria


def slugify(text: str) -> str:
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    text = re.sub(r"[^a-z0-9-]+", "-", text.lower())
    return re.sub(r"-+", "-", text).strip("-")[:80]


def main() -> None:
    parser = argparse.ArgumentParser(description="Pre-renderiza un PDF para el visor flipbook.")
    parser.add_argument("pdf", type=Path, help="Ruta del PDF")
    parser.add_argument("--title", required=True, help="Título de la publicación")
    parser.add_argument("--slug", default="", help="Slug (por defecto se deriva del título)")
    parser.add_argument("--height", type=int, default=1600, help="Alto de página en px (1600 por defecto)")
    parser.add_argument("--format", choices=["webp", "jpeg"], default="webp")
    parser.add_argument("--quality", type=int, default=85, help="Calidad de compresión (85 por defecto)")
    parser.add_argument("--hard-cover", action="store_true",
                        help="Portada de pasta dura (primera y última página rígidas)")
    parser.add_argument("--out", type=Path, default=Path("out"), help="Carpeta de salida")
    args = parser.parse_args()

    if not args.pdf.is_file():
        sys.exit(f"No existe el archivo: {args.pdf}")

    slug = slugify(args.slug or args.title)
    if not slug:
        sys.exit("No se pudo derivar un slug válido; usa --slug")

    ext = "jpg" if args.format == "jpeg" else "webp"
    pil_format = "JPEG" if args.format == "jpeg" else "WEBP"

    book_dir = args.out / slug
    pages_dir = book_dir / "pages"
    thumbs_dir = book_dir / "thumbs"
    pages_dir.mkdir(parents=True, exist_ok=True)
    thumbs_dir.mkdir(parents=True, exist_ok=True)

    info = pdfinfo_from_path(args.pdf)
    total = int(info["Pages"])
    print(f"» {args.pdf.name}: {total} páginas → {book_dir}/")

    width = height = 0
    for start in range(1, total + 1, BATCH):
        end = min(start + BATCH - 1, total)
        # pdf2image usa DPI; calculamos el DPI que produce el alto pedido (base 72 pt).
        images = convert_from_path(
            args.pdf, first_page=start, last_page=end,
            size=(None, args.height), fmt="ppm",
        )
        for offset, image in enumerate(images):
            n = start + offset
            if n == 1:
                width, height = image.size
                # Portada dedicada para la rejilla de la biblioteca.
                cover_w = round(image.width * COVER_HEIGHT / image.height)
                image.resize((cover_w, COVER_HEIGHT)).save(
                    book_dir / f"cover.{ext}", pil_format, quality=args.quality
                )
            image.save(pages_dir / f"page-{n:03d}.{ext}", pil_format, quality=args.quality)

            thumb_w = round(image.width * THUMB_HEIGHT / image.height)
            thumb = image.resize((thumb_w, THUMB_HEIGHT))
            thumb.save(thumbs_dir / f"thumb-{n:03d}.{ext}", pil_format, quality=80)
            image.close()
            print(f"  página {n}/{total}", end="\r", flush=True)

    print()
    shutil.copyfile(args.pdf, book_dir / "original.pdf")

    meta = {
        "title": args.title,
        "slug": slug,
        "pages": total,
        "width": width,
        "height": height,
        "format": args.format,
        "hardCover": bool(args.hard_cover),
        "created": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "hasPdf": True,
    }
    (book_dir / "book.json").write_text(
        json.dumps(meta, ensure_ascii=False, indent=2), encoding="utf-8"
    )

    print(f"✓ Listo. Sube la carpeta \"{book_dir}\" a public_html/books/{slug}/")


if __name__ == "__main__":
    main()
