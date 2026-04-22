<?php
/**
 * Endpoint de Traduccion v2
 *
 * POST /admin/api/translate.php
 * Headers: Authorization: Bearer sk_xxx
 *
 * Body JSON soporta tres formas de input:
 *
 *   1) Texto simple:
 *      { "text": "Hola", "to": "en" }
 *
 *   2) Batch posicional (array de strings):
 *      { "text": ["Hola", "Adios"], "to": "en" }
 *
 *   3) Batch keyed (array de objetos {id, text} con html opt por item):
 *      { "text": [
 *          { "id": "title",   "text": "..." },
 *          { "id": "body",    "text": "<p>...</p>", "html": true }
 *        ], "to": "en" }
 *
 * Parametros opcionales:
 *   from:    "es" | "auto"            (default "auto")
 *   tone:    "formal"|"informal"|"neutral"  (default "neutral")
 *   html:    bool                     (default false; modo preserva-tags)
 *   context: string                   (hint de tono/dominio agregado al system prompt)
 *   model:   string                   (default "qwen2.5:7b-instruct")
 *
 * Respuestas:
 *   - texto simple:        { translation, from, to, model, html?, usage }
 *   - batch posicional:    { translations: [...strings], from, to, model, usage }
 *   - batch keyed:         { translations: [{id, value}, ...], from, to, model, usage }
 *
 * Errores: { error: { code, message, details } } con HTTP code adecuado.
 *   Codigos: INVALID_JSON, MISSING_FIELD, INVALID_FIELD, INVALID_TOKEN,
 *            DISABLED_KEY, RATE_LIMIT_EXCEEDED, MODEL_ERROR,
 *            HTML_STRUCTURE_MISMATCH, METHOD_NOT_ALLOWED.
 *
 * Headers de respuesta:
 *   X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido. Usar POST.');
    exit;
}

require_once __DIR__ . '/../api-middleware.php';

// === Auth ===
$auth = validarApiKey($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (isset($auth['error'])) {
    $authErrCode = mapAuthErrorToCode($auth['code']);
    $details = [];
    if ($auth['code'] === 429) {
        $details = [
            'limit'    => $auth['limite'] ?? null,
            'used'     => $auth['usado']  ?? null,
            'reset_at' => isset($auth['reset']) ? gmdate('c', $auth['reset']) : null,
        ];
        // Tambien headers en 429
        if (isset($auth['limite'])) header('X-RateLimit-Limit: ' . $auth['limite']);
        header('X-RateLimit-Remaining: 0');
        if (isset($auth['reset']))  header('X-RateLimit-Reset: ' . $auth['reset']);
    }
    errorResponse($auth['code'], $authErrCode, $auth['error'], $details);
    exit;
}

// === Rate-limit headers en respuestas exitosas ===
header('X-RateLimit-Limit: '     . $auth['rate_limit_diario']);
header('X-RateLimit-Remaining: ' . max(0, $auth['requests_restantes']));
header('X-RateLimit-Reset: '     . $auth['reset_at']);

// === Parseo del body ===
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    errorResponse(400, 'INVALID_JSON', 'Body JSON invalido o vacio.');
    exit;
}

$text     = $input['text']    ?? null;
$to       = trim((string)($input['to']      ?? ''));
$from     = trim((string)($input['from']    ?? 'auto'));
$tone     = strtolower(trim((string)($input['tone'] ?? 'neutral')));
$htmlTop  = !empty($input['html']);
$context  = trim((string)($input['context'] ?? ''));
$modelo   = trim((string)($input['model']   ?? 'qwen2.5:7b-instruct'));

if ($text === null || $to === '') {
    errorResponse(400, 'MISSING_FIELD', 'Campos requeridos: "text" y "to".');
    exit;
}

// === Detectar forma del input y normalizar a una lista de items ===
// Cada item: ['key' => string|int, 'text' => string, 'html' => bool]
$inputForm = 'single';   // 'single' | 'positional' | 'keyed'
$items = [];

if (is_array($text)) {
    if (empty($text)) {
        errorResponse(400, 'INVALID_FIELD', '"text" es un array vacio.');
        exit;
    }
    $first = reset($text);
    if (is_array($first) && array_key_exists('id', $first) && array_key_exists('text', $first)) {
        $inputForm = 'keyed';
        foreach ($text as $i => $obj) {
            if (!is_array($obj) || !isset($obj['id']) || !isset($obj['text']) || !is_string($obj['text'])) {
                errorResponse(400, 'INVALID_FIELD', "El item {$i} del batch debe ser {id, text} con text string.");
                exit;
            }
            if (trim($obj['text']) === '') {
                errorResponse(400, 'INVALID_FIELD', "El item con id '{$obj['id']}' tiene texto vacio.");
                exit;
            }
            $items[] = [
                'key'  => $obj['id'],
                'text' => $obj['text'],
                'html' => array_key_exists('html', $obj) ? !empty($obj['html']) : $htmlTop,
            ];
        }
    } else {
        $inputForm = 'positional';
        foreach ($text as $i => $t) {
            if (!is_string($t) || trim($t) === '') {
                errorResponse(400, 'INVALID_FIELD', "El item en indice {$i} debe ser string no vacio.");
                exit;
            }
            $items[] = ['key' => $i, 'text' => $t, 'html' => $htmlTop];
        }
    }
} else {
    if (!is_string($text) || trim($text) === '') {
        errorResponse(400, 'MISSING_FIELD', '"text" no puede estar vacio.');
        exit;
    }
    $items[] = ['key' => 0, 'text' => $text, 'html' => $htmlTop];
}

// === Procesar cada item (con chunking automatico para textos >3000 chars) ===
$idiomaDestino = normalizarNombreIdioma($to);
$idiomaOrigen  = ($from === '' || strtolower($from) === 'auto')
    ? 'el idioma de origen (detectalo automaticamente)'
    : normalizarNombreIdioma($from);

$inicio      = microtime(true);
$totalIn     = 0;
$totalOut    = 0;
$totalChunks = 0;
$resultados  = [];

foreach ($items as $item) {
    $systemPrompt = construirSystemPrompt($idiomaOrigen, $idiomaDestino, $tone, $context, $item['html']);
    $chunks       = chunkearTexto($item['text'], $item['html']);
    $totalChunks += count($chunks);
    $partes       = [];

    foreach ($chunks as $ci => $chunk) {
        $result = traducirItem($chunk, $item['html'], $modelo, $systemPrompt, $totalIn, $totalOut);

        if (isset($result['error'])) {
            $tiempoMs = (int) round((microtime(true) - $inicio) * 1000);
            registrarUso($auth['proyecto_id'], 'translate', $modelo, $totalIn, $totalOut, $tiempoMs, 500, $result['error']);
            errorResponse(500, 'MODEL_ERROR', $result['error'], ['model' => $modelo, 'item_id' => $item['key']]);
            exit;
        }
        if (isset($result['mismatch'])) {
            $tiempoMs = (int) round((microtime(true) - $inicio) * 1000);
            registrarUso($auth['proyecto_id'], 'translate', $modelo, $totalIn, $totalOut, $tiempoMs, 422, 'HTML_STRUCTURE_MISMATCH');
            errorResponse(422, 'HTML_STRUCTURE_MISMATCH', 'La traduccion modifico la estructura HTML del original. El modelo fallo dos veces; intentar con texto mas corto o dividirlo manualmente.', [
                'item_id'       => $item['key'],
                'chunk_index'   => $ci,
                'original_tags' => $result['original'],
                'returned_tags' => $result['traducido'],
            ]);
            exit;
        }
        $partes[] = $result['value'];
    }

    $traduccion    = implode($item['html'] ? '' : "\n\n", $partes);
    $resultados[]  = ['key' => $item['key'], 'value' => $traduccion];
}

$tiempoMs = (int) round((microtime(true) - $inicio) * 1000);
registrarUso($auth['proyecto_id'], 'translate', $modelo, $totalIn, $totalOut, $tiempoMs);

// === Armar respuesta segun forma del input ===
$usage = [
    'prompt_tokens'     => $totalIn,
    'completion_tokens' => $totalOut,
    'total_tokens'      => $totalIn + $totalOut,
    'tiempo_ms'         => $tiempoMs,
];
if ($totalChunks > count($items)) {
    $usage['chunks'] = $totalChunks;
}

$out = [
    'from'  => $from === '' ? 'auto' : $from,
    'to'    => $to,
    'model' => $modelo,
    'usage' => $usage,
];

if ($inputForm === 'single') {
    $out['translation'] = $resultados[0]['value'];
    if ($htmlTop) $out['html'] = true;
} elseif ($inputForm === 'positional') {
    $out['translations'] = array_map(function ($r) { return $r['value']; }, $resultados);
} else { // keyed
    $out['translations'] = array_map(function ($r) {
        return ['id' => $r['key'], 'value' => $r['value']];
    }, $resultados);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


// ============ Helpers ============

function errorResponse($httpCode, $errorCode, $message, $details = []) {
    http_response_code($httpCode);
    echo json_encode([
        'error' => [
            'code'    => $errorCode,
            'message' => $message,
            'details' => (object) $details,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function mapAuthErrorToCode($httpCode) {
    switch ((int) $httpCode) {
        case 401: return 'INVALID_TOKEN';
        case 403: return 'DISABLED_KEY';
        case 429: return 'RATE_LIMIT_EXCEEDED';
        default:  return 'AUTH_ERROR';
    }
}

function construirSystemPrompt($origen, $destino, $tone, $context, $html) {
    $toneClause = '';
    if ($tone === 'formal')        $toneClause = ' Usa registro formal.';
    elseif ($tone === 'informal')  $toneClause = ' Usa registro informal y coloquial.';

    $ctxClause = '';
    if ($context !== '') {
        $ctxClause = "\n\nCONTEXTO ADICIONAL (tenelo en cuenta para terminologia, voz y nombres preferidos):\n{$context}";
    }

    if ($html) {
        return "Eres un traductor profesional de contenido HTML. Tu unica tarea es traducir el contenido textual de un fragmento HTML desde {$origen} al {$destino}.{$toneClause}{$ctxClause}

REGLAS ESTRICTAS — debes cumplirlas todas:

1. PRESERVAR LA ESTRUCTURA HTML EXACTA:
   - Misma cantidad y orden de tags (<p>, <a>, <strong>, <em>, <span>, <h1>-<h6>, <ul>, <li>, <blockquote>, etc.).
   - No agregues, quites ni renombres ningun tag.
   - Mantene el anidamiento idéntico.

2. PRESERVAR ATRIBUTOS BYTE A BYTE (no traducir, no modificar):
   - class, style, href, id, target, rel, data-*, aria-* (excepto aria-label).
   - Las URLs en href y src se copian sin cambios.

3. TRADUCIR SOLO TEXTO VISIBLE:
   - Text nodes (contenido entre tags).
   - Atributos visibles para el usuario: alt, title, placeholder, aria-label.

4. NO TRADUCIR:
   - Contenido dentro de <code>, <pre>, <script>, <style>.
   - Nombres propios (personas, organizaciones, lugares).
   - Marcas registradas, siglas (INAES, UNMdP, PDF, QR, etc.).
   - Numeros, fechas, codigos.
   - Emojis y caracteres especiales (—, ñ, comillas tipograficas '' \"\").

5. FORMATO DE SALIDA:
   - Devuelve UNICAMENTE el HTML traducido.
   - NO envuelvas en markdown (sin \`\`\`html, sin \`\`\`).
   - NO agregues comillas, prefijos ni explicaciones.
   - NO agregues comentarios ni notas.";
    }

    return "Eres un traductor profesional. Tu unica tarea es traducir desde {$origen} al {$destino}.{$toneClause}{$ctxClause}

REGLAS ESTRICTAS:
- Devuelve UNICAMENTE la traduccion. Sin comentarios, sin explicaciones, sin notas, sin prefacios.
- Preserva el formato, puntuacion, saltos de linea y estructura del texto original.
- No envuelvas la salida en comillas ni agregues prefijos/sufijos.
- No traduzcas nombres propios, marcas, codigos tecnicos, siglas ni URLs.
- Si el texto ya esta en {$destino}, devuelvelo sin cambios.";
}

function limpiarSalida($texto, $htmlMode) {
    $t = trim($texto);

    // Quitar markdown wrapping (```html ... ``` o ``` ... ```)
    if (strpos($t, '```') !== false) {
        $t = preg_replace('/^```[a-zA-Z]*\s*\r?\n?/', '', $t);
        $t = preg_replace('/\r?\n?```\s*$/', '', $t);
        $t = trim($t);
    }

    if (!$htmlMode) {
        // Sacar comillas envolventes (solo en modo plano)
        if (strlen($t) >= 2) {
            $first = substr($t, 0, 1);
            $last  = substr($t, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $t = substr($t, 1, -1);
            }
        }
        // Sacar prefijos "Traduccion:" / "Translation:"
        $t = preg_replace('/^\s*(traducci[oó]n|translation)\s*:\s*/i', '', $t);
    }

    return trim($t);
}

/**
 * Compara la cantidad de tags HTML por tipo entre original y traducido.
 * Retorna ['ok' => true] o ['ok' => false, 'original' => [...], 'traducido' => [...]].
 */
function validarEstructuraHtml($original, $traducido) {
    $patron = '/<\s*([a-z][a-z0-9]*)\b[^>]*\/?\s*>/i';

    preg_match_all($patron, $original,  $m1);
    preg_match_all($patron, $traducido, $m2);

    $count1 = array_count_values(array_map('strtolower', $m1[1]));
    $count2 = array_count_values(array_map('strtolower', $m2[1]));

    ksort($count1);
    ksort($count2);

    if ($count1 !== $count2) {
        return ['ok' => false, 'original' => $count1, 'traducido' => $count2];
    }
    return ['ok' => true];
}

/**
 * Traduce un fragmento de texto con validacion HTML y retry automatico.
 * Retorna ['value' => string] en exito, ['error' => string] o ['mismatch' => true, ...] en fallo.
 */
function traducirItem($texto, $html, $modelo, $systemPrompt, &$totalIn, &$totalOut) {
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $texto],
    ];

    $resp = ollamaChat($modelo, $messages);
    if (isset($resp['error'])) {
        return ['error' => $resp['error']];
    }
    $totalIn  += (int) $resp['tokens_input'];
    $totalOut += (int) $resp['tokens_output'];

    $traduccion = limpiarSalida($resp['content'], $html);

    if ($html) {
        $val = validarEstructuraHtml($texto, $traduccion);
        if (!$val['ok']) {
            $messagesRetry = $messages;
            $messagesRetry[0]['content'] .= "\n\nIMPORTANTE: tu intento anterior modifico la estructura HTML. Debes preservar EXACTAMENTE la cantidad y orden de tags del original. No agregues, no quites, no renombres ningun tag.";
            $respRetry = ollamaChat($modelo, $messagesRetry);
            if (isset($respRetry['error'])) {
                return ['error' => $respRetry['error']];
            }
            $totalIn  += (int) $respRetry['tokens_input'];
            $totalOut += (int) $respRetry['tokens_output'];
            $traduccionRetry = limpiarSalida($respRetry['content'], true);
            $val2 = validarEstructuraHtml($texto, $traduccionRetry);
            if ($val2['ok']) {
                $traduccion = $traduccionRetry;
            } else {
                return ['mismatch' => true, 'original' => $val['original'], 'traducido' => $val2['traducido']];
            }
        }
    }

    return ['value' => $traduccion];
}

/**
 * Divide texto en chunks de max $limite chars respetando limites naturales.
 * Para HTML corta en bordes de tags de bloque; para texto plano en parrafos/oraciones.
 */
function chunkearTexto($texto, $html, $limite = 3000) {
    if (mb_strlen($texto) <= $limite) {
        return [$texto];
    }
    return $html ? chunkearHtml($texto, $limite) : chunkearPlano($texto, $limite);
}

function chunkearHtml($html, $limite) {
    // Corta despues de cada tag de bloque de cierre para mantener fragmentos validos
    $parts = preg_split(
        '/(?<=<\/(p|h[1-6]|li|blockquote|div|section|article|ul|ol|dl|pre|table|figure|header|footer|nav|aside)>)/i',
        $html
    );

    $chunks = [];
    $actual = '';

    foreach ($parts as $part) {
        if (mb_strlen($actual . $part) <= $limite) {
            $actual .= $part;
        } else {
            if (trim($actual) !== '') {
                $chunks[] = trim($actual);
            }
            // Si la parte sola supera el limite, se agrega de todas formas como chunk unico
            $actual = $part;
        }
    }
    if (trim($actual) !== '') {
        $chunks[] = trim($actual);
    }

    return array_values(array_filter($chunks, fn($c) => trim($c) !== ''));
}

function chunkearPlano($texto, $limite) {
    // Divide primero por parrafos (doble salto de linea), luego por oraciones si hace falta
    $partes = preg_split('/(\n\n+)/', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);

    $chunks = [];
    $actual = '';

    foreach ($partes as $parte) {
        if (mb_strlen($actual . $parte) <= $limite) {
            $actual .= $parte;
        } else {
            if (trim($actual) !== '') {
                $chunks[] = trim($actual);
            }
            if (mb_strlen($parte) > $limite) {
                // Parrafo demasiado largo: dividir por oraciones
                $oraciones = preg_split('/(?<=[.!?])\s+/', trim($parte));
                $sub = '';
                foreach ($oraciones as $o) {
                    if (mb_strlen($sub . ' ' . $o) <= $limite) {
                        $sub .= ($sub !== '' ? ' ' : '') . $o;
                    } else {
                        if ($sub !== '') $chunks[] = $sub;
                        $sub = $o;
                    }
                }
                if ($sub !== '') $chunks[] = $sub;
                $actual = '';
            } else {
                $actual = $parte;
            }
        }
    }
    if (trim($actual) !== '') {
        $chunks[] = trim($actual);
    }

    return array_values(array_filter($chunks, fn($c) => trim($c) !== ''));
}

/**
 * Normaliza codigo o nombre de idioma a forma que el modelo entienda bien.
 */
function normalizarNombreIdioma($codigo) {
    $c = strtolower(trim($codigo));
    $mapa = [
        'es' => 'espanol', 'spa' => 'espanol', 'spanish' => 'espanol', 'castellano' => 'espanol', 'espanol' => 'espanol', 'español' => 'espanol',
        'en' => 'ingles',  'eng' => 'ingles',  'english' => 'ingles',  'ingles' => 'ingles', 'inglés' => 'ingles',
        'pt' => 'portugues', 'por' => 'portugues', 'portuguese' => 'portugues', 'portugues' => 'portugues', 'portugués' => 'portugues',
        'pt-br' => 'portugues (Brasil)', 'pt_br' => 'portugues (Brasil)',
        'fr' => 'frances', 'fra' => 'frances', 'french' => 'frances', 'frances' => 'frances', 'francés' => 'frances',
        'it' => 'italiano', 'ita' => 'italiano', 'italian' => 'italiano', 'italiano' => 'italiano',
        'de' => 'aleman',  'deu' => 'aleman',  'ger' => 'aleman', 'german' => 'aleman', 'aleman' => 'aleman', 'alemán' => 'aleman',
        'zh' => 'chino mandarin simplificado', 'zh-cn' => 'chino mandarin simplificado', 'chi' => 'chino', 'chinese' => 'chino mandarin', 'chino' => 'chino mandarin',
        'zh-tw' => 'chino tradicional',
        'ja' => 'japones', 'jpn' => 'japones', 'japanese' => 'japones', 'japones' => 'japones', 'japonés' => 'japones',
        'ko' => 'coreano', 'kor' => 'coreano', 'korean' => 'coreano', 'coreano' => 'coreano',
        'ru' => 'ruso',    'rus' => 'ruso',    'russian' => 'ruso', 'ruso' => 'ruso',
        'ar' => 'arabe',   'ara' => 'arabe',   'arabic' => 'arabe', 'arabe' => 'arabe', 'árabe' => 'arabe',
        'nl' => 'holandes', 'dutch' => 'holandes', 'holandes' => 'holandes', 'holandés' => 'holandes',
        'pl' => 'polaco',  'polish' => 'polaco', 'polaco' => 'polaco',
        'tr' => 'turco',   'turkish' => 'turco', 'turco' => 'turco',
        'he' => 'hebreo',  'hebrew' => 'hebreo', 'hebreo' => 'hebreo',
        'ca' => 'catalan', 'catalan' => 'catalan', 'catalán' => 'catalan',
        'eu' => 'euskera', 'basque' => 'euskera',
        'gl' => 'gallego', 'galician' => 'gallego',
        'sv' => 'sueco',   'swedish' => 'sueco',
        'no' => 'noruego', 'norwegian' => 'noruego',
        'da' => 'danes',   'danish' => 'danes',
        'fi' => 'finlandes', 'finnish' => 'finlandes',
        'gn' => 'guarani', 'guarani' => 'guarani',
        'qu' => 'quechua', 'quechua' => 'quechua',
        'ay' => 'aymara',  'aymara' => 'aymara',
    ];
    return $mapa[$c] ?? $codigo;
}
