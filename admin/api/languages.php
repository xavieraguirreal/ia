<?php
/**
 * Endpoint de idiomas soportados
 *
 * GET /admin/api/languages.php
 * Headers: Authorization: Bearer sk_xxx
 *
 * Respuesta:
 *   { languages: [{code, name, name_en, region}], count }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Usar GET.']]);
    exit;
}

require_once __DIR__ . '/../api-middleware.php';

$auth = validarApiKey($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (isset($auth['error'])) {
    $code = match((int) $auth['code']) {
        401 => 'INVALID_TOKEN',
        403 => 'DISABLED_KEY',
        429 => 'RATE_LIMIT_EXCEEDED',
        default => 'AUTH_ERROR',
    };
    http_response_code($auth['code']);
    echo json_encode(['error' => ['code' => $code, 'message' => $auth['error']]]);
    exit;
}

$languages = [
    ['code' => 'es',    'name' => 'Español',               'name_en' => 'Spanish',                  'region' => null],
    ['code' => 'en',    'name' => 'Inglés',                 'name_en' => 'English',                  'region' => null],
    ['code' => 'pt',    'name' => 'Portugués',              'name_en' => 'Portuguese',               'region' => null],
    ['code' => 'pt-br', 'name' => 'Portugués (Brasil)',     'name_en' => 'Portuguese (Brazil)',      'region' => 'BR'],
    ['code' => 'fr',    'name' => 'Francés',                'name_en' => 'French',                   'region' => null],
    ['code' => 'it',    'name' => 'Italiano',               'name_en' => 'Italian',                  'region' => null],
    ['code' => 'de',    'name' => 'Alemán',                 'name_en' => 'German',                   'region' => null],
    ['code' => 'zh',    'name' => 'Chino (Simplificado)',   'name_en' => 'Chinese (Simplified)',     'region' => 'CN'],
    ['code' => 'zh-tw', 'name' => 'Chino (Tradicional)',    'name_en' => 'Chinese (Traditional)',    'region' => 'TW'],
    ['code' => 'ja',    'name' => 'Japonés',                'name_en' => 'Japanese',                 'region' => null],
    ['code' => 'ko',    'name' => 'Coreano',                'name_en' => 'Korean',                   'region' => null],
    ['code' => 'ru',    'name' => 'Ruso',                   'name_en' => 'Russian',                  'region' => null],
    ['code' => 'ar',    'name' => 'Árabe',                  'name_en' => 'Arabic',                   'region' => null],
    ['code' => 'nl',    'name' => 'Holandés',               'name_en' => 'Dutch',                    'region' => null],
    ['code' => 'pl',    'name' => 'Polaco',                 'name_en' => 'Polish',                   'region' => null],
    ['code' => 'tr',    'name' => 'Turco',                  'name_en' => 'Turkish',                  'region' => null],
    ['code' => 'he',    'name' => 'Hebreo',                 'name_en' => 'Hebrew',                   'region' => null],
    ['code' => 'ca',    'name' => 'Catalán',                'name_en' => 'Catalan',                  'region' => null],
    ['code' => 'eu',    'name' => 'Euskera',                'name_en' => 'Basque',                   'region' => null],
    ['code' => 'gl',    'name' => 'Gallego',                'name_en' => 'Galician',                 'region' => null],
    ['code' => 'sv',    'name' => 'Sueco',                  'name_en' => 'Swedish',                  'region' => null],
    ['code' => 'no',    'name' => 'Noruego',                'name_en' => 'Norwegian',                'region' => null],
    ['code' => 'da',    'name' => 'Danés',                  'name_en' => 'Danish',                   'region' => null],
    ['code' => 'fi',    'name' => 'Finlandés',              'name_en' => 'Finnish',                  'region' => null],
    ['code' => 'gn',    'name' => 'Guaraní',                'name_en' => 'Guarani',                  'region' => null],
    ['code' => 'qu',    'name' => 'Quechua',                'name_en' => 'Quechua',                  'region' => null],
    ['code' => 'ay',    'name' => 'Aymara',                 'name_en' => 'Aymara',                   'region' => null],
];

echo json_encode([
    'languages' => $languages,
    'count'     => count($languages),
    'model'     => 'qwen2.5:7b-instruct',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
