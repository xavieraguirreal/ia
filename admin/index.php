<?php
/**
 * Dashboard - IA Admin Panel
 */
require_once 'config.php';
requireAuth();

$pdo = getDB();

// Stats del dia
$hoy = date('Y-m-d');
$stats = $pdo->query("
    SELECT
        COALESCE(SUM(total_requests), 0) as requests_hoy,
        COALESCE(SUM(total_tokens_input + total_tokens_output), 0) as tokens_hoy,
        COALESCE(AVG(total_tiempo_ms / NULLIF(total_requests, 0)), 0) as tiempo_promedio
    FROM ia_usage_daily
    WHERE fecha = '$hoy'
")->fetch();

// Total proyectos
$totalProyectos = $pdo->query("SELECT COUNT(*) as total FROM ia_proyectos WHERE activo = 1")->fetch()['total'];

// Alertas no leidas
$alertasNoLeidas = $pdo->query("SELECT COUNT(*) as total FROM ia_alertas WHERE leida = 0")->fetch()['total'];

// Ultimas alertas
$alertas = $pdo->query("
    SELECT * FROM ia_alertas
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll();

// Uso por proyecto (ultimos 7 dias)
$usoPorProyecto = $pdo->query("
    SELECT p.nombre, COALESCE(SUM(d.total_requests), 0) as requests
    FROM ia_proyectos p
    LEFT JOIN ia_usage_daily d ON p.id = d.proyecto_id AND d.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    WHERE p.activo = 1
    GROUP BY p.id, p.nombre
    ORDER BY requests DESC
    LIMIT 5
")->fetchAll();

// Modelos instalados
$modelos = [];
$ch = curl_init(OLLAMA_URL . '/api/tags');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$ollamaOk = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
curl_close($ch);
if ($ollamaOk && $response) {
    $data = json_decode($response, true);
    $modelos = $data['models'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - IA Admin</title>
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
            transition: color 0.2s;
        }
        .navbar nav a:hover, .navbar nav a.active { color: #00d9ff; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #222;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            color: #00d9ff;
            margin-bottom: 5px;
        }
        .stat-label { color: #888; font-size: 14px; }
        .stat-card.warning .stat-value { color: #ffcc00; }
        .stat-card.success .stat-value { color: #00cc66; }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 800px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

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

        .alert-item {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-item.unread { background: #333; }
        .alert-item.read { background: #2a2a2a; opacity: 0.7; }
        .alert-icon { font-size: 18px; }
        .alert-time { color: #666; font-size: 11px; margin-left: auto; }

        .proyecto-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #333;
        }
        .proyecto-row:last-child { border-bottom: none; }
        .proyecto-name { color: #fff; }
        .proyecto-requests { color: #00d9ff; font-weight: bold; }

        .modelo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #333;
            font-size: 13px;
        }
        .modelo-item:last-child { border-bottom: none; }
        .modelo-name { color: #fff; }
        .modelo-size { color: #888; }
        .status-ok { color: #00cc66; }
        .status-error { color: #ff4444; }

        .empty-state {
            color: #666;
            text-align: center;
            padding: 30px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>IA Admin</h1>
        <nav>
            <a href="index.php" class="active">Dashboard</a>
            <a href="api-keys.php">API Keys</a>
            <a href="modelos.php">Modelos</a>
            <a href="logs.php">Logs</a>
            <a href="logout.php">Salir</a>
        </nav>
    </div>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo formatNumber($stats['requests_hoy']); ?></div>
                <div class="stat-label">Requests Hoy</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['tiempo_promedio'] > 0 ? round($stats['tiempo_promedio'] / 1000, 1) . 's' : '-'; ?></div>
                <div class="stat-label">Tiempo Promedio</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?php echo count($modelos); ?></div>
                <div class="stat-label">Modelos Disponibles</div>
            </div>
            <div class="stat-card <?php echo $alertasNoLeidas > 0 ? 'warning' : ''; ?>">
                <div class="stat-value"><?php echo $alertasNoLeidas; ?></div>
                <div class="stat-label">Alertas Pendientes</div>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <h2>Uso por Proyecto (7 dias)</h2>
                <?php if (empty($usoPorProyecto) || array_sum(array_column($usoPorProyecto, 'requests')) === 0): ?>
                    <div class="empty-state">No hay datos de uso todavia</div>
                <?php else: ?>
                    <?php foreach ($usoPorProyecto as $p): ?>
                        <div class="proyecto-row">
                            <span class="proyecto-name"><?php echo htmlspecialchars($p['nombre']); ?></span>
                            <span class="proyecto-requests"><?php echo formatNumber($p['requests']); ?> req</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Alertas Recientes</h2>
                <?php if (empty($alertas)): ?>
                    <div class="empty-state">Sin alertas</div>
                <?php else: ?>
                    <?php foreach ($alertas as $a): ?>
                        <div class="alert-item <?php echo $a['leida'] ? 'read' : 'unread'; ?>">
                            <span class="alert-icon">
                                <?php
                                switch ($a['tipo']) {
                                    case 'modelo_nuevo': echo '🆕'; break;
                                    case 'modelo_actualizado': echo '⚠️'; break;
                                    case 'rate_limit': echo '🚫'; break;
                                    case 'error': echo '❌'; break;
                                    default: echo '📢';
                                }
                                ?>
                            </span>
                            <span><?php echo htmlspecialchars($a['mensaje']); ?></span>
                            <span class="alert-time"><?php echo date('d/m H:i', strtotime($a['created_at'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>Estado de Servicios</h2>
            <div class="modelo-item">
                <span class="modelo-name">Ollama</span>
                <span class="<?php echo $ollamaOk ? 'status-ok' : 'status-error'; ?>">
                    <?php echo $ollamaOk ? '● Conectado' : '● Desconectado'; ?>
                </span>
            </div>
            <?php if ($ollamaOk): ?>
                <?php foreach (array_slice($modelos, 0, 6) as $m): ?>
                    <div class="modelo-item">
                        <span class="modelo-name"><?php echo htmlspecialchars($m['name']); ?></span>
                        <span class="modelo-size"><?php echo formatBytes($m['size']); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (count($modelos) > 6): ?>
                    <div class="modelo-item">
                        <a href="modelos.php" style="color: #00d9ff; text-decoration: none;">Ver todos (<?php echo count($modelos); ?> modelos)</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
