<?php
/**
 * Monitor de Modelos
 */
require_once 'config.php';
requireAuth();

$pdo = getDB();

// Obtener modelos de Ollama
$modelos = [];
$ollamaOk = false;
$ch = curl_init(OLLAMA_URL . '/api/tags');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$ollamaOk = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
curl_close($ch);

if ($ollamaOk && $response) {
    $data = json_decode($response, true);
    $modelos = $data['models'] ?? [];
}

// Sincronizar con DB y detectar cambios
$alertasNuevas = [];
foreach ($modelos as $m) {
    $nombre = $m['name'];
    $digest = $m['digest'] ?? '';
    $tamano = $m['size'] ?? 0;
    $tipo = (stripos($nombre, 'embed') !== false) ? 'embedding' : 'chat';

    $stmt = $pdo->prepare("SELECT id, digest FROM ia_modelos WHERE nombre = ?");
    $stmt->execute([$nombre]);
    $existente = $stmt->fetch();

    if (!$existente) {
        // Modelo nuevo
        $stmt = $pdo->prepare("
            INSERT INTO ia_modelos (nombre, digest, tamano_bytes, tipo, ultima_verificacion)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$nombre, $digest, $tamano, $tipo]);

        $stmt = $pdo->prepare("INSERT INTO ia_alertas (tipo, mensaje, datos) VALUES (?, ?, ?)");
        $stmt->execute(['modelo_nuevo', "Nuevo modelo: $nombre", json_encode(['modelo' => $nombre])]);
        $alertasNuevas[] = "Nuevo modelo detectado: $nombre";

    } elseif ($existente['digest'] !== $digest && !empty($digest)) {
        // Modelo actualizado
        $stmt = $pdo->prepare("
            INSERT INTO ia_modelos_historial (modelo_id, digest_anterior, digest_nuevo)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$existente['id'], $existente['digest'], $digest]);

        $stmt = $pdo->prepare("UPDATE ia_modelos SET digest = ?, tamano_bytes = ?, ultima_verificacion = NOW() WHERE id = ?");
        $stmt->execute([$digest, $tamano, $existente['id']]);

        $stmt = $pdo->prepare("INSERT INTO ia_alertas (tipo, mensaje, datos) VALUES (?, ?, ?)");
        $stmt->execute(['modelo_actualizado', "Modelo actualizado: $nombre", json_encode(['modelo' => $nombre])]);
        $alertasNuevas[] = "Modelo actualizado: $nombre";
    } else {
        $stmt = $pdo->prepare("UPDATE ia_modelos SET ultima_verificacion = NOW() WHERE id = ?");
        $stmt->execute([$existente['id']]);
    }
}

// Obtener historial de cambios
$historial = $pdo->query("
    SELECT h.*, m.nombre as modelo_nombre
    FROM ia_modelos_historial h
    JOIN ia_modelos m ON h.modelo_id = m.id
    ORDER BY h.detectado_at DESC
    LIMIT 20
")->fetchAll();

// Marcar alerta como revisada
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_revisado'])) {
    $id = intval($_POST['historial_id']);
    $pdo->prepare("UPDATE ia_modelos_historial SET revisado = 1, revisado_at = NOW() WHERE id = ?")->execute([$id]);
    header('Location: modelos.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modelos - IA Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e;
            color: #eee;
            min-height: 100vh;
        }
        .navbar {
            background: #222;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #333;
        }
        .navbar h1 { color: #00d9ff; font-size: 20px; }
        .navbar nav a {
            color: #888;
            text-decoration: none;
            margin-left: 25px;
            font-size: 14px;
        }
        .navbar nav a:hover, .navbar nav a.active { color: #00d9ff; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        .alert-box {
            background: #ffcc0033;
            border: 1px solid #ffcc00;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-box ul { margin-left: 20px; margin-top: 10px; }

        .card {
            background: #222;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h2 {
            color: #fff;
            font-size: 16px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }

        .status-connected { color: #00cc66; }
        .status-disconnected { color: #ff4444; }

        .modelo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        .modelo-card {
            background: #1a1a2e;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #333;
        }
        .modelo-card h3 {
            color: #00d9ff;
            font-size: 14px;
            margin-bottom: 10px;
            word-break: break-all;
        }
        .modelo-info {
            display: flex;
            justify-content: space-between;
            color: #888;
            font-size: 12px;
        }
        .modelo-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .badge-chat { background: #00d9ff33; color: #00d9ff; }
        .badge-embedding { background: #00cc6633; color: #00cc66; }
        .badge-new { background: #ffcc0033; color: #ffcc00; margin-left: 5px; }

        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #333;
            font-size: 13px;
        }
        th { color: #888; font-size: 11px; text-transform: uppercase; }

        .digest { font-family: monospace; font-size: 11px; color: #888; }
        .status-pending { color: #ffcc00; }
        .status-reviewed { color: #00cc66; }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-small { background: #444; color: #fff; }
        .btn-small:hover { background: #555; }

        .empty-state {
            color: #666;
            text-align: center;
            padding: 30px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>IA Admin</h1>
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="api-keys.php">API Keys</a>
            <a href="modelos.php" class="active">Modelos</a>
            <a href="logs.php">Logs</a>
            <a href="logout.php">Salir</a>
        </nav>
    </div>

    <div class="container">
        <?php if (!empty($alertasNuevas)): ?>
            <div class="alert-box">
                <strong>Cambios detectados:</strong>
                <ul>
                    <?php foreach ($alertasNuevas as $a): ?>
                        <li><?php echo htmlspecialchars($a); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>
                Estado de Ollama:
                <span class="<?php echo $ollamaOk ? 'status-connected' : 'status-disconnected'; ?>">
                    <?php echo $ollamaOk ? '● Conectado' : '● Desconectado'; ?>
                </span>
            </h2>

            <?php if (!$ollamaOk): ?>
                <p style="color: #ff4444;">No se puede conectar a Ollama en <?php echo OLLAMA_URL; ?></p>
            <?php elseif (empty($modelos)): ?>
                <p class="empty-state">No hay modelos instalados</p>
            <?php else: ?>
                <div class="modelo-grid">
                    <?php foreach ($modelos as $m): ?>
                        <?php
                        $esEmbedding = stripos($m['name'], 'embed') !== false;
                        $esNuevo = isset($m['modified_at']) && strtotime($m['modified_at']) > strtotime('-24 hours');
                        ?>
                        <div class="modelo-card">
                            <h3>
                                <?php echo htmlspecialchars($m['name']); ?>
                                <span class="modelo-badge <?php echo $esEmbedding ? 'badge-embedding' : 'badge-chat'; ?>">
                                    <?php echo $esEmbedding ? 'Embedding' : 'Chat'; ?>
                                </span>
                                <?php if ($esNuevo): ?>
                                    <span class="modelo-badge badge-new">Nuevo</span>
                                <?php endif; ?>
                            </h3>
                            <div class="modelo-info">
                                <span><?php echo formatBytes($m['size']); ?></span>
                                <span class="digest"><?php echo substr($m['digest'] ?? '', 0, 12); ?>...</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Historial de Cambios</h2>
            <?php if (empty($historial)): ?>
                <p class="empty-state">No hay cambios registrados</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Modelo</th>
                            <th>Digest Anterior</th>
                            <th>Digest Nuevo</th>
                            <th>Detectado</th>
                            <th>Estado</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($h['modelo_nombre']); ?></td>
                                <td class="digest"><?php echo substr($h['digest_anterior'], 0, 12); ?>...</td>
                                <td class="digest"><?php echo substr($h['digest_nuevo'], 0, 12); ?>...</td>
                                <td><?php echo date('d/m/Y H:i', strtotime($h['detectado_at'])); ?></td>
                                <td>
                                    <?php if ($h['revisado']): ?>
                                        <span class="status-reviewed">✓ Revisado</span>
                                    <?php else: ?>
                                        <span class="status-pending">⚠ Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$h['revisado']): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="marcar_revisado" value="1">
                                            <input type="hidden" name="historial_id" value="<?php echo $h['id']; ?>">
                                            <button type="submit" class="btn btn-small">Marcar revisado</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Comandos Utiles</h2>
            <pre style="background: #1a1a2e; padding: 15px; border-radius: 8px; font-size: 13px; overflow-x: auto;">
# Ver modelos instalados
ollama list

# Descargar nuevo modelo
ollama pull nombre-modelo

# Eliminar modelo
ollama rm nombre-modelo

# Probar modelo
ollama run qwen2.5:7b-instruct "hola"

# Ver logs de Ollama
journalctl -u ollama -f
            </pre>
        </div>
    </div>
</body>
</html>
