"""
Étape 5 - Identification émetteur/destinataire/en-tête via Ollama local
==========================================================================
Reçoit le texte BRUT (pas anonymisé - décision explicite : Ollama tourne
100% en local, minicpm-v4.5, aucune donnée ne sort du réseau interne à
cette étape).

Entrée (stdin, JSON) : {
  "text": "...",
  "document_type": "invoice",     # renvoyé par l'étape 4
  "model": "minicpm-v4.5:latest"  # optionnel, override du modèle par défaut
}
Sortie (stdout, JSON) : {
  "status": "ok",
  "header": {"emetteur_nom": ..., "destinataire_nom": ..., ...},
  "model": "minicpm-v4.5:latest"
}
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cli_common import run_step
from ollama_client import call_ollama_json, DEFAULT_MODEL

SYSTEM_PROMPT = (
    "Tu es un assistant d'extraction de données pour des documents commerciaux "
    "et de transport/logistique (factures, bons de livraison, listes de colisage, "
    "documents douaniers). Tu réponds UNIQUEMENT avec un objet JSON valide, sans "
    "aucun texte autour, avec exactement les clés demandées. Si une information "
    "est absente du texte, mets la valeur null - n'invente jamais de valeur."
)

HEADER_FIELDS = [
    "emetteur_nom", "emetteur_adresse",
    "destinataire_nom", "destinataire_adresse",
    "numero_document", "date_document",
    "reference_commande", "devise", "montant_total",
]


def build_prompt(text: str, document_type: str) -> str:
    fields_list = ", ".join(HEADER_FIELDS)
    return (
        f"Type de document identifié : {document_type}.\n\n"
        f"Texte du document (extrait par OCR ou pdftotext, la mise en page peut "
        f"être imparfaite) :\n---\n{text}\n---\n\n"
        f"Renvoie un objet JSON avec exactement ces clés : {fields_list}.\n"
        f"'emetteur' = l'entité qui émet/facture le document. "
        f"'destinataire' = l'entité qui reçoit la marchandise ou la facture."
    )


def handler(payload: dict) -> dict:
    text = payload.get("text")
    document_type = payload.get("document_type", "unknown")
    model = payload.get("model") or DEFAULT_MODEL
    if not text:
        raise ValueError("Champ 'text' manquant")

    prompt = build_prompt(text, document_type)
    header = call_ollama_json(prompt, system=SYSTEM_PROMPT, model=model)
    return {"header": header, "model": model}


if __name__ == "__main__":
    run_step(handler)
