# Guia de Uso - IA Local (Ollama + FastAPI)

## Arquitectura General

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              VPS verumax                                 │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│   SERVICIOS DEL SISTEMA (siempre corriendo)                             │
│   ══════════════════════════════════════════                            │
│                                                                          │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │  OLLAMA (localhost:11434)                                       │   │
│   │  ─────────────────────────────────────────────────────────────  │   │
│   │  Modelos Chat:                                                  │   │
│   │    • qwen2.5:7b-instruct      (general, censurado)             │   │
│   │    • dolphin-mistral:7b-v2.6  (uncensored)                     │   │
│   │    • dolphin-llama3:8b        (uncensored)                     │   │
│   │    • escritor-wizard          (erotico, custom prompt)         │   │
│   │    • escritor-dolphin         (erotico, custom prompt)         │   │
│   │    • escritor-llama3          (erotico, custom prompt)         │   │
│   │                                                                 │   │
│   │  Modelos Embeddings:                                            │   │
│   │    • nomic-embed-text         (768 dimensiones)                │   │
│   └─────────────────────────────────────────────────────────────────┘   │
│                              │                                           │
│                              │                                           │
│   ┌─────────────────────────────────────────────────────────────────┐   │
│   │  FastAPI (localhost:8000)                                       │   │
│   │  ─────────────────────────────────────────────────────────────  │   │
│   │  Wrapper compatible con OpenAI API                              │   │
│   │  Autenticacion por API Key                                      │   │
│   │  Endpoints: /v1/chat/completions, /v1/embeddings               │   │
│   └─────────────────────────────────────────────────────────────────┘   │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│   PROYECTOS WEB                                                          │
│   ═════════════                                                          │
│                                                                          │
│   /var/www/verumax.com/ia/test/    → Chat de pruebas                    │
│   /var/www/verumax.com/ia/api/     → Codigo FastAPI                     │
│   /var/www/tramaeducativa/         → Laravel (puede usar IA)            │
│   /var/www/juliana/                → Futuro proyecto RAG                │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Cuando Usar Cada Opcion

### Opcion 1: Ollama Directo (localhost:11434)

**Usar cuando:**
- El proyecto corre en el MISMO servidor
- Queres maxima velocidad (sin intermediario)
- No necesitas compatibilidad con OpenAI
- Proyecto interno/privado

**Ventajas:**
- Mas rapido (una llamada menos)
- Sin autenticacion necesaria
- Mas simple

**Desventajas:**
- Formato de API diferente a OpenAI
- Solo accesible desde el servidor

---

### Opcion 2: FastAPI (localhost:8000)

**Usar cuando:**
- Queres reemplazar OpenAI con minimos cambios de codigo
- Tu codigo ya usa formato OpenAI
- Necesitas autenticacion por API key
- Proyecto interno que podria exponerse despues

**Ventajas:**
- Compatible con OpenAI SDK
- Facil migracion desde OpenAI
- Autenticacion incluida

**Desventajas:**
- Un poco mas lento (pasa por wrapper)

---

### Opcion 3: FastAPI Expuesta (puerto publico)

**Usar cuando:**
- Proyectos EXTERNOS necesitan acceder
- Apps moviles o sitios en otros servidores
- Queres dar acceso a terceros

**Requiere:**
- Configurar nginx/apache como proxy
- HTTPS obligatorio
- API keys por proyecto
- Rate limiting

---

## Tabla Resumen

| Escenario | Usar | Puerto | Auth |
|-----------|------|--------|------|
| Proyecto PHP en mismo servidor | Ollama directo | 11434 | No |
| Reemplazar OpenAI en Laravel | FastAPI | 8000 | Si |
| App externa / otro servidor | FastAPI + proxy | 443 | Si |
| Chat de pruebas | Ollama directo | 11434 | No |
| julIAna (RAG) | Ollama directo | 11434 | No |

---

## Ejemplos de Codigo

### 1. Ollama Directo - Chat

```php
<?php
/**
 * Chat directo con Ollama
 * Usar para: proyectos internos en el mismo servidor
 */

function chatOllama($mensaje, $modelo = 'qwen2.5:7b-instruct') {
    $ch = curl_init('http://localhost:11434/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $modelo,
            'messages' => [
                ['role' => 'user', 'content' => $mensaje]
            ],
            'stream' => false
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['message']['content'] ?? null;
}

// Uso
$respuesta = chatOllama('Hola, como estas?');
echo $respuesta;
```

### 2. Ollama Directo - Chat con Historial

```php
<?php
/**
 * Chat con memoria de conversacion
 */

function chatConHistorial($mensajeNuevo, $historial = [], $modelo = 'qwen2.5:7b-instruct') {
    // Agregar mensaje del usuario al historial
    $historial[] = ['role' => 'user', 'content' => $mensajeNuevo];

    $ch = curl_init('http://localhost:11434/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $modelo,
            'messages' => $historial,
            'stream' => false
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $respuesta = $data['message']['content'] ?? '';

    // Agregar respuesta al historial
    $historial[] = ['role' => 'assistant', 'content' => $respuesta];

    return [
        'respuesta' => $respuesta,
        'historial' => $historial
    ];
}

// Uso con sesion
session_start();
if (!isset($_SESSION['chat'])) $_SESSION['chat'] = [];

$resultado = chatConHistorial('Hola!', $_SESSION['chat']);
$_SESSION['chat'] = $resultado['historial'];
echo $resultado['respuesta'];
```

### 3. Ollama Directo - Embeddings

```php
<?php
/**
 * Generar embeddings directo con Ollama
 * Retorna array de 768 floats
 */

function generarEmbedding($texto) {
    $ch = curl_init('http://localhost:11434/api/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'nomic-embed-text',
            'prompt' => $texto
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['embedding'] ?? null; // Array de 768 floats
}

// Uso
$embedding = generarEmbedding('La educacion es fundamental');
echo "Dimensiones: " . count($embedding); // 768
```

### 4. Ollama Directo - Streaming

```php
<?php
/**
 * Chat con streaming (respuesta en tiempo real)
 * Usar para: interfaces de chat interactivas
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

if (ob_get_level()) ob_end_clean();

$mensaje = $_POST['mensaje'] ?? 'Hola';
$modelo = 'qwen2.5:7b-instruct';

$ch = curl_init('http://localhost:11434/api/chat');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 300,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $modelo,
        'messages' => [['role' => 'user', 'content' => $mensaje]],
        'stream' => true
    ]),
    CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
        $lines = explode("\n", $chunk);
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $data = json_decode($line, true);
            if ($data && isset($data['message']['content'])) {
                echo "data: " . json_encode(['token' => $data['message']['content']]) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            }
        }
        return strlen($chunk);
    }
]);

curl_exec($ch);
curl_close($ch);

echo "data: [DONE]\n\n";
```

### 5. FastAPI - Formato OpenAI (para reemplazar OpenAI)

```php
<?php
/**
 * Usar FastAPI cuando queres compatibilidad con OpenAI
 * Ideal para migrar proyectos que ya usan OpenAI
 */

$apiKey = 'tu-api-key-aqui';

function chatOpenAICompatible($mensaje, $apiKey) {
    $ch = curl_init('http://localhost:8000/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'qwen2.5:7b-instruct',
            'messages' => [
                ['role' => 'user', 'content' => $mensaje]
            ],
            'max_tokens' => 1000,
            'temperature' => 0.7
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function embeddingsOpenAICompatible($texto, $apiKey) {
    $ch = curl_init('http://localhost:8000/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'nomic-embed-text',
            'input' => $texto
        ])
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['data'][0]['embedding'] ?? null;
}
```

### 6. Laravel - Reemplazar OpenAI

```php
<?php
// En Laravel, si ya usas el SDK de OpenAI, solo cambias la URL base

// config/services.php
return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'http://localhost:8000/v1'),
    ],
];

// .env
OPENAI_API_KEY=tu-api-key-local
OPENAI_BASE_URL=http://localhost:8000/v1

// Uso (el codigo no cambia!)
use OpenAI\Laravel\Facades\OpenAI;

$response = OpenAI::chat()->create([
    'model' => 'qwen2.5:7b-instruct',
    'messages' => [
        ['role' => 'user', 'content' => 'Hola'],
    ],
]);
```

---

## Clase Helper Completa

```php
<?php
/**
 * LocalAI.php - Clase helper para usar IA local
 * Copiar a tu proyecto y usar
 */

class LocalAI {
    private $ollamaUrl = 'http://localhost:11434';
    private $fastapiUrl = 'http://localhost:8000';
    private $apiKey;
    private $defaultChatModel = 'qwen2.5:7b-instruct';
    private $defaultEmbeddingModel = 'nomic-embed-text';

    public function __construct($apiKey = null) {
        $this->apiKey = $apiKey;
    }

    /**
     * Chat directo con Ollama (mas rapido)
     */
    public function chat($mensaje, $modelo = null, $historial = []) {
        $modelo = $modelo ?? $this->defaultChatModel;
        $historial[] = ['role' => 'user', 'content' => $mensaje];

        $ch = curl_init($this->ollamaUrl . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $modelo,
                'messages' => $historial,
                'stream' => false
            ])
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => $error];
        }

        $data = json_decode($response, true);
        $respuesta = $data['message']['content'] ?? '';
        $historial[] = ['role' => 'assistant', 'content' => $respuesta];

        return [
            'respuesta' => $respuesta,
            'historial' => $historial,
            'modelo' => $modelo
        ];
    }

    /**
     * Generar embedding directo con Ollama
     */
    public function embedding($texto, $modelo = null) {
        $modelo = $modelo ?? $this->defaultEmbeddingModel;

        $ch = curl_init($this->ollamaUrl . '/api/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $modelo,
                'prompt' => $texto
            ])
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['embedding'] ?? null;
    }

    /**
     * Multiples embeddings de una vez
     */
    public function embeddings($textos) {
        $resultados = [];
        foreach ($textos as $texto) {
            $resultados[] = $this->embedding($texto);
        }
        return $resultados;
    }

    /**
     * Similitud coseno entre dos embeddings
     */
    public function similitud($embedding1, $embedding2) {
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $norm1 += $embedding1[$i] ** 2;
            $norm2 += $embedding2[$i] ** 2;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Buscar textos mas similares
     */
    public function buscarSimilares($query, $documentos, $topK = 5) {
        $queryEmb = $this->embedding($query);
        $scores = [];

        foreach ($documentos as $i => $doc) {
            $docEmb = is_array($doc['embedding']) ? $doc['embedding'] : $this->embedding($doc['texto']);
            $scores[$i] = [
                'indice' => $i,
                'texto' => $doc['texto'],
                'score' => $this->similitud($queryEmb, $docEmb)
            ];
        }

        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scores, 0, $topK);
    }

    /**
     * Generar tags para un texto
     */
    public function generarTags($texto, $cantidad = 5) {
        $prompt = "Genera exactamente $cantidad tags/etiquetas relevantes para este texto.
        Responde SOLO con las etiquetas separadas por comas, sin numeros ni explicaciones.

        Texto: $texto";

        $resultado = $this->chat($prompt);
        $tags = array_map('trim', explode(',', $resultado['respuesta']));
        return array_slice($tags, 0, $cantidad);
    }

    /**
     * Generar resumen
     */
    public function generarResumen($texto, $maxPuntos = 4) {
        $prompt = "Resume este texto en maximo $maxPuntos puntos clave.
        Cada punto debe ser conciso (maximo 15 palabras).
        Responde SOLO con los bullet points, sin introduccion.
        Usa el formato: • Punto

        Texto: $texto";

        $resultado = $this->chat($prompt);
        return $resultado['respuesta'];
    }

    /**
     * Listar modelos disponibles
     */
    public function listarModelos() {
        $ch = curl_init($this->ollamaUrl . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['models'] ?? [];
    }
}

// ============ USO ============

$ai = new LocalAI();

// Chat simple
$resultado = $ai->chat('Hola, como estas?');
echo $resultado['respuesta'];

// Chat con historial
$historial = [];
$r1 = $ai->chat('Me llamo Juan', 'qwen2.5:7b-instruct', $historial);
$historial = $r1['historial'];
$r2 = $ai->chat('Como me llamo?', 'qwen2.5:7b-instruct', $historial);
echo $r2['respuesta']; // Deberia decir "Juan"

// Embedding
$emb = $ai->embedding('La educacion es importante');
echo "Dimensiones: " . count($emb); // 768

// Tags
$tags = $ai->generarTags('El gobierno anuncio nuevas medidas economicas...');
print_r($tags);

// Resumen
$resumen = $ai->generarResumen('Texto largo aqui...');
echo $resumen;

// Busqueda semantica
$docs = [
    ['texto' => 'Python es un lenguaje de programacion'],
    ['texto' => 'Los gatos son mascotas populares'],
    ['texto' => 'JavaScript se usa para web'],
];
$similares = $ai->buscarSimilares('programacion web', $docs, 2);
print_r($similares);
```

---

## API Keys y Administracion

### Sistema de API Keys Propuesto

```
┌─────────────────────────────────────────────────────────────┐
│                    ADMIN PANEL (/ia/admin/)                  │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Proyectos Registrados                                       │
│  ─────────────────────────────────────────────────────────  │
│                                                              │
│  Proyecto          API Key                    Requests/dia   │
│  ───────────────────────────────────────────────────────── │
│  tramaeducativa    sk_trama_abc123...         1,234         │
│  juliana           sk_juliana_def456...       567           │
│  app-movil         sk_movil_ghi789...         2,100         │
│                                                              │
│  [+ Nuevo Proyecto]                                          │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Estadisticas Globales                                       │
│  • Total requests hoy: 3,901                                │
│  • Modelos mas usados: qwen (60%), nomic (35%), otros (5%) │
│  • Tiempo promedio respuesta: 2.3s                          │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Tabla MySQL para API Keys

```sql
CREATE TABLE ia_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto VARCHAR(100) NOT NULL,
    api_key VARCHAR(255) NOT NULL UNIQUE,
    activo BOOLEAN DEFAULT TRUE,
    rate_limit INT DEFAULT 1000,  -- requests por dia
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME,
    INDEX idx_api_key (api_key)
);

CREATE TABLE ia_usage_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT,
    endpoint VARCHAR(50),  -- chat, embeddings
    modelo VARCHAR(100),
    tokens_input INT,
    tokens_output INT,
    tiempo_ms INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_key_id) REFERENCES ia_api_keys(id),
    INDEX idx_created (created_at)
);
```

---

## Diferencias de Formato: Ollama vs OpenAI

### Embeddings

```
OLLAMA DIRECTO:
POST /api/embeddings
{
    "model": "nomic-embed-text",
    "prompt": "texto"           ← prompt (singular)
}
Response: { "embedding": [...] }  ← embedding (singular)

OPENAI / FASTAPI:
POST /v1/embeddings
{
    "model": "nomic-embed-text",
    "input": "texto"            ← input (puede ser array)
}
Response: { "data": [{ "embedding": [...] }] }  ← dentro de data[]
```

### Chat

```
OLLAMA DIRECTO:
POST /api/chat
{
    "model": "qwen2.5:7b-instruct",
    "messages": [...],
    "stream": false
}
Response: { "message": { "content": "..." } }

OPENAI / FASTAPI:
POST /v1/chat/completions
{
    "model": "qwen2.5:7b-instruct",
    "messages": [...],
    "max_tokens": 1000
}
Response: { "choices": [{ "message": { "content": "..." } }] }
```

---

## Modelos Disponibles

### Para Chat

| Modelo | ID | Uso | Censurado |
|--------|-----|-----|-----------|
| Qwen 2.5 7B | `qwen2.5:7b-instruct` | General | Si |
| Dolphin Mistral | `dolphin-mistral:7b-v2.6` | General | No |
| Dolphin Llama3 | `dolphin-llama3:8b` | General | No |
| Escritor Wizard | `escritor-wizard` | Erotico | No |
| Escritor Mistral | `escritor-dolphin` | Erotico | No |
| Escritor Llama3 | `escritor-llama3` | Erotico | No |

### Para Embeddings

| Modelo | ID | Dimensiones |
|--------|-----|-------------|
| Nomic | `nomic-embed-text` | 768 |

---

## Comandos Utiles

```bash
# Ver modelos instalados
ollama list

# Probar modelo
ollama run qwen2.5:7b-instruct "hola"

# Ver logs Ollama
journalctl -u ollama -f

# Ver logs FastAPI
journalctl -u local-ai-api -f

# Reiniciar servicios
systemctl restart ollama
systemctl restart local-ai-api

# Ver uso de RAM
free -h

# Test API directo
curl http://localhost:11434/api/chat \
  -d '{"model":"qwen2.5:7b-instruct","messages":[{"role":"user","content":"hola"}]}'

# Test FastAPI
curl http://localhost:8000/v1/chat/completions \
  -H "Authorization: Bearer TU_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"qwen2.5:7b-instruct","messages":[{"role":"user","content":"hola"}]}'
```

---

## Panel Admin - Funcionalidades

### Dashboard Principal

```
┌─────────────────────────────────────────────────────────────────────────┐
│  🤖 IA Admin                                          [Cerrar Sesion]   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐    │
│  │   3,901     │  │    2.3s     │  │  6 modelos  │  │  ⚠️ 1 aviso │    │
│  │ Requests    │  │  Promedio   │  │ Disponibles │  │  Modelo     │    │
│  │    hoy      │  │  respuesta  │  │             │  │ actualizado │    │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘    │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  📊 Uso por Proyecto (ultimos 7 dias)                                   │
│  ─────────────────────────────────────────────────────────────────────  │
│                                                                          │
│  tramaeducativa  ████████████████████████░░░░░  8,432 requests          │
│  juliana         ██████████░░░░░░░░░░░░░░░░░░░  3,567 requests          │
│  app-movil       ████████████████░░░░░░░░░░░░░  5,890 requests          │
│                                                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  🔔 Alertas                                                              │
│  ─────────────────────────────────────────────────────────────────────  │
│  ⚠️ qwen2.5:7b-instruct actualizado hace 2 dias (version anterior: 1.2) │
│  ✅ Todos los servicios funcionando                                      │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### Gestion de API Keys

```
┌─────────────────────────────────────────────────────────────────────────┐
│  API Keys                                            [+ Nueva API Key]  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Proyecto          API Key              Limite    Usado Hoy   Estado    │
│  ───────────────────────────────────────────────────────────────────── │
│  tramaeducativa    sk_trama_abc1***     5000/dia  1,234       ✅ Activo │
│  juliana           sk_juliana_def4***   2000/dia    567       ✅ Activo │
│  app-movil         sk_movil_ghi7***     10000/dia 2,100       ✅ Activo │
│  test-externo      sk_test_xyz9***      100/dia      98       ⚠️ 98%   │
│                                                                          │
│  [Ver detalles] [Editar] [Desactivar] [Regenerar Key]                   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### Detalle por Proyecto

```
┌─────────────────────────────────────────────────────────────────────────┐
│  📊 tramaeducativa - Estadisticas                                       │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Periodo: [Hoy ▼]  [Ayer]  [7 dias]  [30 dias]  [Personalizado]        │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │  Requests por hora                                                │  │
│  │                                                                   │  │
│  │  150│      ██                                                     │  │
│  │  100│    ████████                    ██                          │  │
│  │   50│  ████████████████        ████████████                      │  │
│  │    0│──────────────────────────────────────────                  │  │
│  │      00  04  08  12  16  20  24                                  │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  Por Endpoint:                      Por Modelo:                         │
│  • /chat/completions: 890 (72%)     • qwen2.5:7b: 650 (53%)            │
│  • /embeddings: 344 (28%)           • nomic-embed: 344 (28%)           │
│                                      • dolphin: 240 (19%)               │
│                                                                          │
│  Tokens consumidos:                                                      │
│  • Input: 234,567 tokens                                                │
│  • Output: 89,012 tokens                                                │
│  • Total: 323,579 tokens                                                │
│                                                                          │
│  Tiempo de respuesta:                                                    │
│  • Promedio: 2.3s                                                       │
│  • Minimo: 0.8s                                                         │
│  • Maximo: 15.2s                                                        │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

### Monitor de Modelos

```
┌─────────────────────────────────────────────────────────────────────────┐
│  🤖 Modelos Instalados                                    [Actualizar]  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Modelo                    Tamaño    Ultima Mod.    Estado              │
│  ─────────────────────────────────────────────────────────────────────  │
│  qwen2.5:7b-instruct       4.7 GB    22/01/2026     ⚠️ Actualizado     │
│  dolphin-mistral:7b-v2.6   4.1 GB    15/01/2026     ✅ Sin cambios      │
│  dolphin-llama3:8b         4.7 GB    15/01/2026     ✅ Sin cambios      │
│  nomic-embed-text          274 MB    10/01/2026     ✅ Sin cambios      │
│  escritor-wizard           4.1 GB    24/01/2026     🆕 Nuevo           │
│  escritor-dolphin          4.1 GB    24/01/2026     🆕 Nuevo           │
│  escritor-llama3           4.7 GB    24/01/2026     🆕 Nuevo           │
│                                                                          │
│  ─────────────────────────────────────────────────────────────────────  │
│  ⚠️ qwen2.5:7b-instruct fue actualizado el 22/01/2026                   │
│     Cambios detectados en el digest del modelo.                         │
│     Recomendacion: Probar que las respuestas sigan siendo correctas.   │
│     [Marcar como revisado] [Ver historial]                              │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Esquema de Base de Datos Completo

```sql
-- ============================================
-- TABLAS PARA ADMIN DE API
-- ============================================

-- Proyectos y API Keys
CREATE TABLE ia_proyectos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT,
    api_key VARCHAR(255) NOT NULL UNIQUE,
    rate_limit_diario INT DEFAULT 1000,
    activo BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_key (api_key),
    INDEX idx_nombre (nombre)
);

-- Logs de uso (para estadisticas)
CREATE TABLE ia_usage_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    endpoint ENUM('chat', 'embeddings') NOT NULL,
    modelo VARCHAR(100),
    tokens_input INT DEFAULT 0,
    tokens_output INT DEFAULT 0,
    tiempo_ms INT,
    ip_address VARCHAR(45),
    status_code INT DEFAULT 200,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proyecto_id) REFERENCES ia_proyectos(id),
    INDEX idx_proyecto_fecha (proyecto_id, created_at),
    INDEX idx_created (created_at),
    INDEX idx_endpoint (endpoint)
);

-- Contadores diarios (para rate limiting rapido)
CREATE TABLE ia_usage_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proyecto_id INT NOT NULL,
    fecha DATE NOT NULL,
    total_requests INT DEFAULT 0,
    total_tokens_input BIGINT DEFAULT 0,
    total_tokens_output BIGINT DEFAULT 0,
    total_tiempo_ms BIGINT DEFAULT 0,
    UNIQUE KEY uk_proyecto_fecha (proyecto_id, fecha),
    FOREIGN KEY (proyecto_id) REFERENCES ia_proyectos(id)
);

-- Monitor de modelos
CREATE TABLE ia_modelos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    digest VARCHAR(255),  -- Hash del modelo para detectar cambios
    tamano_bytes BIGINT,
    tipo ENUM('chat', 'embedding') NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    ultima_verificacion DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
);

-- Historial de cambios en modelos
CREATE TABLE ia_modelos_historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modelo_id INT NOT NULL,
    digest_anterior VARCHAR(255),
    digest_nuevo VARCHAR(255),
    detectado_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    revisado BOOLEAN DEFAULT FALSE,
    revisado_at DATETIME,
    notas TEXT,
    FOREIGN KEY (modelo_id) REFERENCES ia_modelos(id)
);

-- Alertas del sistema
CREATE TABLE ia_alertas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('modelo_actualizado', 'rate_limit', 'error', 'servicio_caido') NOT NULL,
    mensaje TEXT NOT NULL,
    datos JSON,
    leida BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_leida (leida),
    INDEX idx_created (created_at)
);
```

---

## Script de Monitoreo de Modelos

```php
<?php
/**
 * cron-check-models.php
 * Ejecutar cada hora: 0 * * * * php /path/to/cron-check-models.php
 *
 * Detecta si Ollama actualizo algun modelo
 */

require_once 'config.php'; // Conexion DB

// Obtener modelos de Ollama
$ch = curl_init('http://localhost:11434/api/tags');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$modelos = json_decode($response, true)['models'] ?? [];

foreach ($modelos as $modelo) {
    $nombre = $modelo['name'];
    $digest = $modelo['digest'];
    $tamano = $modelo['size'];

    // Buscar en DB
    $stmt = $pdo->prepare("SELECT id, digest FROM ia_modelos WHERE nombre = ?");
    $stmt->execute([$nombre]);
    $existente = $stmt->fetch();

    if (!$existente) {
        // Modelo nuevo
        $stmt = $pdo->prepare("
            INSERT INTO ia_modelos (nombre, digest, tamano_bytes, tipo, ultima_verificacion)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $tipo = strpos($nombre, 'embed') !== false ? 'embedding' : 'chat';
        $stmt->execute([$nombre, $digest, $tamano, $tipo]);

        // Crear alerta
        crearAlerta('modelo_actualizado', "Nuevo modelo detectado: $nombre", [
            'modelo' => $nombre,
            'accion' => 'nuevo'
        ]);

    } elseif ($existente['digest'] !== $digest) {
        // Modelo actualizado!
        $stmt = $pdo->prepare("
            INSERT INTO ia_modelos_historial (modelo_id, digest_anterior, digest_nuevo)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$existente['id'], $existente['digest'], $digest]);

        $stmt = $pdo->prepare("
            UPDATE ia_modelos SET digest = ?, ultima_verificacion = NOW() WHERE id = ?
        ");
        $stmt->execute([$digest, $existente['id']]);

        // Crear alerta
        crearAlerta('modelo_actualizado', "Modelo actualizado: $nombre", [
            'modelo' => $nombre,
            'digest_anterior' => substr($existente['digest'], 0, 12),
            'digest_nuevo' => substr($digest, 0, 12),
            'accion' => 'actualizado'
        ]);
    } else {
        // Sin cambios, solo actualizar fecha verificacion
        $stmt = $pdo->prepare("UPDATE ia_modelos SET ultima_verificacion = NOW() WHERE id = ?");
        $stmt->execute([$existente['id']]);
    }
}

function crearAlerta($tipo, $mensaje, $datos) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO ia_alertas (tipo, mensaje, datos) VALUES (?, ?, ?)");
    $stmt->execute([$tipo, $mensaje, json_encode($datos)]);

    // Opcional: enviar email
    // mail('admin@verumax.com', "IA Alert: $tipo", $mensaje);
}

echo "Check completado: " . date('Y-m-d H:i:s') . "\n";
```

---

## Middleware de Rate Limiting

```php
<?php
/**
 * rate-limiter.php
 * Incluir antes de procesar requests en la API
 */

function verificarRateLimit($apiKey, $pdo) {
    // Obtener proyecto
    $stmt = $pdo->prepare("
        SELECT id, nombre, rate_limit_diario, activo
        FROM ia_proyectos WHERE api_key = ?
    ");
    $stmt->execute([$apiKey]);
    $proyecto = $stmt->fetch();

    if (!$proyecto) {
        return ['error' => 'API key invalida', 'code' => 401];
    }

    if (!$proyecto['activo']) {
        return ['error' => 'API key desactivada', 'code' => 403];
    }

    // Obtener uso del dia
    $hoy = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT total_requests FROM ia_usage_daily
        WHERE proyecto_id = ? AND fecha = ?
    ");
    $stmt->execute([$proyecto['id'], $hoy]);
    $uso = $stmt->fetch();

    $requestsHoy = $uso ? $uso['total_requests'] : 0;

    if ($requestsHoy >= $proyecto['rate_limit_diario']) {
        // Crear alerta si es la primera vez que llega al limite
        if ($requestsHoy == $proyecto['rate_limit_diario']) {
            $stmt = $pdo->prepare("
                INSERT INTO ia_alertas (tipo, mensaje, datos)
                VALUES ('rate_limit', ?, ?)
            ");
            $stmt->execute([
                "Proyecto {$proyecto['nombre']} alcanzo su limite diario",
                json_encode(['proyecto' => $proyecto['nombre'], 'limite' => $proyecto['rate_limit_diario']])
            ]);
        }

        return [
            'error' => 'Rate limit excedido',
            'code' => 429,
            'limite' => $proyecto['rate_limit_diario'],
            'usado' => $requestsHoy,
            'reset' => strtotime('tomorrow')
        ];
    }

    return [
        'ok' => true,
        'proyecto_id' => $proyecto['id'],
        'proyecto_nombre' => $proyecto['nombre'],
        'requests_restantes' => $proyecto['rate_limit_diario'] - $requestsHoy - 1
    ];
}

function registrarUso($proyectoId, $endpoint, $modelo, $tokensIn, $tokensOut, $tiempoMs, $pdo) {
    // Log detallado
    $stmt = $pdo->prepare("
        INSERT INTO ia_usage_logs (proyecto_id, endpoint, modelo, tokens_input, tokens_output, tiempo_ms, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$proyectoId, $endpoint, $modelo, $tokensIn, $tokensOut, $tiempoMs, $_SERVER['REMOTE_ADDR'] ?? '']);

    // Contador diario (upsert)
    $hoy = date('Y-m-d');
    $stmt = $pdo->prepare("
        INSERT INTO ia_usage_daily (proyecto_id, fecha, total_requests, total_tokens_input, total_tokens_output, total_tiempo_ms)
        VALUES (?, ?, 1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_requests = total_requests + 1,
            total_tokens_input = total_tokens_input + VALUES(total_tokens_input),
            total_tokens_output = total_tokens_output + VALUES(total_tokens_output),
            total_tiempo_ms = total_tiempo_ms + VALUES(total_tiempo_ms)
    ");
    $stmt->execute([$proyectoId, $hoy, $tokensIn, $tokensOut, $tiempoMs]);
}
```

---

## Proximos Pasos

- [ ] Crear panel admin para gestionar API keys
- [ ] Implementar rate limiting en FastAPI
- [ ] Logging de uso por proyecto
- [ ] Exponer FastAPI via HTTPS para acceso externo
- [ ] Dashboard de estadisticas
- [ ] Cron job para monitoreo de modelos
- [ ] Sistema de alertas (email/web)
- [ ] Graficos de uso en tiempo real

---

*Documento creado: 24/01/2026*
*Ubicacion: E:\appIA\GUIA-USO-API.md*
