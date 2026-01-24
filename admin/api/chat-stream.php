<?php
/**
 * Endpoint de Chat con STREAMING
 * Usa Server-Sent Events (SSE) para enviar tokens en tiempo real
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Desactivar buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

require_once __DIR__ . '/../api-middleware.php';

// Validar API Key
$auth = validarApiKey($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (isset($auth['error'])) {
    echo "data: " . json_encode(['error' => $auth['error']]) . "\n\n";
    exit;
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);
$modelo = $input['model'] ?? 'qwen2.5:3b-instruct';
$messages = $input['messages'] ?? [];
$options = $input['options'] ?? [];

if (empty($messages)) {
    echo "data: " . json_encode(['error' => 'Messages requeridos']) . "\n\n";
    exit;
}

$inicio = microtime(true);
$respuestaCompleta = '';
$tokensOutput = 0;

// Preparar payload para Ollama
$ollamaPayload = [
    'model' => $modelo,
    'messages' => $messages,
    'stream' => true
];

// Agregar opciones si existen (temperature, num_predict, etc.)
if (!empty($options)) {
    $ollamaPayload['options'] = $options;
}

// Llamar a Ollama con streaming
$ch = curl_init(OLLAMA_URL . '/api/chat');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ollamaPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

// Callback para procesar chunks
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$respuestaCompleta, &$tokensOutput) {
    $lines = explode("\n", $chunk);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $data = json_decode($line, true);
        if ($data && isset($data['message']['content'])) {
            $token = $data['message']['content'];
            $respuestaCompleta .= $token;
            $tokensOutput++;

            // Enviar token al cliente
            echo "data: " . json_encode(['token' => $token]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }

        // Verificar si terminó
        if ($data && isset($data['done']) && $data['done'] === true) {
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
    }
    return strlen($chunk);
});

curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

$tiempoMs = round((microtime(true) - $inicio) * 1000);

// Registrar uso
if (!empty($respuestaCompleta)) {
    $tokensInput = 0;
    foreach ($messages as $msg) {
        $tokensInput += ceil(strlen($msg['content'] ?? '') / 4);
    }
    registrarUso($auth['proyecto_id'], 'chat', $modelo, $tokensInput, $tokensOutput, $tiempoMs);
}

if ($error) {
    echo "data: " . json_encode(['error' => $error]) . "\n\n";
}

echo "data: [DONE]\n\n";
