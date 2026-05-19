<?php
session_start();
require_once '../app/conexion.php';
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403); exit('No autorizado');
}
$res = $conn->query("SELECT COUNT(*) as c FROM reclamos_sugerencias WHERE estado='pendiente'");
echo $res->fetch_assoc()['c'] ?? 0;
$res->close();
?>
