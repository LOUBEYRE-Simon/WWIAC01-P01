"""
Utilitaires communs aux scripts CLI de la chaîne de traitement (appelés
depuis PHP via exec/shell_exec/proc_open, un process Python par étape).

Contrat commun à tous les scripts step*_*.py :
- Entrée : un objet JSON unique lu sur stdin.
- Sortie : un objet JSON unique écrit sur stdout, TOUJOURS avec une clé
  "status" ("ok" ou "error"). Rien d'autre ne doit être écrit sur stdout,
  pour que le PHP puisse faire un json_decode() direct de la sortie
  standard du process.
- Code de sortie : 0 si status="ok", 1 si status="error" - le PHP peut donc
  vérifier soit le exit code, soit le champ "status", les deux sont
  cohérents entre eux.

Toute erreur inattendue (fichier manquant, dépendance non installée,
service externe injoignable...) est capturée et renvoyée sous la même
forme JSON - jamais de stack trace brute sur stdout qui casserait le
json_decode() côté PHP.
"""

import sys
import json


def read_stdin_json() -> dict:
    raw = sys.stdin.read()
    if not raw.strip():
        raise ValueError("Entrée stdin vide - un objet JSON était attendu")
    return json.loads(raw)


def emit_ok(payload: dict) -> None:
    out = {"status": "ok", "error": None}
    out.update(payload)
    print(json.dumps(out, ensure_ascii=False))
    sys.exit(0)


def emit_error(message: str) -> None:
    print(json.dumps({"status": "error", "error": message}, ensure_ascii=False))
    sys.exit(1)


def run_step(handler) -> None:
    """
    Exécute handler(payload: dict) -> dict et gère uniformément succès/échec.
    `handler` ne doit renvoyer que les champs spécifiques à l'étape (pas besoin
    d'inclure "status", ajouté automatiquement par emit_ok).
    """
    try:
        payload = read_stdin_json()
        result = handler(payload)
        emit_ok(result)
    except Exception as exc:
        emit_error(f"{type(exc).__name__}: {exc}")
