<?php
// app/helpers/roles.php
// Criterio compartido de "es tutor/creador activo" — extraído de perfil.php:382
// ($es_creador), que hasta ahora solo vivía inline ahí (panel_gestion.php:116 lo
// lee como variable heredada, no lo recalcula). Mismo criterio exacto, reescrito
// como EXISTS/COUNT para no traer filas completas cuando solo hace falta un booleano.

if (!function_exists('nb_es_tutor_activo')) {
    function nb_es_tutor_activo(mysqli $conn, int $usuario_id): bool {
        if ($usuario_id <= 0) return false;

        // 1) ¿Tiene al menos 1 servicio o apunte activo publicado?
        $stmt = $conn->prepare(
            "SELECT
                EXISTS(SELECT 1 FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND COALESCE(visible,1) = 1) AS tiene_servicio,
                EXISTS(SELECT 1 FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND bloqueado = 0 AND COALESCE(visible,1) = 1) AS tiene_apunte"
        );
        $stmt->bind_param("ii", $usuario_id, $usuario_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && ((int)$row['tiene_servicio'] === 1 || (int)$row['tiene_apunte'] === 1)) {
            return true;
        }

        // 2) Reputación de vendedor (tabla valoraciones + columna legacy alumnos.cantidad_votos)
        //    — cubre tutores con historial pero sin publicación activa hoy.
        $stmt = $conn->prepare(
            "SELECT
                (SELECT cantidad_votos FROM alumnos WHERE id = ?) AS leg_qty,
                (SELECT COUNT(*) FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor') AS v_qty"
        );
        $stmt->bind_param("ii", $usuario_id, $usuario_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && ((int)($row['leg_qty'] ?? 0) + (int)($row['v_qty'] ?? 0)) > 0;
    }
}
