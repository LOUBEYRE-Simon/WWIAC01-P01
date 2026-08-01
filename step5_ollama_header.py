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
from ollama_client import call_ollama_json, DEFAULT_MODEL, DEFAULT_OLLAMA_URL

SYSTEM_PROMPT = (
    "Tu es un assistant d'extraction de données pour des documents commerciaux "
    "et de transport/logistique (factures, bons de livraison, listes de colisage, "
    "documents douaniers). Tu réponds UNIQUEMENT avec un objet JSON valide, sans "
    "aucun texte autour, avec exactement les clés demandées. Si une information "
    "est absente du texte, mets la valeur null - n'invente jamais de valeur."
)

# Indices/synonymes par champ - un simple nom de clé ("reference_commande")
# ne suffit pas toujours à un petit modèle pour relier le champ demandé au
# libellé réellement utilisé dans le document (ex: "Votre référence" ou
# "Numéro d'engagement", jamais littéralement "référence de commande").
# Ajouté après un cas réel où reference_commande revenait à null de façon
# stable (y compris à température basse) malgré la valeur présente en clair
# dans le texte, sous un libellé différent.
HEADER_FIELD_HINTS = {
    "emetteur_nom": (
        "nom de l'entité qui émet/facture le document - souvent en pied de "
        "page, éventuellement après une mention 'Nom Commercial :'. ATTENTION : "
        "un document peut afficher PLUSIEURS noms d'entreprise différents (ex: "
        "une marque commerciale ou un nom de gamme de produits en haut de page, "
        "et la raison sociale légale en pied de page avec SIRET/TVA/IBAN/RIB). "
        "Dans ce cas, c'est TOUJOURS l'entité identifiable par un SIRET, un "
        "numéro de TVA, ou des coordonnées bancaires (IBAN/RIB) qui est "
        "l'émetteur réel - ne renvoie JAMAIS null pour ce champ si une telle "
        "entité est présente dans le texte, même si un autre nom apparaît "
        "ailleurs et te semble plus visible ou plus proche du début du texte."
    ),
    "emetteur_adresse": "adresse de l'émetteur",
    "destinataire_nom": (
        "nom de l'entité qui reçoit la marchandise et/ou la facture - "
        "peut y avoir une adresse de facturation et une adresse de livraison distinctes"
    ),
    "destinataire_adresse": "adresse(s) du destinataire (facturation et/ou livraison si distinctes)",
    "numero_document": "numéro de la facture/du document (ex: 'Facture: 91036701', 'N° de facture')",
    "date_document": "date d'émission du document (ex: 'Date de facture')",
    "reference_commande": (
        "référence de la commande PASSÉE PAR LE CLIENT - PAS un code article, "
        "PAS un numéro de bordereau/BL, PAS un code client. Peut apparaître "
        "sous des libellés variés : 'Votre référence', 'Numéro de commande', "
        "'N° de commande', 'Commande client n°', 'Numéro d'engagement', "
        "'PO number', 'Order reference'. EXCLUS explicitement : 'Livraison "
        "client n°', 'Préparation livraison n°' - ce sont des références de "
        "livraison, pas la référence de commande du client, même si le "
        "libellé se ressemble. Renvoie UNIQUEMENT la valeur isolée (le code "
        "après le libellé), jamais toute la ligne de texte brute qui "
        "l'entoure (pas de date ni de code interne collés à la suite)."
    ),
    "devise": "devise du montant total (ex: EUR, USD)",
    "montant_total": "montant total du document (ex: 'Total TTC', 'Valeur totale', 'Net à payer')",
}

HEADER_FIELDS = list(HEADER_FIELD_HINTS.keys())


def build_prompt(text: str, document_type: str) -> str:
    fields_description = "\n".join(
        f"- {field} : {hint}" for field, hint in HEADER_FIELD_HINTS.items()
    )
    return (
        f"Type de document identifié : {document_type}.\n\n"
        f"Texte du document (extrait par OCR ou pdftotext, la mise en page peut "
        f"être imparfaite) :\n---\n{text}\n---\n\n"
        f"Renvoie un objet JSON avec exactement les clés suivantes (l'indice après "
        f"chaque clé précise ce qui est attendu, et sous quels libellés cette "
        f"information apparaît généralement dans ce type de document) :\n"
        f"{fields_description}"
    )


def handler(payload: dict) -> dict:
    text = payload.get("text")
    document_type = payload.get("document_type", "unknown")
    model = payload.get("model") or DEFAULT_MODEL
    # ollama_url : permet de pointer vers un autre serveur Ollama (un second
    # SLM sur une autre machine/port, par exemple) sans toucher au code -
    # utile pour comparer des modèles manuellement avant d'automatiser quoi
    # que ce soit. Ne couvre PAS un LLM externe avec une API différente
    # (OpenAI/Anthropic/etc.) - ollama_client.py ne parle que le protocole
    # /api/chat d'Ollama.
    ollama_url = payload.get("ollama_url") or DEFAULT_OLLAMA_URL
    if not text:
        raise ValueError("Champ 'text' manquant")

    prompt = build_prompt(text, document_type)
    header = call_ollama_json(prompt, system=SYSTEM_PROMPT, model=model, base_url=ollama_url)
    return {"header": header, "model": model, "ollama_url": ollama_url}


if __name__ == "__main__":
    run_step(handler)
