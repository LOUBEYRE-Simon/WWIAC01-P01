"""
Étape 1 - Découpage logique du PDF par page
=============================================
Ne matérialise PAS de fichiers séparés par page : pdftotext/pdftoppm (voir
ocr_extraction.py, étape 2) savent déjà cibler une page précise dans le PDF
d'origine via les options -f/-l, donc dupliquer le fichier ne servirait à
rien de plus. Ce script se contente de renvoyer le nombre de pages, pour
que le PHP sache combien de fois boucler sur l'étape 2 (page_number de 1 à
nb_pages, en série ou en parallèle selon les workers PHP disponibles).

Entrée (stdin, JSON) : {"pdf_path": "/chemin/vers/fichier.pdf"}
Sortie (stdout, JSON) : {"status": "ok", "nb_pages": N}
"""

import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cli_common import run_step


def handler(payload: dict) -> dict:
    pdf_path = payload.get("pdf_path")
    if not pdf_path or not os.path.isfile(pdf_path):
        raise FileNotFoundError(f"PDF introuvable : {pdf_path}")

    info = subprocess.run(["pdfinfo", pdf_path], capture_output=True, text=True)
    if info.returncode != 0:
        raise RuntimeError(f"pdfinfo a échoué : {info.stderr.strip()}")

    nb_pages = None
    for line in info.stdout.splitlines():
        if line.startswith("Pages:"):
            nb_pages = int(line.split(":", 1)[1].strip())
            break
    if nb_pages is None:
        raise RuntimeError("Impossible de déterminer le nombre de pages (sortie pdfinfo inattendue)")

    return {"nb_pages": nb_pages}


if __name__ == "__main__":
    run_step(handler)
