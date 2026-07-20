<?php
/**
 * HELPER: BUSCAR TUTORES ALTERNATIVOS POR CATEGORÍA (NUBIRA 2.0)
 * Extraído de notificar_alternativas_chat.php para reutilizarse también
 * desde paneles de campaña manuales (ej. enviar_cupon_alternativas.php).
 */

if (!function_exists('buscar_tutores_alternativos')) {
    function buscar_tutores_alternativos($conn, $categoria, $tutor_original_id) {
        $sql_estricta = "
            SELECT s.id, s.titulo, s.slug, a.id AS tutor_id, a.nombre AS nombre_tutor, a.foto_perfil,
                   (SELECT AVG(rt.minutos_respuesta)
                    FROM respuestas_tutor rt
                    WHERE rt.tutor_id = a.id
                      AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                      AND rt.minutos_respuesta <= 1440) AS tiempo_resp_calculado
            FROM servicios s
            INNER JOIN alumnos a ON s.alumno_id = a.id
            WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0
              AND s.categoria = ? AND a.id != ?
            HAVING tiempo_resp_calculado IS NOT NULL AND tiempo_resp_calculado < 60
            ORDER BY tiempo_resp_calculado ASC
            LIMIT 3
        ";
        $stmt = $conn->prepare($sql_estricta);
        $stmt->bind_param("si", $categoria, $tutor_original_id);
        $stmt->execute();
        $alternativas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($alternativas) >= 2) {
            return $alternativas;
        }

        $sql_amplia = "
            SELECT s.id, s.titulo, s.slug, a.id AS tutor_id, a.nombre AS nombre_tutor, a.foto_perfil,
                   (SELECT AVG(rt.minutos_respuesta)
                    FROM respuestas_tutor rt
                    WHERE rt.tutor_id = a.id
                      AND rt.creado_en > (NOW() - INTERVAL 30 DAY)
                      AND rt.minutos_respuesta <= 1440) AS tiempo_resp_calculado
            FROM servicios s
            INNER JOIN alumnos a ON s.alumno_id = a.id
            WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0
              AND s.categoria = ? AND a.id != ?
            ORDER BY tiempo_resp_calculado IS NULL, tiempo_resp_calculado ASC
            LIMIT 3
        ";
        $stmt2 = $conn->prepare($sql_amplia);
        $stmt2->bind_param("si", $categoria, $tutor_original_id);
        $stmt2->execute();
        $alternativas_amplias = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();

        return $alternativas_amplias;
    }
}

if (!function_exists('obtener_tutores_por_ids')) {
    function obtener_tutores_por_ids($conn, array $ids, $categoria, $tutor_original_id) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "
            SELECT s.id, s.titulo, s.slug, a.id AS tutor_id, a.nombre AS nombre_tutor, a.foto_perfil
            FROM servicios s
            INNER JOIN alumnos a ON s.alumno_id = a.id
            WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0
              AND s.categoria = ? AND a.id != ? AND s.id IN ($placeholders)
        ";
        $stmt = $conn->prepare($sql);
        $tipos = "si" . str_repeat('i', count($ids));
        $params = array_merge([$categoria, $tutor_original_id], $ids);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }
}
