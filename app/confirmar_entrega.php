<?php
/**
 * NUBIRA 2.0 - CONFIRMAR ENTREGA (Lado Alumno)
 * ESTADO: Sincronizado con la Billetera (Escrow Liberado)
 */
session_start();
require_once __DIR__ . '/conexion.php';

// 1. Seguridad estricta
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';
$es_admin   = ($rol === 'admin');

$contrato_id = (int)($_POST['contrato_id'] ?? 0);
if ($contrato_id <= 0) {
    $_SESSION['flash_error'] = "Error: Contrato inválido.";
    header("Location: /app/mis_contratos.php");
    exit;
}

// 2. Traer el contrato con bloqueo para evitar doble confirmación
$stmt = $conn->prepare("SELECT id, comprador_id, vendedor_id, estado FROM contratos WHERE id = ? LIMIT 1 FOR UPDATE");
$stmt->bind_param("i", $contrato_id);
$stmt->execute();
$contrato = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contrato) {
    $_SESSION['flash_error'] = "Contrato no encontrado.";
    header("Location: /app/mis_contratos.php");
    exit;
}

// 3. Validación de Propiedad
if (!$es_admin && $usuario_id !== (int)$contrato['comprador_id']) {
    $_SESSION['flash_error'] = "No tienes permiso para confirmar este contrato.";
    header("Location: /app/mini_aula.php?id=" . $contrato_id);
    exit;
}

// 4. EL GATILLO FINANCIERO: Cambiar estado a 'liberado'
// Esto es lo que activa el saldo en datos_bancarios.php
$stmtUpdate = $conn->prepare("
    UPDATE contratos
    SET estado = 'liberado',
        finalizado_comprador = 1,
        fecha_cierre = NOW()
    WHERE id = ?
");
$stmtUpdate->bind_param("i", $contrato_id);
$stmtUpdate->execute();
$stmtUpdate->close();

// 5. Historial de Auditoría
$ev = $conn->prepare("INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle) VALUES (?, ?, 'CONFIRMADO_COMPRADOR', 'El alumno confirmó la clase. Pago liberado al tutor.')");
if ($ev) {
    $ev->bind_param("ii", $contrato_id, $usuario_id);
    $ev->execute();
    $ev->close();
}

// 6. Feedback Visual para el alumno
$_SESSION['flash'] = "¡Clase confirmada! El pago ha sido liberado al tutor con éxito.";
header("Location: /app/mini_aula.php?id=" . $contrato_id);
exit;
?>