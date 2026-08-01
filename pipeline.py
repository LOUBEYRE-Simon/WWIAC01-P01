"""
Pipeline bout-en-bout - prototype v0 (+ orchestration CLI : job, logs, sorties fichiers)
==========================================================================================
Assemble les 4 modules :
  0. OCR / extraction locale (ocr_extraction.py)
  1. Classification du type de document (document_classifier.py)
  2. Détection d'entités + pseudonymisation réversible (entity_anonymizer.py)
  3. Interface de transmission à l'IA externe (ai_dispatch.py, stub)

process_pdf() : logique métier inchangée, produit un rapport JSON par document,
avec séparation claire entre ce qui est exportable (texte anonymisé, type,
confiance) et ce qui doit rester strictement local (entity_mapping).

Ajouts de cette version (couche orchestration/CLI, n'affecte pas process_pdf) :
  - CLI avec --job-id / --output-dir / --emit-* pour un appel depuis PHP (proc_open).
  - Log de progression NDJSON (une ligne JSON par étape), flush immédiat pour
    lecture en direct par un script de suivi pendant que le traitement tourne.
    Attention : ce log ne doit JAMAIS contenir de texte de document ni de valeur
    d'entité détectée - uniquement des métadonnées techniques (voir StepLogger).
  - Écriture atomique des fichiers de sortie (write .tmp puis rename) pour
    qu'un lecteur concurrent ne voie jamais un fichier à moitié écrit.
  - Manifeste final (stdout) listant les fichiers générés, avec un marquage
    explicite des fichiers sensibles (jamais à transmettre à l'extérieur).
  - --cleanup-only : purge des fichiers de job plus vieux que --max-age-days,
    à appeler périodiquement (cron) pour éviter l'accumulation indéfinie.

Limite assumée : process_pdf() reste une unité de traitement unique (OCR +
classification + anonymisation + dispatch par page, dans une seule fonction
déjà testée). Une erreur à n'importe quelle étape interne remonte donc sous
un seul code de sortie (EXIT_PROCESSING) plutôt qu'un code par module - un
découpage plus fin nécessiterait de modifier process_pdf() elle-même, ce
qu'on évite ici pour ne pas remettre en cause une logique déjà validée.

Testé de bout en bout sur FEX-DOC-000002036320.pdf (job-id invalide rejeté
proprement, cas nominal avec les 3 --emit-*, purge --cleanup-only).
"""

import json
import os
import re
import sys
import time
import argparse
import traceback
from typing import Dict, Any

from ocr_extraction import extract_pdf
from document_classifier import s03_detect_page_type_from_text
from entity_anonymizer import anonymize_text
from ai_dispatch import dispatch_to_external_ai

EXIT_USAGE = 1
EXIT_PROCESSING = 2

JOB_ID_PATTERN = re.compile(r"^[A-Za-z0-9_-]{1,64}$")


def normalize_whitespace(text: str) -> str:
    """
    Étape de nettoyage ajoutée après test : les longues séquences d'espaces
    issues de mises en page tabulaires (natives ou OCR) perturbent fortement
    la reconnaissance d'entités de Presidio/spaCy (des noms d'organisation
    entiers passent inaperçus). Normaliser AVANT anonymisation restaure la
    détection (vérifié empiriquement sur un document réel du prototype).
    """
    text = re.sub(r"[ \t]{2,}", " ", text)
    text = re.sub(r"\n{2,}", "\n", text)
    return text


def process_pdf(pdf_path: str) -> Dict[str, Any]:
    pages_report = []

    for page in extract_pdf(pdf_path):
        cleaned_text = normalize_whitespace(page.text)
        classification = s03_detect_page_type_from_text(cleaned_text)
        anonymization = anonymize_text(cleaned_text)
        dispatch_preview = dispatch_to_external_ai(
            anonymized_content=anonymization["anonymized_text"],
            document_type=classification["document_type"],
            confidence=classification["confidence"],
        )

        pages_report.append({
            "page_number": page.page_number,
            "extraction": {
                "source": page.source,
                "engine": page.engine,
                "char_count": len(page.text),
            },
            "classification": {
                "document_type": classification["document_type"],
                "confidence": classification["confidence"],
                "score": classification["score"],
                "second_score": classification["second_score"],
            },
            # -- Exportable vers l'extérieur --
            "anonymized_text": anonymization["anonymized_text"],
            "dispatch_preview": dispatch_preview,
            # -- STRICTEMENT LOCAL, ne jamais transmettre --
            "_local_only_entity_mapping": anonymization["entity_mapping"],
            "_local_only_entities_detected": anonymization["entities_detected"],
        })

    return {"file": pdf_path.split("/")[-1], "pages": pages_report}


# ---------------------------------------------------------------------------
# Orchestration CLI : job_id, logging de progression, sorties fichiers
# ---------------------------------------------------------------------------

class StepLogger:
    """
    Écrit une ligne JSON (NDJSON) par étape, avec flush + fsync immédiats pour
    qu'un script de suivi puisse lire le fichier pendant que le traitement
    tourne encore (pas seulement une fois le job terminé).

    Chaque ligne sépare les champs techniques (step, status, elapsed_ms...)
    du message destiné à l'utilisateur final (message_fr) - à toi de décider
    côté PHP ce qui est effectivement affiché.

    IMPORTANT : ne jamais passer de texte de document ou de valeur d'entité
    détectée à log() - uniquement des métadonnées (compteurs, types, durées).
    """

    def __init__(self, log_path: str, step_total: int):
        self.step_total = step_total
        self.step_index = 0
        self.f = open(log_path, "a", buffering=1, encoding="utf-8")

    def log(self, step: str, status: str, message_fr: str = "", **extra):
        if status == "started":
            self.step_index += 1
        entry = {
            "ts": time.strftime("%Y-%m-%dT%H:%M:%S"),
            "step": step,
            "status": status,          # "started" | "done" | "error"
            "step_index": self.step_index,
            "step_total": self.step_total,
            "message_fr": message_fr,
        }
        entry.update(extra)
        self.f.write(json.dumps(entry, ensure_ascii=False) + "\n")
        self.f.flush()
        os.fsync(self.f.fileno())

    def close(self):
        self.f.close()


def _atomic_write_text(path: str, content: str) -> None:
    """Écrit dans un fichier .tmp puis rename() atomique - évite qu'un lecteur
    concurrent (le script de suivi PHP) ne lise un fichier à moitié écrit."""
    tmp_path = path + ".tmp"
    with open(tmp_path, "w", encoding="utf-8") as f:
        f.write(content)
    os.replace(tmp_path, path)


def _atomic_write_json(path: str, data: Any) -> None:
    _atomic_write_text(path, json.dumps(data, ensure_ascii=False, indent=2))


def cleanup_old_jobs(output_dir: str, max_age_days: int = 30) -> int:
    """
    Maintenance : supprime les fichiers de job (logs + sorties) plus vieux que
    max_age_days. À appeler périodiquement (cron), pas à chaque traitement -
    voir --cleanup-only. Renvoie le nombre de fichiers supprimés.
    """
    if not os.path.isdir(output_dir):
        return 0
    cutoff = time.time() - max_age_days * 86400
    removed = 0
    for name in os.listdir(output_dir):
        path = os.path.join(output_dir, name)
        try:
            if os.path.isfile(path) and os.path.getmtime(path) < cutoff:
                os.remove(path)
                removed += 1
        except OSError:
            continue
    return removed


def run_job(
    file_path: str,
    job_id: str,
    output_dir: str,
    emit_text: bool,
    emit_entities: bool,
    emit_ai_result: bool,
) -> Dict[str, Any]:
    os.makedirs(output_dir, exist_ok=True)
    log_path = os.path.join(output_dir, f"{job_id}.log.ndjson")
    logger = StepLogger(log_path, step_total=2)
    files: Dict[str, str] = {"log": log_path}

    try:
        # --- Étape 1 : traitement complet (OCR/texte natif, classification, anonymisation) ---
        logger.log("traitement", "started",
                   message_fr="Extraction, classification et anonymisation en cours")
        t0 = time.time()
        report = process_pdf(file_path)
        elapsed_ms = int((time.time() - t0) * 1000)

        sources = sorted({p["extraction"]["source"] for p in report["pages"]})
        doc_types = [p["classification"]["document_type"] for p in report["pages"]]
        total_entities = sum(len(p["_local_only_entities_detected"]) for p in report["pages"])

        logger.log("traitement", "done", elapsed_ms=elapsed_ms,
                   pages_count=len(report["pages"]), sources=sources,
                   document_types=doc_types, entities_count=total_entities,
                   message_fr=f"{len(report['pages'])} page(s) traitee(s), "
                              f"{total_entities} entite(s) au total")

        # --- Étape 2 : écriture des fichiers de sortie demandés ---
        logger.log("ecriture_sorties", "started",
                   message_fr="Enregistrement des fichiers de sortie")
        t0 = time.time()

        if emit_text:
            text_path = os.path.join(output_dir, f"{job_id}.text.txt")
            full_text = "\n\n----- SAUT DE PAGE -----\n\n".join(
                p["anonymized_text"] for p in report["pages"]
            )
            _atomic_write_text(text_path, full_text)
            files["texte_anonymise"] = text_path  # exportable

        if emit_entities:
            entities_path = os.path.join(output_dir, f"{job_id}.entities.json")
            sensitive_payload = [
                {
                    "page_number": p["page_number"],
                    "entities_detected": p["_local_only_entities_detected"],
                    "entity_mapping": p["_local_only_entity_mapping"],
                }
                for p in report["pages"]
            ]
            _atomic_write_json(entities_path, sensitive_payload)
            # Nom de clé volontairement explicite : ce fichier contient les valeurs
            # d'origine en clair (entity_mapping) - ne doit jamais sortir du serveur.
            files["entity_mapping_SENSIBLE_LOCAL_UNIQUEMENT"] = entities_path

        if emit_ai_result:
            ai_path = os.path.join(output_dir, f"{job_id}.ai_result.json")
            ai_payload = [
                {"page_number": p["page_number"], "dispatch_preview": p["dispatch_preview"]}
                for p in report["pages"]
            ]
            _atomic_write_json(ai_path, ai_payload)
            files["ai_result"] = ai_path  # exportable (contenu déjà anonymisé par construction)

        logger.log("ecriture_sorties", "done", elapsed_ms=int((time.time() - t0) * 1000),
                   message_fr="Fichiers de sortie enregistres")
        logger.log("pipeline", "done", message_fr="Traitement termine")
        logger.close()

        return {
            "ok": True,
            "job_id": job_id,
            "file": report["file"],
            "pages_count": len(report["pages"]),
            "document_types": doc_types,
            "entities_count": total_entities,
            "files": files,
        }

    except Exception as e:
        logger.log("pipeline", "error", message_fr="Echec du traitement", error=str(e))
        logger.close()
        raise


def main():
    parser = argparse.ArgumentParser(description="Pipeline PII bout-en-bout (OCR/texte, classification, anonymisation)")
    parser.add_argument("file_path", nargs="?", help="Chemin du PDF a traiter")
    parser.add_argument("--job-id", help="Identifiant unique du job (alphanumerique/-/_, 64 car. max)")
    parser.add_argument("--output-dir", help="Dossier ou ecrire le log et les fichiers de sortie")
    parser.add_argument("--emit-text", action="store_true", help="Ecrit le texte anonymise (par page)")
    parser.add_argument("--emit-entities", action="store_true",
                         help="Ecrit les entites detectees + la table de correspondance (SENSIBLE)")
    parser.add_argument("--emit-ai-result", action="store_true", help="Ecrit l'apercu de dispatch IA")
    parser.add_argument("--cleanup-only", action="store_true",
                         help="Ne traite rien : purge les fichiers de job plus vieux que --max-age-days et quitte")
    parser.add_argument("--max-age-days", type=int, default=30)
    args = parser.parse_args()

    if args.cleanup_only:
        if not args.output_dir:
            print(json.dumps({"ok": False, "error": "--output-dir requis avec --cleanup-only"}), file=sys.stderr)
            sys.exit(EXIT_USAGE)
        removed = cleanup_old_jobs(args.output_dir, args.max_age_days)
        print(json.dumps({"ok": True, "cleanup": True, "removed": removed}))
        sys.exit(0)

    if not args.file_path or not args.job_id or not args.output_dir:
        print(json.dumps({"ok": False, "error": "file_path, --job-id et --output-dir sont requis"}), file=sys.stderr)
        sys.exit(EXIT_USAGE)

    if not JOB_ID_PATTERN.match(args.job_id):
        print(json.dumps({
            "ok": False,
            "error": "job-id invalide (attendu : alphanumerique, tiret, underscore, 64 caracteres max)",
        }), file=sys.stderr)
        sys.exit(EXIT_USAGE)

    try:
        result = run_job(
            args.file_path, args.job_id, args.output_dir,
            args.emit_text, args.emit_entities, args.emit_ai_result,
        )
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(0)
    except Exception as e:
        print(json.dumps({"ok": False, "job_id": args.job_id, "error": str(e)}), file=sys.stderr)
        traceback.print_exc(file=sys.stderr)
        sys.exit(EXIT_PROCESSING)


if __name__ == "__main__":
    main()
