"""
Serveur factice reproduisant la forme exacte de l'API Ollama (/api/chat),
utilisé UNIQUEMENT pour valider la logique de step5/step6/ollama_client.py
dans cet environnement sandbox (où le vrai Ollama + minicpm-v4.5 ne sont
pas installables). Ne remplace pas un test réel contre l'Ollama du poste
de l'utilisateur - à faire avant mise en production.
"""

from flask import Flask, request, jsonify
import json

app = Flask(__name__)


@app.route("/api/chat", methods=["POST"])
def chat():
    body = request.get_json(silent=True) or {}
    messages = body.get("messages", [])
    user_msg = next((m["content"] for m in reversed(messages) if m.get("role") == "user"), "")

    if "lines" in (messages[0]["content"] if messages else ""):
        # Cas step6 (system prompt mentionne "lines")
        fake_content = json.dumps({
            "lines": [
                {"reference_article": "CAC-107530021015", "designation": "ASSY JACK CONSTANT",
                 "quantite": 1, "prix_unitaire": 653.98, "montant_ligne": 1961.94},
                {"reference_article": "0026342000", "designation": "GROOVE PIN",
                 "quantite": 2, "prix_unitaire": 2.07, "montant_ligne": 4.14},
            ]
        })
    else:
        # Cas step5
        fake_content = json.dumps({
            "emetteur_nom": "Stryker France SAS",
            "emetteur_adresse": "ZAC - Avenue de Satolas, 69330 Pusignan",
            "destinataire_nom": "CLINIQUE LES ORCHIDEES",
            "destinataire_adresse": "130 AVENUE ..., 97420 LE PORT",
            "numero_document": "318997208",
            "date_document": "07-JUL-26",
            "reference_commande": None,
            "devise": "EUR",
            "montant_total": 1966.08,
        })

    return jsonify({
        "model": body.get("model"),
        "message": {"role": "assistant", "content": fake_content},
        "done": True,
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=11434)
