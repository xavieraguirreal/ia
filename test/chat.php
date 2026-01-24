<?php
/**
 * Chat interactivo con streaming
 * v1.9 - Streaming + Detener
 */
session_start();

$modelos = array(
    'qwen' => array('id' => 'qwen2.5:7b-instruct', 'nombre' => 'Qwen 2.5 7B', 'directo' => false),
    'dolphin' => array('id' => 'dolphin-mistral:7b-v2.6', 'nombre' => 'Dolphin-Mistral', 'directo' => false),
    'uncensored' => array('id' => 'uncensored-custom', 'nombre' => 'Wizard Uncensored', 'directo' => true),
    'escritor' => array('id' => 'escritor-erotico', 'nombre' => 'Escritor Erotico', 'directo' => true)
);

$modeloKey = isset($_GET['modelo']) ? $_GET['modelo'] : 'qwen';
if (!isset($modelos[$modeloKey])) $modeloKey = 'qwen';

$modeloActual = $modelos[$modeloKey]['id'];
$modeloNombre = $modelos[$modeloKey]['nombre'];
$usarStreaming = $modelos[$modeloKey]['directo']; // Solo streaming para modelos directos a Ollama

// Inicializar historial
if (!isset($_SESSION['historial'])) $_SESSION['historial'] = array();
if (!isset($_SESSION['historial'][$modeloKey])) $_SESSION['historial'][$modeloKey] = array();

// Limpiar historial
if (isset($_GET['limpiar'])) {
    $_SESSION['historial'][$modeloKey] = array();
    header('Location: chat.php?modelo=' . $modeloKey);
    exit;
}

$historial = $_SESSION['historial'][$modeloKey];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat IA - <?php echo $modeloNombre; ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #eee; margin: 0; padding: 20px; min-height: 100vh;
        }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { text-align: center; color: #00d9ff; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #888; margin-bottom: 20px; font-size: 14px; }

        .modelo-selector { display: flex; gap: 8px; justify-content: center; margin-bottom: 20px; flex-wrap: wrap; }
        .modelo-btn {
            padding: 10px 16px; border: 2px solid #444; background: #222;
            color: #fff; border-radius: 8px; cursor: pointer;
            transition: all 0.3s; text-decoration: none; font-size: 13px;
        }
        .modelo-btn:hover { border-color: #00d9ff; background: #2a2a4a; }
        .modelo-btn.active { border-color: #00d9ff; background: #00d9ff22; color: #00d9ff; }
        .modelo-btn.erotico { border-color: #ff6b6b; }
        .modelo-btn.erotico.active { border-color: #ff6b6b; background: #ff6b6b22; color: #ff6b6b; }
        .badge-stream { font-size: 9px; background: #00cc66; color: #000; padding: 2px 5px; border-radius: 3px; margin-left: 5px; }

        .chat-container { background: #222; border-radius: 12px; padding: 20px; margin-bottom: 20px; }

        .historial {
            height: 400px; overflow-y: auto; margin-bottom: 20px; padding: 15px;
            background: #1a1a2e; border-radius: 8px;
        }
        .historial-vacio { color: #666; font-style: italic; text-align: center; padding: 50px; }

        .mensaje { margin-bottom: 15px; padding: 12px; border-radius: 8px; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .mensaje.user { background: #0066cc44; border-left: 3px solid #0066cc; margin-left: 40px; }
        .mensaje.assistant { background: #00cc6644; border-left: 3px solid #00cc66; margin-right: 40px; }
        .mensaje.streaming { border-left-color: #ffcc00; background: #ffcc0022; }
        .mensaje-rol { font-size: 11px; color: #888; margin-bottom: 5px; text-transform: uppercase; }
        .mensaje-contenido { line-height: 1.7; white-space: pre-wrap; word-wrap: break-word; }
        .cursor-stream { display: inline-block; width: 8px; height: 16px; background: #00d9ff; animation: blink 0.5s infinite; margin-left: 2px; vertical-align: middle; }
        @keyframes blink { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }

        .form-grupo { margin-bottom: 15px; }
        textarea {
            width: 100%; padding: 15px; border: 2px solid #444; border-radius: 8px;
            background: #1a1a2e; color: #fff; font-size: 15px; resize: vertical; min-height: 80px;
        }
        textarea:focus { outline: none; border-color: #00d9ff; }
        textarea:disabled { opacity: 0.5; }

        .botones { display: flex; gap: 10px; }
        .btn-enviar {
            flex: 1; padding: 15px; background: linear-gradient(135deg, #00d9ff 0%, #0099cc 100%);
            border: none; border-radius: 8px; color: #000; font-size: 16px; font-weight: bold; cursor: pointer;
        }
        .btn-enviar:hover { transform: scale(1.02); }
        .btn-enviar:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-limpiar {
            padding: 15px 25px; background: #cc3333; border: none; border-radius: 8px;
            color: #fff; font-size: 14px; cursor: pointer; text-decoration: none;
        }
        .btn-limpiar:hover { background: #aa2222; }
        .btn-detener {
            padding: 15px 25px; background: #ff9900; border: none; border-radius: 8px;
            color: #000; font-size: 14px; font-weight: bold; cursor: pointer;
        }
        .btn-detener:hover { background: #cc7700; }

        .stats { text-align: center; padding: 10px; background: #1a1a2e; border-radius: 8px; font-size: 13px; color: #888; }
        .tiempo { color: #00d9ff; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Chat IA Local</h1>
        <p class="subtitle">v1.9 - Streaming + Detener | <?php echo $modeloNombre; ?></p>

        <div class="modelo-selector">
            <?php foreach ($modelos as $key => $modelo): ?>
                <?php $esErotico = ($key === 'escritor' || $key === 'uncensored'); ?>
                <a href="?modelo=<?php echo $key; ?>"
                   class="modelo-btn <?php echo $esErotico ? 'erotico' : ''; ?> <?php echo $modeloKey === $key ? 'active' : ''; ?>">
                    <?php echo $modelo['nombre']; ?>
                    <?php if ($modelo['directo']): ?><span class="badge-stream">STREAM</span><?php endif; ?>
                    <?php if (isset($_SESSION['historial'][$key]) && count($_SESSION['historial'][$key]) > 0): ?>
                        (<?php echo count($_SESSION['historial'][$key]); ?>)
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="chat-container">
            <div class="historial" id="historial">
                <?php if (empty($historial)): ?>
                    <div class="historial-vacio" id="placeholder">Escribi algo para comenzar la conversacion...</div>
                <?php else: ?>
                    <?php foreach ($historial as $msg): ?>
                        <div class="mensaje <?php echo $msg['role']; ?>">
                            <div class="mensaje-rol"><?php echo $msg['role'] === 'user' ? 'Vos' : '<?php echo $modeloNombre; ?>'; ?></div>
                            <div class="mensaje-contenido"><?php echo nl2br(htmlspecialchars($msg['content'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form id="chatForm" onsubmit="enviarMensaje(event)">
                <div class="form-grupo">
                    <textarea name="mensaje" id="mensaje" placeholder="Escribi tu mensaje... (Ctrl+Enter para enviar)" autofocus></textarea>
                </div>
                <div class="botones">
                    <button type="submit" class="btn-enviar" id="btnEnviar">Enviar</button>
                    <button type="button" class="btn-detener" id="btnDetener" style="display:none;" onclick="detenerGeneracion()">Detener</button>
                    <a href="?modelo=<?php echo $modeloKey; ?>&limpiar=1" class="btn-limpiar"
                       onclick="return confirm('¿Limpiar historial?');">Limpiar</a>
                </div>
            </form>
        </div>

        <div class="stats">
            <span id="statsMsg">Mensajes: <?php echo count($historial); ?></span> |
            <span id="statsTiempo"></span>
            Modelo: <?php echo $modeloActual; ?>
            <?php if ($usarStreaming): ?> | <span style="color:#00cc66">Streaming activo</span><?php endif; ?>
        </div>
    </div>

    <script>
        const modeloKey = '<?php echo $modeloKey; ?>';
        const modeloId = '<?php echo $modeloActual; ?>';
        const modeloNombre = '<?php echo $modeloNombre; ?>';
        const usarStreaming = <?php echo $usarStreaming ? 'true' : 'false'; ?>;

        const historialDiv = document.getElementById('historial');
        const mensajeInput = document.getElementById('mensaje');
        const btnEnviar = document.getElementById('btnEnviar');
        const btnDetener = document.getElementById('btnDetener');
        const statsTiempo = document.getElementById('statsTiempo');

        let abortController = null;
        let generando = false;

        function detenerGeneracion() {
            if (abortController) {
                abortController.abort();
                abortController = null;
            }
            generando = false;
            btnEnviar.style.display = 'block';
            btnDetener.style.display = 'none';
            btnEnviar.disabled = false;
            btnEnviar.textContent = 'Enviar';
            mensajeInput.disabled = false;
            statsTiempo.innerHTML = '<span style="color:#ff9900">Detenido</span> | ';
        }

        // Auto-scroll
        function scrollToBottom() {
            historialDiv.scrollTop = historialDiv.scrollHeight;
        }
        scrollToBottom();

        // Ctrl+Enter para enviar
        mensajeInput.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'Enter') {
                document.getElementById('chatForm').dispatchEvent(new Event('submit'));
            }
        });

        function agregarMensaje(rol, contenido, streaming = false) {
            // Quitar placeholder si existe
            const placeholder = document.getElementById('placeholder');
            if (placeholder) placeholder.remove();

            const div = document.createElement('div');
            div.className = 'mensaje ' + rol + (streaming ? ' streaming' : '');
            div.innerHTML = `
                <div class="mensaje-rol">${rol === 'user' ? 'Vos' : modeloNombre}</div>
                <div class="mensaje-contenido">${contenido}${streaming ? '<span class="cursor-stream"></span>' : ''}</div>
            `;
            historialDiv.appendChild(div);
            scrollToBottom();
            return div;
        }

        function actualizarMensaje(div, contenido, finalizado = false) {
            const contenidoDiv = div.querySelector('.mensaje-contenido');
            contenidoDiv.innerHTML = contenido.replace(/\n/g, '<br>') +
                (finalizado ? '' : '<span class="cursor-stream"></span>');
            if (finalizado) {
                div.classList.remove('streaming');
            }
            scrollToBottom();
        }

        async function enviarMensaje(e) {
            e.preventDefault();

            const mensaje = mensajeInput.value.trim();
            if (!mensaje) return;

            // Deshabilitar input y mostrar botón detener
            mensajeInput.disabled = true;
            btnEnviar.style.display = 'none';
            btnDetener.style.display = 'block';
            generando = true;
            abortController = new AbortController();

            // Agregar mensaje del usuario
            agregarMensaje('user', mensaje.replace(/\n/g, '<br>'));
            mensajeInput.value = '';

            const inicio = Date.now();
            statsTiempo.innerHTML = '<span class="tiempo">Generando...</span> | ';

            if (usarStreaming) {
                // Usar streaming
                const respuestaDiv = agregarMensaje('assistant', '', true);
                let respuestaCompleta = '';

                try {
                    const response = await fetch('stream.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            modelo: modeloId,
                            modelo_key: modeloKey,
                            mensaje: mensaje
                        }),
                        signal: abortController.signal
                    });

                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();

                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;

                        const chunk = decoder.decode(value);
                        const lines = chunk.split('\n');

                        for (const line of lines) {
                            if (line.startsWith('data: ')) {
                                const data = line.slice(6);
                                if (data === '[DONE]') continue;

                                try {
                                    const json = JSON.parse(data);
                                    if (json.token) {
                                        respuestaCompleta += json.token;
                                        actualizarMensaje(respuestaDiv, respuestaCompleta, false);
                                    }
                                    if (json.done) {
                                        actualizarMensaje(respuestaDiv, respuestaCompleta, true);
                                    }
                                    if (json.error) {
                                        actualizarMensaje(respuestaDiv, 'Error: ' + json.error, true);
                                    }
                                } catch (e) {}
                            }
                        }
                    }

                    actualizarMensaje(respuestaDiv, respuestaCompleta, true);

                } catch (error) {
                    actualizarMensaje(respuestaDiv, 'Error: ' + error.message, true);
                }

            } else {
                // Sin streaming (para modelos via FastAPI)
                try {
                    const response = await fetch('no-stream.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            modelo: modeloId,
                            modelo_key: modeloKey,
                            mensaje: mensaje
                        })
                    });

                    const data = await response.json();
                    if (data.respuesta) {
                        agregarMensaje('assistant', data.respuesta.replace(/\n/g, '<br>'));
                    } else if (data.error) {
                        agregarMensaje('assistant', 'Error: ' + data.error);
                    }
                } catch (error) {
                    agregarMensaje('assistant', 'Error: ' + error.message);
                }
            }

            // Calcular tiempo
            const tiempo = ((Date.now() - inicio) / 1000).toFixed(1);
            statsTiempo.innerHTML = `<span class="tiempo">${tiempo}s</span> | `;

            // Rehabilitar input
            generando = false;
            abortController = null;
            mensajeInput.disabled = false;
            btnEnviar.style.display = 'block';
            btnDetener.style.display = 'none';
            mensajeInput.focus();
        }
    </script>
</body>
</html>
