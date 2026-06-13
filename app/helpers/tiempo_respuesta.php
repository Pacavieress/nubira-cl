<?php
// app/helpers/tiempo_respuesta.php
// [NUBIRA 2.0] Formato unificado del tiempo de respuesta del tutor.
// Fuente: alumnos.tiempo_respuesta_promedio (mediana 30d, ≥5 respuestas; cron diario).
// Devuelve ['texto' => string, 'tono' => 'verde'|'azul'|'naranjo'|'gris'].
if (!function_exists('formatearTiempoRespuestaNubira')) {
    function formatearTiempoRespuestaNubira($minutos) {
        if ($minutos === null) {
            return ['texto' => 'Tutor nuevo', 'tono' => 'gris'];
        }
        $minutos = (int)$minutos;
        if ($minutos < 15)  return ['texto' => 'En minutos',         'tono' => 'verde'];
        if ($minutos < 60)  return ['texto' => 'En menos de 1 hora', 'tono' => 'verde'];
        if ($minutos < 180) return ['texto' => 'En pocas horas',     'tono' => 'azul'];
        if ($minutos < 720) return ['texto' => 'En el día',          'tono' => 'azul'];
        return ['texto' => 'En 1 día', 'tono' => 'naranjo'];
    }
}
