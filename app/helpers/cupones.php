<?php
/**
 * Helper de cupones (becas) — validación estricta + consumo atómico.
 * Extraído de crear_contrato.php (única lógica autoritativa hasta ahora) para
 * poder reusarla en otros checkouts (ej. slots_excepcion) sin triplicarla.
 *
 * IMPORTANTE: no abre transacción ni conexión propia — recibe la $conn del
 * caller, que debe tener ya abierta la transacción donde corre el FOR UPDATE
 * de este helper. La atomicidad (validar + consumir + insertar contrato,
 * todo o nada) la sigue garantizando el caller con su propio begin_transaction/
 * commit/rollback, exactamente igual que antes de esta extracción.
 */

if (!function_exists('nb_aplicar_cupon')) {
    /**
     * Valida y consume (si corresponde) un código de beca contra un servicio,
     * aplicando el descuento sobre $monto_base.
     *
     * @param mysqli $conn       Conexión con transacción ya abierta por el caller.
     * @param string $codigo_beca Código ya normalizado (mismo formato que usa
     *                             crear_contrato.php: strtoupper+trim). Si viene
     *                             vacío, no hace nada y devuelve el monto base intacto.
     * @param int    $servicio_id  ID del servicio contra el que se valida el alcance.
     * @param int    $monto_base   Monto sobre el que se calcula el descuento
     *                              (ya con la oferta del servicio aplicada, si corresponde).
     *
     * @return array{valido: bool, motivo: ?string, descuento: int, monto_final: int, cupon_id: ?int}
     *         'valido' => false SOLO cuando el código vino no vacío y falló alguna
     *         validación (no existe, agotado, expirado, fuera de alcance) — en ese
     *         caso 'motivo' trae el mensaje exacto a mostrarle al usuario. Con
     *         'valido' => true, 'monto_final' ya viene con el descuento aplicado
     *         (o igual a $monto_base si no había código).
     */
    function nb_aplicar_cupon(mysqli $conn, string $codigo_beca, int $servicio_id, int $monto_base): array {
        if (empty($codigo_beca)) {
            return ['valido' => true, 'motivo' => null, 'descuento' => 0, 'monto_final' => $monto_base, 'cupon_id' => null];
        }

        $stmt_cup = $conn->prepare("SELECT id, porcentaje_descuento, usos_actuales, usos_maximos, fecha_expiracion, servicio_id FROM cupones WHERE codigo = ? LIMIT 1 FOR UPDATE");

        if (!$stmt_cup) {
            // Un fallo de infraestructura al validar el cupón NO debe terminar en un
            // contrato creado sin el descuento pedido — a diferencia del no-op
            // silencioso que tenía el código viejo, esto es un error explícito.
            error_log("Nubira | nb_aplicar_cupon: fallo al preparar SELECT de cupones — " . $conn->error);
            return ['valido' => false, 'motivo' => "No se pudo validar la beca en este momento. Intenta nuevamente.", 'descuento' => 0, 'monto_final' => $monto_base, 'cupon_id' => null];
        }

        $stmt_cup->bind_param("s", $codigo_beca);
        $stmt_cup->execute();
        $res_cup = $stmt_cup->get_result();

        if ($res_cup->num_rows === 0) {
            $stmt_cup->close();
            return ['valido' => false, 'motivo' => "Código de beca inválido o inexistente.", 'descuento' => 0, 'monto_final' => $monto_base, 'cupon_id' => null];
        }

        $c = $res_cup->fetch_assoc();
        $stmt_cup->close();

        // Validaciones estrictas Nubira Shield
        if ($c['usos_maximos'] > 0 && $c['usos_actuales'] >= $c['usos_maximos']) {
            return ['valido' => false, 'motivo' => "La beca ingresada ya alcanzó su límite máximo de usos.", 'descuento' => 0, 'monto_final' => $monto_base, 'cupon_id' => null];
        }

        // Fix fecha
        if (!empty($c['fecha_expiracion'])) {
            date_default_timezone_set('America/Santiago');
            $hoy = date('Y-m-d');
            if ($hoy > $c['fecha_expiracion']) {
                return ['valido' => false, 'motivo' => "La beca ingresada ha expirado.", 'descuento' => 0, 'monto_final' => $monto_base, 'cupon_id' => null];
            }
        }

        // Fix alcance automático
        $es_global = is_null($c['servicio_id']) || (int)$c['servicio_id'] === 0;
        if (!$es_global && (int)$c['servicio_id'] !== $servicio_id) {
            return ['valido' => false, 'motivo' => "La beca no aplica para este servicio.", 'descuento' => 0, 'monto_final' => $monto_base, 'cupon_id' => null];
        }

        $cupon_id = (int)$c['id'];
        $descuento_aplicado = (int)(($monto_base * (int)$c['porcentaje_descuento']) / 100);
        $monto_final_calculado = max(0, $monto_base - $descuento_aplicado); // Protege contra negativos

        $stmt_uso = $conn->prepare("UPDATE cupones SET usos_actuales = usos_actuales + 1 WHERE id = ?");
        if ($stmt_uso) {
            $stmt_uso->bind_param("i", $cupon_id);
            $stmt_uso->execute();
            $stmt_uso->close();
        }

        return [
            'valido'      => true,
            'motivo'      => null,
            'descuento'   => $descuento_aplicado,
            'monto_final' => $monto_final_calculado,
            'cupon_id'    => $cupon_id,
        ];
    }
}
