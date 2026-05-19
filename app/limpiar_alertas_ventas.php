<?php
/**
 * API: LIMPIAR ALERTAS DE VENTAS Y SERVICIOS (NUBIRA 2.0)
 * Este archivo pone los "revisados" en 1 para que el badge desaparezca.
 */
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No session']);
    exit;
}

require_once __DIR__ . '/conexion.php';
$uid = (int)$_SESSION['usuario_id'];

try {
    // 1. Limpiamos las alertas de ventas de apuntes
    $sqlA = "UPDATE ventas_apuntes SET revisado = 1 WHERE vendedor_id = ? AND revisado = 0";
    $stmtA = $conn->prepare($sqlA);
    $stmtA->bind_param("i", $uid);
    $stmtA->execute();
    $filasApuntes = $stmtA->affected_rows;
    $stmtA->close();

    // 2. Limpiamos las alertas de contratos de servicios
    $sqlS = "UPDATE contratos SET revisado = 1 WHERE vendedor_id = ? AND revisado = 0";
    $stmtS = $conn->prepare($sqlS);
    $stmtS->bind_param("i", $uid);
    $stmtS->execute();
    $filasServicios = $stmtS->affected_rows;
    $stmtS->close();

    echo json_encode([
        'status' => 'success',
        'apuntes_limpiados' => $filasApuntes,
        'servicios_limpiados' => $filasServicios
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}