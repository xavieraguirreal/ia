<?php
/**
 * Gestion de API Keys
 */
require_once 'config.php';
requireAuth();

$pdo = getDB();
$mensaje = '';
$error = '';

// Crear nueva API Key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $rateLimit = intval($_POST['rate_limit'] ?? 1000);
        $prefijo = trim($_POST['prefijo'] ?? 'sk');

        if (empty($nombre)) {
            $error = 'El nombre es requerido';
        } else {
            // Verificar nombre unico
            $stmt = $pdo->prepare("SELECT id FROM ia_proyectos WHERE nombre = ?");
            $stmt->execute([$nombre]);
            if ($stmt->fetch()) {
                $error = 'Ya existe un proyecto con ese nombre';
            } else {
                $apiKey = generarApiKey($prefijo);
                $stmt = $pdo->prepare("
                    INSERT INTO ia_proyectos (nombre, descripcion, api_key, rate_limit_diario)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $descripcion, $apiKey, $rateLimit]);
                $mensaje = "API Key creada: <code>$apiKey</code>";
            }
        }
    }

    if ($_POST['action'] === 'toggle') {
        $id = intval($_POST['id']);
        $pdo->prepare("UPDATE ia_proyectos SET activo = NOT activo WHERE id = ?")->execute([$id]);
        header('Location: api-keys.php');
        exit;
    }

    if ($_POST['action'] === 'regenerar') {
        $id = intval($_POST['id']);
        $prefijo = $_POST['prefijo'] ?? 'sk';
        $nuevaKey = generarApiKey($prefijo);
        $pdo->prepare("UPDATE ia_proyectos SET api_key = ? WHERE id = ?")->execute([$nuevaKey, $id]);
        $mensaje = "Nueva API Key: <code>$nuevaKey</code>";
    }

    if ($_POST['action'] === 'eliminar') {
        $id = intval($_POST['id']);
        $pdo->prepare("DELETE FROM ia_proyectos WHERE id = ?")->execute([$id]);
        header('Location: api-keys.php');
        exit;
    }

    if ($_POST['action'] === 'editar') {
        $id = intval($_POST['id']);
        $rateLimit = intval($_POST['rate_limit'] ?? 1000);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $pdo->prepare("UPDATE ia_proyectos SET rate_limit_diario = ?, descripcion = ? WHERE id = ?")
            ->execute([$rateLimit, $descripcion, $id]);
        $mensaje = 'Proyecto actualizado';
    }
}

// Obtener proyectos
$hoy = date('Y-m-d');
$proyectos = $pdo->query("
    SELECT p.*,
           COALESCE(d.total_requests, 0) as uso_hoy,
           COALESCE(d.total_tokens_input + d.total_tokens_output, 0) as tokens_hoy
    FROM ia_proyectos p
    LEFT JOIN ia_usage_daily d ON p.id = d.proyecto_id AND d.fecha = '$hoy'
    ORDER BY p.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys - IA Admin</title>
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

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .header h2 { color: #fff; }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #00d9ff;
            color: #000;
            font-weight: bold;
        }
        .btn-danger { background: #cc3333; color: #fff; }
        .btn-secondary { background: #444; color: #fff; }
        .btn-small { padding: 6px 12px; font-size: 12px; }

        .card {
            background: #222;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .mensaje {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .mensaje.success { background: #00cc6633; border: 1px solid #00cc66; }
        .mensaje.error { background: #cc333333; border: 1px solid #cc3333; }
        .mensaje code {
            background: #000;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 5px;
            font-size: 14px;
            word-break: break-all;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            color: #888;
            margin-bottom: 5px;
            font-size: 13px;
        }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #444;
            border-radius: 6px;
            background: #1a1a2e;
            color: #fff;
            font-size: 14px;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none;
            border-color: #00d9ff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        th { color: #888; font-size: 12px; text-transform: uppercase; }
        td { font-size: 14px; }

        .api-key-display {
            font-family: monospace;
            background: #1a1a2e;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        .status-active { color: #00cc66; }
        .status-inactive { color: #888; }

        .progress-bar {
            background: #333;
            border-radius: 4px;
            height: 6px;
            overflow: hidden;
            width: 100px;
        }
        .progress-fill {
            height: 100%;
            background: #00d9ff;
            border-radius: 4px;
        }
        .progress-fill.warning { background: #ffcc00; }
        .progress-fill.danger { background: #cc3333; }

        .actions { display: flex; gap: 5px; }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: #222;
            padding: 30px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .modal-close {
            background: none;
            border: none;
            color: #888;
            font-size: 24px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>IA Admin</h1>
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="api-keys.php" class="active">API Keys</a>
            <a href="modelos.php">Modelos</a>
            <a href="logs.php">Logs</a>
            <a href="logout.php">Salir</a>
        </nav>
    </div>

    <div class="container">
        <?php if ($mensaje): ?>
            <div class="mensaje success"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mensaje error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="header">
            <h2>API Keys</h2>
            <button class="btn btn-primary" onclick="document.getElementById('modalCrear').classList.add('show')">
                + Nueva API Key
            </button>
        </div>

        <div class="card">
            <?php if (empty($proyectos)): ?>
                <p style="color: #888; text-align: center; padding: 40px;">
                    No hay API keys creadas. Crea una para empezar.
                </p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Proyecto</th>
                            <th>API Key</th>
                            <th>Limite Diario</th>
                            <th>Uso Hoy</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proyectos as $p): ?>
                            <?php
                            $porcentaje = $p['rate_limit_diario'] > 0 ? ($p['uso_hoy'] / $p['rate_limit_diario']) * 100 : 0;
                            $progressClass = $porcentaje > 90 ? 'danger' : ($porcentaje > 70 ? 'warning' : '');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($p['nombre']); ?></strong>
                                    <?php if ($p['descripcion']): ?>
                                        <br><small style="color: #888;"><?php echo htmlspecialchars($p['descripcion']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="api-key-display">
                                        <?php echo substr($p['api_key'], 0, 12); ?>...
                                    </span>
                                    <button class="btn btn-secondary btn-small" onclick="copiarKey('<?php echo $p['api_key']; ?>')">Copiar</button>
                                </td>
                                <td><?php echo number_format($p['rate_limit_diario']); ?>/dia</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span><?php echo number_format($p['uso_hoy']); ?></span>
                                        <div class="progress-bar">
                                            <div class="progress-fill <?php echo $progressClass; ?>"
                                                 style="width: <?php echo min($porcentaje, 100); ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="<?php echo $p['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $p['activo'] ? '● Activo' : '○ Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-secondary btn-small"
                                                onclick="editarProyecto(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                            Editar
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-small">
                                                <?php echo $p['activo'] ? 'Desactivar' : 'Activar'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Crear -->
    <div id="modalCrear" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Nueva API Key</h3>
                <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="crear">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre del Proyecto *</label>
                        <input type="text" name="nombre" required placeholder="ej: tramaeducativa">
                    </div>
                    <div class="form-group">
                        <label>Prefijo de Key</label>
                        <select name="prefijo">
                            <option value="sk">sk_ (standard)</option>
                            <option value="pk">pk_ (produccion)</option>
                            <option value="tk">tk_ (test)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Descripcion</label>
                    <textarea name="descripcion" rows="2" placeholder="Descripcion opcional del proyecto"></textarea>
                </div>
                <div class="form-group">
                    <label>Limite Diario (requests)</label>
                    <input type="number" name="rate_limit" value="1000" min="1">
                </div>
                <button type="submit" class="btn btn-primary">Crear API Key</button>
            </form>
        </div>
    </div>

    <!-- Modal Editar -->
    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Editar Proyecto</h3>
                <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')">&times;</button>
            </div>
            <form method="POST" id="formEditar">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" id="editId">
                <div class="form-group">
                    <label>Nombre</label>
                    <input type="text" id="editNombre" disabled style="opacity: 0.7;">
                </div>
                <div class="form-group">
                    <label>Descripcion</label>
                    <textarea name="descripcion" id="editDescripcion" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Limite Diario (requests)</label>
                    <input type="number" name="rate_limit" id="editRateLimit" min="1">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Guardar</button>
                    <button type="button" class="btn btn-secondary" onclick="regenerarKey()">Regenerar Key</button>
                    <button type="button" class="btn btn-danger" onclick="eliminarProyecto()">Eliminar</button>
                </div>
            </form>
            <form method="POST" id="formRegenerar" style="display:none;">
                <input type="hidden" name="action" value="regenerar">
                <input type="hidden" name="id" id="regenerarId">
                <input type="hidden" name="prefijo" value="sk">
            </form>
            <form method="POST" id="formEliminar" style="display:none;">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" id="eliminarId">
            </form>
        </div>
    </div>

    <script>
        function copiarKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                alert('API Key copiada!');
            });
        }

        function editarProyecto(p) {
            document.getElementById('editId').value = p.id;
            document.getElementById('editNombre').value = p.nombre;
            document.getElementById('editDescripcion').value = p.descripcion || '';
            document.getElementById('editRateLimit').value = p.rate_limit_diario;
            document.getElementById('regenerarId').value = p.id;
            document.getElementById('eliminarId').value = p.id;
            document.getElementById('modalEditar').classList.add('show');
        }

        function regenerarKey() {
            if (confirm('¿Regenerar API Key? La key anterior dejara de funcionar.')) {
                document.getElementById('formRegenerar').submit();
            }
        }

        function eliminarProyecto() {
            if (confirm('¿Eliminar este proyecto? Se perdera todo el historial de uso.')) {
                document.getElementById('formEliminar').submit();
            }
        }

        // Cerrar modal con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(m => m.classList.remove('show'));
            }
        });
    </script>
</body>
</html>
