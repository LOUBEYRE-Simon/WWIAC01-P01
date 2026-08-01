# Prototype v0 — pipeline exécutable

Premier prototype fonctionnel des 4 modules discutés dans le dossier d'exploration (`../01` à `../04`), testé de bout en bout sur les trois PDF fournis (`FEX-DOC-000000780647.pdf` : facture, `log_6a61c58ccb565-0.pdf` : bon de livraison, `UT-DOC-000002336254.pdf` : déclaration de matières dangereuses).

## Modules

| Fichier | Rôle |
|---|---|
| `ocr_extraction.py` | Module 1 : extraction locale (texte natif via `pdftotext`, sinon rendu image + OCR Tesseract). Remplace GCP Vision. |
| `document_classifier.py` | Port Python fidèle de `functions.php` (classification par mots-clés et regex pondérés). |
| `entity_anonymizer.py` | Détection d'entités + pseudonymisation réversible via Microsoft Presidio (opérateur personnalisé, table `entity_mapping` locale). |
| `ai_dispatch.py` | Interface de transmission à une IA externe — stub, aucun appel réseau réel. |
| `pipeline.py` | Orchestrateur bout-en-bout (mono-page/mono-processus), produit un rapport JSON par document. |
| `parallel_pipeline.py` | Variante multi-pages avec parallélisation (3 étages : extraction/classification par page en parallèle, regroupement séquentiel en documents logiques, anonymisation par document en parallèle). Voir `../03-architecture-technique.md`. |
| `tessdata/` | Modèles de langue Tesseract (français + anglais) embarqués, pour un environnement où le paquet système ne fournit que l'anglais. |
| `api_server.py` | Point d'entrée HTTP de test (`POST /process-page`) : PDF ou image en base64 → JSON complet en un seul appel. Utile pour des tests rapides via curl, mais **ce n'est pas le mode retenu pour la production** (voir section CLI ci-dessous). |
| `step1_split_pdf.py` à `step6_ollama_lines.py` | Scripts CLI, un par étape du pipeline, appelés individuellement par le PHP via `exec`/`proc_open`. C'est le mode d'intégration retenu en production. Voir section dédiée. |
| `cli_common.py` | Contrat commun aux scripts CLI (JSON sur stdin, JSON sur stdout, gestion d'erreur uniforme). |
| `ollama_client.py` | Client HTTP minimal pour Ollama local (`/api/chat`, `format="json"`), utilisé par step5 et step6. |
| `pipeline_orchestrator.php` | Squelette d'orchestration PHP : récupère le PDF par URL (wget), boucle sur les pages, enchaîne les 6 scripts CLI via `proc_open`, agrège le résultat. À adapter à l'architecture PHP existante. |
| `mock_ollama_server.py` | Serveur Flask factice reproduisant la forme de l'API Ollama - utilisé uniquement pour valider step5/step6 dans cet environnement de prototypage (Ollama réel non installable ici). Ne remplace pas un test contre le vrai minicpm-v4.5. |

## API de test (`api_server.py`)

Lancement : `python3 api_server.py` (écoute sur le port 5005, choisi pour ne pas entrer en conflit avec Ollama sur 11434 ou les endpoints Presidio natifs sur 5001/5002).

`POST /process-page` accepte deux formats d'entrée :
- `{"pdf_base64": "...", "page_number": 1}` (**recommandé en production**) : le serveur tente `pdftotext` en premier (instantané, fiable sur PDF natif), et ne bascule sur le rendu image + OCR que si le texte natif est absent ou trop pauvre (PDF scanné).
- `{"image_base64": "..."}` (legacy/tests unitaires) : passe directement par l'OCR Tesseract, car il n'y a pas de PDF source à interroger. **À ne pas utiliser en production** si les pages proviennent de PDF natifs : convertir systématiquement en image avant l'appel ferait perdre le chemin rapide `pdftotext`, même sur des factures jamais scannées.

Réponse : `status`, `extraction_source` (`native_text` ou `ocr` - permet de vérifier quel chemin a été emprunté), `extraction_engine` (`pdftotext` ou `tesseract(fra+eng)`), `document_type` + `document_type_confidence`, `raw_text`, `entities_detected` (liste Presidio : type, position, score, valeur réelle), `anonymized_text`.

**Point de sécurité à ne pas perdre de vue :** cette réponse contient volontairement les valeurs réelles (`raw_text`, `entities_detected`) pour permettre de valider le pipeline pendant les tests. Cet endpoint ne doit tourner qu'en local/réseau de confiance. Seul le champ `anonymized_text` doit être transmis à une IA externe (Ollama ou autre) - jamais `raw_text` ni `entities_detected` tels quels.

Temps de réponse observés (tests réels) : ~0.08 s pour un PDF natif via `pdftotext` ; ~6 s pour une page scannée à 150 DPI via OCR ; à 300 DPI (recommandé en production pour la qualité OCR), prévoir plutôt 10-15 s par page OCR - à refléter dans le timeout du client curl PHP côté appelant.

## Architecture retenue en production : scripts CLI + orchestration PHP

Après discussion, l'architecture réelle n'est pas un serveur HTTP unique mais un enchaînement de scripts Python indépendants, chacun lancé par le PHP via `exec`/`proc_open` (un process par étape). C'est `pipeline_orchestrator.php` qui illustre cet enchaînement.

Étapes, dans l'ordre :

1. **`step1_split_pdf.py`** - reçoit `{"pdf_path": "..."}`, renvoie `{"nb_pages": N}`. Ne matérialise pas de fichiers séparés : `pdftotext`/`pdftoppm` savent déjà cibler une page précise du PDF d'origine (options `-f`/`-l`).
2. **`step2_extract_page.py`** - reçoit `{"pdf_path": "...", "page_number": N}`, tente `pdftotext` puis bascule sur l'OCR Tesseract si besoin. Renvoie `{"source", "engine", "text"}`.
3. **`step3_anonymize.py`** - reçoit `{"text": "..."}`, renvoie le texte anonymisé Presidio + `entities_detected` + `entity_mapping` (donnée la plus sensible du pipeline - à ne jamais transmettre à l'extérieur, y compris à Ollama).
4. **`step4_classify.py`** - reçoit `{"text": "..."}`, renvoie le type de document (`document_type`, `confidence`). Fonctionne indifféremment sur texte brut ou anonymisé.
5. **`step5_ollama_header.py`** - reçoit `{"text": "...", "document_type": "..."}`, appelle Ollama local (`minicpm-v4.5:latest` par défaut) pour identifier émetteur/destinataire/en-tête. **Reçoit le texte BRUT** (décision actée : Ollama tourne 100% en local, l'anonymisation ne protège donc pas cette étape mais sert de trace/audit en parallèle).
6. **`step6_ollama_lines.py`** - reçoit `{"text": "...", "document_type": "..."}`, extrait les lignes de détail (référence, quantité, prix...) via Ollama, **seulement si `document_type` est éligible** (`invoice`, `delivery_note`, `packing_list` - configurable dans `LINE_TYPES_ELIGIBLE`). Renvoie `{"lines": [], "skipped": true, "reason": "..."}` sinon, sans erreur.

Contrat commun (voir `cli_common.py`) : chaque script lit un objet JSON unique sur stdin, écrit un objet JSON unique sur stdout avec une clé `status` (`"ok"` ou `"error"`), et sort avec le code 0 ou 1 en conséquence. Le PHP peut donc vérifier indifféremment le code de sortie ou le champ `status`.

**Coût de démarrage mesuré** (choix assumé du mode CLI plutôt qu'un service qui reste chargé) :
- `step1`/`step4` : quasi instantané.
- `step2` : ~0.1 s (PDF natif) à ~6-15 s (OCR, selon DPI).
- `step3` : ~4-5 s, dont la majorité est le chargement du modèle spaCy à chaque appel - c'est le principal surcoût du mode "un process par étape".
- `step5`/`step6` : dépend entièrement du temps de réponse d'Ollama/minicpm-v4.5 sur la machine cible - à mesurer avec le vrai modèle, aucune données fiables disponibles depuis cet environnement de prototypage (Ollama n'a pas pu être installé ici, voir note ci-dessous).

**Limite de validation à connaître :** `step5`/`step6` ont été testés contre `mock_ollama_server.py`, un faux serveur Flask qui reproduit exactement la forme JSON de l'API Ollama (`/api/chat`, champ `message.content`) - cela valide la construction de la requête, le parsing de la réponse et la gestion d'erreur (timeout, connexion refusée, JSON invalide), mais **pas** la qualité réelle des réponses de minicpm-v4.5 sur vos documents. Ollama n'a pas pu être installé dans cet environnement de prototypage (pas d'accès root, script d'installation officiel nécessitant sudo). Premier test à faire côté utilisateur avant mise en production :
```bash
echo '{"text": "<texte extrait d une vraie facture>", "document_type": "invoice"}' | python3 step5_ollama_header.py
```

Exemple d'appel PHP réel de l'étape 5, isolé :
```php
$result = call_python_step('step5_ollama_header.py', [
    'text' => $rawText,
    'document_type' => 'invoice',
], 120);
// $result['header']['emetteur_nom'], $result['header']['destinataire_nom'], ...
```

`pipeline_orchestrator.php` n'a pas pu être vérifié avec un interpréteur PHP réel dans cet environnement (PHP non installé, pas d'accès root pour l'installer) - la syntaxe a été relue manuellement (accolades, points-virgules, types) mais un `php -l pipeline_orchestrator.php` côté utilisateur avant intégration est recommandé.

## Point d'entrée GET pour tests rapides (`test_get_endpoint.php`)

Pour éviter de construire un corps JSON à chaque test manuel, ce script accepte l'URL du PDF encodée en base64 directement dans le GET :

```
test_get_endpoint.php?fic=aHR0cHM6Ly9lZGktZXhwbG9pdGF0aW9uLmdyb3VwZXNpZmEuY29tL2dlZC9TQUZFWC8yMDI2MDczMS9GRVgtRE9DLTAwMDAwMDc4MzYxNS5wZGY=
```

Il décode `fic`, valide que le résultat est une URL, puis appelle `process_document()` (défini dans `pipeline_orchestrator.php`, qu'il inclut) et renvoie le JSON complet.

Le round-trip base64 → décodage → `wget` → découpage → extraction → classification a été testé de bout en bout (fichier servi en local dans l'environnement de prototypage, `pdftotext` utilisé, type détecté `invoice` à 0.9 de confiance) - seule la partie PHP proprement dite (décodage du GET, appel de `process_document()`) n'a pas pu être rejouée faute d'interpréteur PHP disponible ici.

**Réservé aux tests, pas à la production** : le base64 n'est pas un chiffrement (URL/nom de fichier lisibles dans les logs d'accès, l'historique navigateur, les caches), et un GET est plus facilement mis en cache ou rejoué qu'un POST. Pour la production, préférer un vrai POST JSON (exemple en bas de `pipeline_orchestrator.php`).

## Dépendances à installer

```
pip install presidio-analyzer presidio-anonymizer spacy pytesseract pillow pdf2image
python -m spacy download fr_core_news_md
```

Modèle de langue Tesseract français (`fra.traineddata`) à placer dans un dossier pointé par la variable d'environnement `TESSDATA_PREFIX` si le paquet système ne le fournit pas (cas rencontré dans cet environnement de test).

## Résultats du test sur les 3 documents fournis

La classification du type de document (module 0/1) a été correcte sur les trois documents, avec un bon niveau de confiance : facture détectée en `invoice` (confiance 0,79), bon de livraison en `delivery_note` (0,62), déclaration de matières dangereuses en `dangerous_goods_declaration` (0,82). Le port Python de ta fonction PHP se comporte donc comme attendu.

L'anonymisation, en revanche, n'est pas encore fiable à 100 % et ne doit pas être utilisée telle quelle pour un envoi externe sans relecture humaine — conformément à la recommandation déjà posée dans `../03-architecture-technique.md`. Deux constats concrets issus de ce test :

**Découverte utile, déjà corrigée dans le pipeline :** les séquences de longs espaces provenant des mises en page tabulaires (natives comme OCR) perturbaient fortement la détection d'entités — un nom d'organisation entier (« B2B PHARMA SAS ») restait invisible pour Presidio/spaCy tant que le texte n'était pas normalisé. Une étape de normalisation des espaces avant anonymisation a été ajoutée dans `pipeline.py` et a restauré la détection de cette entité, vérifié empiriquement.

**Limite non résolue à ce stade :** malgré cette correction, des faux négatifs subsistent — par exemple le nom du destinataire (« PHARMACIE DE LA PROVIDENCE ») n'est toujours pas détecté sur le bon de livraison, et le texte issu de l'OCR sur les deux documents scannés reste par endroits bruité, ce qui peut à la fois masquer une entité réelle ou en faire apparaître une fausse. Le modèle de langue utilisé ici (`fr_core_news_md`, un modèle spaCy générique) n'est pas spécialisé sur le vocabulaire logistique — c'est exactement le chantier de spécialisation verticale déjà identifié comme nécessaire dans `../03-architecture-technique.md`.

## Prochaines étapes suggérées

Ajouter des reconnaisseurs Presidio personnalisés pour des motifs métier récurrents (suffixes de raison sociale : SAS, SARL, SA... ; mots-déclencheurs de nom de destinataire : PHARMACIE, CLINIQUE...) plutôt que de compter uniquement sur la détection générique. Tester un modèle spaCy plus large (`fr_core_news_lg`) pour voir si le gain de précision justifie le coût mémoire supplémentaire. Comparer avec un moteur OCR à meilleure structuration de mise en page (PaddleOCR/Surya, voir `../03-architecture-technique.md`) sur les deux documents scannés, pour voir si un texte moins bruité en amont améliore mécaniquement l'anonymisation en aval. Et, tant que ces points ne sont pas stabilisés, garder une relecture humaine obligatoire avant toute transmission réelle à une IA externe.
