<?php
/**
 * CRON: RECORDATORIOS DE CLASES AGENDADAS (NUBIRA 2.0)
 * 
 * Frecuencia recomendada: Cada 30 minutos
 * Ubicación: /app/cron/recordatorios_clases.php
 * 
 * Lógica:
 * - Recordatorio 24h: clases entre 23h y 25h adelante (ventana 2h)
 * - Recordatorio 1h:  clases entre 50min y 70min adelante (ventana 20min)
 * - Tracking en reservas_slots evita envíos duplicados
 * - Envía a alumno Y tutor (2 correos por recordatorio)
 */

// Solo permitir ejecución por CLI o por Hostinger (no acceso web)
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}

// Token simple anti-acceso web no autorizado (opcional, si quieres correrlo manualmente vía URL)
$CRON_SECRET = 'nubira_cron_2026_xK9p2L'; // Cámbialo si quieres
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $CRON_SECRET) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
set_time_limit(300); // 5 minutos máximo

date_default_timezone_set('America/Santiago');

// Rutas
$app_dir = dirname(__DIR__); // sube de /app/cron/ a /app/
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/correo.php';

// Logging
$log_file = __DIR__ . '/log_recordatorios.txt';
function log_cron($msg) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

log_cron("=== INICIO cron recordatorios ===");

$total_24h = 0;
$total_1h  = 0;
$errores   = 0;

// =========================================================================
// 1. RECORDATORIO 24h ANTES
// =========================================================================
// Ventana: clases entre 23h y 25h en el futuro (rango de 2h por si el cron tarda)
$sql_24h = "
    SELECT 
        r.id AS reserva_id,
        r.contrato_id,
        r.fecha_clase,
        s.titulo AS servicio_titulo,
        a_alumno.correo  AS correo_alumno,
        a_alumno.nombre  AS nombre_alumno,
        a_tutor.correo   AS correo_tutor,
        a_tutor.nombre   AS nombre_tutor
    FROM reservas_slots r
    INNER JOIN servicios s ON s.id = r.servicio_id
    INNER JOIN alumnos a_alumno ON a_alumno.id = r.alumno_id
    INNER JOIN alumnos a_tutor  ON a_tutor.id  = r.tutor_id
    WHERE r.estado = 'reservado'
      AND r.recordatorio_24h_enviado IS NULL
      AND r.fecha_clase BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
";

$res = $conn->query($sql_24h);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reserva_id = (int)$row['reserva_id'];
        $contrato_id = (int)$row['contrato_id'];
        
        log_cron("[24h] Procesando reserva #$reserva_id (contrato #$contrato_id)");
        
        // Email al ALUMNO
        $ok_alumno = false;
        try {
            $ok_alumno = enviarCorreoRecordatorioClase(
                $row['correo_alumno'],
                $row['nombre_alumno'],
                $row['nombre_tutor'],
                $row['servicio_titulo'],
                $row['fecha_clase'],
                $contrato_id,
                '24h',
                'alumno'
            );
        } catch (Exception $e) {
            log_cron("[24h] ERROR enviando a alumno (reserva #$reserva_id): " . $e->getMessage());
            $errores++;
        }
        
        // Email al TUTOR
        $ok_tutor = false;
        try {
            $ok_tutor = enviarCorreoRecordatorioClase(
                $row['correo_tutor'],
                $row['nombre_tutor'],
                $row['nombre_alumno'],
                $row['servicio_titulo'],
                $row['fecha_clase'],
                $contrato_id,
                '24h',
                'tutor'
            );
        } catch (Exception $e) {
            log_cron("[24h] ERROR enviando a tutor (reserva #$reserva_id): " . $e->getMessage());
            $errores++;
        }
        
        // Marcar como enviado SOLO si al menos uno se envió
        if ($ok_alumno || $ok_tutor) {
            $stmt_upd = $conn->prepare("UPDATE reservas_slots SET recordatorio_24h_enviado = NOW() WHERE id = ?");
            $stmt_upd->bind_param("i", $reserva_id);
            $stmt_upd->execute();
            $stmt_upd->close();
            $total_24h++;
            log_cron("[24h] OK reserva #$reserva_id (alumno=" . ($ok_alumno ? 'sí' : 'no') . ", tutor=" . ($ok_tutor ? 'sí' : 'no') . ")");
        } else {
            log_cron("[24h] FALLO total reserva #$reserva_id (no se marca como enviado)");
        }
    }
}

// =========================================================================
// 2. RECORDATORIO 1h ANTES
// =========================================================================
// Ventana: clases entre 50min y 70min en el futuro (rango de 20min)
$sql_1h = "
    SELECT 
        r.id AS reserva_id,
        r.contrato_id,
        r.fecha_clase,
        s.titulo AS servicio_titulo,
        a_alumno.correo  AS correo_alumno,
        a_alumno.nombre  AS nombre_alumno,
        a_tutor.correo   AS correo_tutor,
        a_tutor.nombre   AS nombre_tutor
    FROM reservas_slots r
    INNER JOIN servicios s ON s.id = r.servicio_id
    INNER JOIN alumnos a_alumno ON a_alumno.id = r.alumno_id
    INNER JOIN alumnos a_tutor  ON a_tutor.id  = r.tutor_id
    WHERE r.estado = 'reservado'
      AND r.recordatorio_1h_enviado IS NULL
      AND r.fecha_clase BETWEEN DATE_ADD(NOW(), INTERVAL 50 MINUTE) AND DATE_ADD(NOW(), INTERVAL 70 MINUTE)
";

$res = $conn->query($sql_1h);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reserva_id = (int)$row['reserva_id'];
        $contrato_id = (int)$row['contrato_id'];
        
        log_cron("[1h] Procesando reserva #$reserva_id (contrato #$contrato_id)");
        
        // Email al ALUMNO
        $ok_alumno = false;
        try {
            $ok_alumno = enviarCorreoRecordatorioClase(
                $row['correo_alumno'],
                $row['nombre_alumno'],
                $row['nombre_tutor'],
                $row['servicio_titulo'],
                $row['fecha_clase'],
                $contrato_id,
                '1h',
                'alumno'
            );
        } catch (Exception $e) {
            log_cron("[1h] ERROR enviando a alumno (reserva #$reserva_id): " . $e->getMessage());
            $errores++;
        }
        
        // Email al TUTOR
        $ok_tutor = false;
        try {
            $ok_tutor = enviarCorreoRecordatorioClase(
                $row['correo_tutor'],
                $row['nombre_tutor'],
                $row['nombre_alumno'],
                $row['servicio_titulo'],
                $row['fecha_clase'],
                $contrato_id,
                '1h',
                'tutor'
            );
        } catch (Exception $e) {
            log_cron("[1h] ERROR enviando a tutor (reserva #$reserva_id): " . $e->getMessage());
            $errores++;
        }
        
        if ($ok_alumno || $ok_tutor) {
            $stmt_upd = $conn->prepare("UPDATE reservas_slots SET recordatorio_1h_enviado = NOW() WHERE id = ?");
            $stmt_upd->bind_param("i", $reserva_id);
            $stmt_upd->execute();
            $stmt_upd->close();
            $total_1h++;
            log_cron("[1h] OK reserva #$reserva_id (alumno=" . ($ok_alumno ? 'sí' : 'no') . ", tutor=" . ($ok_tutor ? 'sí' : 'no') . ")");
        } else {
            log_cron("[1h] FALLO total reserva #$reserva_id (no se marca como enviado)");
        }
    }
}

log_cron("=== FIN cron recordatorios | 24h enviados: $total_24h | 1h enviados: $total_1h | errores: $errores ===");
log_cron(""); // Línea en blanco para separar ejecuciones

// Output (visible solo si se ejecuta vía URL con cron_secret)
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    echo "OK | 24h: $total_24h | 1h: $total_1h | errores: $errores\n";
}
?>