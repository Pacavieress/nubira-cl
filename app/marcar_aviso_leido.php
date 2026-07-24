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
$aviso_ids_raw = $_POST['aviso_ids'] ?? null;
$usuario_id = (int)$_SESSION['usuario_id'];

global $conn;

// Modo masivo: modal resumen (2+ avisos), marca todos los pendientes indicados de una vez
if (is_array($aviso_ids_raw) && !empty($aviso_ids_raw)) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $aviso_ids_raw), fn($v) => $v > 0)));

    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'Sin IDs válidos.']);
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Blindaje: destino_id = ? combinado con AND asegura que solo se actualicen avisos del usuario actual,
        // sin importar qué IDs venga en el arreglo
        $stmt = $conn->prepare("UPDATE avisos_admin SET leido = 1, fecha_leido = NOW() WHERE destino_id = ? AND id IN ($placeholders)");
        $tipos = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$usuario_id], $ids);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();

        echo json_encode(['success' => true, 'actualizados' => $stmt->affected_rows]);
        $stmt->close();
    } catch (Exception $e) {
        error_log("[NUBIRA SHIELD] Error marcando avisos como leídos (masivo): " . $e->getMessage());
        echo json_encode(['success' => false]);
    }
    exit;
}

if ($aviso_id <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

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