<?php
session_start();
require_once __DIR__ . '/conexion.php';

// 1. Seguridad
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login");
    exit;
}

// CSRF
if (!hash_equals($_SESSION['csrf_contratos'] ?? '', $_GET['csrf_token'] ?? '')) {
    header("Location: /app/admin_contratos.php?error=csrf_invalido");
    exit;
}

// 2. Validar ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    // CORRECCIÓN: Apuntamos a /app/
    header("Location: /app/admin_contratos.php?error=id_invalido");
    exit;
}

// 3. Ejecutar Cancelación
$stmt = $conn->prepare("UPDATE contratos SET estado = 'cancelado', fecha_cierre = NOW() WHERE id = ? AND estado = 'en_progreso'");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    // CORRECCIÓN: Redirección a la ruta correcta en /app/
    header("Location: /app/admin_contratos.php?msg=cancelado_ok&estado=en_progreso");
} else {
    header("Location: /app/admin_contratos.php?error=sql_error");
}
$stmt->close();
?>