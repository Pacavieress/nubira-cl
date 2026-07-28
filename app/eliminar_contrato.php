<?php
// --- 1. ACTIVAR DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- 2. CARGA DE CONEXIÓN ---
$rutas_posibles = [
    __DIR__ . '/conexion.php',
    __DIR__ . '/../app/conexion.php'
];

$conexion_cargada = false;
foreach ($rutas_posibles as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_cargada = true;
        break;
    }
}

if (!$conexion_cargada) die("Error Crítico: No se encuentra conexion.php");

// --- 3. SEGURIDAD ---
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login");
    exit;
}

// CSRF
if (!hash_equals($_SESSION['csrf_contratos'] ?? '', $_GET['csrf_token'] ?? '')) {
    header("Location: /app/admin_contratos.php?error=csrf_invalido");
    exit;
}

// --- 4. LÓGICA DE ELIMINACIÓN ---
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// === CORRECCIÓN AQUÍ ===
// Cambiamos '/admin/' por '/app/' para que coincida con la ubicación real de tu archivo
$url_retorno = "/app/admin_contratos.php"; 

if ($id) {
    // INICIAMOS TRANSACCIÓN
    $conn->begin_transaction();

    try {
        // PASO B: Borrar el contrato
        $stmt_contrato = $conn->prepare("DELETE FROM contratos WHERE id = ?");
        $stmt_contrato->bind_param("i", $id);
        
        if ($stmt_contrato->execute()) {
            $stmt_contrato->close();
            $conn->commit(); // Confirmar cambios
            header("Location: $url_retorno?msg=eliminado_ok");
            exit;
        } else {
            throw new Exception("No se pudo borrar el contrato principal.");
        }

    } catch (Exception $e) {
        $conn->rollback(); // Deshacer cambios si falla
        die("<div style='color:red; font-family:sans-serif; padding:20px;'>
                <h1>Error al eliminar</h1>
                <p>No se pudo completar la operación.</p>
                <p><b>Detalle técnico:</b> " . $e->getMessage() . "</p>
                <p><b>Error SQL:</b> " . $conn->error . "</p>
             </div>");
    }

} else {
    die("Error: ID inválido.");
}
?>