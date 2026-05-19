<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';
$es_admin   = ($rol === 'admin');

$contrato_id = (int)($_POST['contrato_id'] ?? 0);
if ($contrato_id <= 0) {
    exit('Contrato inválido');
}

// 1) Traer contrato
$stmt = $conn->prepare("
    SELECT id, comprador_id, vendedor_id, estado
    FROM contratos
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) {
    exit('Contrato no encontrado');
}

// 2) Seguridad: solo comprador o admin
if (!$es_admin && $usuario_id !== (int)$contrato['comprador_id']) {
    http_response_code(403);
    exit('No tienes permiso para reclamar este contrato.');
}

// 3) Solo tiene sentido reclamar si el vendedor ya marcó entregado
if (!in_array($contrato['estado'], ['en_progreso', 'finalizado_vendedor'], true)) {
    exit('Este contrato no está en un estado válido para reclamo.');
}

// 4) Actualizar estado -> pendiente_revisión
$stmt = $conn->prepare("
    UPDATE contratos
    SET estado = 'pendiente_revisión'
    WHERE id = ?
");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$stmt->close();

// 5) Registrar evento
if ($ev = $conn->prepare("
    INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
    VALUES (?, ?, 'RECLAMO', 'El comprador reportó un problema con el servicio')
")) {
    $ev->bind_param("ii", $contrato_id, $usuario_id);
    $ev->execute();
    $ev->close();
}

/**
 * Aquí podrías:
 * - Enviar correo a soporte@nubira.cl
 * - Marcar el contrato en un panel de “casos abiertos”
 */

header("Location: /app/mini_aula.php?id=" . $contrato_id);
exit;
