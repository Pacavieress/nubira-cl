<?php
/**
 * ENDPOINT: SLOTS DISPONIBLES (NUBIRA 2.0)
 * GET /app/api/slots_disponibles.php?servicio_id=X&fecha=YYYY-MM-DD
 * 
 * Devuelve JSON con slots de 30min dentro de los rangos de horarios_json
 * del tutor para el día solicitado, marcando ocupados según reservas_slots.
 * 
 * Pensado API-first: este mismo contrato JSON se usará en Flutter.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../conexion.php';
date_default_timezone_set('America/Santiago');

// 1. Validación de sesión (solo logueados pueden ver slots)
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// 2. Validación de parámetros
$servicio_id = (int)($_GET['servicio_id'] ?? 0);
$fecha       = trim($_GET['fecha'] ?? '');

if ($servicio_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

// 3. No permitir fechas pasadas
$hoy = date('Y-m-d');
if ($fecha < $hoy) {
    echo json_encode(['fecha' => $fecha, 'slots' => [], 'motivo' => 'fecha_pasada']);
    exit;
}

// 4. Obtener servicio (horarios + duración + tutor)
$stmt = $conn->prepare("SELECT alumno_id AS tutor_id, horarios_json, duracion_minutos FROM servicios WHERE id = ? AND estado = 'aprobado' LIMIT 1");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$serv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$serv) {
    http_response_code(404);
    echo json_encode(['error' => 'Servicio no encontrado']);
    exit;
}

$tutor_id = (int)$serv['tutor_id'];
$duracion = (int)$serv['duracion_minutos'];
$horarios = !empty($serv['horarios_json']) ? json_decode($serv['horarios_json'], true) : [];

if (!is_array($horarios) || empty($horarios)) {
    echo json_encode(['fecha' => $fecha, 'slots' => [], 'motivo' => 'sin_horarios']);
    exit;
}

// 5. Calcular día de la semana en español (con tildes, igual a horarios_json)
$mapa_dias = [
    'Monday' => 'Lunes',
    'Tuesday' => 'Martes',
    'Wednesday' => 'Miércoles',
    'Thursday' => 'Jueves',
    'Friday' => 'Viernes',
    'Saturday' => 'Sábado',
    'Sunday' => 'Domingo',
];
$dia_en  = date('l', strtotime($fecha));
$dia_es  = $mapa_dias[$dia_en] ?? null;

if (!$dia_es || empty($horarios[$dia_es])) {
    echo json_encode(['fecha' => $fecha, 'slots' => [], 'motivo' => 'dia_no_disponible']);
    exit;
}

// 6. Generar slots de 30min dentro de cada rango
$bloques = $horarios[$dia_es];
$slots_propuestos = [];
$paso = 30; // minutos entre slots

foreach ($bloques as $bloque) {
    if (!preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $bloque, $m)) continue;
    $rango_ini = strtotime("$fecha {$m[1]}:00");
    $rango_fin = strtotime("$fecha {$m[2]}:00");

    // Generar inicios cada 30min, mientras inicio + duracion <= rango_fin
    for ($t = $rango_ini; ($t + $duracion * 60) <= $rango_fin; $t += $paso * 60) {
        $slots_propuestos[] = date('Y-m-d H:i:s', $t);
    }
}

if (empty($slots_propuestos)) {
    echo json_encode(['fecha' => $fecha, 'slots' => [], 'motivo' => 'sin_slots_validos']);
    exit;
}

// 7. Cruzar con reservas existentes del tutor para ese día
$ini_dia = $fecha . ' 00:00:00';
$fin_dia = $fecha . ' 23:59:59';

$stmt = $conn->prepare("
    SELECT fecha_clase, duracion_minutos 
    FROM reservas_slots 
    WHERE tutor_id = ? 
      AND estado IN ('reservado','en_curso') 
      AND fecha_clase BETWEEN ? AND ?
");
$stmt->bind_param("iss", $tutor_id, $ini_dia, $fin_dia);
$stmt->execute();
$res = $stmt->get_result();
$ocupados = [];
while ($r = $res->fetch_assoc()) {
    $ini = strtotime($r['fecha_clase']);
    $fin = $ini + ((int)$r['duracion_minutos'] * 60);
    $ocupados[] = ['ini' => $ini, 'fin' => $fin];
}
$stmt->close();

// 7b. Cruzar también con slots de excepción pendientes o pagados (anti-doble booking)
$stmt_exc = $conn->prepare("
    SELECT se.fecha_clase, s.duracion_minutos
    FROM slots_excepcion se
    JOIN servicios s ON se.servicio_id = s.id
    WHERE se.tutor_id    = ?
      AND se.estado      IN ('pendiente', 'pagado')
      AND se.expira_en   > NOW()
      AND se.fecha_clase BETWEEN ? AND ?
");
$stmt_exc->bind_param("iss", $tutor_id, $ini_dia, $fin_dia);
$stmt_exc->execute();
$res_exc = $stmt_exc->get_result();
while ($r = $res_exc->fetch_assoc()) {
    $ini = strtotime($r['fecha_clase']);
    $ocupados[] = ['ini' => $ini, 'fin' => $ini + ((int)$r['duracion_minutos'] * 60)];
}
$stmt_exc->close();

// 8. No permitir slots en el pasado del día actual (con buffer 30min)
$ahora_buffer = time() + (30 * 60);

// 9. Construir respuesta final
$slots_final = [];
foreach ($slots_propuestos as $slot_str) {
    $slot_ini = strtotime($slot_str);
    $slot_fin = $slot_ini + ($duracion * 60);

    $disponible = true;
    $motivo = null;

    // Slot ya pasó (o muy pronto)
    if ($slot_ini < $ahora_buffer) {
        $disponible = false;
        $motivo = 'pasado';
    }

    // Slot solapa con reserva existente
    if ($disponible) {
        foreach ($ocupados as $oc) {
            if ($slot_ini < $oc['fin'] && $slot_fin > $oc['ini']) {
                $disponible = false;
                $motivo = 'ocupado';
                break;
            }
        }
    }

    $slots_final[] = [
        'datetime'   => $slot_str,                          // "2026-05-08 18:00:00"
        'hora'       => date('H:i', $slot_ini),             // "18:00"
        'disponible' => $disponible,
        'motivo'     => $motivo,
    ];
}

echo json_encode([
    'fecha'    => $fecha,
    'duracion' => $duracion,
    'slots'    => $slots_final,
]);