<?php
// app/helpers/tiempo_respuesta.php
// [NUBIRA 2.0] Formato unificado del tiempo de respuesta del tutor.
// Fuente: cálculo on-demand desde respuestas_tutor (mediana 30d, ≥1 respuesta).
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

if (!function_exists('calcular_tiempo_respuesta_tutor')) {
    // Cálculo on-demand de la mediana móvil 30d (reemplaza al cron).
    // Mismo criterio que app/cron/recalcular_tiempos_tutores.php: ventana 30d,
    // outliers >24h descartados, mínimo 1 respuesta para tener señal.
    function calcular_tiempo_respuesta_tutor($conn, $tutor_id) {
        $sql = "SELECT minutos_respuesta
                FROM respuestas_tutor
                WHERE tutor_id = ?
                  AND creado_en > (NOW() - INTERVAL 30 DAY)
                  AND minutos_respuesta <= 1440
                ORDER BY minutos_respuesta ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $tutor_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $valores = [];
        while ($r = $res->fetch_assoc()) {
            $valores[] = (int)$r['minutos_respuesta'];
        }
        $stmt->close();

        $cantidad = count($valores);
        if ($cantidad < 1) {
            return null;
        }

        $mid = (int) floor($cantidad / 2);
        if ($cantidad % 2 === 0) {
            return (int) round(($valores[$mid - 1] + $valores[$mid]) / 2);
        }
        return $valores[$mid];
    }
}
