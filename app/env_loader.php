<?php
/**
 * Carga el archivo .env en $_ENV y putenv().
 * Busca el .env en múltiples rutas para funcionar tanto en
 * desarrollo local (Windows) como en producción (Hostinger).
 * Idempotente: solo se ejecuta una vez por proceso.
 */
if (defined('NUBIRA_ENV_LOADED')) return;

$candidates = [];

// Producción: .env un nivel arriba de public_html
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $candidates[] = dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')) . '/.env';
}

// Desarrollo: rutas relativas desde public_html/app/
$candidates[] = __DIR__ . '/../../.env';  // raíz del proyecto
$candidates[] = __DIR__ . '/../.env';     // dentro de public_html/
$candidates[] = __DIR__ . '/.env';        // mismo directorio app/

foreach ($candidates as $path) {
    if (!file_exists($path)) continue;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;

        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");

        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
            putenv("$key=$val");
        }
    }

    define('NUBIRA_ENV_LOADED', true);
    return;
}
