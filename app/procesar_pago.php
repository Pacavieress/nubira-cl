<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../public/login.html");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$id_apunte = $_POST['id_apunte'] ?? null;
$archivo = $_POST['archivo'] ?? '';

if (!$id_apunte || !$archivo) {
    echo "❌ Datos incompletos.";
    exit;
}

// Verificar si ya está registrada la compra
$stmt = $conn->prepare("SELECT * FROM compras WHERE usuario_id = ? AND id_apunte = ?");
$stmt->bind_param("ii", $usuario_id, $id_apunte);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    // Obtener el apunte para recuperar el precio
    $stmt_apunte = $conn->prepare("SELECT id_alumno, precio FROM apuntes WHERE id = ?");
    $stmt_apunte->bind_param("i", $id_apunte);
    $stmt_apunte->execute();
    $res_apunte = $stmt_apunte->get_result();
    $apunte = $res_apunte->fetch_assoc();
    $stmt_apunte->close();

    if (!$apunte) {
        echo "❌ El apunte no existe.";
        exit;
    }

    $vendedor_id = $apunte['id_alumno'];
    $precio = $apunte['precio'];
    $estado = 'approved';
    $payment_id = 'manual';

    // Insertar en compras
    $stmt = $conn->prepare("INSERT INTO compras (id_apunte, usuario_id, email_comprador, monto, estado_pago, payment_id, fecha) VALUES (?, ?, '', ?, ?, ?, NOW())");
    $stmt->bind_param("iids", $id_apunte, $usuario_id, $precio, $estado, $payment_id);
    $stmt->execute();
    $stmt->close();

    // Insertar en ventas_apuntes
    $stmt = $conn->prepare("INSERT INTO ventas_apuntes (apunte_id, comprador_id, vendedor_id, precio, pagado_al_vendedor) VALUES (?, ?, ?, ?, 1)");
    $stmt->bind_param("iiid", $id_apunte, $usuario_id, $vendedor_id, $precio);
    $stmt->execute();
    $stmt->close();
}

// Redirigir al apunte comprado
header("Location: ver_apunte.php?archivo=" . urlencode($archivo));
exit;
