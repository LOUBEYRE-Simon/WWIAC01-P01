"""
Étape 4 - Détection du type de document (port fidèle de functions.php, sans IA)
==================================================================================
Entrée (stdin, JSON) : {"text": "..."}
Sortie (stdout, JSON) : {
  "status": "ok",
  "document_type": "invoice" | "delivery_note" | ... | "unknown",
  "confidence": 0.0-1.0,
  "score": ..., "second_score": ..., "signals": [...], "ranking": [...]
}

Peut recevoir le texte brut OU anonymisé indifféremment : la classification
repose sur des mots-clés/regex métier (montants, "facture", "MRN", "packing
list"...), pas sur les entités PII masquées par l'étape 3.
"""

import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cli_common import run_step
from document_classifier import s03_detect_page_type_from_text


def handler(payload: dict) -> dict:
    text = payload.get("text")
    if text is None:
        raise ValueError("Champ 'text' manquant")
    return s03_detect_page_type_from_text(text)


if __name__ == "__main__":
    run_step(handler)
