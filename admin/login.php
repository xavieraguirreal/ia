<?php
/**
 * Login Admin Panel
 */
session_start();
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_auth'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Password incorrecta';
    }
}

// Si ya esta logueado, redirigir
if (isset($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - IA Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            background: #222;
            padding: 40px;
            border-radius: 12px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        h1 {
            color: #00d9ff;
            text-align: center;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #888;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            color: #aaa;
            margin-bottom: 8px;
            font-size: 14px;
        }
        input[type="password"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #444;
            border-radius: 8px;
            background: #1a1a2e;
            color: #fff;
            font-size: 16px;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #00d9ff;
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #00d9ff 0%, #0099cc 100%);
            border: none;
            border-radius: 8px;
            color: #000;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button:hover { transform: scale(1.02); }
        .error {
            background: #ff4444;
            color: #fff;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>IA Admin</h1>
        <p class="subtitle">Panel de administracion</p>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" autofocus required>
            </div>
            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>
