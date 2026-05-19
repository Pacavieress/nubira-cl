<?php
/**
 * ENDPOINT: MARCAR AVISO ADMIN COMO LEÍDO
 * ESTADO: BLINDADO
 */
require_once __DIR__ . '/init_sesion.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

// Validación CSRF (Anti-Falsificación)
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['success' => false, 'error' => 'Token inválido.']);
    exit;
}

$aviso_id = (int)($_POST['aviso_id'] ?? 0);
$usuario_id = (int)$_SESSION['usuario_id'];

if ($aviso_id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

global $conn;

try {
    // Blindaje: Aseguramos que solo el destinatario real pueda marcar SU aviso como leído
$stmt = $conn->prepare("UPDATE avisos_admin SET leido = 1, fecha_leido = NOW() WHERE id = ? AND destino_id = ?");
    $stmt->bind_param("ii", $aviso_id, $usuario_id);
    $stmt->execute();
    
if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Aviso no encontrado o ya leído.']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("[NUBIRA SHIELD] Error marcando aviso como leído: " . $e->getMessage());
    echo json_encode(['success' => false]);
}
?>