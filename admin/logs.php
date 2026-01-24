<?php
/**
 * Logs de Uso
 */
require_once 'config.php';
requireAuth();

$pdo = getDB();

// Filtros
$proyectoFiltro = isset($_GET['proyecto']) ? intval($_GET['proyecto']) : 0;
$endpointFiltro = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';
$fechaDesde = isset($_GET['desde']) ? $_GET['desde'] : date('Y-m-d', strtotime('-7 days'));
$fechaHasta = isset($_GET['hasta']) ? $_GET['hasta'] : date('Y-m-d');

// Query base
$where = ["l.created_at >= ?", "l.created_at < DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$fechaDesde, $fechaHasta];

if ($proyectoFiltro > 0) {
    $where[] = "l.proyecto_id = ?";
    $params[] = $proyectoFiltro;
}
if ($endpointFiltro) {
    $where[] = "l.endpoint = ?";
    $params[] = $endpointFiltro;
}

$whereStr = implode(' AND ', $where);

// Total logs
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM ia_usage_logs l WHERE $whereStr");
$stmt->execute($params);
$total = $stmt->fetch()['total'];

// Paginacion
$porPagina = 50;
$pagina = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($pagina - 1) * $porPagina;
$totalPaginas = ceil($total / $porPagina);

// Logs
$stmt = $pdo->prepare("
    SELECT l.*, p.nombre as proyecto_nombre
    FROM ia_usage_logs l
    JOIN ia_proyectos p ON l.proyecto_id = p.id
    WHERE $whereStr
    ORDER BY l.created_at DESC
    LIMIT $porPagina OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Stats del periodo
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_requests,
        SUM(tokens_input) as total_tokens_in,
        SUM(tokens_output) as total_tokens_out,
        AVG(tiempo_ms) as tiempo_promedio,
        SUM(CASE WHEN endpoint = 'chat' THEN 1 ELSE 0 END) as chat_requests,
        SUM(CASE WHEN endpoint = 'embeddings' THEN 1 ELSE 0 END) as embedding_requests
    FROM ia_usage_logs l
    WHERE $whereStr
");
$stmt->execute($params);
$stats = $stmt->fetch();

// Proyectos para filtro
$proyectos = $pdo->query("SELECT id, nombre FROM ia_proyectos ORDER BY nombre")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs - IA Admin</title>
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
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }

        .filters {
            background: #222;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { color: #888; font-size: 12px; }
        .filter-group select, .filter-group input {
            padding: 8px 12px;
            border: 1px solid #444;
            border-radius: 6px;
            background: #1a1a2e;
            color: #fff;
            font-size: 14px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary { background: #00d9ff; color: #000; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-mini {
            background: #222;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-mini .value { font-size: 24px; font-weight: bold; color: #00d9ff; }
        .stat-mini .label { font-size: 11px; color: #888; margin-top: 5px; }

        .card {
            background: #222;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #333;
            font-size: 13px;
        }
        th { color: #888; font-size: 11px; text-transform: uppercase; }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
        }
        .badge-chat { background: #00d9ff33; color: #00d9ff; }
        .badge-embeddings { background: #00cc6633; color: #00cc66; }
        .badge-error { background: #ff444433; color: #ff4444; }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            background: #333;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: 13px;
        }
        .pagination a:hover { background: #444; }
        .pagination .active { background: #00d9ff; color: #000; }
        .pagination .disabled { opacity: 0.5; cursor: not-allowed; }

        .empty-state {
            color: #666;
            text-align: center;
            padding: 40px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>IA Admin</h1>
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="api-keys.php">API Keys</a>
            <a href="modelos.php">Modelos</a>
            <a href="logs.php" class="active">Logs</a>
            <a href="logout.php">Salir</a>
        </nav>
    </div>

    <div class="container">
        <form class="filters" method="GET">
            <div class="filter-group">
                <label>Proyecto</label>
                <select name="proyecto">
                    <option value="0">Todos</option>
                    <?php foreach ($proyectos as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $proyectoFiltro == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Endpoint</label>
                <select name="endpoint">
                    <option value="">Todos</option>
                    <option value="chat" <?php echo $endpointFiltro === 'chat' ? 'selected' : ''; ?>>Chat</option>
                    <option value="embeddings" <?php echo $endpointFiltro === 'embeddings' ? 'selected' : ''; ?>>Embeddings</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Desde</label>
                <input type="date" name="desde" value="<?php echo $fechaDesde; ?>">
            </div>
            <div class="filter-group">
                <label>Hasta</label>
                <input type="date" name="hasta" value="<?php echo $fechaHasta; ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </form>

        <div class="stats-row">
            <div class="stat-mini">
                <div class="value"><?php echo formatNumber($stats['total_requests'] ?? 0); ?></div>
                <div class="label">Requests</div>
            </div>
            <div class="stat-mini">
                <div class="value"><?php echo formatNumber($stats['chat_requests'] ?? 0); ?></div>
                <div class="label">Chat</div>
            </div>
            <div class="stat-mini">
                <div class="value"><?php echo formatNumber($stats['embedding_requests'] ?? 0); ?></div>
                <div class="label">Embeddings</div>
            </div>
            <div class="stat-mini">
                <div class="value"><?php echo formatNumber(($stats['total_tokens_in'] ?? 0) + ($stats['total_tokens_out'] ?? 0)); ?></div>
                <div class="label">Tokens Total</div>
            </div>
            <div class="stat-mini">
                <div class="value"><?php echo $stats['tiempo_promedio'] > 0 ? round($stats['tiempo_promedio'] / 1000, 1) . 's' : '-'; ?></div>
                <div class="label">Tiempo Prom.</div>
            </div>
        </div>

        <div class="card">
            <?php if (empty($logs)): ?>
                <p class="empty-state">No hay logs para el periodo seleccionado</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proyecto</th>
                            <th>Endpoint</th>
                            <th>Modelo</th>
                            <th>Tokens In</th>
                            <th>Tokens Out</th>
                            <th>Tiempo</th>
                            <th>IP</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['proyecto_nombre']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $log['endpoint']; ?>">
                                        <?php echo $log['endpoint']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['modelo'] ?? '-'); ?></td>
                                <td><?php echo number_format($log['tokens_input']); ?></td>
                                <td><?php echo number_format($log['tokens_output']); ?></td>
                                <td><?php echo $log['tiempo_ms'] ? round($log['tiempo_ms'] / 1000, 2) . 's' : '-'; ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($log['status_code'] === 200): ?>
                                        <span style="color: #00cc66;">OK</span>
                                    <?php else: ?>
                                        <span class="badge badge-error"><?php echo $log['status_code']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPaginas > 1): ?>
                    <div class="pagination">
                        <?php
                        $queryParams = $_GET;
                        unset($queryParams['page']);
                        $queryString = http_build_query($queryParams);
                        ?>

                        <?php if ($pagina > 1): ?>
                            <a href="?<?php echo $queryString; ?>&page=<?php echo $pagina - 1; ?>">← Anterior</a>
                        <?php endif; ?>

                        <?php
                        $inicio = max(1, $pagina - 2);
                        $fin = min($totalPaginas, $pagina + 2);
                        for ($i = $inicio; $i <= $fin; $i++):
                        ?>
                            <?php if ($i == $pagina): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo $queryString; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($pagina < $totalPaginas): ?>
                            <a href="?<?php echo $queryString; ?>&page=<?php echo $pagina + 1; ?>">Siguiente →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
