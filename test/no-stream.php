<?php
/**
 * Endpoint sin streaming para modelos via FastAPI
 */
header('Content-Type: application/json');
session_start();

$baseUrl = 'http://localhost:8000';
$apiKey = 'fd4d2af95ef7df9f1a1bcbd0000dc408f091f77ec5b28e23f8293c71f80b1d3a';

$input = json_decode(file_get_contents('php://input'), true);
$modelo = isset($input['modelo']) ? $input['modelo'] : 'qwen2.5:7b-instruct';
$mensaje = isset($input['mensaje']) ? $input['mensaje'] : '';
$modeloKey = isset($input['modelo_key']) ? $input['modelo_key'] : 'qwen';

if (empty($mensaje)) {
    echo json_encode(array('error' => 'Mensaje vacio'));
    exit;
}

// Inicializar historial
if (!isset($_SESSION['historial'])) $_SESSION['historial'] = array();
if (!isset($_SESSION['historial'][$modeloKey])) $_SESSION['historial'][$modeloKey] = array();

// Agregar mensaje del usuario
$_SESSION['historial'][$modeloKey][] = array('role' => 'user', 'content' => $mensaje);

// Preparar mensajes para la API
$mensajesAPI = array();
$mensajesAPI[] = array('role' => 'system', 'content' => 'Eres un asistente util. Responde en español de forma clara y concisa.');
foreach ($_SESSION['historial'][$modeloKey] as $msg) {
    $mensajesAPI[] = $msg;
}

$ch = curl_init($baseUrl . '/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
    'model' => $modelo,
    'messages' => $mensajesAPI,
    'max_tokens' => 1000,
    'temperature' => 0.7,
)));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
));
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    $respuesta = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';

    if ($respuesta) {
        $_SESSION['historial'][$modeloKey][] = array('role' => 'assistant', 'content' => $respuesta);
    }

    echo json_encode(array('respuesta' => $respuesta));
} else {
    echo json_encode(array('error' => 'HTTP ' . $httpCode . ': ' . $response));
}
