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
        "ailleurs et te semble plus visible ou plus proche du début du texte. "
        "ATTENTION - piège fréquent : le nom d'entreprise affiché le plus "
        "en évidence en haut de page (mise en forme façon en-tête/logo) "
        "n'est PAS forcément l'émetteur - ce peut être le CLIENT (destinataire), "
        "affiché ainsi pour être visible en fenêtre d'enveloppe. Le véritable "
        "émetteur peut n'apparaître nulle part à côté de sa propre ligne "
        "'Notre N° de TVA'/'Notre SIRET' (juste le numéro, sans le nom "
        "répété) - dans ce cas, cherche son nom ailleurs dans le document "
        "(ex: adresse email de contact du type 'contact@nomdomaine.xx', "
        "mention d'un service en ligne de la marque, ou pied de page de "
        "conditions générales de vente sur une AUTRE page) et vérifie que "
        "le SIRET qui y est cité correspond bien aux chiffres du numéro de "
        "TVA du bloc principal avant de conclure. Ne te fie JAMAIS à la "
        "position ou à la mise en forme visuelle seule pour désigner "
        "l'émetteur."
    ),
    "emetteur_adresse": (
        "adresse de l'émetteur - rue, code postal et ville. Comme pour "
        "destinataire_adresse, le code postal et la ville peuvent apparaître "
        "ailleurs dans le texte (souvent en en-tête/pied de page, séparés de "
        "la rue) - inclus-les si tu les identifies pour la même entité, "
        "même sur une autre ligne ou un autre bloc. Une ligne du type 'CS "
        "90149' ou 'BP 55065' (boîte postale/cedex) fait partie intégrante "
        "de l'adresse et doit être incluse, même isolée sur sa propre "
        "ligne. ATTENTION : n'inclus JAMAIS le numéro SIRET, le numéro RCS, "
        "le numéro de TVA, ni le capital social dans cette valeur - ce sont "
        "des identifiants légaux de l'entreprise, pas des éléments "
        "d'adresse postale, même s'ils apparaissent sur la même ligne ou "
        "juste après la rue dans le texte source."
    ),
    "destinataire_nom": (
        "nom de l'entité qui reçoit la marchandise et/ou la facture - peut y "
        "avoir un destinataire de livraison et un destinataire de facturation "
        "distincts (blocs typiquement intitulés 'LIVRAISON:'/'Livré à' et "
        "'FACTURATION:'/'Confirmé à'). Si ces deux noms diffèrent, renvoie au "
        "moins le nom associé à la FACTURATION (celui qui reçoit la facture, "
        "pas seulement la marchandise) plutôt que de renvoyer null - ne "
        "renvoie null que si AUCUN nom de destinataire n'est identifiable "
        "nulle part dans le texte."
    ),
    "destinataire_adresse": (
        "adresse(s) du destinataire (facturation et/ou livraison si distinctes). "
        "Une adresse complète inclut la ville ET le code postal, pas seulement "
        "la rue - si ces informations apparaissent ailleurs dans le texte pour "
        "la même entité (même si sur une autre ligne ou dans un autre bloc), "
        "inclus-les dans la valeur renvoyée plutôt que de t'arrêter à la rue. "
        "ATTENTION : comme pour destinataire_nom, si l'adresse de livraison "
        "(bloc 'LIVRAISON:'/'Adresse de livraison') appartient en réalité à "
        "un TRANSITAIRE/transporteur (ex: nom d'une société de fret/logistique "
        "différente du client, ou en lien avec un 'Donneur d'ordre' distinct) "
        "plutôt qu'au client lui-même, ne renvoie PAS cette adresse de "
        "transitaire seule - privilégie l'adresse propre du client "
        "('Donneur d'ordre'/'FACTURATION:'), quitte à donner les deux si "
        "elles sont clairement distinctes et toutes deux disponibles."
    ),
    "numero_document": (
        "numéro de la facture/du document (ex: 'Facture: 91036701', 'N° de "
        "facture', 'N° DE FACTURE', 'N° DE FAC'). Renvoie UNIQUEMENT le "
        "numéro isolé (ex: '91036701'), jamais le mot 'Facture' ou le "
        "libellé qui le précède accolé devant. ATTENTION : ne confonds "
        "JAMAIS avec le numéro de commande/bon de commande ('N° BON DE "
        "CDE', 'Bon de commande', voir reference_commande) - même si ce "
        "numéro de commande est plus court, plus lisible, ou identique sur "
        "toutes les pages (donc visuellement plus fiable qu'un numéro de "
        "facture parfois mal OCRisé et légèrement différent d'une page à "
        "l'autre) - ce n'est PAS le numero_document. En cas de plusieurs "
        "valeurs candidates légèrement différentes pour le numéro de "
        "facture sur des pages différentes (probable bruit OCR), choisis "
        "la valeur qui revient le plus souvent."
    ),
    "date_document": (
        "date d'émission du document (ex: 'Date de facture'). Peut aussi "
        "apparaître SANS libellé explicite, sous la forme d'une mention "
        "traditionnelle de courrier français 'Ville, le JJ/MM/AA' (ex: "
        "'Labège, le 30/07/26') placée près de l'adresse de l'émetteur - "
        "cette forme est aussi valable que 'Date de facture' si aucune autre "
        "date n'est explicitement labellisée comme date d'émission."
    ),
    "reference_commande": (
        "référence de la commande PASSÉE PAR LE CLIENT - PAS un code article, "
        "PAS un numéro de bordereau/BL, PAS un code client. Peut apparaître "
        "sous des libellés variés : 'Votre référence', 'Numéro de commande', "
        "'N° de commande', 'Commande client n°', 'Numéro d'engagement', "
        "'N° BON DE CDE', 'Bon de commande', 'PO number', 'Order reference'. "
        "Peut aussi apparaître sous le seul mot 'REFERENCE' (sans 'Votre'/"
        "'commande'), mais UNIQUEMENT dans un petit bloc d'en-tête à 3 "
        "colonnes du type 'NUMERO | DATE | REFERENCE' (identifiants du "
        "document, proche du numéro de facture et de sa date) - jamais si "
        "'REFERENCE' est la colonne d'un tableau de lignes d'articles à "
        "plusieurs colonnes (Désignation/Qté/Prix/Montant...), où elle "
        "désigne alors un code produit et ne doit PAS être utilisée comme "
        "référence de commande. "
        "EXCLUS explicitement : 'Livraison "
        "client n°', 'Préparation livraison n°' - ce sont des références de "
        "livraison, pas la référence de commande du client, même si le "
        "libellé se ressemble. Renvoie UNIQUEMENT la valeur isolée (le code "
        "après le libellé), jamais toute la ligne de texte brute qui "
        "l'entoure (pas de date ni de code interne collés à la suite) - "
        "MÊME quand le document affiche systématiquement le code suivi d'une "
        "date sur toutes ses références internes (ex: 'Votre référence "
        "B237218 / 26.06.2026' -> renvoie 'B237218' seul, jamais "
        "'B237218 / 26.06.2026', même si ce format code+date est la "
        "convention constante du document pour toutes ses références). "
        "ATTENTION : ne confonds JAMAIS avec 'N° client'/'Code client' - "
        "c'est le numéro de COMPTE du client chez l'émetteur (identifiant "
        "commercial permanent), jamais une référence de commande, même s'il "
        "apparaît bien en évidence près du numéro de facture. Sur certains "
        "documents (ex: factures récapitulatives consolidant plusieurs "
        "commandes/livraisons), la référence de commande n'est pas dans le "
        "bloc d'en-tête mais répétée à l'identique à côté de CHAQUE ligne "
        "d'article, sous un libellé du type 'Votre N° de cde' - une valeur "
        "qui revient à l'identique sur toutes les lignes d'articles est un "
        "signal fort qu'il s'agit de la référence de commande à renvoyer, "
        "même si elle n'apparaît pas dans l'en-tête du document. Peut aussi "
        "apparaître sous la forme d'un simple libellé 'commande' (sans "
        "'Votre'/'N°') en en-tête de colonne d'un petit tableau récapitulatif "
        "près du numéro de facture, avec d'autres colonnes comme 'type', "
        "'du' (date) ou un code d'adresse de facturation - confirmé sur cas "
        "réel."
    ),
    "devise": (
        "devise du montant total (ex: EUR, USD). Peut apparaître comme un "
        "simple code à 3 lettres accolé directement au montant, sans "
        "libellé 'Devise:' explicite (ex: '924,00 EUR') - dans ce cas, "
        "extrais quand même ce code comme devise plutôt que de renvoyer "
        "null."
    ),
    "montant_total": (
        "montant total du document (ex: 'Total TTC', 'Valeur totale', 'Net "
        "à payer'). Un montant s'écrit TOUJOURS avec une partie décimale "
        "(virgule ou point suivi de 1-2 chiffres, ex: '196,90', '4 163.95', "
        "'1.906,21') - ne renvoie JAMAIS un grand nombre entier sans "
        "séparateur décimal (ex: '4112787221') comme montant, même s'il "
        "apparaît dans la même zone/ligne que d'autres informations "
        "financières : un tel nombre est presque certainement un code "
        "interne (référence, identifiant) mal positionné par l'OCR, pas un "
        "montant. Si aucune valeur correctement formatée comme un montant "
        "n'est identifiable, renvoie null plutôt qu'un nombre suspect."
    ),
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
