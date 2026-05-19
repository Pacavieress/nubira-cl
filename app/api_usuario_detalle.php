<?php
header('Content-Type: application/json');
session_start();

require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

// *** OJO: NO incluye created_at ***
$stmt = $conn->prepare("SELECT id, nombre, correo, dominio, confirmado, rol, carrera FROM alumnos WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();

if (method_exists($stmt, 'get_result')) {
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'usuario' => $row]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
    }
} else {
    $stmt->bind_result($id, $nombre, $correo, $dominio, $confirmado, $rol, $carrera);
    if ($stmt->fetch()) {
        $row = [
            'id' => $id,
            'nombre' => $nombre,
            'correo' => $correo,
            'dominio' => $dominio,
            'confirmado' => $confirmado,
            'rol' => $rol,
            'carrera' => $carrera
        ];
        echo json_encode(['success' => true, 'usuario' => $row]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
    }
}
$stmt->close();
$conn->close();
?>
