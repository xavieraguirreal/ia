<?php
/**
 * API Middleware - Validacion y logging de requests
 *
 * Incluir al inicio de cualquier endpoint que use la API de IA
 *
 * Uso:
 *   require_once '/path/to/api-middleware.php';
 *   $auth = validarApiKey($_SERVER['HTTP_AUTHORIZATION'] ?? '');
 *   if (isset($auth['error'])) {
 *       http_response_code($auth['code']);
 *       die(json_encode(['error' => $auth['error']]));
 *   }
 *   // $auth contiene: proyecto_id, proyecto_nombre, requests_restantes
 *
 *   // Al final del request:
 *   registrarUso($auth['proyecto_id'], 'chat', 'qwen2.5:7b-instruct', 100, 50, 2500);
 */

require_once __DIR__ . '/config.php';

/**
 * Validar API Key y verificar rate limit
 */
function validarApiKey($authHeader) {
    $pdo = getDB();

    // Extraer API key del header
    $apiKey = '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $apiKey = trim($matches[1]);
    }

    if (empty($apiKey)) {
        return ['error' => 'API key requerida', 'code' => 401];
    }

    // Buscar proyecto
    $stmt = $pdo->prepare("
        SELECT id, nombre, rate_limit_diario, activo
        FROM ia_proyectos WHERE api_key = ?
    ");
    $stmt->execute([$apiKey]);
    $proyecto = $stmt->fetch();

    if (!$proyecto) {
        return ['error' => 'API key invalida', 'code' => 401];
    }

    if (!$proyecto['activo']) {
        return ['error' => 'API key desactivada', 'code' => 403];
    }

    // Verificar rate limit
    $hoy = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT total_requests FROM ia_usage_daily
        WHERE proyecto_id = ? AND fecha = ?
    ");
    $stmt->execute([$proyecto['id'], $hoy]);
    $uso = $stmt->fetch();

    $requestsHoy = $uso ? $uso['total_requests'] : 0;

    if ($requestsHoy >= $proyecto['rate_limit_diario']) {
        // Crear alerta si llego al limite
        if ($requestsHoy == $proyecto['rate_limit_diario']) {
            $stmt = $pdo->prepare("
                INSERT INTO ia_alertas (tipo, mensaje, datos)
                VALUES ('rate_limit', ?, ?)
            ");
            $stmt->execute([
                "Proyecto {$proyecto['nombre']} alcanzo su limite diario",
                json_encode(['proyecto' => $proyecto['nombre'], 'limite' => $proyecto['rate_limit_diario']])
            ]);
        }

        return [
            'error' => 'Rate limit excedido. Limite: ' . $proyecto['rate_limit_diario'] . '/dia',
            'code' => 429,
            'limite' => $proyecto['rate_limit_diario'],
            'usado' => $requestsHoy,
            'reset' => strtotime('tomorrow')
        ];
    }

    return [
        'ok' => true,
        'proyecto_id' => $proyecto['id'],
        'proyecto_nombre' => $proyecto['nombre'],
        'rate_limit_diario' => (int) $proyecto['rate_limit_diario'],
        'usado_hoy' => (int) $requestsHoy,
        'requests_restantes' => (int) ($proyecto['rate_limit_diario'] - $requestsHoy - 1),
        'reset_at' => strtotime('tomorrow')
    ];
}

/**
 * Registrar uso de la API
 */
function registrarUso($proyectoId, $endpoint, $modelo, $tokensIn = 0, $tokensOut = 0, $tiempoMs = 0, $statusCode = 200, $errorMsg = null) {
    $pdo = getDB();

    // Log detallado
    $stmt = $pdo->prepare("
        INSERT INTO ia_usage_logs
        (proyecto_id, endpoint, modelo, tokens_input, tokens_output, tiempo_ms, ip_address, status_code, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $proyectoId,
        $endpoint,
        $modelo,
        $tokensIn,
        $tokensOut,
        $tiempoMs,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $statusCode,
        $errorMsg
    ]);

    // Actualizar contador diario
    $hoy = date('Y-m-d');
    $stmt = $pdo->prepare("
        INSERT INTO ia_usage_daily (proyecto_id, fecha, total_requests, total_tokens_input, total_tokens_output, total_tiempo_ms)
        VALUES (?, ?, 1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_requests = total_requests + 1,
            total_tokens_input = total_tokens_input + VALUES(total_tokens_input),
            total_tokens_output = total_tokens_output + VALUES(total_tokens_output),
            total_tiempo_ms = total_tiempo_ms + VALUES(total_tiempo_ms)
    ");
    $stmt->execute([$proyectoId, $hoy, $tokensIn, $tokensOut, $tiempoMs]);
}

/**
 * Helper para llamar a Ollama directo
 */
function ollamaChat($modelo, $messages, $stream = false) {
    $ch = curl_init(OLLAMA_URL . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 300,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $modelo,
            'messages' => $messages,
            'stream' => $stream
        ])
    ]);

    if ($stream) {
        return $ch; // Retorna el handle para streaming manual
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Error de Ollama: HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    return [
        'content' => $data['message']['content'] ?? '',
        'model' => $modelo,
        'tokens_input' => $data['prompt_eval_count'] ?? 0,
        'tokens_output' => $data['eval_count'] ?? 0
    ];
}

/**
 * Helper para generar embeddings
 */
function ollamaEmbedding($texto, $modelo = 'nomic-embed-text') {
    $ch = curl_init(OLLAMA_URL . '/api/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $modelo,
            'prompt' => $texto
        ])
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Error de Ollama: HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    return [
        'embedding' => $data['embedding'] ?? [],
        'model' => $modelo
    ];
}
