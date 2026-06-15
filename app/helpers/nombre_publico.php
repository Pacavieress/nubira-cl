<?php
if (!function_exists('nombre_publico_tutor')) {
    // Primer nombre + inicial del ÚLTIMO apellido (formato vitrina/detalle/búsqueda).
    function nombre_publico_tutor(string $nombre_completo): string {
        $p = array_values(array_filter(explode(' ', trim($nombre_completo))));
        if (empty($p)) return 'Tutor';
        $out = ucwords(mb_strtolower($p[0], 'UTF-8'));
        if (count($p) >= 2) {
            $out .= ' ' . mb_strtoupper(mb_substr(end($p), 0, 1, 'UTF-8'), 'UTF-8') . '.';
        }
        return $out;
    }
}
