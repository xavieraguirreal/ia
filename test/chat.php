<?php
/**
 * Chat interactivo para probar modelos de IA
 * Uso navegador: chat.php?modelo=qwen o chat.php?modelo=dolphin
 */

// Configuración
$baseUrl = 'http://localhost:8000';
$apiKey = 'fd4d2af95ef7df9f1a1bcbd0000dc408f091f77ec5b28e23f8293c71f80b1d3a';

$modelos = array(
    'qwen' => 'qwen2.5:7b-instruct',
    'dolphin' => 'dolphin-mistral:7b-v2.6'
);

// Obtener modelo seleccionado
$modeloKey = isset($_GET['modelo']) ? $_GET['modelo'] : 'qwen';
if (!isset($modelos[$modeloKey])) {
    $modeloKey = 'qwen';
}
$modeloActual = $modelos[$modeloKey];

// Procesar mensaje si se envió
$respuesta = '';
$tiempoRespuesta = 0;
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mensaje'])) {
    $mensaje = trim($_POST['mensaje']);
    $modeloActual = isset($_POST['modelo']) ? $_POST['modelo'] : $modeloActual;

    $inicio = microtime(true);

    $ch = curl_init($baseUrl . '/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
        'model' => $modeloActual,
        'messages' => array(
            array(
                'role' => 'system',
                'content' => 'Eres un asistente util. Responde en español de forma clara y concisa.'
            ),
            array(
                'role' => 'user',
                'content' => $mensaje
            )
        ),
        'max_tokens' => 500,
        'temperature' => 0.7,
    )));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $tiempoRespuesta = round(microtime(true) - $inicio, 2);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $respuesta = isset($data['choices'][0]['message']['content'])
            ? $data['choices'][0]['message']['content']
            : 'Sin respuesta';
    } else {
        $respuesta = 'Error HTTP ' . $httpCode . ': ' . $response;
    }
}

// Obtener modelos disponibles en Ollama
$modelosDisponibles = array();
$ch = curl_init($baseUrl . '/test');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$testResponse = curl_exec($ch);
curl_close($ch);
if ($testResponse) {
    $testData = json_decode($testResponse, true);
    if (isset($testData['available_models'])) {
        $modelosDisponibles = $testData['available_models'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat IA Local - Test</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #eee;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            color: #00d9ff;
            margin-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            color: #888;
            margin-bottom: 30px;
        }
        .modelo-selector {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .modelo-btn {
            padding: 12px 24px;
            border: 2px solid #444;
            background: #222;
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        .modelo-btn:hover {
            border-color: #00d9ff;
            background: #2a2a4a;
        }
        .modelo-btn.active {
            border-color: #00d9ff;
            background: #00d9ff22;
            color: #00d9ff;
        }
        .chat-box {
            background: #222;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #aaa;
        }
        textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #444;
            border-radius: 8px;
            background: #1a1a2e;
            color: #fff;
            font-size: 16px;
            resize: vertical;
            min-height: 100px;
        }
        textarea:focus {
            outline: none;
            border-color: #00d9ff;
        }
        button[type="submit"] {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #00d9ff 0%, #0099cc 100%);
            border: none;
            border-radius: 8px;
            color: #000;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button[type="submit"]:hover {
            transform: scale(1.02);
        }
        .response-box {
            background: #1a1a2e;
            border: 2px solid #333;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }
        .response-model {
            color: #00d9ff;
            font-weight: bold;
        }
        .response-time {
            color: #888;
            font-size: 14px;
        }
        .response-content {
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .modelos-disponibles {
            text-align: center;
            margin-top: 30px;
            padding: 15px;
            background: #1a1a2e;
            border-radius: 8px;
        }
        .modelos-disponibles h3 {
            color: #888;
            margin-bottom: 10px;
        }
        .modelo-tag {
            display: inline-block;
            padding: 5px 12px;
            background: #333;
            border-radius: 20px;
            margin: 3px;
            font-size: 13px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .loading.show {
            display: block;
        }
        .spinner {
            border: 3px solid #333;
            border-top: 3px solid #00d9ff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .ejemplos {
            margin-top: 20px;
            padding: 15px;
            background: #1a1a2e;
            border-radius: 8px;
        }
        .ejemplos h3 {
            color: #888;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .ejemplo-btn {
            display: inline-block;
            padding: 8px 15px;
            background: #333;
            border: 1px solid #444;
            border-radius: 20px;
            margin: 3px;
            font-size: 13px;
            color: #ccc;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ejemplo-btn:hover {
            background: #444;
            border-color: #00d9ff;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Chat IA Local</h1>
        <p class="subtitle">Probando modelos Ollama</p>

        <div class="modelo-selector">
            <a href="?modelo=qwen" class="modelo-btn <?php echo $modeloKey === 'qwen' ? 'active' : ''; ?>">
                Qwen 2.5 7B
            </a>
            <a href="?modelo=dolphin" class="modelo-btn <?php echo $modeloKey === 'dolphin' ? 'active' : ''; ?>">
                Dolphin-Qwen (Uncensored)
            </a>
        </div>

        <div class="chat-box">
            <form method="POST" id="chatForm">
                <input type="hidden" name="modelo" value="<?php echo htmlspecialchars($modeloActual); ?>">

                <div class="form-group">
                    <label for="mensaje">Tu mensaje:</label>
                    <textarea name="mensaje" id="mensaje" placeholder="Escribe tu mensaje aqui..."><?php echo htmlspecialchars($mensaje); ?></textarea>
                </div>

                <button type="submit">Enviar mensaje</button>
            </form>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Generando respuesta... (puede tardar 30-60 segundos)</p>
            </div>

            <?php if ($respuesta): ?>
            <div class="response-box">
                <div class="response-header">
                    <span class="response-model"><?php echo htmlspecialchars($modeloActual); ?></span>
                    <span class="response-time"><?php echo $tiempoRespuesta; ?> segundos</span>
                </div>
                <div class="response-content"><?php echo nl2br(htmlspecialchars($respuesta)); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="ejemplos">
            <h3>Ejemplos para probar diferencias de censura:</h3>
            <span class="ejemplo-btn" onclick="setMensaje('Genera 5 tags para un articulo sobre tecnologia educativa')">Tags educativos</span>
            <span class="ejemplo-btn" onclick="setMensaje('Resume en 3 puntos: La inteligencia artificial esta transformando la educacion')">Resumen IA</span>
            <span class="ejemplo-btn" onclick="setMensaje('Escribe un parrafo creativo sobre un tema controversial')">Test censura</span>
            <span class="ejemplo-btn" onclick="setMensaje('Que opinas sobre temas politicos sensibles?')">Test opinion</span>
            <span class="ejemplo-btn" onclick="setMensaje('Explica como funciona el cifrado de datos')">Tema tecnico</span>
        </div>

        <?php if (!empty($modelosDisponibles)): ?>
        <div class="modelos-disponibles">
            <h3>Modelos instalados en Ollama:</h3>
            <?php foreach ($modelosDisponibles as $m): ?>
                <span class="modelo-tag"><?php echo htmlspecialchars($m); ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        document.getElementById('chatForm').addEventListener('submit', function() {
            document.getElementById('loading').classList.add('show');
        });

        function setMensaje(texto) {
            document.getElementById('mensaje').value = texto;
            document.getElementById('mensaje').focus();
        }
    </script>
</body>
</html>
