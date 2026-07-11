<?php
declare(strict_types=1);

/**
 * index.php — Test en ligne d'Ollama (chat + streaming) en PHP 8.3
 *
 * Utilisation:
 *  - Ouvrir la page dans votre navigateur pour le formulaire.
 *  - Le backend proxifie /api/chat d’Ollama et gère le streaming.
 */

if (($_GET['action'] ?? '') === 'chat') {
    chatProxy();
    exit;
}

function chatProxy(): void
{
    // Récup input JSON
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    $ollamaHost = rtrim($in['host'] ?? 'http://localhost:11434', '/');
    $model      = (string)($in['model'] ?? 'llama3.1:8b');
    $method     = (string)($in['method'] ?? 'chat');
    $userPrompt = (string)($in['user']  ?? '');
    $system     = trim((string)($in['system'] ?? ''));
    $stream     = (bool)($in['stream'] ?? true);

    if ($userPrompt === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Le message utilisateur est vide.']);
        return;
    }

    $payload = [
        'model'    => $model,
        'messages' => array_values(array_filter([
            $system !== '' ? ['role' => 'system', 'content' => $system] : null,
            ['role' => 'user', 'content' => "----- DÉBUT DOCUMENT -----\n" . $userPrompt . "\n----- FIN DOCUMENT -----"],
            // ['role' => 'user', 'content' => "<USER_TEXT>\n" . $userPrompt . "\n</USER_TEXT>"],
            // ['role' => 'user', 'content' => $userPrompt],
        ])),
        'stream'   => false,
		'format'   => 'json',
		// 'keep_alive' => '0',
        'options' => ['temperature'=>0.1]
    ];

    $url = $ollamaHost . '/api/chat';
    $url = $ollamaHost . '/api/' . $method;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => !$stream, // en streaming, on gère nous-mêmes l'écriture
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 0,        // laisser le flux ouvert tant que nécessaire
    ]);

    if ($stream) {
        // Streaming : on pousse les caractères au fur et à mesure (chunked)
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no'); // Nginx: disable buffering si possible
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) { ob_end_flush(); }
        ob_implicit_flush(true);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
            // Ollama /api/chat en stream renvoie des lignes JSON successives
            // On peut recevoir plusieurs objets ou des morceaux; on découpe par lignes.
            static $buffer = '';
            $buffer .= $chunk;

            $lines = preg_split('/\R/u', $buffer);
            // Garder la dernière ligne (potentiellement incomplète) en tampon
            $buffer = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                // Chaque ligne doit être un JSON; on ignore celles non parsables.
                $obj = json_decode($line, true);
                if (is_array($obj)) {
                    // Nouveaux tokens arrivent dans $obj['message']['content'] (souvent char/word)
                    if (isset($obj['message']['content'])) {
                        echo $obj['message']['content'];
                        flush();
                    }
                    // Si le flux signale "done": true, on peut s’arrêter proprement
                    if (!empty($obj['done'])) {
                        // Optionnel: renvoyer des metas via un séparateur, par ex. "\n"
                        echo "\n";
                        flush();
                    }
                }
            }
            // Retourner la taille lue à cURL
            return strlen($chunk);
        });

        $ok = curl_exec($ch);
        if ($ok === false) {
            // En cas d’erreur initiale de connexion
            echo "\n[ERREUR] cURL: " . curl_error($ch) . "\n";
        }
    } else {
        // Réponse non-stream: on renvoie un JSON consolidé {content: "...", raw: {...}}
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        header('Content-Type: application/json; charset=utf-8');

        if ($resp === false) {
            echo json_encode(['error' => "cURL: $err"], JSON_UNESCAPED_UNICODE);
        } else {
            $obj = json_decode($resp, true);
            $content = $obj['message']['content'] ?? '';
            echo json_encode([
                'content' => $content,
                'raw'     => $obj,
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    curl_close($ch);
}

?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test Ollama en ligne (PHP 8.3) HEADER</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<style>
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 1.2rem; }
  .row { display: grid; gap: .5rem; grid-template-columns: 1fr 1fr; margin-bottom: .75rem; }
  label { font-weight: 600; }
  input, textarea, select, button { font: inherit; padding: .5rem; }
  textarea { width: 100%; min-height: 140px; }
  .full { grid-column: 1 / -1; }
  #out { white-space: pre-wrap; border: 1px solid #ddd; padding: .75rem; border-radius: .5rem; min-height: 160px; }
  .muted { color: #666; font-size: .9em; }
  .inline { display: inline-flex; align-items: center; gap: .5rem; }
</style>
</head>
<body>
  <h1>Test Ollama (chat) — PHP 8.3 HEADER</h1>
  <p class="muted">
    Assurez-vous qu’Ollama tourne (ex: <code>ollama serve</code>) et qu’un modèle est disponible
    (ex: <code>ollama pull llama3.1:8b</code>).
  </p>

  <form id="f">
    <div class="row">
      <div class="col-md-4">
        <label for="host">Hôte Ollama</label>
        <input id="host" name="host" value="http://localhost:11434" required>
        <div class="muted">Ex: http://localhost:11434</div>
      </div>
      <div class="col-md-4">
        <label for="method">Methode</label>
		<select id="method" name="method">
		  <option value="chat">Chat</option>
		  <option value="tokenize">Tokenizer</option>
		</select>
        <div class="muted">Chat pour une reponse</div>
      </div>
      <div class="col-md-4">
        <label for="model">Modèle</label>
		<select id="model" name="model">
		  <option value="granite3.1-dense:8b">Granite IBM 3.1 (8b)</option>
		  <option value="llama3.1:8b">llama 3.1 (8b)</option>
		  <option value="gemma2:9b">Gemini 2 (9b)</option>
		  <option value="mistral:7b">Mistral (7b)</option>
		  <option value="gemma3">Gemini 3 (4b)</option>
		  <option value="qwen2.5:7b">Qwen 2.5 (7b)</option>
		  <option value="nuextract:3.8b">Nuextract (4b)</option>
		  <option value="deepseek-r1:8b">DeepSeek R1 (8b)</option>
		  <option value="gpt-oss:20b">OpenAI - GPT-OSS by Groq (20b)</option>
		  <option value="gpt-trubo3.5b">OpenAI - GPT-3.5-Turbo (680b)</option>
		</select>
        <div class="muted">Ex: llama3.1:8b, mistral, qwen2.5:7b, phi3, etc.</div>
      </div>
    </div>

    <div class="row">
      <div class="full">
        <label for="system">System prompt (facultatif)</label>
        <textarea id="system" name="system" placeholder="Tu es un assistant concis et précis.">
IMPORTANT :
- Tu dois TOUJOURS répondre en FRANÇAIS.
- Tu ne dois JAMAIS utiliser l’anglais ni aucune autre langue.
- Tu dois TOUJOURS répondre au format JSON strict, sans aucun texte autour.

RÔLE
Tu es un extracteur expert de documents commerciaux (factures, bons de livraison, proformas, documents douaniers, avoirs).

CONTRAINTE GÉNÉRALE
- Analyse UNIQUEMENT le texte du document fourni dans le message utilisateur.
- Ne JAMAIS inventer de valeur.
- Si une information est absente ou incertaine, renvoie "" (chaîne vide).
- La sortie doit être un JSON STRICT, VALIDE, sans commentaire, sans texte avant ou après, sans Markdown.

FORMAT DE SORTIE
Tu dois toujours renvoyer EXACTEMENT ce JSON, avec ces clés, dans cet ordre :

{
  "num_doc": "",
  "date_doc": "",
  "date_ech": "",
  "montant_total": "",
  "devise": "",
  "emetteur": "",
  "destinataire": ""
}

RÈGLES D’EXTRACTION

1) NUMÉRO DE DOCUMENT (num_doc)
- Cherche des labels comme : "Facture", "Invoice", "Document No", "N°", "No", "Numéro".
- Si plusieurs numéros existent, privilégie le numéro lié au type de document (ex. “Facture n°…”, “Invoice No …”).
- Ne pas inclure le type de document, seulement le numéro (ex : "F2025-12345").

2) DATE DU DOCUMENT (date_doc)
- Cherche les dates proches de mentions comme : "Date", "Du", "Date d'émission", "Document date", "Invoice date".
- S’il y a plusieurs dates, privilégie la date la plus clairement liée au document.
- Format de sortie : "YYYY-MM-DD" si possible.
- Si ambiguïté jour/mois, considérer que le format est européen (DD/MM/YYYY) par défaut.

3) DATE D’ÉCHÉANCE (date_ech)
- Cherche des labels comme : "Échéance", "Date d'échéance", "Due date", "Payment due", "Scad.".
- Format de sortie : "YYYY-MM-DD" si possible.
- Si aucune date d’échéance n’apparaît clairement, renvoie "".

4) MONTANT TOTAL (montant_total) ET DEVISE (devise)
- Objectif : montant total à payer par le client (net à payer, total TTC).
- Cherche prioritairement des mentions comme :
  - "Net à payer", "Net à payer TTC", "Total TTC", "Montant TTC", "Amount due", "Total due".
- Si plusieurs montants existent (HT, TVA, TTC), choisir le montant correspondant au total TTC ou au net à payer.
- Sortie pour montant_total :
  - chaîne de caractères,
  - séparateur décimal = ".", par exemple "1234.56"
  - pas de séparateurs de milliers (pas d’espace, pas de virgule de milliers).
- Sortie pour devise :
  - par exemple "EUR", "USD", "XPF", "GBP".
  - Utiliser les codes les plus probables à partir des symboles ("€" → "EUR", "$" → "USD" si le contexte ne dit pas autre chose).
  - Si la devise est incertaine ou absente : "".

5) ÉMETTEUR (emetteur)
- C’est le fournisseur / vendeur / expéditeur de la facture ou du document.
- Cherche en priorité le bloc comportant :
  - raison sociale + adresse + pays éventuel,
  - souvent en haut à gauche ou marqué par "Fournisseur", "Seller", "Vendor", "Émetteur".
- Restituer dans une seule chaîne, en une seule ligne :
  - par exemple : "SOCIÉTÉ X, 12 RUE DE PARIS, 75000 PARIS, FRANCE".
- Si plusieurs blocs possibles, choisir celui qui ressemble le plus à un fournisseur (souvent celui dont les coordonnées bancaires apparaissent plus loin).

6) DESTINATAIRE (destinataire)
- C’est le client / acheteur / destinataire de la facture ou des marchandises.
- Chercher des mentions : "Client", "Sold to", "Bill to", "Ship to", "Destinataire", "Bénéficiaire".
- Restituer aussi en une seule ligne : "ENTREPRISE Y, ADRESSE…".
- Ne jamais dupliquer l’émetteur : si un seul bloc d’adresse est clairement celui du fournisseur, laisser destinataire="".

RÈGLES GÉNÉRALES
- Toujours respecter la casse du texte source pour les noms / adresses, sauf pour nettoyer les retours à la ligne (remplacés par des espaces).
- Retirer les doublons évidents et les espaces multiples.
- N’ajoute pas de commentaires, pas de champs supplémentaires.

RAPPEL FINAL
- Tu dois renvoyer UNIQUEMENT un JSON valide avec les 7 clés ci-dessus.
- Pas de phrase d’explication, pas de Markdown, pas de texte autour.
		</textarea>
      </div>
    </div>

    <div class="row">
      <div class="full">
        <label for="user">Message utilisateur</label>
        <textarea id="user" name="user" placeholder="Pose ta question ici..." required></textarea>
      </div>
    </div>

    <div class="row">
	<div class="col-sm-4">
		<a href="./body.php" target="_blank"><h4>Go to chat with me for Body Docs Test</h4></a>
	</div>
      <div class="inline">
        <input type="checkbox" id="stream" name="stream">
        <label for="stream">Streaming temps réel</label>
      </div>
      <div class="inline" id="spin">
		<button class="btn btn-primary" type="button" disabled>
		  <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
		  <span role="status">Loading...</span>
		</button>
      </div>
      <div style="text-align:right">
        <button type="submit">Envoyer</button>
      </div>
    </div>
  </form>

  <h2>Réponse</h2>
  <pre id="out"></pre>
<div class="alert alert-primary" role="alert" id="log"></div>

  <script>
  const form = document.getElementById('f');
  const out  = document.getElementById('out');
  const log  = document.getElementById('log');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    out.textContent = ''; // reset
    log.innerHTML = ''; // reset
	
	const t0 = performance.now();

    const host   = document.getElementById('host').value.trim();
    const model  = document.getElementById('model').value.trim();
    const method  = document.getElementById('method').value.trim();
    const system = document.getElementById('system').value;
    const user   = document.getElementById('user').value;
    const stream = document.getElementById('stream').checked;

    const payload = { host, model, system, user, stream, method };

    try {
      const resp = await fetch('?action=' . method, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

		$("#spin").hide();
		const t1 = performance.now();
		const clientMs = Math.round(t1 - t0);
		log.innerHTML = `⏱ Durée côté client : ${model} : ${method} : ${clientMs} ms`;

      if (!resp.ok) {
        const t = await resp.text();
        out.textContent = 'Erreur HTTP: ' + resp.status + '\n' + t;
        return;
	  }

      if (stream) {
        // Lecture par flux (ReadableStream)
        const reader = resp.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let done, value;
        while (({done, value} = await reader.read()) && !done) {
          out.textContent += decoder.decode(value, {stream:true});
        }
      } else {
        // Réponse JSON non-stream
        const data = await resp.json();
        if (data.error) {
          out.textContent = 'Erreur: ' + data.error;
        } else {
          out.textContent = data.content ?? JSON.stringify(data, null, 2);
        }
      }
    } catch (err) {
      out.textContent = 'Exception: ' + err;
    }
  });
  
  </script>
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
  
<script>
$(document).ready(function() {
	$("#spin").hide();
    $("#f").on("submit", function() {
        $("#spin").show(); // affiche le spin
    });
});
</script>
</body>
</html>
