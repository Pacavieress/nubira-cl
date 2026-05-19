<?php
/**
 * ENDPOINT: ENVIAR AVISO DE ADMINISTRADOR (NUBIRA 2.0)
 * ESTADO: BLINDADO (CSRF + RBAC + Prepared Statements Estrictos)
 */
require_once __DIR__ . '/init_sesion.php';

// 1. CABECERAS ESTRICTAS
header('Content-Type: application/json; charset=utf-8');

// 2. CORTAFUEGOS DE MÉTODO
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// 3. CORTAFUEGOS DE ROL (RBAC)
if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requiere privilegios de administrador.']);
    exit;
}

// 4. VALIDACIÓN CSRF (Anti-Falsificación)
$csrf_post = $_POST['csrf_token'] ?? '';
$csrf_session = $_SESSION['csrf_token'] ?? '';

if (empty($csrf_post) || !hash_equals($csrf_session, $csrf_post)) {
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido o expirado. Recarga la página.']);
    exit;
}

// 5. SANITIZACIÓN Y CASTEO ESTRICTO
$admin_id   = (int)($_SESSION['usuario_id'] ?? 0);
$destino_id = (int)($_POST['destino_id'] ?? 0);
$mensaje    = trim((string)($_POST['mensaje'] ?? ''));

// 6. REGLAS DE NEGOCIO
if ($destino_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'El identificador del usuario destino está corrupto.']);
    exit;
}

if (mb_strlen($mensaje) < 3) {
    echo json_encode(['success' => false, 'error' => 'El mensaje oficial debe contener al menos 3 caracteres.']);
    exit;
}

if ($admin_id === $destino_id) {
    echo json_encode(['success' => false, 'error' => 'Operación redundante: No puedes enviarte un aviso a ti mismo.']);
    exit;
}

// 7. INSERCIÓN BLINDADA
global $conn; // Asegura el alcance si init_sesion encapsula la conexión

try {
    // Nota: Asumimos que la tabla avisos_admin maneja su propio timestamp (fecha_creacion DEFAULT CURRENT_TIMESTAMP)
    $stmt = $conn->prepare("INSERT INTO avisos_admin (admin_id, destino_id, mensaje) VALUES (?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Fallo en la preparación estructural de la base de datos.");
    }

    $stmt->bind_param("iis", $admin_id, $destino_id, $mensaje);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Aviso oficial emitido correctamente.']);
    } else {
        throw new Exception("El motor de base de datos rechazó la inserción.");
    }
    
    $stmt->close();

} catch (Exception $e) {
    // Logger silencioso: Registra el error real en el servidor, devuelve un mensaje limpio al cliente
    error_log("[NUBIRA SHIELD] Error en avisos_admin: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error de infraestructura. El equipo de soporte ha sido notificado.']);
}
?>