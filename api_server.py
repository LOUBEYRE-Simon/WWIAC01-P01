"""
Point d'entrée HTTP de test - une page image en base64 -> JSON complet
========================================================================
Conçu pour être appelé depuis un test PHP (curl), avant le branchement
d'un appel IA externe (Ollama / minicpm-v4.5 à venir).

POST /process-page
Corps JSON, deux modes d'entrée possibles :
  - {"pdf_base64": "<PDF encodé en base64>", "page_number": 1}  (RECOMMANDÉ)
    -> tente pdftotext en premier (rapide, fiable sur PDF natif), bascule sur
    le rendu image + OCR seulement si le texte natif est absent/pauvre.
  - {"image_base64": "<image déjà rendue, encodée en base64>"}  (legacy/tests)
    -> passe directement par l'OCR, car il n'y a pas de PDF source à
    interroger avec pdftotext. À réserver aux tests unitaires sur une image
    isolée : si le PHP envoie systématiquement des images pré-converties en
    production, le chemin pdftotext (gratuit/instantané sur PDF natif) n'est
    jamais utilisé, même pour des factures PDF non scannées.

Réponse JSON :
{
  "status": "ok" | "error",
  "error": null ou message,
  "extraction_source": "native_text" | "ocr",
  "extraction_engine": "pdftotext" | "tesseract(fra+eng)",
  "document_type": "invoice" | "delivery_note" | ... | "unknown",
  "document_type_confidence": 0.0-1.0,
  "raw_text": "texte brut avec mise en page (regroupement par ligne OCR, ou texte natif via pdftotext)",
  "entities_detected": [{"entity_type":..., "start":..., "end":..., "score":..., "original_value":...}, ...],
  "anonymized_text": "texte anonymisé, prêt pour un envoi externe"
}

IMPORTANT - sécurité/confidentialité : cette réponse contient volontairement
les valeurs réelles détectées (entities_detected, raw_text) pour permettre
la validation du pipeline pendant les tests. Cet endpoint ne doit tourner
qu'en local/réseau de confiance. Seul le champ "anonymized_text" doit être
utilisé pour un envoi vers une IA externe (Ollama ou autre) - jamais
"raw_text" ni "entities_detected" tels quels.
"""

import base64
import os
import re
import tempfile

from flask import Flask, request, jsonify

from ocr_extraction import ocr_image_bytes, extract_single_page
from document_classifier import s03_detect_page_type_from_text
from entity_anonymizer import anonymize_text

app = Flask(__name__)


def normalize_whitespace(text: str) -> str:
    """Voir pipeline.py - les longs espaces de mise en page perturbent la NER."""
    text = re.sub(r"[ \t]{2,}", " ", text)
    text = re.sub(r"\n{2,}", "\n", text)
    return text


@app.route("/process-page", methods=["POST"])
def process_page():
    payload = request.get_json(silent=True) or {}
    pdf_b64 = payload.get("pdf_base64")
    image_b64 = payload.get("image_base64")
    page_number = int(payload.get("page_number", 1))

    if not pdf_b64 and not image_b64:
        return jsonify({
            "status": "error",
            "error": "Paramètre 'pdf_base64' (recommandé) ou 'image_base64' manquant",
        }), 400

    tmp_pdf_path = None
    try:
        if pdf_b64:
            # Chemin recommandé : on laisse extract_single_page tenter pdftotext
            # d'abord, et ne basculer sur l'OCR que si le texte natif est absent.
            pdf_bytes = base64.b64decode(pdf_b64)
            tmp_pdf_path = tempfile.mktemp(suffix=".pdf")
            with open(tmp_pdf_path, "wb") as f:
                f.write(pdf_bytes)
            page = extract_single_page(tmp_pdf_path, page_number)
        else:
            # Chemin legacy/tests : une image déjà rendue, pas de PDF source
            # disponible -> OCR direct, pdftotext ne peut pas s'appliquer ici.
            image_bytes = base64.b64decode(image_b64)
            page = ocr_image_bytes(image_bytes)
    except Exception as exc:
        return jsonify({"status": "error", "error": f"extraction invalide : {exc}"}), 400
    finally:
        if tmp_pdf_path and os.path.exists(tmp_pdf_path):
            os.remove(tmp_pdf_path)

    try:
        cleaned_text = normalize_whitespace(page.text)
        classification = s03_detect_page_type_from_text(cleaned_text)
        anonymization = anonymize_text(cleaned_text)
    except Exception as exc:
        return jsonify({"status": "error", "error": str(exc)}), 500

    return jsonify({
        "status": "ok",
        "error": None,
        "extraction_source": page.source,
        "extraction_engine": page.engine,
        "document_type": classification["document_type"],
        "document_type_confidence": classification["confidence"],
        "raw_text": page.text,
        "entities_detected": anonymization["entities_detected"],
        "anonymized_text": anonymization["anonymized_text"],
    })


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"})


if __name__ == "__main__":
    # Port dédié pour éviter tout conflit avec Ollama (11434) ou Presidio (5001/5002)
    app.run(host="0.0.0.0", port=5005)
