<?php
// --- 1. ACTIVAR DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexion.php'; // Conexión directa en /app/

// --- 2. SEGURIDAD: SOLO ADMIN ---
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login");
    exit;
}

// CSRF
if (!hash_equals($_SESSION['csrf_contratos'] ?? '', $_GET['csrf_token'] ?? '')) {
    header("Location: /app/admin_contratos.php?error=csrf_invalido");
    exit;
}

// --- 3. LÓGICA DE REVERSIÓN ---
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// IMPORTANTE: Redirección a la carpeta /app/ donde tienes tu admin_contratos.php
$url_retorno = "/app/admin_contratos.php"; 

if ($id) {
    // Cambiamos el estado a 'en_progreso' y borramos la fecha de cierre
    // para que vuelva a estar activo y gestionable.
    $stmt = $conn->prepare("UPDATE contratos SET estado = 'en_progreso', fecha_cierre = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        // Redirigir filtrando por 'en_progreso' para ver el contrato que acabamos de revivir
        header("Location: $url_retorno?msg=revertido_ok&estado=en_progreso");
        exit;
    } else {
        die("Error SQL al revertir: " . $stmt->error);
    }
    $stmt->close();
} else {
    die("Error: ID de contrato no válido.");
}
?>