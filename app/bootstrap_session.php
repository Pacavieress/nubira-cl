<?php
// ARCHIVO: app/bootstrap_session.php
// Punto único que fija Domain/Secure de la cookie PHPSESSID, vía auto_prepend_file
// (corre ANTES de cualquier script, incluido antes del session_start() que cada
// uno de los 287 archivos llama directamente). Mínimo a propósito: un error acá
// tumba TODO el sitio en cada request.

if (session_status() !== PHP_SESSION_NONE) {
    return;
}

$host_actual = $_SERVER['HTTP_HOST'] ?? '';
$es_local = (stripos($host_actual, 'nubira.local') !== false)
    || (stripos($host_actual, 'localhost') !== false);

if ($es_local) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '.nubira.cl',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
