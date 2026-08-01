"""
Étape 2 - Extraction du texte d'une page (pdftotext natif, sinon OCR)
=======================================================================
Réutilise extract_single_page() de ocr_extraction.py : tente pdftotext en
premier (rapide, fiable sur PDF non scanné), et ne rend l'image + lance
Tesseract que si le texte natif est absent ou trop pauvre.

Entrée (stdin, JSON) : {"pdf_path": "...", "page_number": 1, "dpi": 300}
Sortie (stdout, JSON) : {
  "status": "ok",
  "page_number": 1,
  "source": "native_text" | "ocr",
  "engine": "pdftotext" | "tesseract(fra+eng)",
  "text": "..."
}
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cli_common import run_step
from ocr_extraction import extract_single_page


def handler(payload: dict) -> dict:
    pdf_path = payload.get("pdf_path")
    page_number = int(payload.get("page_number", 1))
    dpi = int(payload.get("dpi", 300))

    if not pdf_path or not os.path.isfile(pdf_path):
        raise FileNotFoundError(f"PDF introuvable : {pdf_path}")
    if page_number < 1:
        raise ValueError(f"page_number invalide : {page_number}")

    page = extract_single_page(pdf_path, page_number, dpi=dpi)

    return {
        "page_number": page.page_number,
        "source": page.source,
        "engine": page.engine,
        "text": page.text,
    }


if __name__ == "__main__":
    run_step(handler)
