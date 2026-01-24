<?php
/**
 * Chat interactivo con memoria para probar modelos de IA
 * v1.7 - Con historial de conversación
 */
session_start();

// Configuración
$baseUrl = 'http://localhost:8000';
$ollamaUrl = 'http://localhost:11434';
$apiKey = 'fd4d2af95ef7df9f1a1bcbd0000dc408f091f77ec5b28e23f8293c71f80b1d3a';

$modelos = array(
    'qwen' => array('id' => 'qwen2.5:7b-instruct', 'nombre' => 'Qwen 2.5 7B', 'directo' => false),
    'dolphin' => array('id' => 'dolphin-mistral:7b-v2.6', 'nombre' => 'Dolphin-Mistral', 'directo' => false),
    'uncensored' => array('id' => 'uncensored-custom', 'nombre' => 'Wizard Uncensored', 'directo' => true),
    'escritor' => array('id' => 'escritor-erotico', 'nombre' => 'Escritor Erotico', 'directo' => true)
);

// Obtener modelo seleccionado
$modeloKey = isset($_GET['modelo']) ? $_GET['modelo'] : 'qwen';
if (!isset($modelos[$modeloKey])) {
    $modeloKey = 'qwen';
}
$modeloActual = $modelos[$modeloKey]['id'];
$modeloNombre = $modelos[$modeloKey]['nombre'];
$llamarDirecto = $modelos[$modeloKey]['directo'];

// Inicializar historial por modelo
if (!isset($_SESSION['historial'])) {
    $_SESSION['historial'] = array();
}
if (!isset($_SESSION['historial'][$modeloKey])) {
    $_SESSION['historial'][$modeloKey] = array();
}

// Limpiar historial si se solicita
if (isset($_GET['limpiar'])) {
    $_SESSION['historial'][$modeloKey] = array();
    header('Location: chat.php?modelo=' . $modeloKey);
    exit;
}

// Procesar mensaje si se envió
$respuesta = '';
$tiempoRespuesta = 0;
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mensaje'])) {
    $mensaje = trim($_POST['mensaje']);
    $modeloKey = isset($_POST['modelo_key']) ? $_POST['modelo_key'] : $modeloKey;
    $modeloActual = $modelos[$modeloKey]['id'];
    $llamarDirecto = $modelos[$modeloKey]['directo'];

    // Agregar mensaje del usuario al historial
    $_SESSION['historial'][$modeloKey][] = array('role' => 'user', 'content' => $mensaje);

    $inicio = microtime(true);

    if ($llamarDirecto) {
        // Llamar directo a Ollama
        $ch = curl_init($ollamaUrl . '/api/chat');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'model' => $modeloActual,
            'messages' => $_SESSION['historial'][$modeloKey],
            'stream' => false
        )));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tiempoRespuesta = round(microtime(true) - $inicio, 2);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $respuesta = isset($data['message']['content']) ? $data['message']['content'] : 'Sin respuesta';
        } else {
            $respuesta = 'Error HTTP ' . $httpCode . ': ' . $response;
        }
    } else {
        // Llamar a FastAPI
        $ch = curl_init($baseUrl . '/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        $mensajesAPI = array();
        $mensajesAPI[] = array(
            'role' => 'system',
            'content' => 'Eres un asistente util. Responde en español de forma clara y concisa.'
        );
        foreach ($_SESSION['historial'][$modeloKey] as $msg) {
            $mensajesAPI[] = $msg;
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'model' => $modeloActual,
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

    // Agregar respuesta al historial
    if ($respuesta && strpos($respuesta, 'Error') !== 0) {
        $_SESSION['historial'][$modeloKey][] = array('role' => 'assistant', 'content' => $respuesta);
    }
}

$historial = $_SESSION['historial'][$modeloKey];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat IA Local - <?php echo $modeloNombre; ?></title>
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
        .container { max-width: 900px; margin: 0 auto; }
        h1 { text-align: center; color: #00d9ff; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #888; margin-bottom: 20px; font-size: 14px; }

        .modelo-selector {
            display: flex; gap: 8px; justify-content: center;
            margin-bottom: 20px; flex-wrap: wrap;
        }
        .modelo-btn {
            padding: 10px 16px; border: 2px solid #444; background: #222;
            color: #fff; border-radius: 8px; cursor: pointer;
            transition: all 0.3s; text-decoration: none; font-size: 13px;
        }
        .modelo-btn:hover { border-color: #00d9ff; background: #2a2a4a; }
        .modelo-btn.active { border-color: #00d9ff; background: #00d9ff22; color: #00d9ff; }
        .modelo-btn.erotico { border-color: #ff6b6b; }
        .modelo-btn.erotico.active { border-color: #ff6b6b; background: #ff6b6b22; color: #ff6b6b; }

        .chat-container {
            background: #222; border-radius: 12px;
            padding: 20px; margin-bottom: 20px;
        }

        .historial {
            max-height: 400px; overflow-y: auto;
            margin-bottom: 20px; padding: 10px;
            background: #1a1a2e; border-radius: 8px;
        }
        .historial:empty::before {
            content: "No hay mensajes. Escribí algo para comenzar...";
            color: #666; font-style: italic;
        }
        .mensaje { margin-bottom: 15px; padding: 12px; border-radius: 8px; }
        .mensaje.user {
            background: #0066cc44; border-left: 3px solid #0066cc;
            margin-left: 20px;
        }
        .mensaje.assistant {
            background: #00cc6644; border-left: 3px solid #00cc66;
            margin-right: 20px;
        }
        .mensaje-rol {
            font-size: 11px; color: #888;
            margin-bottom: 5px; text-transform: uppercase;
        }
        .mensaje-contenido { line-height: 1.6; white-space: pre-wrap; }

        .form-grupo { margin-bottom: 15px; }
        textarea {
            width: 100%; padding: 15px; border: 2px solid #444;
            border-radius: 8px; background: #1a1a2e; color: #fff;
            font-size: 15px; resize: vertical; min-height: 80px;
        }
        textarea:focus { outline: none; border-color: #00d9ff; }

        .botones { display: flex; gap: 10px; }
        .btn-enviar {
            flex: 1; padding: 15px;
            background: linear-gradient(135deg, #00d9ff 0%, #0099cc 100%);
            border: none; border-radius: 8px; color: #000;
            font-size: 16px; font-weight: bold; cursor: pointer;
        }
        .btn-enviar:hover { transform: scale(1.02); }
        .btn-limpiar {
            padding: 15px 25px; background: #cc3333; border: none;
            border-radius: 8px; color: #fff; font-size: 14px;
            cursor: pointer; text-decoration: none;
        }
        .btn-limpiar:hover { background: #aa2222; }

        .stats {
            text-align: center; padding: 10px;
            background: #1a1a2e; border-radius: 8px;
            font-size: 13px; color: #888;
        }

        .loading { display: none; text-align: center; padding: 20px; }
        .loading.show { display: block; }
        .spinner {
            border: 3px solid #333; border-top: 3px solid #00d9ff;
            border-radius: 50%; width: 40px; height: 40px;
            animation: spin 1s linear infinite; margin: 0 auto 10px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <h1>Chat IA Local</h1>
        <p class="subtitle">v1.7 - Con memoria de conversacion | Modelo: <?php echo $modeloNombre; ?></p>

        <div class="modelo-selector">
            <?php foreach ($modelos as $key => $modelo): ?>
                <?php $esErotico = ($key === 'escritor' || $key === 'uncensored'); ?>
                <a href="?modelo=<?php echo $key; ?>"
                   class="modelo-btn <?php echo $esErotico ? 'erotico' : ''; ?> <?php echo $modeloKey === $key ? 'active' : ''; ?>">
                    <?php echo $modelo['nombre']; ?>
                    <?php if (isset($_SESSION['historial'][$key]) && count($_SESSION['historial'][$key]) > 0): ?>
                        (<?php echo count($_SESSION['historial'][$key]); ?>)
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="chat-container">
            <div class="historial" id="historial">
                <?php foreach ($historial as $msg): ?>
                    <div class="mensaje <?php echo $msg['role']; ?>">
                        <div class="mensaje-rol"><?php echo $msg['role'] === 'user' ? 'Vos' : $modeloNombre; ?></div>
                        <div class="mensaje-contenido"><?php echo nl2br(htmlspecialchars($msg['content'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="POST" id="chatForm">
                <input type="hidden" name="modelo_key" value="<?php echo $modeloKey; ?>">

                <div class="form-grupo">
                    <textarea name="mensaje" id="mensaje" placeholder="Escribi tu mensaje..." autofocus></textarea>
                </div>

                <div class="botones">
                    <button type="submit" class="btn-enviar">Enviar</button>
                    <a href="?modelo=<?php echo $modeloKey; ?>&limpiar=1" class="btn-limpiar"
                       onclick="return confirm('¿Limpiar historial de conversacion?');">
                        Limpiar
                    </a>
                </div>
            </form>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Generando respuesta... (puede tardar 30-60 segundos)</p>
            </div>
        </div>

        <div class="stats">
            Mensajes en esta conversacion: <?php echo count($historial); ?> |
            Modelo: <?php echo $modeloActual; ?> |
            <?php if ($tiempoRespuesta > 0): ?>Ultima respuesta: <?php echo $tiempoRespuesta; ?>s<?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-scroll al final del historial
        var historial = document.getElementById('historial');
        historial.scrollTop = historial.scrollHeight;

        // Mostrar loading al enviar
        document.getElementById('chatForm').addEventListener('submit', function() {
            document.getElementById('loading').classList.add('show');
        });

        // Enviar con Ctrl+Enter
        document.getElementById('mensaje').addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                document.getElementById('chatForm').submit();
            }
        });
    </script>
</body>
</html>
