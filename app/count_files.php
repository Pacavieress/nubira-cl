<?php
// app/count_files.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login.php");
    exit;
}
header('Content-Type: application/json');

// Incluimos conexión (están en la misma carpeta app/)
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id']) || !isset($_GET['id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$id_contrato = (int)$_GET['id'];

// CRÍTICO: Ahora usamos la tabla correcta que vi en tu código
$sql = "SELECT COUNT(*) as total FROM contrato_archivos WHERE contrato_id = ?";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $id_contrato);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    echo json_encode(['count' => (int)$res['total']]);
} else {
    echo json_encode(['count' => 0]);
}
?>