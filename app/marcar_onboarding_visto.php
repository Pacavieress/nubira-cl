<?php
/**
 * ENDPOINT: MARCAR ONBOARDING ("Cómo funciona Nubira") COMO VISTO
 * TIPO: Async (Fire-and-Forget)
 * ESTADO: BLINDADO
 *
 * - POST únicamente (GET => 405)
 * - Valida CSRF (patrón hash_equals, igual que marcar_aviso_leido.php)
 * - Con sesión: UPDATE alumnos SET onboarding_visto = 1 WHERE id = ?
 * - Sin sesión: responde success:true sin tocar BD (el front usa localStorage)
 */
require_once __DIR__ . '/init_sesion.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Solo POST (rechazar GET y demás con 405)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// 2. Visitante (sin sesión): no hay nada que persistir en BD.
//    El frontend recuerda el estado vía localStorage.
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => true]);
    exit;
}

// 3. Validación CSRF (Anti-Falsificación)
$csrf_post = $_POST['csrf_token'] ?? '';
if (empty($csrf_post) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_post)) {
    echo json_encode(['success' => false, 'error' => 'Token inválido.']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

global $conn;

try {
    $stmt = $conn->prepare("UPDATE alumnos SET onboarding_visto = 1 WHERE id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Error de BD.']);
        exit;
    }
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();

    // Sincronizar caché de sesión para evitar relanzar el modal en el mismo flujo
    $_SESSION['onboarding_visto'] = 1;

    echo json_encode(['success' => true]);
    $stmt->close();
} catch (Exception $e) {
    error_log("[NUBIRA SHIELD] Error marcando onboarding como visto: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
?>
