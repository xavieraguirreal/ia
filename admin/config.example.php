<?php
/**
 * Configuracion del Admin Panel - EJEMPLO
 *
 * COPIAR este archivo como admin/config.php en cada entorno,
 * y completar con las credenciales reales. admin/config.php
 * NO se trackea en git (ver .gitignore).
 */

// Base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'verumax_ia');
define('DB_USER', 'verumax_admin');
define('DB_PASS', 'CAMBIAR_PASSWORD');

// Ollama
define('OLLAMA_URL', 'http://localhost:11434');

// FastAPI
define('FASTAPI_URL', 'http://localhost:8000');

// Admin
define('ADMIN_PASSWORD', 'CAMBIAR_PASSWORD');

// Timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Conexion PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Error de conexion: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Generar API Key unica
function generarApiKey($prefijo = 'sk') {
    return $prefijo . '_' . bin2hex(random_bytes(24));
}

// Verificar sesion admin
function requireAuth() {
    session_start();
    if (!isset($_SESSION['admin_auth']) || $_SESSION['admin_auth'] !== true) {
        header('Location: login.php');
        exit;
    }
}

// Formatear bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

// Formatear numero
function formatNumber($num) {
    if ($num >= 1000000) {
        return round($num / 1000000, 1) . 'M';
    } elseif ($num >= 1000) {
        return round($num / 1000, 1) . 'K';
    }
    return $num;
}
