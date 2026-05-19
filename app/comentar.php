<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../public/login.html");
    exit;
}

$id_apunte = $_POST['id_apunte'] ?? null;
$contenido = trim($_POST['comentario'] ?? '');
$id_alumno = $_SESSION['usuario_id'];

if ($id_apunte && $contenido !== '') {
    $stmt = $conexion->prepare("INSERT INTO comentarios (id_apunte, id_alumno, contenido) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $id_apunte, $id_alumno, $contenido);
    $stmt->execute();
    $stmt->close();
}

header("Location: ver_apuntes.php");
exit;
