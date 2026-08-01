"""
Étape 3 - Anonymisation Presidio (détection PII + pseudonymisation réversible)
=================================================================================
Entrée (stdin, JSON) : {"text": "..."}
Sortie (stdout, JSON) : {
  "status": "ok",
  "anonymized_text": "...",
  "entities_detected": [{"entity_type":..., "start":..., "end":..., "score":..., "original_value":...}, ...],
  "entity_mapping": {"[ORGANIZATION_1]": "Stryker France SAS", ...}
}

ATTENTION SÉCURITÉ : "entity_mapping" est la donnée la plus sensible de tout
le pipeline (table jeton -> valeur réelle). Le PHP doit la conserver
strictement en local (ex: variable de session/process, jamais dans un log
persistant en clair) et ne JAMAIS la transmettre à un service externe.

Coût de démarrage : ce script charge le modèle spaCy fr_core_news_md à
l'import (quelques secondes) - c'est le prix du mode "un process par appel"
retenu côté PHP (exec/shell_exec) plutôt qu'un service qui reste chargé.
"""

import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cli_common import run_step
from entity_anonymizer import anonymize_text


def handler(payload: dict) -> dict:
    text = payload.get("text")
    if text is None:
        raise ValueError("Champ 'text' manquant")
    return anonymize_text(text)


if __name__ == "__main__":
    run_step(handler)
