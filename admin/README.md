# IA Admin Panel

Panel de administracion para gestionar API keys, monitorear uso y controlar modelos de IA local.

## Instalacion

### 1. Crear base de datos

```sql
CREATE DATABASE verumax_ia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego ejecutar el schema:

```bash
mysql -u root -p verumax_ia < sql/schema.sql
```

### 2. Configurar conexion

Editar `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'verumax_ia');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_password');
define('ADMIN_PASSWORD', 'tu_password_admin');  // CAMBIAR!
```

### 3. Subir al servidor

Copiar la carpeta `admin` a:

```
/var/www/verumax.com/ia/admin/
```

### 4. Acceder

```
https://verumax.com/ia/admin/
```

---

## Estructura

```
admin/
├── config.php           # Configuracion (DB, passwords)
├── api-middleware.php   # Middleware para validar API keys
├── login.php           # Login
├── logout.php          # Logout
├── index.php           # Dashboard
├── api-keys.php        # Gestion de API keys
├── modelos.php         # Monitor de modelos
├── logs.php            # Logs de uso
├── api/                # Endpoints con autenticacion
│   ├── chat.php       # POST /api/chat.php
│   └── embeddings.php # POST /api/embeddings.php
└── sql/
    └── schema.sql     # Estructura de tablas
```

---

## Uso desde otros proyectos

### Opcion 1: Usar los endpoints del admin (recomendado)

```php
// Desde cualquier proyecto en el servidor
$apiKey = 'sk_tuproyecto_xxx';

$ch = curl_init('http://localhost/ia/admin/api/chat.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'qwen2.5:7b-instruct',
        'messages' => [
            ['role' => 'user', 'content' => 'Hola']
        ]
    ])
]);
$response = json_decode(curl_exec($ch), true);
echo $response['choices'][0]['message']['content'];
```

### Opcion 2: Incluir middleware en tu proyecto

```php
// En tu proyecto
require_once '/var/www/verumax.com/ia/admin/api-middleware.php';

// Validar key
$auth = validarApiKey('Bearer ' . $apiKey);
if (isset($auth['error'])) {
    die($auth['error']);
}

// Usar helpers
$resultado = ollamaChat('qwen2.5:7b-instruct', $messages);
$embedding = ollamaEmbedding('texto');

// Registrar uso
registrarUso($auth['proyecto_id'], 'chat', 'qwen', 100, 50, 2000);
```

### Opcion 3: Llamar Ollama directo (sin logging)

```php
// Sin autenticacion ni logging
$ch = curl_init('http://localhost:11434/api/chat');
// ...
```

---

## API Endpoints

### POST /api/chat.php

Chat completion (formato OpenAI).

**Headers:**
- `Authorization: Bearer sk_xxx`
- `Content-Type: application/json`

**Body:**
```json
{
    "model": "qwen2.5:7b-instruct",
    "messages": [
        {"role": "user", "content": "Hola"}
    ]
}
```

**Response:**
```json
{
    "choices": [{
        "message": {
            "role": "assistant",
            "content": "Hola! Como puedo ayudarte?"
        }
    }],
    "usage": {
        "prompt_tokens": 10,
        "completion_tokens": 8,
        "total_tokens": 18
    }
}
```

### POST /api/embeddings.php

Generar embeddings (formato OpenAI).

**Body:**
```json
{
    "model": "nomic-embed-text",
    "input": "Texto para embedding"
}
```

**Response:**
```json
{
    "data": [{
        "embedding": [0.123, -0.456, ...]
    }],
    "usage": {
        "total_tokens": 5
    }
}
```

---

## Rate Limiting

Cada API key tiene un limite diario configurable:

- Default: 1000 requests/dia
- Se puede ajustar por proyecto
- Cuando llega al 100%: retorna HTTP 429
- Se resetea a medianoche

---

## Alertas

El sistema genera alertas automaticas para:

- Modelo nuevo instalado
- Modelo actualizado (cambio de digest)
- Proyecto llego a rate limit
- Errores de API

Ver alertas en Dashboard o tabla `ia_alertas`.

---

## Cron para monitoreo

Opcional: agregar cron para verificar modelos cada hora:

```bash
0 * * * * php /var/www/verumax.com/ia/admin/cron-check-models.php
```

(Crear el archivo basandose en el codigo de modelos.php)
