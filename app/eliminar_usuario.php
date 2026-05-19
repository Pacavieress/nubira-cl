<?php
session_start();
require_once 'conexion.php';

// Solo admin puede eliminar
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: ../public/login.html");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = intval($_POST['id']);

    // Evitar que el admin se elimine a sí mismo
    if ($id === $_SESSION['usuario_id']) {
        header("Location: admin_usuarios.php?error=no-puedes-eliminarte");
        exit;
    }

    // Eliminar usuario
    $stmt = $conexion->prepare("DELETE FROM alumnos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

$conexion->close();
header("Location: admin_usuarios.php?eliminado=ok");
exit;
