"""
Module 4 - Interface de transmission à l'IA externe (stub, pas d'appel réel)
=============================================================================
Reçoit le texte (ou image) anonymisé + le type de document identifié,
choisit le traitement à appliquer selon le type, et *simule* l'envoi à un
fournisseur d'IA générative externe. Aucun appel réseau réel à ce stade -
interface prête à brancher sur un fournisseur (Claude, OpenAI...) plus tard.

Important : seule la version ANONYMISÉE doit être transmise ici. La table
de correspondance (entity_mapping) ne doit jamais être passée à cette
fonction ni transmise à l'extérieur.
"""

from typing import Dict, Any, Optional


# Traitement à appliquer selon le type de document identifié (étape 0).
# À enrichir librement - c'est la configuration métier du produit.
TREATMENT_BY_DOCUMENT_TYPE: Dict[str, str] = {
    "invoice": "Extraire montant total, devise, échéance et vérifier la cohérence TVA/HT/TTC.",
    "delivery_note": "Extraire les lignes de livraison (référence, quantité) et le statut de livraison.",
    "packing_list": "Extraire le détail des colis/palettes et les poids/volumes.",
    "dangerous_goods_declaration": "Vérifier la cohérence des numéros ONU et classes de danger déclarés.",
    "customs_document": "Extraire les références douanières (MRN, codes TARIC/HS).",
    "commercial_conditions": "Résumer les clauses clés (juridiction, réserve de propriété, responsabilité).",
    "transport_document": "Extraire transporteur, référence CMR/AWB et statut de transport.",
    "unknown": "Aucun traitement spécifique défini - relecture humaine recommandée.",
}


def dispatch_to_external_ai(
    anonymized_content: str,
    document_type: str,
    confidence: float,
    provider: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Stub d'appel à une IA générative externe. Ne fait AUCUN appel réseau :
    prépare et retourne la requête telle qu'elle serait envoyée, pour
    inspection/validation avant de brancher un fournisseur réel.
    """
    treatment = TREATMENT_BY_DOCUMENT_TYPE.get(document_type, TREATMENT_BY_DOCUMENT_TYPE["unknown"])

    simulated_request = {
        "provider": provider or "(non configuré)",
        "instruction": treatment,
        "document_type": document_type,
        "confidence": confidence,
        "content_sent": anonymized_content,  # jamais la version réelle
        "note": "STUB - aucun appel réseau réel effectué.",
    }

    # -- Point d'intégration futur --
    # if provider == "claude":
    #     response = anthropic_client.messages.create(..., content=anonymized_content)
    # elif provider == "openai":
    #     response = openai_client.chat.completions.create(..., content=anonymized_content)

    return simulated_request


if __name__ == "__main__":
    result = dispatch_to_external_ai(
        anonymized_content="Facture de [ORGANIZATION_1] pour un montant de 1 250,00 EUR.",
        document_type="invoice",
        confidence=0.79,
    )
    import json
    print(json.dumps(result, ensure_ascii=False, indent=2))
