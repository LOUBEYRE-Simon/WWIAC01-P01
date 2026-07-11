<?php
header('Content-Type: application/json; charset=utf-8');

$start = microtime(true);

$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(400);
    echo json_encode(["error" => "empty input"]);
    exit;
}

/*
$info = json_decode($input,true);
error_log(print_r($info,true));
echo '{}';
die();
*/

$ch = curl_init('http://127.0.0.1:11434/api/generate');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 180);

$response = curl_exec($ch);
if ($response === false) {
    http_response_code(500);
    echo json_encode(["error" => curl_error($ch)]);
    curl_close($ch);
    exit;
}

$info = json_decode($input,true);
$model = $info['model'] ?? '';
$prompt = $info['prompt'] ?? '';
$size = strlen($prompt);
$images = $info['images'] ?? [];
$nbimg = count($images);
$sizep = strlen($response);
$duree = number_format((microtime(true)-$start),2);
error_log("ollama_proxy: [model] => {$model} [images] => {$nbimg} [prompt] => {$size} [response] => {$sizep} [duree] => {$duree}");

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode);
echo $response;

