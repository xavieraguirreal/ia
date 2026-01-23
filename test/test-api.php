<?php
/**
 * Script de prueba para la API local de IA
 * Probar con: php test-api.php
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return array(
        'success' => $httpCode === 200,
        'code' => $httpCode,
        'response' => json_decode($response, true),
        'raw' => $response,
        'error' => $error,
    );
}

output("============================================================");
output("       TEST DE API LOCAL DE IA (Ollama + Qwen)");
output("============================================================");
output("");

// ============ TEST 1: Health Check ============
output("[TEST 1] Health Check");

$ch = curl_init(str_replace('/v1', '', $baseUrl) . '/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    output("  OK - API funcionando");
    output("  OK - Modelo chat: " . (isset($data['models']['chat']) ? $data['models']['chat'] : 'N/A'));
    output("  OK - Modelo embedding: " . (isset($data['models']['embedding']) ? $data['models']['embedding'] : 'N/A'));
} else {
    output("  ERROR: HTTP $httpCode");
    output("  Respuesta: $response");
}
output("");

// ============ TEST 2: Conexión Ollama ============
output("[TEST 2] Verificar conexion con Ollama");

$ch = curl_init(str_replace('/v1', '', $baseUrl) . '/test');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    output("  OK - Ollama conectado: " . ($data['ollama_connection'] ? 'Si' : 'No'));
    output("  OK - Modelo chat disponible: " . ($data['chat_model']['available'] ? 'Si' : 'No'));
    output("  OK - Modelo embedding disponible: " . ($data['embedding_model']['available'] ? 'Si' : 'No'));
} else {
    output("  ERROR: HTTP $httpCode");
}
output("");

// ============ TEST 3: Embeddings ============
output("[TEST 3] Generar Embedding");
output("  Texto: 'La educacion es fundamental para el desarrollo'");
output("  Esperando respuesta...");

$result = makeRequest($baseUrl . '/embeddings', array(
    'model' => 'nomic-embed-text',
    'input' => 'La educacion es fundamental para el desarrollo',
), $apiKey);

if ($result['success']) {
    $embedding = isset($result['response']['data'][0]['embedding']) ? $result['response']['data'][0]['embedding'] : array();
    output("  OK - Embedding generado correctamente");
    output("  OK - Dimensiones: " . count($embedding));
    $primeros = array_slice($embedding, 0, 5);
    $primeros_str = array();
    foreach ($primeros as $v) {
        $primeros_str[] = round($v, 4);
    }
    output("  OK - Primeros 5 valores: [" . implode(', ', $primeros_str) . ", ...]");
} else {
    output("  ERROR: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ TEST 4: Chat - Generar Tags ============
output("[TEST 4] Generar Tags (como suggestTags)");
output("  Enviando solicitud... (puede tardar 10-30 segundos)");

$result = makeRequest($baseUrl . '/chat/completions', array(
    'model' => 'qwen2.5:7b-instruct',
    'messages' => array(
        array(
            'role' => 'system',
            'content' => 'Eres un editor de noticias educativas. Sugiere 5 etiquetas/tags relevantes para el siguiente articulo. Las etiquetas deben ser palabras clave cortas (1-3 palabras cada una). Responde SOLO con las etiquetas separadas por comas, sin numeros ni explicaciones.'
        ),
        array(
            'role' => 'user',
            'content' => 'El Ministerio de Educacion anuncio hoy nuevas politicas para la integracion de tecnologia en las aulas. Se implementaran tablets en todas las escuelas primarias del pais, junto con capacitacion docente en herramientas digitales. La inversion total supera los 500 millones de pesos.'
        )
    ),
    'max_tokens' => 100,
    'temperature' => 0.3,
), $apiKey);

if ($result['success']) {
    $content = isset($result['response']['choices'][0]['message']['content']) ? $result['response']['choices'][0]['message']['content'] : '';
    output("  OK - Respuesta recibida");
    output("  OK - Tags sugeridos: " . $content);
} else {
    output("  ERROR: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ TEST 5: Chat - Generar Resumen ============
output("[TEST 5] Generar Resumen (como generateSummary)");
output("  Enviando solicitud... (puede tardar 10-30 segundos)");

$result = makeRequest($baseUrl . '/chat/completions', array(
    'model' => 'qwen2.5:7b-instruct',
    'messages' => array(
        array(
            'role' => 'system',
            'content' => 'Eres un editor de noticias educativas. Genera un resumen en bullet points (maximo 4 puntos) del articulo. Cada punto debe ser conciso (maximo 15 palabras). Responde SOLO con los bullet points, sin introduccion. Usa el formato: * Punto 1'
        ),
        array(
            'role' => 'user',
            'content' => 'El Ministerio de Educacion anuncio hoy nuevas politicas para la integracion de tecnologia en las aulas. Se implementaran tablets en todas las escuelas primarias del pais, junto con capacitacion docente en herramientas digitales. La inversion total supera los 500 millones de pesos. El programa comenzara en marzo del proximo ano y se espera que beneficie a mas de 2 millones de estudiantes.'
        )
    ),
    'max_tokens' => 300,
    'temperature' => 0.3,
), $apiKey);

if ($result['success']) {
    $content = isset($result['response']['choices'][0]['message']['content']) ? $result['response']['choices'][0]['message']['content'] : '';
    output("  OK - Respuesta recibida");
    output("  OK - Resumen:");
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        if (trim($line)) {
            output("    " . trim($line));
        }
    }
} else {
    output("  ERROR: HTTP " . $result['code']);
    output("  Respuesta: " . $result['raw']);
}
output("");

// ============ RESUMEN ============
output("============================================================");
output("                   TESTS COMPLETADOS");
output("============================================================");
output("");
output("Si todos los tests muestran OK, la API esta lista.");
output("");

if (php_sapi_name() !== 'cli') {
    echo "</pre>";
}
