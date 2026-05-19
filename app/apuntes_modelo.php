<?php
require_once __DIR__ . '/conexion.php';

function obtenerUltimosApuntes($limite = 6) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM apuntes ORDER BY creado_en DESC LIMIT ?");
    $stmt->bind_param("i", $limite);
    $stmt->execute();
    $resultado = $stmt->get_result();
    return $resultado->fetch_all(MYSQLI_ASSOC);
}

function contarTotalApuntes() {
    global $conn;
    $resultado = $conn->query("SELECT COUNT(*) as total FROM apuntes");
    return $resultado->fetch_assoc()['total'] ?? 0;
}

function contarTotalUsuarios() {
    global $conn;
    $resultado = $conn->query("SELECT COUNT(*) as total FROM alumnos");
    return $resultado->fetch_assoc()['total'] ?? 0;
}
