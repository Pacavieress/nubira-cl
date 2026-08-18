<?php
// app/helpers/publicaciones_pago.php
// Cupo de publicación de servicios: 1 gratis de por vida (por historial real,
// nunca decrece aunque se borren servicios) + pago único desde la 2da en
// adelante. Mismo patrón que app/helpers/creditos_ia.php.

if (!defined('PRECIO_PUBLICACION_SERVICIO')) define('PRECIO_PUBLICACION_SERVICIO', 3000);

if (!function_exists('verificarCupoPublicacion')) {
    /**
     * @return array{puede_publicar_gratis: bool, total_historico: int}
     */
    function verificarCupoPublicacion(mysqli $conn, int $alumno_id): array {
        $stmt = $conn->prepare("SELECT servicios_publicados_total FROM alumnos WHERE id = ?");
        $stmt->bind_param("i", $alumno_id);
        $stmt->execute();
        $stmt->bind_result($total);
        $stmt->fetch();
        $stmt->close();

        return [
            'puede_publicar_gratis' => ((int)$total === 0),
            'total_historico' => (int)$total,
        ];
    }
}

if (!function_exists('incrementarContadorPublicaciones')) {
    /**
     * Se llama justo después de un INSERT exitoso en `servicios`, sin importar
     * si esa publicación terminó en 'pendiente' (gratis) o 'pendiente_pago'
     * (paga) — el historial cuenta el intento real de publicar, no la
     * aprobación posterior. Nunca decrece (no hay función de "revertir").
     */
    function incrementarContadorPublicaciones(mysqli $conn, int $alumno_id): bool {
        $stmt = $conn->prepare("UPDATE alumnos SET servicios_publicados_total = servicios_publicados_total + 1 WHERE id = ?");
        $stmt->bind_param("i", $alumno_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
