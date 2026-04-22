<?php
/**
 * Endpoint de Traduccion con autenticacion y logging
 *
 * POST /admin/api/translate.php
 * Headers: Authorization: Bearer sk_xxx
 *
 * Body JSON:
 *   {
 *     "text": "Hola mundo"                 // string o array de strings
 *     "to":   "en"                         // idioma destino (codigo ISO o nombre)
 *     "from": "es" | "auto"                // opcional, default "auto"
 *     "tone": "formal"|"informal"|"neutral" // opcional, default "neutral"
 *     "model": "qwen2.5:7b-instruct"       // opcional, default qwen2.5:7b-instruct
 *   }
 *
 * Respuesta:
 *   - si "text" es string:
 *     { "translation": "Hello world", "from": "auto", "to": "en", "model": ..., "usage": {...} }
 *   - si "text" es array:
 *     { "translations": ["Hello", "Goodbye"], ... }
 *
 * Errores: { "error": "mensaje" } con HTTP code adecuado (400/401/403/429/500)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo no permitido. Usar POST.']);
    exit;
}

require_once __DIR__ . '/../api-middleware.php';

// === Auth ===
$auth = validarApiKey($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (isset($auth['error'])) {
    http_response_code($auth['code']);
    echo json_encode(['error' => $auth['error']]);
    exit;
}

// === Parseo y validacion del body ===
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Body JSON invalido']);
    exit;
}

$text   = $input['text']  ?? null;
$to     = trim((string)($input['to']   ?? ''));
$from   = trim((string)($input['from'] ?? 'auto'));
$tone   = strtolower(trim((string)($input['tone']  ?? 'neutral')));
$modelo = trim((string)($input['model'] ?? 'qwen2.5:7b-instruct'));

if ($text === null || $text === '' || $to === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Campos requeridos: "text" (string o array) y "to" (idioma destino)']);
    exit;
}

$esArray = is_array($text);
$textos  = $esArray ? array_values($text) : [$text];

foreach ($textos as $i => $t) {
    if (!is_string($t) || trim($t) === '') {
        http_response_code(400);
        echo json_encode(['error' => "\"text\" contiene un elemento vacio o no-string en indice {$i}"]);
        exit;
    }
}

// === Construccion del system prompt ===
$idiomaDestino = normalizarNombreIdioma($to);
$idiomaOrigen  = ($from === '' || strtolower($from) === 'auto')
    ? 'el idioma de origen (detectalo automaticamente)'
    : normalizarNombreIdioma($from);

$instruccionTono = '';
if ($tone === 'formal')        $instruccionTono = ' Usa registro formal.';
elseif ($tone === 'informal')  $instruccionTono = ' Usa registro informal y coloquial.';

$systemPrompt = "Eres un traductor profesional. Tu unica tarea es traducir desde {$idiomaOrigen} al {$idiomaDestino}.{$instruccionTono}

REGLAS ESTRICTAS:
- Devuelve UNICAMENTE la traduccion. Sin comentarios, sin explicaciones, sin notas, sin prefacios.
- Preserva el formato, puntuacion, saltos de linea y estructura del texto original.
- No envuelvas la salida en comillas ni agregues prefijos/sufijos.
- No traduzcas nombres propios, marcas, codigos tecnicos ni URLs.
- Si el texto ya esta en {$idiomaDestino}, devuelvelo sin cambios.";

// === Loop de traduccion ===
$inicio = microtime(true);
$traducciones = [];
$totalIn = 0;
$totalOut = 0;

foreach ($textos as $t) {
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $t]
    ];

    $resultado = ollamaChat($modelo, $messages);

    if (isset($resultado['error'])) {
        $tiempoMs = (int) round((microtime(true) - $inicio) * 1000);
        registrarUso($auth['proyecto_id'], 'chat', $modelo, $totalIn, $totalOut, $tiempoMs, 500, $resultado['error']);
        http_response_code(500);
        echo json_encode(['error' => $resultado['error']]);
        exit;
    }

    $traducciones[] = limpiarSalidaTraduccion($resultado['content']);
    $totalIn  += (int) $resultado['tokens_input'];
    $totalOut += (int) $resultado['tokens_output'];
}

$tiempoMs = (int) round((microtime(true) - $inicio) * 1000);

// Log (endpoint = 'chat' para compatibilidad con ENUM actual; el modelo y tokens quedan registrados igual)
registrarUso($auth['proyecto_id'], 'chat', $modelo, $totalIn, $totalOut, $tiempoMs);

// === Respuesta ===
$out = [
    'from'  => $from === '' ? 'auto' : $from,
    'to'    => $to,
    'model' => $modelo,
    'usage' => [
        'prompt_tokens'     => $totalIn,
        'completion_tokens' => $totalOut,
        'total_tokens'      => $totalIn + $totalOut,
        'tiempo_ms'         => $tiempoMs
    ]
];

if ($esArray) {
    $out['translations'] = $traducciones;
} else {
    $out['translation'] = $traducciones[0];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


// ============ Helpers locales ============

/**
 * Quita artefactos comunes que los LLMs suelen agregar pese al prompt.
 */
function limpiarSalidaTraduccion($texto) {
    $t = trim($texto);

    // Si esta envuelto en comillas simples o dobles, sacarlas
    if (strlen($t) >= 2) {
        $first = substr($t, 0, 1);
        $last  = substr($t, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $t = substr($t, 1, -1);
        }
    }

    // Sacar prefijos tipo "Traduccion:" o "Translation:"
    $t = preg_replace('/^\s*(traduccion|translation|traducci[oó]n)\s*:\s*/i', '', $t);

    return trim($t);
}

/**
 * Normaliza codigo o nombre de idioma a forma que el modelo entienda bien.
 * Si no reconoce el codigo, devuelve lo que envio el usuario.
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
    ];
    return $mapa[$c] ?? $codigo;
}
