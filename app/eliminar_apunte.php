<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../public/login.html");
    exit;
}

$id_apunte = $_POST['id'] ?? null;
$usuario_id = $_SESSION['usuario_id'];
$es_admin = ($_SESSION['rol'] ?? '') === 'admin';

if ($id_apunte) {
    // Obtener el apunte
    $stmt = $conexion->prepare("SELECT id_alumno, archivo FROM apuntes WHERE id = ?");
    $stmt->bind_param("i", $id_apunte);
    $stmt->execute();
    $stmt->store_result();

    // Si no existe el apunte
    if ($stmt->num_rows === 0) {
        $stmt->close();
        $destino = $es_admin ? "admin_apuntes.php" : "mis_apuntes.php";
        header("Location: $destino?error=apunte-no-encontrado");
        exit;
    }

    $stmt->bind_result($id_alumno, $archivo);
    $stmt->fetch();
    $stmt->close();

    // Verificar permiso: admin o dueño
    if ($es_admin || $id_alumno == $usuario_id) {
        // Eliminar archivo del servidor
        if ($archivo && file_exists("../uploads/$archivo")) {
            unlink("../uploads/$archivo");
        }

        // Eliminar de la base de datos
        $stmt = $conexion->prepare("DELETE FROM apuntes WHERE id = ?");
        $stmt->bind_param("i", $id_apunte);
        $stmt->execute();
        $stmt->close();

        $destino = $es_admin ? "admin_apuntes.php" : "mis_apuntes.php";
        header("Location: $destino?eliminado=ok");
        exit;
    } else {
        // No autorizado
        $destino = $es_admin ? "admin_apuntes.php" : "mis_apuntes.php";
        header("Location: $destino?error=no-autorizado");
        exit;
    }
} else {
    header("Location: dashboard.php?error=solicitud-invalida");
    exit;
}

