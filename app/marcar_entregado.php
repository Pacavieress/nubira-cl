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
    SELECT id, servicio_id, comprador_id, vendedor_id, estado
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

// 2) Seguridad: solo vendedor o admin
if (!$es_admin && $usuario_id !== (int)$contrato['vendedor_id']) {
    http_response_code(403);
    exit('No tienes permiso para marcar este contrato como entregado.');
}

// 3) Solo se puede marcar desde en_progreso
if ($contrato['estado'] !== 'en_progreso') {
    exit('Este contrato no está en estado válido para marcar como entregado.');
}

// 4) Actualizar estado -> finalizado_vendedor
$stmt = $conn->prepare("
    UPDATE contratos
    SET estado = 'finalizado_vendedor',
        fecha_cierre = NOW()
    WHERE id = ?
");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$stmt->close();

// 5) Registrar evento (si existe la tabla)
if ($ev = $conn->prepare("
    INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
    VALUES (?, ?, 'ENTREGADO_VENDEDOR', 'El vendedor marcó el servicio como entregado')
")) {
    $ev->bind_param("ii", $contrato_id, $usuario_id);
    $ev->execute();
    $ev->close();
}

// 6) Redirigir de vuelta a Mini Aula
header("Location: /app/mini_aula.php?id=" . $contrato_id);
exit;
