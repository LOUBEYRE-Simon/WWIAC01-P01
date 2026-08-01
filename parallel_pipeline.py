"""
Extension multi-pages avec traitement parallèle
==================================================
Répond à deux besoins : traiter un PDF de plusieurs pages, et paralléliser
ce qui peut l'être sans casser la cohérence de la pseudonymisation.

Principe retenu (trois étages, pas un seul bloc parallèle) :

  Étage A - extraction + classification, PAR PAGE, en parallèle
            (indépendant d'une page à l'autre, pas de recomposition
            de mise en page requise, aucun modèle lourd à charger).

  Étage B - regroupement séquentiel des pages consécutives de même type
            en "documents logiques" (une facture de 2 pages doit rester
            un seul document, pas deux). Étape rapide, pas parallélisable
            utilement (elle dépend de l'ordre des pages).

  Étage C - anonymisation PAR DOCUMENT LOGIQUE (pas par page isolée),
            en parallèle entre documents différents. La table
            entity_mapping doit être partagée entre les pages d'UN MEME
            document (pour qu'un même émetteur garde le même jeton d'une
            page à l'autre), mais ne doit surtout pas être partagée entre
            deux documents différents détectés dans le même fichier -
            mélanger les tables de deux documents sans rapport serait une
            confusion, pas un gain.

Le modèle spaCy/Presidio est coûteux à charger (voir 03-architecture-
technique.md) : chaque worker du pool de l'étage C le charge UNE SEULE
fois via un initializer, pas à chaque document traité.
"""

import os
from concurrent.futures import ProcessPoolExecutor, as_completed
from dataclasses import dataclass
from typing import List, Dict, Any

from ocr_extraction import extract_pdf, extract_single_page, PageExtraction
from document_classifier import s03_detect_page_type_from_text

# Rempli une fois par processus worker (voir _init_worker) pour éviter de
# recharger le modèle NLP à chaque document.
_worker_state: Dict[str, Any] = {}


def _init_anonymizer_worker():
    """Initializer du pool : charge le modèle Presidio/spaCy UNE FOIS par worker."""
    import entity_anonymizer  # le chargement du modèle a lieu à l'import
    _worker_state["anonymize_text"] = entity_anonymizer.anonymize_text


# ---------------------------------------------------------------------
# Étage A - extraction + classification par page, en parallèle
# ---------------------------------------------------------------------

def _extract_and_classify_one_page(args):
    pdf_path, page_number = args
    # Corrigé : chaque worker ne traite QUE sa page assignée (extract_single_page),
    # plutôt que de relancer l'OCR de tout le fichier (bug de la 1ere version -
    # ça triplait le travail OCR au lieu de le paralléliser).
    page = extract_single_page(pdf_path, page_number)
    classification = s03_detect_page_type_from_text(page.text)
    return page, classification


def extract_and_classify_parallel(pdf_path: str, max_workers: int = None) -> List[Dict[str, Any]]:
    import subprocess
    info = subprocess.run(["pdfinfo", pdf_path], capture_output=True, text=True).stdout
    nb_pages = 1
    for line in info.splitlines():
        if line.startswith("Pages:"):
            nb_pages = int(line.split(":")[1].strip())

    results = [None] * nb_pages
    with ProcessPoolExecutor(max_workers=max_workers or os.cpu_count()) as pool:
        futures = {
            pool.submit(_extract_and_classify_one_page, (pdf_path, n)): n
            for n in range(1, nb_pages + 1)
        }
        for future in as_completed(futures):
            page, classification = future.result()
            results[page.page_number - 1] = {"page": page, "classification": classification}

    return results


# ---------------------------------------------------------------------
# Étage B - regroupement séquentiel en documents logiques
# ---------------------------------------------------------------------

@dataclass
class LogicalDocument:
    document_type: str
    page_numbers: List[int]
    combined_text: str


def group_pages_into_documents(page_results: List[Dict[str, Any]],
                                min_confidence_for_new_boundary: float = 0.5) -> List[LogicalDocument]:
    """
    Règle simple de regroupement : une nouvelle page démarre un nouveau
    document logique seulement si son type diffère du précédent ET que la
    confiance est suffisante ; sinon elle est rattachée au document en
    cours (cas d'une page "unknown" ou peu sûre au milieu d'un document,
    par exemple une page de conditions générales insérée dans une facture).
    """
    documents: List[LogicalDocument] = []

    for entry in page_results:
        page: PageExtraction = entry["page"]
        classification = entry["classification"]
        doc_type = classification["document_type"]
        confidence = classification["confidence"]

        starts_new_document = (
            not documents
            or (doc_type != documents[-1].document_type and confidence >= min_confidence_for_new_boundary)
        )

        if starts_new_document:
            documents.append(LogicalDocument(
                document_type=doc_type,
                page_numbers=[page.page_number],
                combined_text=page.text,
            ))
        else:
            documents[-1].page_numbers.append(page.page_number)
            documents[-1].combined_text += "\n" + page.text

    return documents


# ---------------------------------------------------------------------
# Étage C - anonymisation par document logique, en parallèle
# ---------------------------------------------------------------------

def _anonymize_one_document(doc: LogicalDocument) -> Dict[str, Any]:
    anonymize_text = _worker_state["anonymize_text"]
    result = anonymize_text(doc.combined_text)
    return {
        "document_type": doc.document_type,
        "page_numbers": doc.page_numbers,
        "anonymized_text": result["anonymized_text"],
        "_local_only_entity_mapping": result["entity_mapping"],
    }


def anonymize_documents_parallel(documents: List[LogicalDocument], max_workers: int = None) -> List[Dict[str, Any]]:
    with ProcessPoolExecutor(
        max_workers=max_workers or os.cpu_count(),
        initializer=_init_anonymizer_worker,
    ) as pool:
        return list(pool.map(_anonymize_one_document, documents))


# ---------------------------------------------------------------------
# Orchestration complète
# ---------------------------------------------------------------------

def process_pdf_parallel(pdf_path: str) -> List[Dict[str, Any]]:
    page_results = extract_and_classify_parallel(pdf_path)
    documents = group_pages_into_documents(page_results)
    return anonymize_documents_parallel(documents)


if __name__ == "__main__":
    import sys
    import json
    import time

    t0 = time.time()
    all_docs = []
    for path in sys.argv[1:]:
        all_docs.extend(process_pdf_parallel(path))
    elapsed = time.time() - t0

    print(json.dumps(all_docs, ensure_ascii=False, indent=2))
    print(f"\n--- {len(all_docs)} document(s) logique(s), traité en {elapsed:.1f}s "
          f"sur {os.cpu_count()} coeurs disponibles ---", file=sys.stderr)
