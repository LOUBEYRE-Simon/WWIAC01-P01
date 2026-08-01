"""
Étape 6 (conditionnelle) - Extraction des lignes de détail via Ollama local
=============================================================================
"Si nécessaire" (demande initiale) : seuls les types de document avec un
contenu tabulaire exploitable (facture, bon de livraison, packing list)
déclenchent un appel modèle ici. C'est ce script qui applique cette
condition (via document_type) plutôt que de laisser le PHP la dupliquer -
un seul endroit à maintenir si la liste évolue.

Entrée (stdin, JSON) : {
  "text": "...",
  "document_type": "invoice",     # renvoyé par l'étape 4
  "model": "minicpm-v4.5:latest"  # optionnel
}
Sortie (stdout, JSON) :
  - type éligible   : {"status":"ok","lines":[{...}, ...],"model":...}
  - type non éligible (pas une erreur) :
        {"status":"ok","lines":[],"skipped":true,"reason":"..."}
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cli_common import run_step
from ollama_client import call_ollama_json, DEFAULT_MODEL

SYSTEM_PROMPT = (
    "Tu es un assistant d'extraction de données pour des documents commerciaux "
    "et de transport/logistique. Tu réponds UNIQUEMENT avec un objet JSON valide : "
    "{\"lines\": [...]} - une liste d'objets, un par ligne de détail identifiée. "
    "Si aucune ligne n'est identifiable, renvoie {\"lines\": []}. N'invente jamais "
    "de valeur absente du texte."
)

LINE_TYPES_ELIGIBLE = {"invoice", "delivery_note", "packing_list"}

LINE_FIELDS_BY_TYPE = {
    "invoice": ["reference_article", "designation", "quantite", "prix_unitaire", "montant_ligne"],
    "delivery_note": ["reference_article", "designation", "quantite_commandee", "quantite_livree"],
    "packing_list": ["numero_colis", "reference_article", "quantite", "poids_brut", "poids_net"],
}


def build_prompt(text: str, document_type: str) -> str:
    fields = LINE_FIELDS_BY_TYPE.get(document_type, ["designation", "quantite", "montant_ligne"])
    fields_list = ", ".join(fields)
    return (
        f"Type de document : {document_type}.\n\n"
        f"Texte du document (extrait par OCR ou pdftotext, la mise en page peut "
        f"être imparfaite) :\n---\n{text}\n---\n\n"
        f"Extrait chaque ligne de détail sous forme d'objet JSON avec exactement "
        f"ces clés : {fields_list}. Renvoie {{\"lines\": [...]}}."
    )


def handler(payload: dict) -> dict:
    text = payload.get("text")
    document_type = payload.get("document_type", "unknown")
    model = payload.get("model") or DEFAULT_MODEL
    if not text:
        raise ValueError("Champ 'text' manquant")

    if document_type not in LINE_TYPES_ELIGIBLE:
        return {
            "lines": [],
            "skipped": True,
            "reason": f"document_type '{document_type}' non éligible à l'extraction de lignes",
        }

    prompt = build_prompt(text, document_type)
    result = call_ollama_json(prompt, system=SYSTEM_PROMPT, model=model)
    lines = result.get("lines", []) if isinstance(result, dict) else []
    return {"lines": lines, "model": model}


if __name__ == "__main__":
    run_step(handler)
