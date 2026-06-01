<?php
/**
 * HELPER: Vigencia de ofertas/subsidios
 * ARQUITECTURA: NUBIRA 2.0
 */

if (!function_exists('oferta_vigente')) {
    /**
     * Devuelve true si la oferta del servicio está activa y vigente.
     *
     * Condiciones (todas deben cumplirse):
     *   1. is_subvencionado = 1
     *   2. cupos_oferta > 0
     *   3. precio_oferta no es NULL
     *   4. oferta_termino es NULL (sin fecha límite) O >= fecha de hoy
     *
     * @param array $servicio Array asociativo con campos del servicio.
     */
    function oferta_vigente(array $servicio): bool {
        if (empty($servicio['is_subvencionado']) || (int)$servicio['is_subvencionado'] !== 1) {
            return false;
        }
        if ((int)($servicio['cupos_oferta'] ?? 0) <= 0) {
            return false;
        }
        if (is_null($servicio['precio_oferta'] ?? null) || $servicio['precio_oferta'] === '') {
            return false;
        }
        $termino = $servicio['oferta_termino'] ?? null;
        if (!is_null($termino) && $termino !== '' && $termino < date('Y-m-d')) {
            return false;
        }
        return true;
    }
}
