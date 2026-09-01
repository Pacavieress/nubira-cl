<?php
/**
 * CRON: Auto-cerrar y auto-liberar contratos por silencio del alumno
 *
 * FASE 1 — Auto-cierre (regla original, sin cambios):
 *  - estado = 'finalizado_vendedor'
 *  - fecha_cierre (cuando el profe marcó entregado) + 48h < ahora
 *  => pasa a 'finalizado_comprador', y se avisa por correo al alumno
 *     (un solo correo, en el momento de este cambio de estado).
 *
 * FASE 2 — Auto-liberación (nueva, INERTE por ahora — ver $FASE2_AUTOLIBERAR_ACTIVA):
 *  - estado = 'finalizado_comprador'
 *  - fecha_cierre + $dias_gracia_liberacion < ahora
 *  - sin un reclamo abierto vinculado (reclamos_sugerencias.contrato_id)
 *  => pasa a 'liberado' — esto es lo que activaría el saldo retirable en
 *     datos_bancarios.php/solicitar_retiro.php, sin tocar esos archivos.
 *     Escrita y lista, pero desactivada hasta que exista un vínculo real
 *     reclamo → contrato (ver comentario junto al flag, más abajo).
 *
 * fecha_cierre NUNCA se reescribe en ninguna fase — se mantiene como el
 * ancla estable de "cuándo el tutor marcó entregado", para que ambas fases
 * midan el plazo desde el mismo punto de referencia.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

// [NUBIRA 2.0] FASE 2 (auto-liberación a 'liberado') queda escrita más abajo
// pero INERTE detrás de este flag. Motivo: su protección contra reclamos
// abiertos (NOT EXISTS sobre reclamos_sugerencias.contrato_id) hoy no
// protege nada — confirmado con grep en toda la carpeta app/, ningún flujo
// del sitio escribe reclamos_sugerencias.contrato_id (ni el formulario
// público de "Nuevo ticket" en reclamos_sugerencias.php, ni
// admin_reclamos.php). Sin ese vínculo, un alumno no tiene forma de asociar
// su queja a la clase puntual, así que la fase 2 liberaría dinero sin poder
// detectar una disputa real vinculada a ese contrato.
//
// PREREQUISITO PARA ACTIVAR: agregar un campo opcional de contrato_id al
// formulario de "Nuevo ticket" (reclamos_sugerencias.php) para que el
// alumno pueda vincular su reclamo a la clase específica, y persistirlo en
// el INSERT de esa acción. Recién con eso el NOT EXISTS de la fase 2 tiene
// dientes reales. Hasta entonces, dejar en false.
$FASE2_AUTOLIBERAR_ACTIVA = false;

// ============================================================
// FASE 1: finalizado_vendedor -> finalizado_comprador (48h)
// ============================================================
$horas = 48; // 🔧 cámbialo si quieres otro plazo

$sql = $conn->prepare("
    SELECT c.id, c.comprador_id, c.vendedor_id,
           comp.correo AS correo_comprador, comp.nombre AS nombre_comprador,
           vend.nombre AS nombre_tutor,
           s.titulo AS titulo_servicio
    FROM contratos c
    LEFT JOIN alumnos comp ON comp.id = c.comprador_id
    LEFT JOIN alumnos vend ON vend.id = c.vendedor_id
    LEFT JOIN servicios s ON s.id = c.servicio_id
    WHERE c.estado = 'finalizado_vendedor'
      AND c.fecha_cierre IS NOT NULL
      AND c.fecha_cierre < (NOW() - INTERVAL ? HOUR)
");
$sql->bind_param("i", $horas);
$sql->execute();
$res = $sql->get_result();

$filas = [];
$ids = [];
while ($row = $res->fetch_assoc()) {
    $filas[] = $row;
    $ids[] = (int)$row['id'];
}
$sql->close();

if (!empty($ids)) {
    $id_list = implode(',', $ids);

    // 1) Actualizar estado masivo
    $conn->query("
        UPDATE contratos
        SET estado = 'finalizado_comprador'
        WHERE id IN ($id_list)
    ");

    // 2) Registrar eventos
    if ($ev = $conn->prepare("
        INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
        VALUES (?, NULL, 'AUTO_CERRADO', 'Contrato auto-finalizado por tiempo de espera')
    ")) {
        foreach ($ids as $cid) {
            $ev->bind_param("i", $cid);
            $ev->execute();
        }
        $ev->close();
    }

    // 3) Avisar por correo al alumno — un solo correo, acá, en el momento del
    // auto-cierre. Un fallo de envío se loguea y NO debe frenar el resto del
    // lote ni el cambio de estado, que ya se aplicó arriba.
    foreach ($filas as $fila) {
        if (empty($fila['correo_comprador'])) continue;
        try {
            $nombreComp = explode(' ', trim($fila['nombre_comprador'] ?? 'Alumno'))[0];
            $nombreTutor = explode(' ', trim($fila['nombre_tutor'] ?? 'tu tutor'))[0];
            $titulo = $fila['titulo_servicio'] ?? 'tu clase';

            $html = "
                <p>Hola <strong>$nombreComp</strong>,</p>
                <p>Pasaron 48 horas desde que <strong>$nombreTutor</strong> marcó como entregada tu clase
                <strong>\"" . htmlspecialchars($titulo) . "\"</strong> y no recibimos confirmación de tu parte.</p>

                <div style='background-color:#F0F9FF; border-left: 4px solid #54A6D8; padding: 15px; margin: 20px 0;'>
                    <p style='margin:0; color:#1E3A5F;'>Por defecto, dimos la clase por aceptada. Si todo estuvo bien, no necesitas hacer nada.</p>
                </div>

                <p style='color:#64748B; font-size:13px;'><em>Si tuviste un problema con esta clase, avísanos pronto: en unos días liberaremos el pago al tutor si no recibimos ningún reclamo.</em></p>
            ";
            $cuerpo = plantillaMaestra("Tu clase se dio por finalizada", $html, "Reportar un problema", "https://nubira.cl/soporte");
            _enviarEmailBase($fila['correo_comprador'], "Tu clase se dio por finalizada — " . $titulo, $cuerpo);
        } catch (Throwable $e) {
            error_log("cron_autocerrar_contratos: fallo enviando correo de auto-cierre para contrato {$fila['id']} — " . $e->getMessage());
        }
    }
}

// ============================================================
// FASE 2: finalizado_comprador -> liberado (plazo de gracia adicional)
// INERTE mientras $FASE2_AUTOLIBERAR_ACTIVA sea false — ver comentario junto
// al flag, arriba del todo del archivo, antes de activarla.
// ============================================================
if ($FASE2_AUTOLIBERAR_ACTIVA) {
    $dias_gracia_liberacion = 5; // 🔧 cámbialo si quieres otro plazo

    // [NUBIRA 2.0] Excluye contratos con un reclamo/disputa abierto vinculado.
    // 'eliminado' cuenta como NO bloqueante porque significa que el propio
    // usuario retiró su ticket (soft delete desde reclamos_sugerencias.php) —
    // solo 'pendiente'/'en_proceso' (sin resolver) deben frenar la liberación.
    $sqlFase2 = $conn->prepare("
        SELECT c.id
        FROM contratos c
        WHERE c.estado = 'finalizado_comprador'
          AND c.fecha_cierre IS NOT NULL
          AND c.fecha_cierre < (NOW() - INTERVAL ? DAY)
          AND NOT EXISTS (
              SELECT 1 FROM reclamos_sugerencias r
              WHERE r.contrato_id = c.id
                AND r.estado NOT IN ('resuelto', 'eliminado')
          )
    ");
    $sqlFase2->bind_param("i", $dias_gracia_liberacion);
    $sqlFase2->execute();
    $resFase2 = $sqlFase2->get_result();

    $idsFase2 = [];
    while ($row = $resFase2->fetch_assoc()) {
        $idsFase2[] = (int)$row['id'];
    }
    $sqlFase2->close();

    if (!empty($idsFase2)) {
        $id_list_2 = implode(',', $idsFase2);

        // 1) Liberar el pago — esto es lo único que necesita tocar la Fase 2 para
        // que el saldo retirable del tutor (datos_bancarios.php/solicitar_retiro.php,
        // que ya cuenta estado='liberado') lo recoja solo, sin tocar esos archivos.
        $conn->query("
            UPDATE contratos
            SET estado = 'liberado'
            WHERE id IN ($id_list_2)
        ");

        // 2) Registrar eventos
        if ($evLib = $conn->prepare("
            INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
            VALUES (?, NULL, 'AUTO_LIBERADO', 'Pago auto-liberado al tutor tras plazo de gracia sin reclamo')
        ")) {
            foreach ($idsFase2 as $cid) {
                $evLib->bind_param("i", $cid);
                $evLib->execute();
            }
            $evLib->close();
        }
    }
}
