<?php
// ARCHIVO: app/api/sensor.php
// ESTADO: CORREGIDO (Usa columna 'fecha' y maneja JSON correctamente)

header('Content-Type: application/json');
session_start();

// 1. CONEXIÓN (Buscador universal)
$rutas = [
    __DIR__ . '/../../conexion.php',
    $_SERVER['DOCUMENT_ROOT'] . '/conexion.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/conexion.php'
];
foreach ($rutas as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($conn)) { echo json_encode(["status"=>"error","msg"=>"No DB"]); exit; }

// 2. RECIBIR DATOS (Soporta JSON y POST normal)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fallback por si llega como FormData
    $input = $_POST; 
}

$uid          = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
$evento       = $input['evento'] ?? 'view';
$entidad_tipo = $input['tipo'] ?? '';
$entidad_id   = (int)($input['id'] ?? 0);
$url          = $_SERVER['HTTP_REFERER'] ?? '';

// 3. VALIDACIÓN
if ($uid === 0 || empty($entidad_tipo) || $entidad_id === 0) {
    echo json_encode(["status"=>"ignored", "reason"=>"missing_data"]);
    exit;
}

// 4. EVITAR DUPLICADOS RÁPIDOS (Anti-Spam de 1 minuto)
// Usamos la columna 'fecha' que VIMOS que existe en tu diagnóstico
$check = $conn->prepare("SELECT fecha FROM nubira_behavior_logs WHERE usuario_id=? AND entidad_tipo=? AND entidad_id=? ORDER BY id DESC LIMIT 1");
$check->bind_param("isi", $uid, $entidad_tipo, $entidad_id);
$check->execute();
$res = $check->get_result();

if ($row = $res->fetch_assoc()) {
    $ultimo_visto = strtotime($row['fecha']);
    if (time() - $ultimo_visto < 60) {
        echo json_encode(["status"=>"skipped", "msg"=>"Demasiado pronto"]);
        exit;
    }
}

// 5. INSERTAR (Usando NOW() para el campo fecha)
$sql = "INSERT INTO nubira_behavior_logs (usuario_id, tipo_evento, entidad_tipo, entidad_id, url_origen, fecha) VALUES (?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("issis", $uid, $evento, $entidad_tipo, $entidad_id, $url);

if ($stmt->execute()) {
    echo json_encode(["status"=>"success", "id"=>$conn->insert_id]);
} else {
    echo json_encode(["status"=>"error", "sql"=>$conn->error]);
}
?>