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
<title>Test Ollama en ligne (PHP 8.3) BODY</title>
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
  <h1>Test Ollama (chat) — PHP 8.3 BODY</h1>
  <p class="muted">
    Assurez-vous qu’Ollama tourne (ex: <code>ollama serve</code>) et qu’un modèle est disponible
    (ex: <code>ollama pull llama3.1:8b</code>).
  </p>

  <form id="f">
    <div class="row">
      <div>
        <label for="host">Hôte Ollama</label>
        <input id="host" name="host" value="http://localhost:11434" required>
        <div class="muted">Ex: http://localhost:11434</div>
      </div>
      <div>
        <label for="model">Modèle</label>
		<select id="model" name="model">
		  <option value="llama3.1:8b"	>llama 3.1 (8b)</option>
		  <option value="gemma2:9b"		>Gemini 2 (9b)</option>
		  <option value="mistral:7b"	>Mistral (7b)</option>
		  <option value="gemma3"		>Gemini 3 (4b)</option>
		  <option value="claude-3-haiku">claude-3-haiku</option>
		  <option value="gpt-oss:20b"	>OpenAI - GPT-OSS (20b)</option>
		</select>
        <div class="muted">Ex: llama3.1:8b, mistral, qwen2.5:7b, phi3, etc.</div>
      </div>
    </div>

    <div class="row">
      <div class="full">
        <label for="system">System prompt (facultatif)</label>
        <textarea id="system" name="system" placeholder="Tu es un assistant concis et précis.">
IMPORTANT LANGUE ET FORMAT
- Tu dois TOUJOURS répondre en FRANÇAIS.
- Tu dois TOUJOURS répondre au format JSON STRICT, VALIDE.
- Tu ne dois ajouter AUCUN texte avant ou après le JSON.
- Tu ne dois JAMAIS utiliser de Markdown.

RÔLE
Tu es un extracteur expert de lignes d'articles dans des documents commerciaux :
factures, bons de livraison, proformas, avoirs, documents douaniers, etc.

OBJECTIF
À partir du texte fourni (souvent issu d’un OCR, avec tableaux cassés),
tu dois identifier les LIGNES D’ARTICLES et leurs attributs, et renvoyer un JSON unique.

FORMAT DE SORTIE
Tu dois TOUJOURS renvoyer EXACTEMENT ce JSON, sans autre texte :

{
  "lignes": [
    {
      "code": "",
      "libelle": "",
      "quantite": "",
      "prix_unitaire": "",
      "montant_ligne": "",
      "ean": "",
      "ndp": "",
      "pays_origine": "",
      "source_lignes": []
    }
  ]
}

Si aucune ligne d’article : renvoyer { "lignes": [] }.

RÈGLES GÉNÉRALES
- Ne JAMAIS inventer une valeur.
- Si une information est absente : mettre "".
- Les champs NUMÉRIQUES (quantité, prix_unitaire, montant_ligne) doivent être des CHAINES :
  - séparateur décimal = "."
  - AUCUN séparateur de milliers.
- Le libellé doit être sur une seule ligne : remplace les retours à la ligne internes par des espaces.

IGNORER
- Totaux, sous-totaux, récap TVA, mentions "TOTAL", "NET A PAYER".
- Sections d’entête et de pied de document.
- Lignes qui ne décrivent pas un article ou une prestation.

LOGIQUE SÉMANTIQUE DES LIGNES
- Considère qu’une ligne d’article est une petite "phrase commerciale" décrivant une transaction.
- Cette phrase contient en général :
  • un ARTICLE (code possible + libellé),
  • une QUANTITÉ,
  • un PRIX UNITAIRE,
  • un MONTANT DE LIGNE correspondant à QUANTITÉ × PRIX UNITAIRE.

- Lorsque possible, vérifie la cohérence :
      quantite × prix_unitaire ≈ montant_ligne
  (tolérance raisonnable due à l’OCR).

- Si tu observes sur une même ligne (ou zone voisine) un groupe de nombres correspondant
  à [quantité] [prix unitaire] [montant], tu peux valider que c’est une ligne d’article,
  même si le tableau est désaligné.

- Les attributs supplémentaires (EAN, NDP/HS, pays d’origine) peuvent apparaître avant,
  après ou sur une ligne adjacente. Si clairement rattachables à la même ligne, tu les ajoutes.

CRITÈRES POUR CRÉER UNE LIGNE
- Minimum requis : un libellé ET un montant_ligne plausibles.
- Si quantité ou prix unitaire manquent : les laisser à "".
- Si la ligne semble tronquée mais identifiable (ex : libellé + montant) : créer la ligne.

DÉFINITION DES CHAMPS
- code : référence article (SKU, ref fournisseur, ref interne).
- libelle : désignation complète, nettoyée, sur une ligne.
- quantite : chaîne de chiffres ou décimale.
- prix_unitaire : chaîne décimale avec ".".
- montant_ligne : total ligne.
- ean : code EAN/GTIN (8, 12, 13 ou 14 chiffres) si présent.
- ndp : code douanier (6, 8 ou 10 chiffres) si présent.
- pays_origine : ex. "FRANCE", "CN", "DE".

RAPPEL FINAL
- Tu dois renvoyer UNIQUEMENT un JSON strict conforme à la structure définie.
- AUCUN commentaire, AUCUN texte additionnel, AUCUN champ supplémentaire.

ANCRAGE SUR LES LIGNES SOURCE

- Le texte fourni contient des lignes préfixées par un numéro entre crochets, par exemple :
  [001] texte de la ligne
  [002] autre ligne
  etc.

- Pour chaque ligne d’article que tu extrais dans le JSON, tu dois remplir le champ "source_lignes"
  avec la liste des numéros de lignes utilisés pour construire cette ligne logique.

- Exemple : si le code et le libellé sont sur la ligne [001], et la quantité/prix/montant sur la ligne [002],
  alors "source_lignes": [1, 2].

- Tu dois recopier EXACTEMENT les numéros entre crochets, en tant que nombres entiers dans le tableau.
- Tu ne dois JAMAIS inventer de numéro. Si aucun numéro n’est clairement associé, mets "source_lignes": [].

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
			<a href="./index.php" target="_blank"><h4>Go to chat with me for Head Docs Test</h4></a>
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
    const system = document.getElementById('system').value;
    const user   = document.getElementById('user').value;
    const stream = document.getElementById('stream').checked;

    const payload = { host, model, system, user, stream };

    try {
      const resp = await fetch('?action=chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

		$("#spin").hide();
		const t1 = performance.now();
		const clientMs = Math.round(t1 - t0);
		log.innerHTML = `⏱ Durée côté client : ${model} : ${clientMs} ms`;

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
