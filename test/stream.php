<?php
/**
 * Endpoint de streaming para chat con Ollama
 */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Desactivar buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

session_start();

$ollamaUrl = 'http://localhost:11434';

// Obtener datos del POST
$input = json_decode(file_get_contents('php://input'), true);
$modelo = isset($input['modelo']) ? $input['modelo'] : 'escritor-erotico';
$mensaje = isset($input['mensaje']) ? $input['mensaje'] : '';
$modeloKey = isset($input['modelo_key']) ? $input['modelo_key'] : 'escritor';

if (empty($mensaje)) {
    echo "data: {\"error\": \"Mensaje vacio\"}\n\n";
    exit;
}

// Inicializar historial
if (!isset($_SESSION['historial'])) {
    $_SESSION['historial'] = array();
}
if (!isset($_SESSION['historial'][$modeloKey])) {
    $_SESSION['historial'][$modeloKey] = array();
}

// Agregar mensaje del usuario
$_SESSION['historial'][$modeloKey][] = array('role' => 'user', 'content' => $mensaje);

// Preparar request a Ollama con stream
$ch = curl_init($ollamaUrl . '/api/chat');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
    'model' => $modelo,
    'messages' => $_SESSION['historial'][$modeloKey],
    'stream' => true
)));
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_TIMEOUT, 300);

// Callback para procesar chunks
$respuestaCompleta = '';
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$respuestaCompleta) {
    $lines = explode("\n", $chunk);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $data = json_decode($line, true);
        if ($data && isset($data['message']['content'])) {
            $token = $data['message']['content'];
            $respuestaCompleta .= $token;

            // Enviar token al cliente
            echo "data: " . json_encode(array('token' => $token)) . "\n\n";

            if (ob_get_level()) ob_flush();
            flush();
        }

        // Verificar si terminó
        if ($data && isset($data['done']) && $data['done'] === true) {
            echo "data: " . json_encode(array('done' => true)) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
    }
    return strlen($chunk);
});

curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

// Guardar respuesta en historial
if (!empty($respuestaCompleta)) {
    $_SESSION['historial'][$modeloKey][] = array('role' => 'assistant', 'content' => $respuestaCompleta);
}

if ($error) {
    echo "data: " . json_encode(array('error' => $error)) . "\n\n";
}

echo "data: [DONE]\n\n";
