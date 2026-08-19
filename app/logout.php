<?php
session_start();
require_once __DIR__ . '/../app/conexion.php';

// [Fase 5] Captura session_id() ANTES de session_destroy() — sigue siendo legible después,
// pero se captura antes por claridad. Se borra sin importar si había usuario_id (defensivo).
$sid = session_id();
$stmt_del_sesion_api = $conn->prepare("DELETE FROM sesiones_api WHERE session_id = ?");
$stmt_del_sesion_api->bind_param("s", $sid);
$stmt_del_sesion_api->execute();
$stmt_del_sesion_api->close();

// Si hay usuario logueado, borrar el token de la base de datos
if (!empty($_SESSION['usuario_id'])) {
    $id = (int) $_SESSION['usuario_id'];
    $stmt = $conn->prepare("UPDATE alumnos SET remember_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

// Eliminar cookie remember_token del navegador
if (!empty($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    unset($_COOKIE['remember_token']);
}

// Destruir sesión PHP
$_SESSION = [];
session_destroy();

// Redirigir al inicio
header("Location: /");
exit;
