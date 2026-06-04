<?php
/**
 * ENDPOINT: GENERAR SLOT DE EXCEPCIÓN
 * POST /app/generar_slot_excepcion.php
 * Solo el vendedor de la conversación puede llamar este endpoint.
 */
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/conexion.php';

// 1. Sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión expirada. Vuelve a ingresar.']);
    exit;
}

$my_id = (int)$_SESSION['usuario_id'];

// 2. CSRF
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

// 3. Entradas
$conversacion_id = (int)($_POST['conversacion_id'] ?? 0);
$fecha_raw       = trim($_POST['fecha'] ?? '');
$hora_raw        = trim($_POST['hora']  ?? '');

if ($conversacion_id <= 0 || empty($fecha_raw) || empty($hora_raw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan datos obligatorios.']);
    exit;
}

// 4. Validar formato
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_raw) || !preg_match('/^\d{2}:\d{2}$/', $hora_raw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de fecha u hora inválido.']);
    exit;
}

date_default_timezone_set('America/Santiago');
$fecha_clase_sql = $fecha_raw . ' ' . $hora_raw . ':00';
$ts = strtotime($fecha_clase_sql);

if (!$ts) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Fecha u hora inválida.']);
    exit;
}

// 5. Hora >= 07:00
if ((int)date('H', $ts) < 7) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La hora de la reserva debe ser desde las 07:00.']);
    exit;
}

// 6. Rango 1 hora mínimo — 30 días máximo
$ahora = time();
if ($ts < $ahora + 3600) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La reserva debe ser con al menos 1 hora de anticipación.']);
    exit;
}
if ($ts > $ahora + (30 * 86400)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No puedes generar reservas a más de 30 días.']);
    exit;
}

// 7. Verificar que el usuario es vendedor y obtener datos de la conversación
$stmt = $conn->prepare("
    SELECT c.comprador_id, c.vendedor_id, c.servicio_id,
           v.nombre AS nombre_vendedor
    FROM conversaciones c
    JOIN alumnos v ON c.vendedor_id = v.id
    WHERE c.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $conversacion_id);
$stmt->execute();
$conv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$conv) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Conversación no encontrada.']);
    exit;
}
if ((int)$conv['vendedor_id'] !== $my_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Solo el tutor puede generar reservas.']);
    exit;
}

$servicio_id = (int)$conv['servicio_id'];
$alumno_id   = (int)$conv['comprador_id'];

// Nombre del tutor con privacidad (Pablo C.)
$partes = preg_split('/\s+/u', trim($conv['nombre_vendedor']), -1, PREG_SPLIT_NO_EMPTY);
$nombre_tutor = htmlspecialchars($partes[0], ENT_QUOTES, 'UTF-8');
if (count($partes) > 1) {
    $nombre_tutor .= ' ' . htmlspecialchars(mb_substr($partes[1], 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '.';
}

// 8. Leer servicio
$stmt = $conn->prepare("
    SELECT precio, precio_oferta, is_subvencionado, cupos_oferta, duracion_minutos
    FROM servicios
    WHERE id = ? AND estado = 'aprobado'
    LIMIT 1
");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$serv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$serv) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Servicio no disponible.']);
    exit;
}

// 9. Calcular monto
$precio_base = (int)$serv['precio'];
$es_oferta   = ($serv['is_subvencionado'] == 1 && $serv['cupos_oferta'] > 0);
$monto_final = $es_oferta ? (int)$serv['precio_oferta'] : $precio_base;
$duracion    = (int)($serv['duracion_minutos'] ?: 60);

// 10. Token único
$token = bin2hex(random_bytes(32));

// 11. Fecha formateada en español
$dias_es  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio',
             'agosto','septiembre','octubre','noviembre','diciembre'];
$fecha_display = ucfirst($dias_es[(int)date('w', $ts)])
               . ' ' . (int)date('j', $ts)
               . ' de ' . $meses_es[(int)date('n', $ts) - 1]
               . ' · ' . date('H:i', $ts);

// 12. INSERT en slots_excepcion
$expira_en_sql = date('Y-m-d H:i:s', $ahora + 86400);
$stmt = $conn->prepare("
    INSERT INTO slots_excepcion
        (token, servicio_id, tutor_id, alumno_id, conversacion_id, fecha_clase, monto, expira_en)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("siiiisis", $token, $servicio_id, $my_id, $alumno_id,
                              $conversacion_id, $fecha_clase_sql, $monto_final, $expira_en_sql);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al registrar la reserva.']);
    exit;
}
$stmt->close();

// 13. Precio display para la card
if ($es_oferta) {
    $pct         = round((1 - $monto_final / $precio_base) * 100);
    $precio_html = '<span style="text-decoration:line-through;color:#9ca3af;">$'
                 . number_format($precio_base, 0, ',', '.')
                 . '</span> <span style="color:#f97316;">-' . $pct . '%</span>'
                 . ' <span style="font-weight:700;">→ $'
                 . number_format($monto_final, 0, ',', '.') . '</span>';
} else {
    $precio_html = '<strong>$' . number_format($monto_final, 0, ',', '.') . '</strong>';
}

$url_pago   = htmlspecialchars('/app/pagar_slot_excepcion.php?token=' . $token, ENT_QUOTES, 'UTF-8');
$fecha_safe = htmlspecialchars($fecha_display, ENT_QUOTES, 'UTF-8');

// 14. Card HTML del mensaje sistema
$card_html = '
<div style="display:flex;justify-content:center;margin:8px 0;">
  <div style="background:#fff;border:1px solid #bfdbfe;border-radius:16px;
              box-shadow:0 1px 4px rgba(0,0,0,.07);max-width:85%;width:100%;overflow:hidden;">
    <div style="background:linear-gradient(90deg,#38bdf8,#54A6D8);padding:12px 16px;">
      <p style="color:#fff;font-size:10px;font-weight:700;letter-spacing:.05em;
                text-transform:uppercase;margin:0 0 2px;">Reserva propuesta</p>
      <p style="color:#fff;font-size:13px;margin:0;">por ' . $nombre_tutor . '</p>
    </div>
    <div style="padding:12px 16px;">
      <p style="font-size:13px;color:#374151;margin:0 0 5px;"><b>Fecha:</b> ' . $fecha_safe . '</p>
      <p style="font-size:13px;color:#374151;margin:0 0 5px;"><b>Duración:</b> ' . $duracion . ' min</p>
      <p style="font-size:13px;color:#374151;margin:0 0 5px;"><b>Precio:</b> ' . $precio_html . '</p>
      <p style="font-size:11px;color:#d97706;margin:0 0 12px;">Válida por 24 horas</p>
      <a href="' . $url_pago . '"
         style="display:block;text-align:center;background:#54A6D8;color:#fff;
                font-weight:700;font-size:13px;padding:10px;border-radius:10px;
                text-decoration:none;">
        Pagar ahora
      </a>
    </div>
  </div>
</div>';

// 15. INSERT mensaje sistema (remitente_id = 0)
$remitente_sistema = 0;
$stmt = $conn->prepare("
    INSERT INTO mensajes (conversacion_id, remitente_id, mensaje, enviado_en, leido)
    VALUES (?, ?, ?, NOW(), 0)
");
$stmt->bind_param("iis", $conversacion_id, $remitente_sistema, $card_html);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al enviar el mensaje al chat.']);
    exit;
}
$stmt->close();

// 16. Actualizar última interacción de la conversación
$stmt = $conn->prepare("
    UPDATE conversaciones
    SET ultima_interaccion = NOW(), oculto_comprador = 0, oculto_vendedor = 0
    WHERE id = ?
");
$stmt->bind_param("i", $conversacion_id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
