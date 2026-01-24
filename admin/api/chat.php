<?php
/**
 * Endpoint de Chat con autenticacion y logging
 *
 * POST /admin/api/chat.php
 * Headers: Authorization: Bearer sk_xxx
 * Body: {"model": "qwen2.5:7b-instruct", "messages": [...]}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit; // CORS preflight
}

require_once __DIR__ . '/../api-middleware.php';

// Validar API Key
$auth = validarApiKey($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (isset($auth['error'])) {
    http_response_code($auth['code']);
    echo json_encode(['error' => $auth['error']]);
    exit;
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);
$modelo = $input['model'] ?? 'qwen2.5:7b-instruct';
$messages = $input['messages'] ?? [];

if (empty($messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'Messages requeridos']);
    exit;
}

$inicio = microtime(true);

// Llamar a Ollama
$resultado = ollamaChat($modelo, $messages);

$tiempoMs = round((microtime(true) - $inicio) * 1000);

if (isset($resultado['error'])) {
    registrarUso($auth['proyecto_id'], 'chat', $modelo, 0, 0, $tiempoMs, 500, $resultado['error']);
    http_response_code(500);
    echo json_encode(['error' => $resultado['error']]);
    exit;
}

// Registrar uso
registrarUso(
    $auth['proyecto_id'],
    'chat',
    $modelo,
    $resultado['tokens_input'],
    $resultado['tokens_output'],
    $tiempoMs
);

// Respuesta formato OpenAI
echo json_encode([
    'id' => 'chatcmpl-' . uniqid(),
    'object' => 'chat.completion',
    'created' => time(),
    'model' => $modelo,
    'choices' => [
        [
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => $resultado['content']
            ],
            'finish_reason' => 'stop'
        ]
    ],
    'usage' => [
        'prompt_tokens' => $resultado['tokens_input'],
        'completion_tokens' => $resultado['tokens_output'],
        'total_tokens' => $resultado['tokens_input'] + $resultado['tokens_output']
    ]
]);
