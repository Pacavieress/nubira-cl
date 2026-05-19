<?php
session_start();
require_once __DIR__ . '/conexion.php';

// Solo lectura pública (no requiere login admin)
header('Content-Type: application/json; charset=UTF-8');

// Obtener instituciones únicas desde dominios_permitidos
$sql = "SELECT DISTINCT LOWER(institucion) AS institucion 
        FROM dominios_permitidos 
        WHERE institucion <> ''
        ORDER BY institucion ASC";

$result = $conn->query($sql);
$instituciones = [];

while ($r = $result->fetch_assoc()) {
    $instituciones[] = $r['institucion'];
}

echo json_encode($instituciones);
