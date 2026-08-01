"""
Client HTTP minimal pour Ollama local (API REST native - pas besoin du
package pip "ollama", un simple appel `requests` suffit et évite une
dépendance supplémentaire côté environnement Python).

Documentation API : https://docs.ollama.com/api (endpoint /api/chat)

Décision produit : les scripts appelants (step5/step6) envoient le texte
BRUT (non anonymisé) à Ollama, car le modèle tourne 100% en local - aucune
donnée ne quitte le réseau interne à cette étape.
"""

import json
import re
import requests

DEFAULT_OLLAMA_URL = "http://localhost:11434"
DEFAULT_MODEL = "minicpm-v4.5:latest"

# Repère un bloc ```json ... ``` ou ``` ... ``` entourant tout le message.
_FENCE_RE = re.compile(r"^```(?:json)?\s*(.*?)\s*```$", re.DOTALL | re.IGNORECASE)
# Filet de sécurité : le premier bloc { ... } du texte, au cas où le modèle
# aurait ajouté du texte avant/après le JSON malgré les consignes.
_BRACE_RE = re.compile(r"\{.*\}", re.DOTALL)


class OllamaError(Exception):
    pass


def _extract_json(content: str) -> dict:
    """
    Certains modèles (minicpm-v4.5 en pratique, observé en usage réel) ne
    respectent pas toujours format="json" à la lettre et enveloppent leur
    réponse dans des balises markdown (```json ... ```), voire ajoutent du
    texte autour. On nettoie en plusieurs passes avant d'abandonner :
      1. essai direct (cas nominal, format="json" respecté)
      2. balises markdown retirées
      3. premier bloc { ... } extrait du texte
    """
    stripped = content.strip()

    try:
        return json.loads(stripped)
    except json.JSONDecodeError:
        pass

    fence_match = _FENCE_RE.match(stripped)
    if fence_match:
        try:
            return json.loads(fence_match.group(1).strip())
        except json.JSONDecodeError:
            pass

    brace_match = _BRACE_RE.search(stripped)
    if brace_match:
        try:
            return json.loads(brace_match.group(0))
        except json.JSONDecodeError:
            pass

    raise OllamaError(
        f"Le modèle n'a pas renvoyé un JSON exploitable (essai direct, sans balises "
        f"markdown, et extraction de bloc {{...}} ont tous échoué) - "
        f"contenu brut du modèle : {content[:1000]}"
    )


def call_ollama_json(
    prompt: str,
    system: str = None,
    model: str = DEFAULT_MODEL,
    base_url: str = DEFAULT_OLLAMA_URL,
    timeout: int = 120,
) -> dict:
    """
    Appelle Ollama en mode chat en exigeant une réponse JSON stricte
    (format="json" - contrainte native de l'API Ollama qui force le modèle
    à produire un JSON syntaxiquement valide). Renvoie le dict déjà parsé.

    Lève OllamaError avec un message explicite si : Ollama n'est pas
    joignable, timeout dépassé, code HTTP d'erreur, ou réponse qui n'est
    pas un JSON valide malgré la contrainte format="json".
    """
    messages = []
    if system:
        messages.append({"role": "system", "content": system})
    messages.append({"role": "user", "content": prompt})

    try:
        response = requests.post(
            f"{base_url}/api/chat",
            json={
                "model": model,
                "messages": messages,
                "format": "json",
                "stream": False,
            },
            timeout=timeout,
        )
    except requests.exceptions.ConnectionError as exc:
        raise OllamaError(
            f"Impossible de joindre Ollama sur {base_url} - vérifier que le service "
            f"tourne (`ollama serve`) et que le modèle '{model}' est bien présent "
            f"(`ollama list` / `ollama pull {model}`). Détail : {exc}"
        )
    except requests.exceptions.Timeout as exc:
        raise OllamaError(f"Timeout ({timeout}s) en attendant la réponse d'Ollama : {exc}")

    if response.status_code != 200:
        raise OllamaError(f"Ollama a répondu HTTP {response.status_code} : {response.text[:500]}")

    try:
        body = response.json()
    except json.JSONDecodeError as exc:
        raise OllamaError(f"Réponse Ollama non-JSON au niveau HTTP : {exc} - contenu : {response.text[:500]}")

    content = (body.get("message") or {}).get("content", "")
    if not content:
        raise OllamaError(f"Réponse Ollama vide ou de forme inattendue : {body}")

    return _extract_json(content)
