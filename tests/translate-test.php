<?php
/**
 * Test suite del endpoint /admin/api/translate.php
 *
 * Uso (desde la maquina que tenga acceso al endpoint):
 *
 *   API_URL=https://verumax.com/ia/admin/api/translate.php \
 *   API_KEY=sk_xxx \
 *   php tests/translate-test.php
 *
 *   # o solo un test:
 *   php tests/translate-test.php 5
 *
 * Cubre los 10 snippets pasados por el equipo de cooperativaliberte.coop +
 * tests para batch posicional, batch keyed, errores estructurados, headers
 * de rate-limit, context hint y modo plain.
 *
 * Sale con codigo 0 si todo OK, 1 si hubo fallas.
 */

$API_URL = getenv('API_URL') ?: 'https://verumax.com/ia/admin/api/translate.php';
$API_KEY = getenv('API_KEY') ?: '';
$ONLY    = isset($argv[1]) ? (int) $argv[1] : null;

if ($API_KEY === '') {
    fwrite(STDERR, "Falta API_KEY. Uso: API_KEY=sk_... php tests/translate-test.php\n");
    exit(2);
}

$tests = [];

// === 10 snippets HTML del equipo ===
$snippetsHtml = [
    1 => '<p>Desde la <strong>Cooperativa Liberté</strong> tenemos el agrado de anunciar la publicación del trabajo de <em>investigación</em> "Cooperativa Liberté: del horror a la esperanza", de la socióloga <strong>Sofía Agudo Lutjens</strong>, ahora disponible en nuestra <a href="/investigaciones">sección Investigaciones</a>.</p>',
    2 => '<h2>El concepto</h2><p>El verbo <strong>restaurar</strong> se conjuga en dos sentidos al mismo tiempo: como espacio que alimenta y como acto que repara.</p><h3>Tres dimensiones</h3><p>Encuentro, tiempo compartido, reparación.</p>',
    3 => '<p>La <strong>justicia restaurativa</strong> entiende el delito principalmente como un daño a personas y relaciones, no como una infracción a una norma abstracta.</p><p>A diferencia del modelo retributivo tradicional, que pregunta "¿qué ley se rompió?", la restaurativa pregunta: "¿quién fue dañado, qué necesita y de quién es la obligación de reparar?".</p>',
    4 => '<p>Socióloga formada en la <a href="https://www.mdp.edu.ar" target="_blank" rel="noopener">Universidad Nacional de Mar del Plata</a>, especializada en sociología de la cárcel.</p>',
    5 => '<p>El proyecto incluye un <span class="glossary-term" data-glossary-slug="cooperativismo" data-tippy-content="Forma de organización económica...">cooperativismo</span> productivo y restaurativo.</p>',
    6 => '<p>El programa lo coordina <a href="/personas/diana-marquez" title="Ver: Diana Márquez" style="color:#059669; text-decoration:underline;">Diana Márquez</a>, abogada y mediadora.</p>',
    7 => '<blockquote>"En Liberté no trabajamos para mostrar una cárcel linda, sino para que cuando la persona salga del encierro haya recuperado la dignidad y no vuelva."</blockquote>',
    8 => '<ul><li>200 personas en situación de cárcel</li><li>13 unidades productivas</li><li>14 meses de funcionamiento como <strong>Restaurante Punto de Paz</strong></li><li>Coordinado por el INAES y la UNMdP</li></ul>',
    9 => '<p>La cooperativa funcionó del <strong>9 de julio de 2022</strong> al <strong>4 de septiembre de 2023</strong> —catorce meses ininterrumpidos— sirviendo "comida real" con cubiertos, servilletas y mantel.</p>',
    10 => '<h2>Importancia y reconocimiento</h2><p>Para la cooperativa esta publicación tiene un valor especial: por primera vez una investigación independiente documenta nuestro recorrido. Agradecemos a Sofía la confianza, al espacio editorial <strong>Política Criminal de la Libertad</strong>, y a la <strong>Universidad Nacional de Mar del Plata</strong>.</p><p><strong>👉 <a href="/investigaciones/cooperativa-liberte-del-horror-a-la-esperanza">Leé la investigación completa acá</a></strong> · Disponible también para descarga en PDF.</p>',
];

foreach ($snippetsHtml as $n => $html) {
    $tests["html-$n"] = [
        'descripcion' => "Snippet HTML #$n",
        'body' => ['text' => $html, 'to' => 'en', 'html' => true],
        'check' => function ($r) use ($html) {
            if (!isset($r['translation'])) return 'Falta campo translation';
            if (empty($r['html'])) return 'Falta o es false el flag html en respuesta';
            $err = compararEstructuraTags($html, $r['translation']);
            if ($err) return $err;
            return null;
        },
    ];
}

// === Tests adicionales (forma de input/output, errores) ===

$tests['plain-simple'] = [
    'descripcion' => 'Texto plano simple ES->EN',
    'body' => ['text' => 'Buenos días, ¿cómo estás hoy?', 'to' => 'en'],
    'check' => function ($r) {
        if (!isset($r['translation'])) return 'Falta translation';
        if (stripos($r['translation'], 'good') === false && stripos($r['translation'], 'morning') === false) {
            return 'Traduccion no parece valida: ' . $r['translation'];
        }
        return null;
    },
];

$tests['batch-positional'] = [
    'descripcion' => 'Batch posicional (array de strings)',
    'body' => ['text' => ['Hola', 'Adios', 'Gracias'], 'to' => 'en'],
    'check' => function ($r) {
        if (!isset($r['translations']) || !is_array($r['translations'])) return 'Falta translations array';
        if (count($r['translations']) !== 3) return 'Cantidad de traducciones != 3';
        if (!is_string($r['translations'][0])) return 'translations[0] deberia ser string';
        return null;
    },
];

$tests['batch-keyed'] = [
    'descripcion' => 'Batch keyed con id',
    'body' => [
        'text' => [
            ['id' => 'title', 'text' => 'Una mirada académica sobre Liberté'],
            ['id' => 'summary', 'text' => 'La socióloga analiza la cooperativa.'],
            ['id' => 'body', 'text' => '<p>Texto con <strong>HTML</strong>.</p>', 'html' => true],
        ],
        'to' => 'en',
    ],
    'check' => function ($r) {
        if (!isset($r['translations']) || !is_array($r['translations'])) return 'Falta translations array';
        if (count($r['translations']) !== 3) return 'Cantidad != 3';
        $ids = array_column($r['translations'], 'id');
        if ($ids !== ['title', 'summary', 'body']) return 'IDs no preservados u orden incorrecto';
        foreach ($r['translations'] as $t) {
            if (!isset($t['id'], $t['value'])) return 'Item no tiene {id, value}';
        }
        return null;
    },
];

$tests['context-hint'] = [
    'descripcion' => 'Context hint inyecta voz',
    'body' => [
        'text' => 'Trabajamos con personas en situación de cárcel.',
        'to' => 'en',
        'context' => 'Cooperativa de Argentina; preferi "people in prison" sobre "inmates".',
    ],
    'check' => function ($r) {
        if (!isset($r['translation'])) return 'Falta translation';
        if (stripos($r['translation'], 'inmate') !== false) {
            return 'El context hint NO se respeto: aparece "inmate" en lugar de "people in prison": ' . $r['translation'];
        }
        return null;
    },
];

$tests['error-missing-field'] = [
    'descripcion' => 'Error estructurado con MISSING_FIELD',
    'body' => ['to' => 'en'], // falta text
    'expectStatus' => 400,
    'check' => function ($r) {
        if (!isset($r['error']['code'])) return 'Falta error.code';
        if ($r['error']['code'] !== 'MISSING_FIELD') return "code esperado MISSING_FIELD, recibido: {$r['error']['code']}";
        if (!isset($r['error']['message'])) return 'Falta error.message';
        return null;
    },
];

$tests['error-invalid-token'] = [
    'descripcion' => 'Error estructurado INVALID_TOKEN con key falsa',
    'body' => ['text' => 'hola', 'to' => 'en'],
    'overrideKey' => 'sk_falsa_para_test',
    'expectStatus' => 401,
    'check' => function ($r) {
        if (!isset($r['error']['code'])) return 'Falta error.code';
        if ($r['error']['code'] !== 'INVALID_TOKEN') return "code esperado INVALID_TOKEN, recibido: {$r['error']['code']}";
        return null;
    },
];

// === Ejecutar ===
$total = 0;
$pass  = 0;
$fail  = 0;
$skipped = 0;

foreach ($tests as $name => $t) {
    if ($ONLY !== null) {
        // Si pidieron uno solo, matchear por nombre que termine con ese numero
        if (!preg_match('/(?:^|-)' . preg_quote((string) $ONLY) . '$/', $name)) {
            $skipped++;
            continue;
        }
    }

    $total++;
    $key = $t['overrideKey'] ?? $API_KEY;
    list($status, $headers, $body) = httpPost($API_URL, $t['body'], $key);
    $json = json_decode($body, true);

    $expectedStatus = $t['expectStatus'] ?? 200;
    if ($status !== $expectedStatus) {
        $fail++;
        echo "❌  [$name] HTTP $status (esperado $expectedStatus)\n";
        echo "    desc: {$t['descripcion']}\n";
        echo "    body: " . substr($body, 0, 300) . "\n\n";
        continue;
    }

    if (!is_array($json)) {
        $fail++;
        echo "❌  [$name] JSON invalido\n";
        echo "    body: " . substr($body, 0, 300) . "\n\n";
        continue;
    }

    $err = $t['check']($json);
    if ($err === null) {
        $pass++;
        $tiempo = isset($json['usage']['tiempo_ms']) ? " ({$json['usage']['tiempo_ms']}ms)" : '';
        echo "✅  [$name]$tiempo  {$t['descripcion']}\n";

        // Verificar headers de rate-limit en respuestas exitosas
        if ($status === 200) {
            $faltaHeader = null;
            foreach (['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'] as $h) {
                if (!isset($headers[$h])) { $faltaHeader = $h; break; }
            }
            if ($faltaHeader) echo "    ⚠️   Falta header: $faltaHeader\n";
        }
    } else {
        $fail++;
        echo "❌  [$name] {$t['descripcion']}\n";
        echo "    razon: $err\n";
        if (isset($json['translation'])) {
            echo "    salida: " . substr($json['translation'], 0, 300) . "\n";
        }
        if (isset($json['error'])) {
            echo "    error: " . json_encode($json['error']) . "\n";
        }
        echo "\n";
    }
}

echo "\n";
echo "=================================================\n";
echo "  Total: $total   Pass: $pass   Fail: $fail";
if ($skipped > 0) echo "   Skipped: $skipped";
echo "\n";
echo "=================================================\n";

exit($fail === 0 ? 0 : 1);


// ============ Helpers ============

function httpPost($url, $body, $apiKey) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (strpos($line, ':') !== false) {
            list($k, $v) = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $headers, $body];
}

function compararEstructuraTags($original, $traducido) {
    $patron = '/<\s*([a-z][a-z0-9]*)\b[^>]*\/?\s*>/i';
    preg_match_all($patron, $original,  $m1);
    preg_match_all($patron, $traducido, $m2);
    $c1 = array_count_values(array_map('strtolower', $m1[1]));
    $c2 = array_count_values(array_map('strtolower', $m2[1]));
    ksort($c1); ksort($c2);
    if ($c1 !== $c2) {
        return 'Tags difieren. Original: ' . json_encode($c1) . '  Traducido: ' . json_encode($c2);
    }
    return null;
}
