<?php
// app/count_files.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión requerida']);
    exit;
}

require_once __DIR__ . '/conexion.php';

$usuario_id  = (int)$_SESSION['usuario_id'];
$id_contrato = (int)($_GET['id'] ?? 0);
$es_admin    = (($_SESSION['rol'] ?? '') === 'admin');

if ($id_contrato <= 0) {
    echo json_encode(['count' => 0]);
    exit;
}

if (!$es_admin) {
    $stmt_auth = $conn->prepare(
        "SELECT id FROM contratos WHERE id = ? AND (comprador_id = ? OR vendedor_id = ?) LIMIT 1"
    );
    $stmt_auth->bind_param("iii", $id_contrato, $usuario_id, $usuario_id);
    $stmt_auth->execute();
    $permitido = $stmt_auth->get_result()->fetch_assoc();
    $stmt_auth->close();

    if (!$permitido) {
        http_response_code(403);
        echo json_encode(['error' => 'Sin permiso']);
        exit;
    }
}

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM contrato_archivos WHERE contrato_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $id_contrato);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['count' => (int)$res['total']]);
} else {
    echo json_encode(['count' => 0]);
}
?>
