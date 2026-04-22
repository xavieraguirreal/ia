# API de Traducción - Guía para Desarrolladores

API propia de Verumax para traducir texto a múltiples idiomas usando IA local (Qwen 2.5 vía Ollama).
**Sin costo por uso, sin enviar datos a terceros.**

---

## Endpoint

```
POST https://verumax.com/ia/admin/api/translate.php
```

**Headers obligatorios:**
```
Content-Type: application/json
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

La API Key la emite el admin del panel (`/ia/admin/`). Cada proyecto tiene su propia key con rate-limit diario.

---

## Body (JSON)

| Campo   | Tipo              | Requerido | Default                | Descripción                                          |
|---------|-------------------|-----------|------------------------|------------------------------------------------------|
| `text`  | string \| string[] | sí        | —                      | Texto a traducir. Puede ser uno solo o un array (batch). |
| `to`    | string            | sí        | —                      | Idioma destino. Acepta código ISO (`en`, `fr`, `pt`) o nombre (`ingles`, `french`). |
| `from`  | string            | no        | `"auto"`               | Idioma origen. Si no se pasa, el modelo lo detecta.  |
| `tone`  | string            | no        | `"neutral"`            | `formal`, `informal` o `neutral`.                    |
| `model` | string            | no        | `"qwen2.5:7b-instruct"` | Modelo Ollama a usar. Solo cambiar si sabés qué hacés. |

---

## Respuesta

### Texto simple

**Request:**
```json
{
  "text": "¿Cómo estás hoy?",
  "to": "en"
}
```

**Response (200):**
```json
{
  "from": "auto",
  "to": "en",
  "model": "qwen2.5:7b-instruct",
  "translation": "How are you today?",
  "usage": {
    "prompt_tokens": 85,
    "completion_tokens": 6,
    "total_tokens": 91,
    "tiempo_ms": 1240
  }
}
```

### Batch (array de textos)

**Request:**
```json
{
  "text": ["Hola", "Buenos días", "Gracias"],
  "to": "pt",
  "tone": "formal"
}
```

**Response (200):**
```json
{
  "from": "auto",
  "to": "pt",
  "model": "qwen2.5:7b-instruct",
  "translations": ["Olá", "Bom dia", "Obrigado"],
  "usage": { "prompt_tokens": 240, "completion_tokens": 12, "total_tokens": 252, "tiempo_ms": 3600 }
}
```

---

## Errores

| HTTP | Causa                                       |
|------|---------------------------------------------|
| 400  | JSON inválido o faltan campos `text`/`to`.  |
| 401  | Falta header `Authorization` o API Key inválida. |
| 403  | API Key desactivada.                        |
| 405  | Método distinto a POST.                     |
| 429  | Rate-limit diario excedido.                 |
| 500  | Error del modelo u Ollama.                  |

Formato de error: `{ "error": "Descripción del problema" }`.

---

## Ejemplos de uso

### PHP (cURL)

```php
<?php
function traducir($texto, $idiomaDestino, $apiKey, $from = 'auto') {
    $ch = curl_init('https://verumax.com/ia/admin/api/translate.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'text' => $texto,
            'to'   => $idiomaDestino,
            'from' => $from,
        ]),
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($code !== 200) {
        throw new RuntimeException($data['error'] ?? 'Error HTTP ' . $code);
    }
    return $data['translation'];
}

// Uso
$apiKey = 'sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
echo traducir('Hola mundo', 'en', $apiKey); // "Hello world"
```

### JavaScript (fetch)

```js
async function traducir(texto, idiomaDestino, apiKey, from = 'auto') {
  const res = await fetch('https://verumax.com/ia/admin/api/translate.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${apiKey}`,
    },
    body: JSON.stringify({ text: texto, to: idiomaDestino, from }),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data.translation;
}

// Uso
const apiKey = 'sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
const r = await traducir('Hola mundo', 'fr', apiKey);
console.log(r); // "Bonjour le monde"
```

### Laravel (Http facade)

```php
use Illuminate\Support\Facades\Http;

$resp = Http::withToken(env('IA_API_KEY'))
    ->timeout(60)
    ->post('https://verumax.com/ia/admin/api/translate.php', [
        'text' => 'Hola mundo',
        'to'   => 'en',
    ])
    ->throw()
    ->json();

echo $resp['translation']; // "Hello world"
```

### curl (línea de comandos)

```bash
curl -X POST https://verumax.com/ia/admin/api/translate.php \
  -H "Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{"text":"Hola mundo","to":"en"}'
```

---

## Idiomas soportados (códigos reconocidos)

El parámetro `to` (y `from`) acepta código ISO 639-1 o nombre:

`es` (español) · `en` (inglés) · `pt` / `pt-br` (portugués) · `fr` (francés) · `it` (italiano) · `de` (alemán) · `zh` / `zh-tw` (chino) · `ja` (japonés) · `ko` (coreano) · `ru` (ruso) · `ar` (árabe) · `nl` (holandés) · `pl` (polaco) · `tr` (turco) · `he` (hebreo) · `ca` (catalán) · `eu` (euskera) · `gl` (gallego) · `sv` (sueco) · `no` (noruego) · `da` (danés) · `fi` (finlandés).

Si pasás un código no listado, se envía textual al modelo — Qwen soporta más idiomas, solo que esta tabla es la que está normalizada.

---

## Recomendaciones

1. **Batch siempre que puedas.** Si tenés 10 strings cortos para traducir, pasalos en un array en una sola request. Cada llamada tiene overhead de red + carga del modelo en RAM.
2. **Timeout**: usá `60s` como mínimo. Primera llamada después de reinicio puede tardar 10–30s (carga del modelo).
3. **Tono**: para emails formales, legales, contratos → `tone: "formal"`. Para UI de app casual o chat → `tone: "informal"`.
4. **No traduzcas HTML crudo** con tags. Extraé el texto, traducí, y re-inyectá. Si no, el modelo puede romper o traducir los atributos.
5. **No pasar textos > 3000 caracteres** en una sola llamada; partí en párrafos.
6. **Rate-limit**: el límite diario está configurado por proyecto en el admin. Si te topás con `429`, pedí al admin que suba el límite o usá una key distinta.

---

## Modelos disponibles

| Modelo                 | Tamaño | Velocidad | Calidad traducción | Cuándo usar               |
|------------------------|--------|-----------|--------------------|---------------------------|
| `qwen2.5:7b-instruct`  | 7B     | Media     | Muy buena ★★★★    | **Default recomendado**    |
| `qwen2.5:3b-instruct`  | 3B     | Rápida    | Buena ★★★          | Textos cortos, UI strings |
| `qwen2.5:1.5b-instruct`| 1.5B   | Muy rápida| Aceptable ★★       | Borradores, alto volumen  |

Qwen 2.5 es multilingüe de fábrica (29+ idiomas). Para pasar a otro modelo, agregar `"model": "qwen2.5:3b-instruct"` al body.

---

## Obtener una API Key

Entrar al admin: `https://verumax.com/ia/admin/` → sección "API Keys" → Nueva API Key → asignar nombre de proyecto y rate limit.

La key se muestra **una sola vez**. Guardala en una variable de entorno del proyecto (`IA_API_KEY`), nunca commitearla.

---

## Soporte

- Logs de uso por proyecto: `/ia/admin/logs.php`
- Estado de modelos: `/ia/admin/modelos.php`
- Si algo no responde: verificar que Ollama esté corriendo en el VPS (`systemctl status ollama`).
