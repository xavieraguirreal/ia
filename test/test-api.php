<?php
/**
 * Script de prueba para la API local de IA
 * Probar con: php test-api.php
 * O desde el navegador si está en public_html
 */

// Configuración
$baseUrl = 'http://localhost:8000/v1';
$apiKey = 'fd4d2af95ef7df9f1a1bcbd0000dc408f091f77ec5b28e23f8293c71f80b1d3a';

// Para mostrar en navegador
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style='font-family: monospace; background: #1a1a1a; color: #0f0; padding: 20px;'>";
}

function output($text) {
    echo $text . "\n";
    if (php_sapi_name() !== 'cli') {
        ob_flush();
        flush();
    }
}

function makeRequest($url, $data, $apiKey) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 120, // 2 minutos para modelos lentos
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'success' => $httpCode === 200,
        'code' => $httpCode,
        'response' => json_decode($response, true),
        'raw' => $response,
        'error' => $error,
    ];
}

output("╔════════════════════════════════════════════════════════════╗");
output("║          TEST DE API LOCAL DE IA (Ollama + Qwen)           ║");
output("╚════════════════════════════════════════════════════════════╝");
output("");

// ============ TEST 1: Health Check ============
output("▶ TEST 1: Health Check");
output("  URL: {$baseUrl}/../");

$ch = curl_init(str_replace('/v1', '', $baseUrl) . '/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    output("  ✓ API funcionando");
    output("  ✓ Modelo chat: " . ($data['models']['chat'] ?? 'N/A'));
    output("  ✓ Modelo embedding: " . ($data['models']['embedding'] ?? 'N/A'));
} else {
    output("  ✗ Error: HTTP $httpCode");
    output("  Respuesta: $response");
}
output("");

// ============ TEST 2: Conexión Ollama ============
output("▶ TEST 2: Verificar conexión con Ollama");
output("  URL: " . str_replace('/v1', '', $baseUrl) . "/test");

$ch = curl_init(str_replace('/v1', '', $baseUrl) . '/test');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    output("  ✓ Ollama conectado: " . ($data['ollama_connection'] ? 'Sí' : 'No'));
    output("  ✓ Modelo chat disponible: " . ($data['chat_model']['available'] ? 'Sí' : 'No'));
    output("  ✓ Modelo embedding disponible: " . ($data['embedding_model']['available'] ? 'Sí' : 'No'));
} else {
    output("  ✗ Error: HTTP $httpCode");
}
output("");

// ============ TEST 3: Embeddings ============
output("▶ TEST 3: Generar Embedding");
output("  Texto: 'La educación es fundamental para el desarrollo'");
output("  Esperando respuesta...");

$result = makeRequest($baseUrl . '/embeddings', [
    'model' => 'nomic-embed-text',
    'input' => 'La educación es fundamental para el desarrollo',
], $apiKey);

if ($result['success']) {
    $embedding = $result['response']['data'][0]['embedding'] ?? [];
    output("  ✓ Embedding generado correctamente");
    output("  ✓ Dimensiones: " . count($embedding));
    output("  ✓ Primeros 5 valores: [" . implode(', ', array_map(fn($v) => round($v, 4), array_slice($embedding, 0, 5))) . ", ...]");
} else {
    output("  ✗ Error: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ TEST 4: Chat - Generar Tags ============
output("▶ TEST 4: Generar Tags (como suggestTags)");
output("  Enviando solicitud... (puede tardar 10-30 segundos)");

$result = makeRequest($baseUrl . '/chat/completions', [
    'model' => 'qwen2.5:7b-instruct',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Eres un editor de noticias educativas. Sugiere 5 etiquetas/tags relevantes para el siguiente artículo. Las etiquetas deben ser palabras clave cortas (1-3 palabras cada una). Responde SOLO con las etiquetas separadas por comas, sin números ni explicaciones.'
        ],
        [
            'role' => 'user',
            'content' => 'El Ministerio de Educación anunció hoy nuevas políticas para la integración de tecnología en las aulas. Se implementarán tablets en todas las escuelas primarias del país, junto con capacitación docente en herramientas digitales. La inversión total supera los 500 millones de pesos.'
        ]
    ],
    'max_tokens' => 100,
    'temperature' => 0.3,
], $apiKey);

if ($result['success']) {
    $content = $result['response']['choices'][0]['message']['content'] ?? '';
    output("  ✓ Respuesta recibida");
    output("  ✓ Tags sugeridos: " . $content);
} else {
    output("  ✗ Error: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ TEST 5: Chat - Generar Resumen ============
output("▶ TEST 5: Generar Resumen (como generateSummary)");
output("  Enviando solicitud... (puede tardar 10-30 segundos)");

$result = makeRequest($baseUrl . '/chat/completions', [
    'model' => 'qwen2.5:7b-instruct',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Eres un editor de noticias educativas. Genera un resumen en bullet points (máximo 4 puntos) del artículo. Cada punto debe ser conciso (máximo 15 palabras). Responde SOLO con los bullet points, sin introducción. Usa el formato: • Punto 1'
        ],
        [
            'role' => 'user',
            'content' => 'El Ministerio de Educación anunció hoy nuevas políticas para la integración de tecnología en las aulas. Se implementarán tablets en todas las escuelas primarias del país, junto con capacitación docente en herramientas digitales. La inversión total supera los 500 millones de pesos. El programa comenzará en marzo del próximo año y se espera que beneficie a más de 2 millones de estudiantes. Los docentes recibirán cursos virtuales de 40 horas sobre el uso pedagógico de las nuevas tecnologías.'
        ]
    ],
    'max_tokens' => 300,
    'temperature' => 0.3,
], $apiKey);

if ($result['success']) {
    $content = $result['response']['choices'][0]['message']['content'] ?? '';
    output("  ✓ Respuesta recibida");
    output("  ✓ Resumen:");
    foreach (explode("\n", $content) as $line) {
        if (trim($line)) output("    " . trim($line));
    }
} else {
    output("  ✗ Error: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ TEST 6: Chat - Generar Extracto ============
output("▶ TEST 6: Generar Extracto (como generateExcerpt)");
output("  Enviando solicitud... (puede tardar 10-30 segundos)");

$result = makeRequest($baseUrl . '/chat/completions', [
    'model' => 'qwen2.5:7b-instruct',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Eres un editor de noticias. Genera un extracto/resumen muy breve (máximo 50 palabras) del siguiente texto. El extracto debe ser atractivo y resumir la idea principal. Responde SOLO con el extracto, sin comillas ni introducción.'
        ],
        [
            'role' => 'user',
            'content' => 'El Ministerio de Educación anunció hoy nuevas políticas para la integración de tecnología en las aulas. Se implementarán tablets en todas las escuelas primarias del país, junto con capacitación docente en herramientas digitales.'
        ]
    ],
    'max_tokens' => 150,
    'temperature' => 0.3,
], $apiKey);

if ($result['success']) {
    $content = $result['response']['choices'][0]['message']['content'] ?? '';
    output("  ✓ Respuesta recibida");
    output("  ✓ Extracto: " . $content);
} else {
    output("  ✗ Error: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ RESUMEN ============
output("╔════════════════════════════════════════════════════════════╗");
output("║                    TESTS COMPLETADOS                       ║");
output("╚════════════════════════════════════════════════════════════╝");
output("");
output("Si todos los tests pasaron (✓), la API está lista para usar.");
output("Podés integrarla con Laravel modificando EmbeddingsService.php");
output("");

if (php_sapi_name() !== 'cli') {
    echo "</pre>";
}
