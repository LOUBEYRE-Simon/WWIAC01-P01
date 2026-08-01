"""
Module 1 - Extraction locale (remplace GCP Vision)
====================================================
Ingestion d'un PDF -> texte + mise en page, par page.

Stratégie :
1. Tenter l'extraction de texte natif (pdftotext) - gratuit, instantané,
   fiable sur les PDF non scannés (texte déjà encodé dans le fichier).
2. Si le texte natif est absent ou trop pauvre (PDF scanné / image),
   basculer sur un rendu image de la page (pdftoppm) + OCR local (Tesseract).

Le moteur OCR est interchangeable (voir 03-architecture-technique.md) :
Tesseract est utilisé ici comme baseline immédiate. PaddleOCR/Surya sont
des candidats à évaluer ensuite sur mise en page complexe (tableaux),
sans changer l'interface de ce module (même structure de retour).
"""

import subprocess
import tempfile
import os
from dataclasses import dataclass, field
from typing import List, Optional

import pytesseract
from PIL import Image

# Modèle de langue français téléchargé manuellement (tessdata_fast) car
# le paquet système ne fournit que l'anglais dans cet environnement.
os.environ.setdefault("TESSDATA_PREFIX", os.path.join(os.path.dirname(os.path.abspath(__file__)), "tessdata"))

NATIVE_TEXT_MIN_CHARS = 30  # seuil sous lequel on considère le PDF "sans texte"


@dataclass
class Word:
    text: str
    left: int
    top: int
    width: int
    height: int
    conf: float


@dataclass
class PageExtraction:
    page_number: int
    source: str  # "native_text" ou "ocr"
    text: str
    words: List[Word] = field(default_factory=list)
    image_path: Optional[str] = None
    engine: str = ""


def _extract_native_text(pdf_path: str, page_number: int) -> str:
    """Tente l'extraction de texte natif d'une page via pdftotext (poppler)."""
    result = subprocess.run(
        [
            "pdftotext", "-layout",
            "-f", str(page_number), "-l", str(page_number),
            pdf_path, "-",
        ],
        capture_output=True, text=True,
    )
    return result.stdout or ""


def _render_page_to_image(pdf_path: str, page_number: int, out_dir: str, dpi: int = 300) -> str:
    """Rend une page de PDF en image PNG (pdftoppm) pour l'OCR."""
    prefix = os.path.join(out_dir, f"page_{page_number}")
    subprocess.run(
        [
            "pdftoppm", "-png", "-r", str(dpi),
            "-f", str(page_number), "-l", str(page_number),
            pdf_path, prefix,
        ],
        check=True, capture_output=True,
    )
    # pdftoppm suffixe le fichier avec le numéro de page (sur N chiffres)
    candidates = [f for f in os.listdir(out_dir) if f.startswith(f"page_{page_number}")]
    if not candidates:
        raise RuntimeError(f"Échec du rendu image pour la page {page_number}")
    return os.path.join(out_dir, sorted(candidates)[0])


def _ocr_image(image_path: str, lang: str = "fra+eng") -> PageExtraction:
    """OCR local via Tesseract, avec récupération du texte ET des positions mot par mot."""
    image = Image.open(image_path)
    data = pytesseract.image_to_data(image, lang=lang, output_type=pytesseract.Output.DICT)

    words: List[Word] = []
    lines = {}
    n = len(data["text"])
    for i in range(n):
        raw_word = data["text"][i].strip()
        if not raw_word:
            continue
        conf = float(data["conf"][i]) if data["conf"][i] not in ("-1", -1) else 0.0
        w = Word(
            text=raw_word,
            left=data["left"][i], top=data["top"][i],
            width=data["width"][i], height=data["height"][i],
            conf=conf,
        )
        words.append(w)
        # reconstruction de lignes pour un texte lisible (regroupement par bloc/ligne)
        key = (data["block_num"][i], data["par_num"][i], data["line_num"][i])
        lines.setdefault(key, []).append(raw_word)

    full_text = "\n".join(" ".join(tokens) for tokens in lines.values())

    return PageExtraction(
        page_number=0,  # rempli par l'appelant
        source="ocr",
        text=full_text,
        words=words,
        image_path=image_path,
        engine=f"tesseract({lang})",
    )


def ocr_image_bytes(image_bytes: bytes, lang: str = "fra+eng") -> PageExtraction:
    """
    Point d'entrée pour une page fournie directement en image (pas de PDF) -
    cas d'usage de l'API de test : une image de page encodée en base64,
    décodée côté appelant, passée ici en bytes bruts.
    """
    import io
    image = Image.open(io.BytesIO(image_bytes))
    tmp_path = tempfile.mktemp(suffix=".png")
    image.save(tmp_path)
    try:
        page_result = _ocr_image(tmp_path, lang=lang)
    finally:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)
    page_result.page_number = 1
    page_result.image_path = None  # image temporaire déjà supprimée
    return page_result


def extract_single_page(pdf_path: str, page_number: int, dpi: int = 300) -> PageExtraction:
    """
    Extraction d'UNE SEULE page (par opposition à extract_pdf qui traite tout
    le fichier). Utilisé par le pipeline parallèle : chaque worker ne doit
    traiter que la page qui lui est assignée, pas relancer l'OCR de tout
    le document (erreur commise dans une première version du prototype).
    """
    native_text = _extract_native_text(pdf_path, page_number)
    if len(native_text.strip()) >= NATIVE_TEXT_MIN_CHARS:
        return PageExtraction(
            page_number=page_number, source="native_text",
            text=native_text, engine="pdftotext",
        )

    work_dir = tempfile.mkdtemp(prefix=f"ocr_page{page_number}_")
    image_path = _render_page_to_image(pdf_path, page_number, work_dir, dpi=dpi)
    page_result = _ocr_image(image_path)
    page_result.page_number = page_number
    return page_result


def extract_pdf(pdf_path: str, dpi: int = 300) -> List[PageExtraction]:
    """
    Point d'entrée du module. Renvoie une extraction par page du PDF.
    """
    info = subprocess.run(["pdfinfo", pdf_path], capture_output=True, text=True).stdout
    nb_pages = 1
    for line in info.splitlines():
        if line.startswith("Pages:"):
            nb_pages = int(line.split(":")[1].strip())

    results: List[PageExtraction] = []
    work_dir = tempfile.mkdtemp(prefix="ocr_pages_")

    for page_number in range(1, nb_pages + 1):
        native_text = _extract_native_text(pdf_path, page_number)
        useful_chars = len(native_text.strip())

        if useful_chars >= NATIVE_TEXT_MIN_CHARS:
            results.append(PageExtraction(
                page_number=page_number,
                source="native_text",
                text=native_text,
                engine="pdftotext",
            ))
        else:
            image_path = _render_page_to_image(pdf_path, page_number, work_dir, dpi=dpi)
            page_result = _ocr_image(image_path)
            page_result.page_number = page_number
            results.append(page_result)

    return results


if __name__ == "__main__":
    import sys
    for path in sys.argv[1:]:
        print(f"\n=== {path} ===")
        pages = extract_pdf(path)
        for p in pages:
            print(f"-- page {p.page_number} | source={p.source} | engine={p.engine} | "
                  f"{len(p.text)} caractères | {len(p.words)} mots positionnés")
            print(p.text[:400])
