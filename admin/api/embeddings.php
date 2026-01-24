<?php
/**
 * Endpoint de Embeddings con autenticacion y logging
 *
 * POST /admin/api/embeddings.php
 * Headers: Authorization: Bearer sk_xxx
 * Body: {"model": "nomic-embed-text", "input": "texto"}
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
$modelo = $input['model'] ?? 'nomic-embed-text';
$textos = $input['input'] ?? '';

// Soportar input como string o array
if (is_string($textos)) {
    $textos = [$textos];
}

if (empty($textos)) {
    http_response_code(400);
    echo json_encode(['error' => 'Input requerido']);
    exit;
}

$inicio = microtime(true);
$embeddings = [];
$totalTokens = 0;

foreach ($textos as $i => $texto) {
    $resultado = ollamaEmbedding($texto, $modelo);

    if (isset($resultado['error'])) {
        $tiempoMs = round((microtime(true) - $inicio) * 1000);
        registrarUso($auth['proyecto_id'], 'embeddings', $modelo, 0, 0, $tiempoMs, 500, $resultado['error']);
        http_response_code(500);
        echo json_encode(['error' => $resultado['error']]);
        exit;
    }

    $embeddings[] = [
        'object' => 'embedding',
        'index' => $i,
        'embedding' => $resultado['embedding']
    ];

    // Estimar tokens (aprox 4 chars = 1 token)
    $totalTokens += ceil(strlen($texto) / 4);
}

$tiempoMs = round((microtime(true) - $inicio) * 1000);

// Registrar uso
registrarUso(
    $auth['proyecto_id'],
    'embeddings',
    $modelo,
    $totalTokens,
    0,
    $tiempoMs
);

// Respuesta formato OpenAI
echo json_encode([
    'object' => 'list',
    'data' => $embeddings,
    'model' => $modelo,
    'usage' => [
        'prompt_tokens' => $totalTokens,
        'total_tokens' => $totalTokens
    ]
]);
