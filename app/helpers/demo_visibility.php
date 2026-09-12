<?php
// app/helpers/demo_visibility.php
// Contenido de la cuenta demo (contacto@nubira.cl, DEMO_TUTOR_USER_ID) solo debe verse
// por viewers logueados que califican como tutor activo (nb_es_tutor_activo) o admin —
// anónimos y crawlers nunca califican.
require_once __DIR__ . '/roles.php';

// Config.php ya define esta constante, pero no todos los archivos que necesitan el
// gate de visibilidad lo incluyen (config.php carga MercadoPago/SMTP/etc. de más) —
// fallback local para no forzar esa dependencia en cada listado.
if (!defined('DEMO_TUTOR_USER_ID')) {
    define('DEMO_TUTOR_USER_ID', 167);
}

if (!function_exists('nb_viewer_ve_demo')) {
    function nb_viewer_ve_demo(mysqli $conn): bool {
        if (!empty($_SESSION['rol']) && $_SESSION['rol'] === 'admin') return true;
        $usuario_id = $_SESSION['usuario_id'] ?? 0;
        if (!$usuario_id) return false;
        return nb_es_tutor_activo($conn, (int)$usuario_id);
    }
}
