<?php
header('Content-Type: application/json');
session_start();
require_once 'conexion.php';
require_once 'correo.php'; // Debe estar configurado

// Seguridad: Solo admin puede hacer esto
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
    echo json_encode(['error' => 'No puedes resetear tu propia contraseña']);
    exit;
}

// 1. Buscar el usuario a resetear
$stmt = $conn->prepare("SELECT correo, nombre FROM alumnos WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($correo_usuario, $nombre_usuario);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuario no encontrado']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

// 2. Generar nueva contraseña segura
function generar_password($largo = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@$%!&?';
    $pw = '';
    for ($i = 0; $i < $largo; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pw;
}
$nueva_password = generar_password(10);
$hash = password_hash($nueva_password, PASSWORD_DEFAULT);

// 3. Actualizar en la base (password y flag)
$stmt2 = $conn->prepare("UPDATE alumnos SET password = ?, debe_cambiar_password = 1 WHERE id = ?");
$stmt2->bind_param('si', $hash, $id);
$stmt2->execute();
if ($stmt2->affected_rows < 1) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo actualizar la contraseña']);
    $stmt2->close();
    $conn->close();
    exit;
}
$stmt2->close();

// 4. Enviar correo al usuario (PHPMailer)
$enviado = enviarCorreoPasswordTemporal($correo_usuario, $nombre_usuario, $nueva_password);

// 5. (Opcional) Guardar log de reseteo aquí

if ($enviado) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No se pudo enviar el correo. Debes notificar manualmente al usuario.'
    ]);
}

$conn->close();
?>
