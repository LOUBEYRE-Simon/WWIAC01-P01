<?php
declare(strict_types=1);

/**
 * qwen.php — proxy minimal vers Ollama /api/generate
 * POST params (obligatoires) :
 *   - prompt_systeme
 *   - prompt_user
 *
 * Renvoie JSON :
 *   {
 *     ok: bool,
 *     model: string,
 *     text: string,
 *     usage: {
 *       total_duration_ns: int|null,
 *       load_duration_ns: int|null,
 *       prompt_tokens: int|null,
 *       completion_tokens: int|null
 *     },
 *     created: "2025-09-30T09:45:00Z"
 *   }
 */

const OLLAMA_HOST = 'http://127.0.0.1:11434';
const OLLAMA = 'https://ollama.com';
const DEFAULT_MODEL = 'qwen3.5:9b'; // change si besoin

header('Content-Type: application/json; charset=utf-8');


// echo json_encode($_REQUEST);
// echo json_encode($_POST);
// die();

try {
    // --- lire les 2 paramètres requis ---
    $pUrl	= isset($_REQUEST['url'])				? OLLAMA									: OLLAMA_HOST;
    $pSys	= isset($_REQUEST['prompt_systeme'])	? trim((string)$_REQUEST['prompt_systeme'])	: '';
    $pUser	= isset($_REQUEST['prompt_user'])		? trim((string)$_REQUEST['prompt_user'])	: '';
    $pModel = isset($_REQUEST['model'])				? trim((string)$_REQUEST['model'])			: DEFAULT_MODEL;
    $imageData = isset($_REQUEST['images'])			? trim((string)$_REQUEST['images'])			: '';

	error_log("cURL-START: $pModel / $pUrl [img] => " . strlen($imageData) . "   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)));
	
    if ($pUser === '') {
    // if ($pSys === '' || $pUser === '') {
		error_log("ERROR: $pModel / $pUrl [img] => " . strlen($imageData) . "   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)));
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => "Champs requis manquants : 'prompt_user'."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else if ($pUser === 'head' || $pUser === 'body') {
		$pUser = 'Extrais les informations pertinentes cette facture sous forme de tableau selon les insctructions systemes.';
	}

	$x = explode("\n",$pUser);
	if (count($x)>100) {
		// on tronque ...
		$tab = array_slice($x,0,100);
		$pUser = implode("\n",$tab);
	}
	// if (strlen(preg_replace('/\s/ui','',$pUser))>8000) {
	if (strlen($pUser)>8000) {
		$pUser = substr($pUser,0,8000);
	}

	$schemaHeader = 'json';
	$temperature = 0;
	$num_predict = 8192; // 4096;
	$num_ctx = 8192; // 4096;
	if ($pUser == 'head') {
		$schemaHeader = [
			"type" => "object",
			"properties" => [
				"typ"       => ["type" => "string"],
				"num"       => ["type" => "string"],
				"dat"      => ["type" => "string"],
				"ech"      => ["type" => "string"],
				"tht" => ["type" => "string"],
				"tax" => ["type" => "string"],
				"ttc" => ["type" => "string"],
				"emetteur_nom"      => ["type" => "string"],
				"emetteur_adr"      => ["type" => "string"],
				"dest_nom"  => ["type" => "string"],
				"dest_adr"  => ["type" => "string"],
			],
			"required" => [
				"typ",
				"num",
				"dat",
				"ech",
				"tht",
				"tax",
				"ttc",
				"emetteur_nom",
				"emetteur_adr",
				"dest_nom",
				"dest_adr",
			],
			"additionalProperties" => false,
		];
		$temperature = 0;
		$num_predict = 8192; // 4096;
		$num_ctx = 8192; // 4096;
	} else if ($pUser == 'body') {
		$schemaHeader = [
			"type" => "array",
			"properties" => [
				"lines" => [
					"type" => "array",
					"items" => [
						"type" => "object",
						"properties" => [
							"cod" => ["type" => "string"],
							"lib" => ["type" => "string"],
							"qte" => ["type" => "string"],
							"prx" => ["type" => "string"],
							"mnt" => ["type" => "string"],
							"pay" => ["type" => "string"],
							"ean" => ["type" => "string"],
							"ndp" => ["type" => "string"],
							"ute" => ["type" => "string"],
							"net" => ["type" => "string"],
							"brt" => ["type" => "string"],
							"vol" => ["type" => "string"],
						],
						"required" => ["cod", "lib", "qte", "prx", "mnt", "pay", "ean", "ndp"],
						"additionalProperties" => false,
					],
					"required" => ["items"]
				],
			],
			"required" => ["lines"],
			"additionalProperties" => false,
		];
		
		$temperature = 0;
		$num_predict = 8192;
		$num_ctx = 8192;
		// $pModel = 'granite3.1-dense:8b';
	}

    // --- payload /api/generate (non-stream) ---
    $payload = [
        'model'  => $pModel,
        'system' => $pSys,
        'prompt' => $pUser,
        'stream' => false,
		'format'   => $schemaHeader,
		// 'format'   => 'json',
		// 'keep_alive' => '0',
		'keep_alive' => '12h',
        'options' => [
			'temperature'=>$temperature,
			"num_predict" => $num_predict, // Force le modèle à ne pas s'arrêter trop tôt
			'num_ctx' => $num_ctx,
			// 'top_p' => 0.9,
			// 'seed' => 1,
			// 'repeat_penalty' => 1.05,
		]
        // 'options' => ['temperature' => 0.3, 'num_ctx' => 4096], // décommente si besoin
    ];
    if ($imageData!='') $payload['images'] =[$imageData];

	$apiKey = '3c4aaa23e16a4f68af002d30d13bc6ba.FCceOIRYZ2mBqvMsx5jncaB_';

    $ch = curl_init(rtrim($pUrl, '/') . '/api/generate');

	if (strcmp($pUrl,OLLAMA_HOST)==0) { // Appel OLLAMA local
		// $model!='gemma4:31b') {
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 180,
		]);
	} else {
		// $ch = curl_init(rtrim($pUrl, '/') . '/api/chat');
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 180,
		]);
	}


    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($resp === false) {
		error_log("cURL: ERROR $resp : $pModel / $pUrl [img] => " . strlen($imageData) . "   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)) . '   [nb_lignes] => ' . count($x));
        throw new RuntimeException("cURL: $err");
    }
    if ($code >= 400) {
		error_log("cURL: ERROR $code : $resp : $pModel / $pUrl [img] => " . strlen($imageData) . "   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)) . '   [nb_lignes] => ' . count($x));
        throw new RuntimeException("HTTP $code: $resp");
    }

	// error_log("cURL: $pModel / $pUrl [img] => " . strlen($imageData) . "   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)));
	// die(json_encode([$resp,$err,$code]));

    // --- parse réponse Ollama ---
    $json = json_decode($resp, true, flags: JSON_THROW_ON_ERROR);

    // champs classiques
    $text  = (string)($json['response'] ?? '');
    $text  = (string)($json['analysis'] ?? '');
    $thinking  = (string)($json['thinking'] ?? '');
	$total_duration = $json['total_duration'] ?? 0;
	$load_duration = $json['load_duration'] ?? 0;
    $usage = [
        'total_duration_ns' => ns_to_seconds($total_duration, 3),
        'load_duration_ns'  => ns_to_seconds($load_duration, 3),
        'prompt_tokens'     => $json['prompt_eval_count']					?? null,
        'completion_tokens' => $json['eval_count']							?? null,
    ];

    echo json_encode([
        'ok'     => true,
        'model'  => $pModel,
        'response'   => $json,
        'text'   => $text,
        'thinking'   => $thinking,
        'usage'  => $usage,
        'created'=> gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);

	$msg = [];
	foreach($usage as $n => $v) $msg[] = '['.$n.'] => ' . $v;
	$x = explode("\n",$pUser);
	error_log("cURL: $pModel / $pUrl [img] => " . strlen($imageData) . "   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)) . '   [nb_lignes] => ' . count($x) . '   ' . implode('   ',$msg));

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

function ns_to_seconds(?int $ns, int $precision = 3): ?float {
    if ($ns === null) return null;
    return round($ns / 1_000_000_000, $precision); // 1e9
}
