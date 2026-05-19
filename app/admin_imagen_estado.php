<?php
session_start();
require_once __DIR__ . '/../app/conexion.php';

// Solo administradores
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Acceso denegado']);
    exit;
}

// Protección CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Solicitud inválida']);
    exit;
}

$id      = intval($_POST['id'] ?? 0);
$accion  = trim($_POST['accion'] ?? '');
$motivo  = trim($_POST['motivo'] ?? '');

if ($id <= 0 || !in_array($accion, ['aprobar', 'rechazar'])) {
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

// --- Definir nuevos valores según acción ---
if ($accion === 'aprobar') {
    $estado = 'aprobada';
    $motivo_sql = null;
} else {
    $estado = 'rechazada';
    $motivo_sql = $motivo !== '' ? $motivo : 'Rechazada por el administrador';
}

// --- Actualizar base de datos ---
$stmt = $conn->prepare("
    UPDATE servicios 
    SET imagen_estado = ?, 
        imagen_motivo = ?, 
        fecha_revision = NOW()
    WHERE id = ?
");
$stmt->bind_param('ssi', $estado, $motivo_sql, $id);
$ok = $stmt->execute();
$stmt->close();

// --- Enviar respuesta ---
if ($ok) {
    echo json_encode(['ok' => true, 'msg' => "Imagen $estado correctamente"]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Error al actualizar registro']);
}
