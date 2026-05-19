<?php
/**
 * NUBIRA 2.0 - CRON: RECÁLCULO DE TIEMPOS DE RESPUESTA DE TUTORES
 * 
 * Frecuencia: Diaria (sugerido 4:15 AM)
 * Misión: Recalcular tiempo_respuesta_promedio de todos los tutores usando
 *         mediana móvil de últimos 30 días, descartando outliers >24h.
 * 
 * Filosofía: Una mala noche no destruye la reputación. Estilo Airbnb.
 * 
 * Modos de ejecución:
 *  - Normal:   ?token=XXXXX
 *  - Dry-run:  ?token=XXXXX&dry_run=1   (no escribe, solo muestra qué haría)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

// =========================================================================
// 1. SEGURIDAD: Token secreto + validación de origen
// =========================================================================
define('CRON_TOKEN', 'j4Bx9mPq2RvN4tLs8wHzKMc6FdAe5BR');

$token_entrada = $_GET['token'] ?? '';
if ($token_entrada !== CRON_TOKEN) {
    http_response_code(403);
    die("Acceso denegado.");
}

$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';

// =========================================================================
// 2. CONEXIÓN A LA BD
// =========================================================================
require_once __DIR__ . '/../conexion.php';

// =========================================================================
// 3. CONFIGURACIÓN DEL CÁLCULO
// =========================================================================
$VENTANA_DIAS    = 30;    // Solo respuestas de los últimos 30 días
$MIN_RESPUESTAS  = 5;     // Mínimo de respuestas para mostrar el indicador
$LIMPIAR_DIAS    = 90;    // Borrar registros más viejos que esto
$log_lines       = [];

$inicio = microtime(true);
$fecha_ejecucion = date('Y-m-d H:i:s');
$log_lines[] = "===== INICIO CRON: $fecha_ejecucion =====";
$log_lines[] = $dry_run ? "MODO: DRY-RUN (no se escriben cambios)" : "MODO: PRODUCCIÓN";

// =========================================================================
// 4. OBTENER TODOS LOS TUTORES CON RESPUESTAS RECIENTES
// =========================================================================
$sql_tutores = "SELECT DISTINCT tutor_id 
                FROM respuestas_tutor 
                WHERE creado_en > (NOW() - INTERVAL ? DAY)";
$stmt = $conn->prepare($sql_tutores);
$stmt->bind_param("i", $VENTANA_DIAS);
$stmt->execute();
$res = $stmt->get_result();

$total_tutores = $res->num_rows;
$tutores_actualizados = 0;
$tutores_insuficientes = 0;
$log_lines[] = "Tutores con actividad en últimos $VENTANA_DIAS días: $total_tutores";

// =========================================================================
// 5. PROCESAR CADA TUTOR
// =========================================================================
while ($row = $res->fetch_assoc()) {
    $tutor_id = (int)$row['tutor_id'];

    // 5a. Obtener todos los minutos_respuesta del tutor en la ventana
    $sql_resp = "SELECT minutos_respuesta 
                 FROM respuestas_tutor 
                 WHERE tutor_id = ? 
                   AND creado_en > (NOW() - INTERVAL ? DAY)
                   AND minutos_respuesta <= 1440  -- Filtro de seguridad doble
                 ORDER BY minutos_respuesta ASC";
    $stmt_r = $conn->prepare($sql_resp);
    $stmt_r->bind_param("ii", $tutor_id, $VENTANA_DIAS);
    $stmt_r->execute();
    $res_r = $stmt_r->get_result();

    $valores = [];
    while ($r = $res_r->fetch_assoc()) {
        $valores[] = (int)$r['minutos_respuesta'];
    }
    $stmt_r->close();

    $cantidad = count($valores);

    // 5b. Si tiene menos del mínimo → setear NULL ("Tutor nuevo")
    if ($cantidad < $MIN_RESPUESTAS) {
        $log_lines[] = "Tutor #$tutor_id: solo $cantidad respuestas (< $MIN_RESPUESTAS) → NULL";
        $tutores_insuficientes++;

        if (!$dry_run) {
            $stmt_null = $conn->prepare("UPDATE alumnos SET tiempo_respuesta_promedio = NULL WHERE id = ?");
            $stmt_null->bind_param("i", $tutor_id);
            $stmt_null->execute();
            $stmt_null->close();
        }
        continue;
    }

    // 5c. Calcular mediana
    // Los valores ya vienen ordenados ASC desde la query
    $mid = floor($cantidad / 2);
    if ($cantidad % 2 === 0) {
        // Cantidad par: promedio de los dos del medio
        $mediana = (int) round(($valores[$mid - 1] + $valores[$mid]) / 2);
    } else {
        // Cantidad impar: el del medio
        $mediana = (int) $valores[$mid];
    }

    $log_lines[] = "Tutor #$tutor_id: $cantidad respuestas | mediana = $mediana min | min={$valores[0]} max=" . end($valores);

    // 5d. Guardar mediana en alumnos
    if (!$dry_run) {
        $stmt_up = $conn->prepare("UPDATE alumnos SET tiempo_respuesta_promedio = ? WHERE id = ?");
        $stmt_up->bind_param("ii", $mediana, $tutor_id);
        $stmt_up->execute();
        $stmt_up->close();
    }
    $tutores_actualizados++;
}
$stmt->close();

// =========================================================================
// 6. LIMPIEZA DE REGISTROS VIEJOS (> 90 días)
// =========================================================================
$registros_borrados = 0;
if (!$dry_run) {
    $stmt_clean = $conn->prepare("DELETE FROM respuestas_tutor WHERE creado_en < (NOW() - INTERVAL ? DAY)");
    $stmt_clean->bind_param("i", $LIMPIAR_DIAS);
    $stmt_clean->execute();
    $registros_borrados = $stmt_clean->affected_rows;
    $stmt_clean->close();
}
$log_lines[] = "Registros borrados (> $LIMPIAR_DIAS días): $registros_borrados";

// =========================================================================
// 7. RESUMEN FINAL
// =========================================================================
$duracion = round(microtime(true) - $inicio, 2);
$log_lines[] = "----- RESUMEN -----";
$log_lines[] = "Tutores procesados:      $total_tutores";
$log_lines[] = "Con métrica calculada:   $tutores_actualizados";
$log_lines[] = "Insuficientes (< $MIN_RESPUESTAS):    $tutores_insuficientes";
$log_lines[] = "Duración:                {$duracion}s";
$log_lines[] = "===== FIN CRON =====\n";

// =========================================================================
// 8. ESCRIBIR LOG (carpeta /logs/ al lado del archivo)
// =========================================================================
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
@file_put_contents(
    $log_dir . '/cron_tiempos.log',
    implode("\n", $log_lines) . "\n",
    FILE_APPEND
);

// =========================================================================
// 9. SALIDA EN PANTALLA (útil para ejecución manual)
// =========================================================================
header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $log_lines);
?>