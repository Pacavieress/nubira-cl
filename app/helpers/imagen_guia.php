<?php
// app/helpers/imagen_guia.php
// Resolver de portada para artículos del Centro de Recursos (3 tamaños WebP
// en /upload/guias/, mismo contrato de nombres que admin_guias.php genera).
// Sin placeholder genérico a propósito: si no hay portada real, el caller
// debe omitir el <img>/og:image por completo (decisión de negocio, no un
// artículo sin foto se ve peor con un placeholder compartido que sin nada).
if (!defined('NB_GUIAS_WEB')) define('NB_GUIAS_WEB', '/upload/guias/');

if (!function_exists('nb_resolver_portada_guia')) {
    function nb_resolver_portada_guia(?string $archivo, string $tamano = 'main'): ?string {
        if (empty($archivo)) return null;
        $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
        $base   = pathinfo($archivo, PATHINFO_FILENAME);
        $sufijo = ($tamano === 'main') ? '' : '_' . $tamano;
        $rel = NB_GUIAS_WEB . $base . $sufijo . '.webp';
        $fis = rtrim($doc_root, '/\\') . $rel;
        return is_file($fis) ? $rel . '?v=' . filemtime($fis) : null;
    }
}
