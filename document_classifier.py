"""
Module 0 - Classification du type de document (port Python fidèle de functions.php)
=====================================================================================
Port ligne à ligne de la fonction PHP s03_detect_page_type_from_text fournie par
l'utilisateur (heuristique par mots-clés et regex pondérés, sans appel IA).

Fidélité : les tables de poids, les seuils minimums et les règles métier sont
recopiés à l'identique du fichier functions.php. Aucune correction silencieuse
n'a été appliquée - voir la note en bas de fichier sur une anomalie repérée
pendant le portage (clé 'Article').
"""

import re
import unicodedata
from typing import Dict, List, Any


def _ascii_lower(text: str) -> str:
    """Équivalent de iconv('UTF-8','ASCII//TRANSLIT//IGNORE') + strtolower en PHP."""
    normalized = unicodedata.normalize("NFKD", text)
    ascii_text = normalized.encode("ascii", "ignore").decode("ascii")
    return ascii_text.lower()


KEYWORD_RULES: Dict[str, Dict[str, int]] = {
    "dangerous_goods_declaration": {
        "adr": 5, "imdg": 6, "iata": 6,
        "dangerous goods": 8, "marchandises dangereuses": 8, "produits dangereux": 8,
        "classe ": 3, "class ": 3,
        "packing group": 5, "groupe emballage": 5,
        "marine pollutant": 6, "polluant marin": 6,
        "point eclair": 4, "flash point": 4,
    },
    "invoice": {
        "facture": 12, "invoice": 12,
        "total ttc": 10, "total ht": 8, "net a payer": 10, "amount due": 10,
        "tva": 6, "vat": 6,
        "echeance": 3, "due date": 3,
    },
    "delivery_note": {
        "bon de livraison": 8, "delivery note": 8, "delivery slip": 7,
        "livraison": 2, "delivered": 2, "ship to": 2,
    },
    "packing_list": {
        "packing list": 8, "colisage": 8,
        "carton": 3, "palette": 3, "pallet": 3,
        "poids brut": 3, "gross weight": 3, "poids net": 3, "net weight": 3,
        "volume": 2,
    },
    "customs_document": {
        "douane": 6, "customs declaration": 8, "declaration douane": 8,
        "mrn": 10, "taric": 6, "hs code": 6, "code douanier": 6,
        "export declaration": 7, "import declaration": 7,
    },
    "commercial_conditions": {
        "conditions generales": 10, "conditions generales de vente": 12,
        "terms and conditions": 10, "CGV": 8, "general terms": 8,
        "conditions de vente": 8, "limitation of liability": 6,
        "retention of title": 6, "clause de reserve de propriete": 6,
        "jurisdiction": 5, "governing law": 5, "tribunal competent": 5,
        "Article": 5,  # NB : voir note de fidélité en bas de fichier
    },
    "transport_document": {
        "transport document": 5, "carrier": 2, "freight": 2, "transporteur": 2,
        "air waybill": 6, "bill of lading": 6, "cmr": 5, "awb": 5,
    },
}

REGEX_RULES: Dict[str, Dict[str, int]] = {
    "dangerous_goods_declaration": {
        r"\bU\s*N\s*\d{4}\b": 5,
        r"\bclasse\s*[0-9](?:\.[0-9])?\b": 3,
        r"\bclass\s*[0-9](?:\.[0-9])?\b": 3,
        r"\bpacking\s+group\s*(I|II|III)\b": 4,
        r"\b(?:GE|PG)\s*(I|II|III)\b": 3,
    },
    "invoice": {
        r"\b(?:facture|invoice)\s*(?:n[o°.]*)?\s*[:\-]?\s*[A-Z0-9\-\/]+": 5,
        r"\b(?:total\s+ttc|total\s+ht|amount\s+due|net\s+a\s+payer)\b": 5,
        r"\b\d{1,3}(?:[ .]\d{3})*(?:[,.]\d{2})\s*(?:€|eur|usd|\$)?\b": 1,
    },
    "delivery_note": {
        r"\b(?:BL|B\/L)\s*[:\-]?\s*[A-Z0-9\-\/]+": 4,
        r"\bbon\s+de\s+livraison\b": 8,
    },
    "packing_list": {
        r"\b\d+\s*(?:cartons?|colis|palettes?|pallets?)\b": 3,
        r"\b\d+(?:[,.]\d+)?\s*(?:kg|kgs|m3|m³|cbm)\b": 2,
    },
    "customs_document": {
        r"\bMRN\s*[:\-]?\s*[A-Z0-9]{10,}\b": 10,
        r"\b(?:HS|TARIC)\s*(?:code)?\s*[:\-]?\s*\d{6,10}\b": 6,
    },
}

MINIMUM_SCORES = {
    "customs_document": 8,
    "commercial_conditions": 6,
    "dangerous_goods_declaration": 5,
}


def s03_detect_page_type_from_text(text: str) -> Dict[str, Any]:
    raw = text
    t = _ascii_lower(text)

    scores = {k: 0 for k in [
        "dangerous_goods_declaration", "invoice", "delivery_note",
        "packing_list", "customs_document", "commercial_conditions",
        "transport_document",
    ]}
    signals: Dict[str, List[Dict[str, Any]]] = {}

    for doc_type, rules in KEYWORD_RULES.items():
        for needle, weight in rules.items():
            if needle in t:
                scores[doc_type] += weight
                signals.setdefault(doc_type, []).append({
                    "kind": "keyword", "value": needle, "weight": weight, "count": 1,
                })

    for doc_type, rules in REGEX_RULES.items():
        for pattern, weight in rules.items():
            matches = re.findall(pattern, raw, flags=re.IGNORECASE)
            count = len(matches)
            if count > 0:
                gain = weight * count
                scores[doc_type] += gain
                signals.setdefault(doc_type, []).append({
                    "kind": "regex", "value": pattern, "weight": weight,
                    "count": count, "gain": gain,
                })

    # Bonus de cohérence DG : UN + IMDG/ADR/marine pollutant
    if scores["dangerous_goods_declaration"] > 0 and (
        "imdg" in t or "adr" in t or "marine pollutant" in t
    ):
        scores["dangerous_goods_declaration"] += 5
        signals.setdefault("dangerous_goods_declaration", []).append({
            "kind": "combo", "value": "DG coherent signals", "weight": 5, "count": 1,
        })

    ranking = sorted(
        ({"document_type": k, "score": v} for k, v in scores.items()),
        key=lambda x: x["score"], reverse=True,
    )

    invoice_score = scores.get("invoice", 0)
    delivery_score = scores.get("delivery_note", 0)
    if delivery_score > 0 and invoice_score > 0 and invoice_score >= delivery_score * 0.75:
        scores["invoice"] += 5
        signals.setdefault("invoice", []).append({
            "kind": "business_rule",
            "value": "prefer_invoice_over_delivery_note_when_ambiguous",
            "weight": 5, "count": 1, "gain": 5,
        })
        ranking = sorted(
            ({"document_type": k, "score": v} for k, v in scores.items()),
            key=lambda x: x["score"], reverse=True,
        )

    best_type = ranking[0]["document_type"] if ranking else "unknown"
    best_score = int(ranking[0]["score"]) if ranking else 0
    second_score = int(ranking[1]["score"]) if len(ranking) > 1 else 0

    if best_type in MINIMUM_SCORES and best_score < MINIMUM_SCORES[best_type]:
        return {
            "document_type": "unknown", "confidence": 0, "score": best_score,
            "second_score": second_score, "signals": signals.get(best_type, []),
            "ranking": ranking,
            "reason": f"Best score below minimum threshold for {best_type}",
        }

    if best_score <= 0:
        return {
            "document_type": "unknown", "confidence": 0, "score": 0,
            "signals": [], "ranking": ranking,
            "reason": "No known document type signal detected",
        }

    confidence = 0.5
    if (best_score + second_score) > 0:
        confidence = best_score / (best_score + second_score)
    confidence = max(0.35, min(0.98, confidence))

    return {
        "document_type": best_type,
        "confidence": round(confidence, 2),
        "score": best_score,
        "second_score": second_score,
        "signals": signals.get(best_type, []),
        "ranking": ranking,
        "reason": f"Best score: {best_score}, second score: {second_score}",
    }


# --- Note de fidélité --------------------------------------------------
# Dans functions.php, la clé de mot-clé 'Article' (majuscule) est comparée
# à $t qui est déjà passé en minuscules (strtolower) juste avant. Résultat :
# ce mot-clé ne matche jamais dans le code d'origine, silencieusement.
# Reproduit à l'identique ici (clé "Article" dans commercial_conditions)
# pour rester fidèle au comportement actuel. À confirmer avec l'utilisateur
# si c'est un bug à corriger (probable) ou un choix volontaire.
