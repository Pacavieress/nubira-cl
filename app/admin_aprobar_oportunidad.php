<?php
session_start();
require_once 'conexion.php';
// Verifica rol admin
if ($_SESSION['rol'] !== 'admin') {
  http_response_code(403); exit;
}
$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("UPDATE oportunidades SET aprobado=1 WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
header("Location: /admin/oportunidades");
exit;
