<?php
// app/helpers/creditos_ia.php
// Cupo combinado del generador de IA: gratis (alumnos.generaciones_ia_usadas)
// + planes pagados (compras_creditos_ia). Ver diseño aprobado en sesión del 13/08/2026.

require_once __DIR__ . '/../config.php'; // LIMITE_GENERACIONES_IA_GRATIS

if (!function_exists('planesCreditosIA')) {
    /**
     * Única fuente de verdad de precios/créditos por plan — nunca se acepta
     * desde el cliente. Usado por iniciar_pago_creditos_ia.php, notificaciones_mp.php
     * y pago_exitoso_creditos_ia.php para que los 3 nunca puedan divergir entre sí.
     */
    function planesCreditosIA(): array {
        return [
            // 'disponible' = false: el plan sigue definido (precio, créditos) pero no se
            // puede comprar todavía — ni desde la UI (sin link real) ni desde el backend
            // (iniciar_pago_creditos_ia.php lo rechaza aunque llegue por URL directa).
            // Cambio de modelo 18/08/2026: generación individual pasa a $500 (antes plan
            // "1 crédito" a $1000). Packs plan_5/plan_10 activados el 2026-09-02.
            'plan_1'  => ['creditos' => 1,  'monto' => 500,  'disponible' => true],
            'plan_5'  => ['creditos' => 5,  'monto' => 1495, 'disponible' => true],
            'plan_10' => ['creditos' => 10, 'monto' => 2990, 'disponible' => true],
        ];
    }
}

if (!function_exists('verificarCupoIA')) {
    /**
     * Determina si el alumno puede generar una descripción con IA ahora mismo,
     * y de dónde sale el cupo (gratis, plan pagado, o ninguno).
     *
     * @return array{puede_generar: bool, origen: 'gratis'|'plan_pagado'|'sin_cupo'|'admin', compra_id: ?int}
     */
    function verificarCupoIA(mysqli $conn, int $alumno_id): array {
        // Bypass admin — verificado SIEMPRE contra BD (alumnos.rol), nunca contra
        // $_SESSION (podría estar mal seteada). Origen 'admin' es ignorado
        // explícitamente por incrementarCupoIA(), no consume cupo real.
        $stmt = $conn->prepare("SELECT rol FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $alumno_id);
        $stmt->execute();
        $stmt->bind_result($rol);
        $stmt->fetch();
        $stmt->close();

        if ($rol === 'admin') {
            return ['puede_generar' => true, 'origen' => 'admin', 'compra_id' => null];
        }

        // 1. Cupo gratis
        $stmt = $conn->prepare("SELECT generaciones_ia_usadas FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $alumno_id);
        $stmt->execute();
        $stmt->bind_result($usadas);
        $stmt->fetch();
        $stmt->close();

        if ($usadas < LIMITE_GENERACIONES_IA_GRATIS) {
            return ['puede_generar' => true, 'origen' => 'gratis', 'compra_id' => null];
        }

        // 2. Plan pagado vigente con crédito disponible — el más antiguo primero (FIFO)
        $stmt = $conn->prepare("
            SELECT id FROM compras_creditos_ia
            WHERE alumno_id = ? AND estado_pago = 'pagado'
              AND fecha_vencimiento > NOW() AND creditos_usados < creditos_totales
            ORDER BY fecha_compra ASC LIMIT 1
        ");
        $stmt->bind_param("i", $alumno_id);
        $stmt->execute();
        $stmt->bind_result($compra_id);
        $encontrado = $stmt->fetch();
        $stmt->close();

        if ($encontrado) {
            return ['puede_generar' => true, 'origen' => 'plan_pagado', 'compra_id' => $compra_id];
        }

        return ['puede_generar' => false, 'origen' => 'sin_cupo', 'compra_id' => null];
    }
}

if (!function_exists('incrementarCupoIA')) {
    /**
     * Consume 1 generación del origen correspondiente (gratis o plan pagado).
     * Encapsula la bifurcación para que ia_nubira.php no la repita en sus 2 puntos de consumo.
     */
    function incrementarCupoIA(mysqli $conn, string $origen, ?int $compra_id, int $alumno_id): bool {
        if ($origen === 'admin') {
            return true; // bypass admin: no descuenta ni cupo gratis ni plan pagado
        }

        if ($origen === 'plan_pagado') {
            if ($compra_id === null) return false;
            $stmt = $conn->prepare("UPDATE compras_creditos_ia SET creditos_usados = creditos_usados + 1 WHERE id = ?");
            $stmt->bind_param("i", $compra_id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        // Default: 'gratis' (y cualquier valor inesperado cae acá por seguridad, no deja sin consumir cupo)
        $stmt = $conn->prepare("UPDATE alumnos SET generaciones_ia_usadas = generaciones_ia_usadas + 1 WHERE id = ?");
        $stmt->bind_param("i", $alumno_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
