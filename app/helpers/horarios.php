<?php
// app/helpers/horarios.php
// [NUBIRA 2.0] Parseo y validación centralizados de horarios_json de servicios.
// Unifica la lógica que antes estaba duplicada en detalle_servicio.php,
// contratar_servicio.php y editar_horarios.php.

if (!function_exists('dias_semana_nubira')) {
    // Los 7 días en orden, fuente única para todo el sistema de horarios.
    function dias_semana_nubira(): array {
        return ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    }
}

if (!function_exists('parsear_horarios_servicio')) {
    /**
     * @return array{tiene_horarios: bool, dias: array<string, array<int, string>>, dia_proximo: ?string}
     */
    function parsear_horarios_servicio(?string $horarios_json): array {
        $resultado = [
            'tiene_horarios' => false,
            'dias'           => [],
            'dia_proximo'    => null,
        ];

        if (empty($horarios_json)) {
            return $resultado;
        }

        $horarios_tutor = json_decode($horarios_json, true);
        if (!is_array($horarios_tutor)) {
            return $resultado;
        }

        $orden_dias = dias_semana_nubira();

        foreach ($orden_dias as $dia) {
            if (!empty($horarios_tutor[$dia]) && count($horarios_tutor[$dia]) > 0) {
                $resultado['dias'][$dia] = $horarios_tutor[$dia];
            }
        }

        if (count($resultado['dias']) > 0) {
            $resultado['tiene_horarios'] = true;

            date_default_timezone_set('America/Santiago');
            $hoy_index = (int)date('N') - 1; // 0=Lunes ... 6=Domingo

            for ($i = 0; $i < 7; $i++) {
                $check_dia = $orden_dias[($hoy_index + $i) % 7];
                if (isset($resultado['dias'][$check_dia])) {
                    $resultado['dia_proximo'] = $check_dia;
                    break;
                }
            }
        }

        return $resultado;
    }
}

if (!function_exists('validar_horarios_json')) {
    /**
     * Valida ESTRUCTURA (no solo sintaxis JSON) antes de guardar horarios_json.
     * @return string|null  null si es válido; mensaje de error específico si no.
     */
    function validar_horarios_json(string $json_crudo): ?string {
        $data = json_decode($json_crudo, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return 'El formato de horarios no es válido.';
        }

        $dias_validos = dias_semana_nubira();
        $claves = $dias_validos;
        sort($claves);
        $recibidas = array_keys($data);
        sort($recibidas);
        if ($claves !== $recibidas) {
            return 'Los días recibidos no coinciden con los 7 días válidos de la semana.';
        }

        foreach ($dias_validos as $dia) {
            if (!is_array($data[$dia])) {
                return "El formato de bloques para $dia no es válido.";
            }
            foreach ($data[$dia] as $bloque) {
                if (!is_string($bloque) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d - ([01]\d|2[0-3]):[0-5]\d$/', $bloque)) {
                    return "Formato de horario inválido en $dia: \"$bloque\". Debe ser HH:MM - HH:MM.";
                }
                [$desde, $hasta] = explode(' - ', $bloque);
                if ($desde >= $hasta) {
                    return "En $dia, la hora de inicio ($desde) debe ser menor a la hora de fin ($hasta).";
                }
            }
        }

        return null;
    }
}
