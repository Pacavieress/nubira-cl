<?php
header('Content-Type: application/json');
require_once 'conexion.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

if ($_SESSION['usuario_id'] == $id) {
    http_response_code(400);
    echo json_encode(['error' => 'No puedes cambiar tu propio rol']);
    exit;
}

$stmt = $conn->prepare("SELECT rol FROM alumnos WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $nuevo_rol = ($row['rol'] === 'admin') ? 'alumno' : 'admin';
    $stmt2 = $conn->prepare("UPDATE alumnos SET rol = ? WHERE id = ?");
    $stmt2->bind_param('si', $nuevo_rol, $id);
    $stmt2->execute();

    if ($stmt2->affected_rows > 0) {
        echo json_encode(['success' => true, 'nuevo_rol' => $nuevo_rol]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo actualizar']);
    }
    $stmt2->close();
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Usuario no encontrado']);
}
$stmt->close();
$conn->close();
?>
