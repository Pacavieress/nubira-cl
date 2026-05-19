<?php
/**
 * NUBIRA 2.0 - SHIELD URL
 * Enmascaramiento de IDs para evitar Data Scraping y Enumeración.
 */

// Semilla de seguridad (Puedes cambiar 'nubira_secreto' por lo que quieras, pero una vez fijado, no lo cambies)
define('NUBIRA_SALT', 'nubira_secreto'); 

if (!function_exists('nubira_encriptar_id')) {
    function nubira_encriptar_id($id) {
        if (!$id || !is_numeric($id)) return '';
        $string = $id . '-' . NUBIRA_SALT;
        // Convertimos a Base64 y reemplazamos caracteres problemáticos en URLs (+ y /)
        return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
    }
}

if (!function_exists('nubira_desencriptar_id')) {
    function nubira_desencriptar_id($hash) {
        if (empty($hash)) return 0;
        // Revertimos los caracteres URL-safe y decodificamos
        $decoded = base64_decode(strtr($hash, '-_', '+/'));
        // Verificamos que la semilla secreta esté presente (evita manipulación)
        if ($decoded && strpos($decoded, '-' . NUBIRA_SALT) !== false) {
            $partes = explode('-', $decoded);
            return (int)$partes[0];
        }
        return 0; // Hash inválido o manipulado
    }
}
?>