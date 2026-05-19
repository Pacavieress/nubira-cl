<?php
session_start();
require_once __DIR__ . '/../app/conexion.php';

if (($_SESSION['rol'] ?? '') !== 'admin') { exit('No autorizado'); }

$id = (int)($_POST['contrato_id'] ?? 0);
$nota = trim($_POST['nota'] ?? '');
if ($id <= 0 || $nota === '') { exit('Datos inválidos'); }

$stmt = $conn->prepare("INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
                        VALUES (?, ?, 'NOTA_ADMIN', ?)");
$stmt->bind_param("iis", $id, $_SESSION['usuario_id'], $nota);
$stmt->execute();
$stmt->close();

header("Location: /admin/admin_eventos_contrato.php?id=$id");
exit;
