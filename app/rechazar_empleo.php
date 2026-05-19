<?php
require_once 'conexion.php';
session_start();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = $_POST['id'] ?? null;

if ($id && is_numeric($id)) {
    $stmt = $conn->prepare("UPDATE empleos SET estado = 'rechazado' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

header("Location: admin_empleos.php");
exit;
