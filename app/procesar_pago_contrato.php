<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) { header('Location: /login'); exit; }

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  http_response_code(403); echo "CSRF inválido"; exit;
}

$contrato_id = (int)($_POST['contrato_id'] ?? 0);
$metodo = trim($_POST['metodo'] ?? '');
$usuario_id = $_SESSION['usuario_id'];

if ($contrato_id <= 0 || !in_array($metodo, ['webpay','transferencia'])) {
  http_response_code(400); echo "Datos inválidos."; exit;
}

// Verificar propiedad del contrato
$stmt = $conn->prepare("SELECT id, estado, monto FROM contratos WHERE id=? AND comprador_id=?");
$stmt->bind_param("ii", $contrato_id, $usuario_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) { echo "Contrato no encontrado."; exit; }
if ($c['estado'] !== 'pendiente_pago') { echo "El contrato ya fue procesado."; exit; }

// Insertar registro de pago simulado
$stmt = $conn->prepare("
  INSERT INTO pagos_escrow (contrato_id, metodo, monto, estado_pago)
  VALUES (?, ?, ?, 'aprobado')
");
$stmt->bind_param("isi", $contrato_id, $metodo, $c['monto']);
$stmt->execute();
$stmt->close();

// Actualizar contrato a “en_progreso”
$stmt = $conn->prepare("
  UPDATE contratos SET estado='en_progreso' WHERE id=? AND comprador_id=?
");
$stmt->bind_param("ii", $contrato_id, $usuario_id);
$stmt->execute();
$stmt->close();

require_once __DIR__ . '/../app/correo.php';

// Datos del contrato
$sql = $conn->prepare("
    SELECT s.titulo, c.monto, comp.correo AS comprador_correo, vend.correo AS vendedor_correo
    FROM contratos c
    JOIN servicios s ON c.servicio_id = s.id
    JOIN alumnos comp ON c.comprador_id = comp.id
    JOIN alumnos vend ON c.vendedor_id = vend.id
    WHERE c.id = ?
");
$sql->bind_param("i", $contrato_id);
$sql->execute();
$datos = $sql->get_result()->fetch_assoc();

if ($datos) {
    enviarCorreoPagoConfirmado(
        $datos['comprador_correo'],
        $datos['vendedor_correo'],
        $datos['titulo'],
        $datos['monto'],
        $contrato_id
    );
}

// Registrar evento
$ev = $conn->prepare("
  INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
  VALUES (?, ?, 'PAGO_OK', CONCAT('Pago confirmado via ', ?))
");
$ev->bind_param("iis", $contrato_id, $usuario_id, $metodo);
$ev->execute();
$ev->close();

header("Location: /app/contrato_detalle.php?id=$contrato_id");
exit;
