<?php
/**
 * NUBIRA 2.0 - FINALIZAR CONTRATO (Lado Tutor)
 * ESTADO: Blindado y con Feedback visual.
 */
session_start();
require_once __DIR__ . '/conexion.php';

// 1. Seguridad de Sesión
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $id_contrato = (int)($_POST['id'] ?? 0);
    $usuario_id  = (int)$_SESSION['usuario_id'];

    if ($id_contrato <= 0) {
        $_SESSION['flash_error'] = "Error: Contrato inválido.";
        header("Location: /app/mis_contratos.php");
        exit;
    }

    // 2. Cirugía SQL: Solo permitimos actualizar si el contrato está realmente "en_progreso"
    $stmt = $conn->prepare("
        UPDATE contratos 
        SET finalizado_vendedor = 1, estado = 'entregado' 
        WHERE id = ? AND vendedor_id = ? AND estado IN ('en_progreso', 'pendiente')
    ");
    
    if ($stmt) {
        $stmt->bind_param("ii", $id_contrato, $usuario_id);
        $stmt->execute();
        
        // 3. Feedback Estilo Nubira (Notificación flash para el frontend)
        if ($stmt->affected_rows > 0) {
            $_SESSION['flash'] = "¡Clase finalizada! Esperando que el alumno confirme para liberar tu pago.";
        }
        $stmt->close();
    }

    header("Location: /app/mis_contratos.php");
    exit;
}

// Redirección de seguridad si entran por GET
header("Location: /app/mis_contratos.php");
exit;
?>