<?php
declare(strict_types=1);

/**
 * bot.php — proxy minimal vers Ollama /api/generate
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
const DEFAULT_MODEL = 'llama3.1:8b'; // change si besoin
// const DEFAULT_MODEL = 'gemma3';

header('Content-Type: application/json; charset=utf-8');

try {
    // --- lire les 2 paramètres requis ---
    $pSys	= isset($_POST['prompt_systeme'])	? trim((string)$_POST['prompt_systeme'])	: '';
    $pUser	= isset($_POST['prompt_user'])		? trim((string)$_POST['prompt_user'])		: '';
    $pModel = isset($_POST['model'])			? trim((string)$_POST['model'])				: DEFAULT_MODEL;

    if ($pUser === '') {
    // if ($pSys === '' || $pUser === '') {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => "Champs requis manquants : 'prompt_user'."
            // 'error' => "Champs requis manquants : 'prompt_systeme' et 'prompt_user'."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

	$schemaHeader = 'json';
    $pHead	= isset($_POST['head'])	? trim((string)$_POST['head'])	: '';
	if ($pHead == 'head') {
		$schemaHeader = [
			"type" => "object",
			"properties" => [
				"num_doc"       => ["type" => "string"],
				"date_doc"      => ["type" => "string"],
				"date_ech"      => ["type" => "string"],
				"emetteur"      => ["type" => "string"],
				"destinataire"  => ["type" => "string"],
			],
			"required" => [
				"num_doc",
				"date_doc",
				"date_ech",
				"emetteur",
				"destinataire",
			],
			"additionalProperties" => false,
		];
	}
	if ($pHead == 'foot') {
		$schemaHeader = [
			"type" => "object",
			"properties" => [
				"num_doc"       => ["type" => "string"],
				"date_ech"      => ["type" => "string"],
				"montant_total" => ["type" => "string"],
				"devise"        => ["type" => "string"],
			],
			"required" => [
				"num_doc",
				"date_ech",
				"montant_total",
				"devise",
			],
			"additionalProperties" => false,
		];
	}
	if ($pHead == 'type') {
		$schemaHeader = [
			"type" => "object",
			"properties" => [
				"header_foot"	=> ["type" => "array","items"=>["type"=>"string"]],
				"body"			=> ["type"=>"array","items"=>["type"=>"string"]],
				"other"			=> ["type" => "array","items"=>["type"=>"string"]],
			],
			"required" => [
				"header_foot",
				"body",
				"other",
			],
			"additionalProperties" => false,
			"strict" => true,
		];
	}
	if ($pHead == 'body') {
		$schemaLignes = [
			"type" => "object",
			"properties" => [
				"lignes" => [
					"type" => "array",
					"items" => [
						"type" => "object",
						"properties" => [
							"code" => [
								"type" => "string",
							],
							"libelle" => [
								"type" => "string",
							],
							"quantite" => [
								"type" => "string",
							],
							"prix_unitaire" => [
								"type" => "string",
							],
							"montant_ligne" => [
								"type" => "string",
							],
							"ean" => [
								"type" => "string",
							],
							"ndp" => [
								"type" => "string",
							],
							"pays_origine" => [
								"type" => "string",
							],
							"source_lignes" => [
								"type" => "array",
								"items" => [
									"type" => "integer",
								],
							],
						],
						"required" => [
							"code",
							"libelle",
							"quantite",
							"prix_unitaire",
							"montant_ligne",
							"ean",
							"ndp",
							"pays_origine",
							"source_lignes",
						],
						"additionalProperties" => false,
					],
				],
			],
			"required" => ["lignes"],
			"additionalProperties" => false,
		];
	}

    // --- payload /api/generate (non-stream) ---
    $payload = [
        'model'  => $pModel,
        'system' => $pSys,
        'prompt' => $pUser,
        'stream'   => false,
		'format'   => $schemaHeader,
		// 'format'   => 'json',
		// 'keep_alive' => '0',
		'keep_alive' => '12h',
        'options' => [
			'temperature'=>0.0
			, 'num_ctx' => 4096
			// , 'seed' => 1
			// , 'repeat_penalty' => 1.05
		]
        // 'options' => ['temperature' => 0.3, 'num_ctx' => 4096], // décommente si besoin
    ];


    $ch = curl_init(rtrim(OLLAMA_HOST, '/') . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 180,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("cURL: $err");
    }
    if ($code >= 400) {
        throw new RuntimeException("HTTP $code: $resp");
    }

    // --- parse réponse Ollama ---
    $json = json_decode($resp, true, flags: JSON_THROW_ON_ERROR);

    // champs classiques
    $text  = (string)($json['response'] ?? '');
    $usage = [
        'total_duration_ns' => ns_to_seconds($json['total_duration'], 3)	?? null,
        'load_duration_ns'  => ns_to_seconds($json['load_duration'], 3)		?? null,
        'prompt_tokens'     => $json['prompt_eval_count']					?? null,
        'completion_tokens' => $json['eval_count']							?? null,
    ];

    echo json_encode([
        'ok'     => true,
        'model'  => $pModel,
        'text'   => $text,
        'usage'  => $usage,
        'created'=> gmdate('c'),
    ], JSON_UNESCAPED_UNICODE);

	$msg = [];
	foreach($usage as $n => $v) $msg[] = '['.$n.'] => ' . $v;
	$x = explode("\n",$pUser);
	error_log("cURL: $pModel / $pHead   [sys] => " . strlen($pSys) . "   [user] => " . strlen($pUser) . '   [prompt] => ' . (strlen($pSys)+strlen($pUser)) . '   [nb_lignes] => ' . count($x) . '   ' . implode('   ',$msg));

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
