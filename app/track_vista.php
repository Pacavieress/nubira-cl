<?php
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/geoip.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['ok' => false]);
    exit;
}

$tipo          = $data['tipo'] ?? '';
$publicacion_id = (int)($data['publicacion_id'] ?? 0);
$session_id_raw = $data['session_id'] ?? '';

if (!in_array($tipo, ['servicio', 'apunte'], true) || $publicacion_id <= 0 || strlen($session_id_raw) < 10) {
    echo json_encode(['ok' => false]);
    exit;
}

$session_id = substr($session_id_raw, 0, 64);
$origen_raw  = $data['origen'] ?? '';
$origen      = $origen_raw !== '' ? substr($origen_raw, 0, 120) : null;

$tiempo     = min((int)($data['tiempo_segundos'] ?? 0), 7200);
$scroll     = max(0, min(100, (int)($data['scroll_max_pct'] ?? 0)));
$leyo       = ($data['leyo_completo'] ?? false) ? 1 : 0;
$disp_raw   = $data['dispositivo'] ?? null;
$dispositivo = in_array($disp_raw, ['movil', 'tablet', 'desktop'], true) ? $disp_raw : null;

$user_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
if ($user_id === 0) $user_id = null;

$ip_raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = substr($ip_raw, 0, 45);

// Lookup geo solo en el primer ping de cada sesión
$pais   = null;
$ciudad = null;
$chk = $conn->prepare("SELECT 1 FROM vistas_detalle WHERE session_id = ? AND tipo = ? AND publicacion_id = ? LIMIT 1");
if ($chk) {
    $chk->bind_param("ssi", $session_id, $tipo, $publicacion_id);
    $chk->execute();
    $chk->store_result();
    $es_primer_ping = ($chk->num_rows === 0);
    $chk->close();
    if ($es_primer_ping) {
        $geo    = geoip_lookup($ip);
        $pais   = $geo['pais'];
        $ciudad = $geo['ciudad'];
    }
}

$sql = "INSERT INTO vistas_detalle
    (tipo, publicacion_id, user_id, session_id, fecha_ultimo_evento, tiempo_segundos, scroll_max_pct, leyo_completo, dispositivo, origen, ip, pais, ciudad)
    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        fecha_ultimo_evento = NOW(),
        tiempo_segundos     = GREATEST(tiempo_segundos, VALUES(tiempo_segundos)),
        scroll_max_pct      = GREATEST(scroll_max_pct,  VALUES(scroll_max_pct)),
        leyo_completo       = GREATEST(leyo_completo,   VALUES(leyo_completo)),
        user_id             = COALESCE(user_id,         VALUES(user_id))";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['ok' => false]);
    exit;
}

// tipos: tipo=s, pub_id=i, user_id=i, session_id=s, tiempo=i, scroll=i, leyo=i, dispositivo=s, origen=s, ip=s
$stmt->bind_param('siisiiisssss', $tipo, $publicacion_id, $user_id, $session_id, $tiempo, $scroll, $leyo, $dispositivo, $origen, $ip, $pais, $ciudad);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);
