"""
Module 2/3 - Détection d'entités + pseudonymisation réversible (Presidio)
==========================================================================
S'appuie sur Microsoft Presidio (analyzer + anonymizer) plutôt que de
réécrire un moteur de NER/chiffrement (voir 03-architecture-technique.md).

Choix retenu : opérateur personnalisé avec entity_mapping (table de
correspondance jeton <-> valeur d'origine), plutôt que l'opérateur
Encrypt/Decrypt intégré - pour obtenir une table exploitable directement
(nécessaire à l'étape 4 : classification émetteur/destinataire).

La table de correspondance ne doit JAMAIS être transmise à l'extérieur -
elle reste locale, c'est la donnée la plus sensible de tout le pipeline.
"""

from typing import Dict, Any, List
from presidio_analyzer import AnalyzerEngine
from presidio_analyzer.nlp_engine import NlpEngineProvider
from presidio_anonymizer import AnonymizerEngine
from presidio_anonymizer.entities import OperatorConfig

# Configuration du moteur NLP en français (modèle spaCy léger pour ce prototype ;
# fr_core_news_md/lg recommandé en production pour une meilleure précision).
_NLP_CONFIG = {
    "nlp_engine_name": "spacy",
    "models": [{"lang_code": "fr", "model_name": "fr_core_news_md"}],
}

_provider = NlpEngineProvider(nlp_configuration=_NLP_CONFIG)
_nlp_engine = _provider.create_engine()

analyzer = AnalyzerEngine(nlp_engine=_nlp_engine, supported_languages=["fr"])
anonymizer = AnonymizerEngine()

# Décision produit : n'anonymiser QUE les entités susceptibles de désigner
# l'émetteur ou le destinataire du document (noms de personnes/organisations),
# pas l'ensemble du texte. PERSON/ORGANIZATION sont les deux types Presidio qui
# correspondent à des identités - LOCATION/EMAIL_ADDRESS/PHONE_NUMBER ont été
# retirés : ce sont eux qui généraient l'essentiel du bruit observé en tests
# réels sur du texte OCR dégradé (ex: "Votre N°", "Ligne000020", des fragments
# de libellés de conditionnement pris pour des lieux/téléphones).
#
# Limite assumée (pas une réordonnance du pipeline) : ceci restreint le TYPE
# d'entité, pas encore le RÔLE (émetteur/destinataire) - un nom de société
# tiers mentionné ailleurs dans le document (transporteur, assureur...) sera
# aussi anonymisé. Cibler précisément les 2 parties demanderait d'inverser
# l'ordre du pipeline (extraire l'en-tête via le modèle AVANT d'anonymiser),
# ce qui retarderait la disponibilité de la trace anonymisée après l'appel
# modèle - écarté pour l'instant au profit de cette restriction plus simple.
ENTITY_TYPES = ["PERSON", "ORGANIZATION"]


def _make_token_operator(entity_type: str, entity_mapping: Dict[str, str], counters: Dict[str, int]):
    """Opérateur personnalisé : attribue un jeton stable par valeur unique détectée.

    Note API Presidio : le callable "custom" reçoit uniquement le texte détecté
    (pas le dict params), d'où un opérateur distinct par type d'entité plutôt
    qu'un opérateur unique lisant params["entity_type"].
    """
    def operator(text: str) -> str:
        key = f"{entity_type}:{text.strip().lower()}"
        if key not in entity_mapping:
            counters[entity_type] = counters.get(entity_type, 0) + 1
            entity_mapping[key] = f"[{entity_type}_{counters[entity_type]}]"
        return entity_mapping[key]
    return operator


def anonymize_text(text: str, language: str = "fr") -> Dict[str, Any]:
    """
    Détecte les entités PII dans un texte et les remplace par des jetons stables.
    Renvoie le texte anonymisé + la table de correspondance jeton -> valeur réelle
    (à conserver localement uniquement, jamais transmise à l'extérieur).
    """
    results = analyzer.analyze(text=text, language=language, entities=ENTITY_TYPES)

    entity_mapping: Dict[str, str] = {}
    counters: Dict[str, int] = {}

    operators = {
        entity: OperatorConfig("custom", {"lambda": _make_token_operator(entity, entity_mapping, counters)})
        for entity in ENTITY_TYPES
    }

    anonymized = anonymizer.anonymize(
        text=text, analyzer_results=results, operators=operators,
    )

    # Table de correspondance lisible : jeton -> valeur d'origine (inverse de entity_mapping)
    token_to_value = {v: k.split(":", 1)[1] for k, v in entity_mapping.items()}

    return {
        "anonymized_text": anonymized.text,
        "entities_detected": [
            {"entity_type": r.entity_type, "start": r.start, "end": r.end, "score": round(r.score, 2),
             "original_value": text[r.start:r.end]}
            for r in results
        ],
        "entity_mapping": token_to_value,  # SENSIBLE - local uniquement
    }


if __name__ == "__main__":
    import sys
    sample = sys.argv[1] if len(sys.argv) > 1 else (
        "Jean Dupont, de la société B2B PHARMA SAS, contact au 01 61 44 14 20, "
        "livraison chez CLINIQUE LES ORCHIDEES."
    )
    result = anonymize_text(sample)
    print("Texte anonymisé:\n", result["anonymized_text"])
    print("\nEntités détectées:")
    for e in result["entities_detected"]:
        print(" ", e)
    print("\nTable de correspondance (locale uniquement):")
    for token, val in result["entity_mapping"].items():
        print(f"  {token} -> {val}")
