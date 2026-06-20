<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login.html");
    exit;
}

$id_alumno = $_SESSION['usuario_id'];
$institucion = $_SESSION['institucion'] ?? '';
$archivo = $_GET['archivo'] ?? '';

if (!$archivo || !$institucion) {
    echo "❌ Archivo o institución no especificado.";
    exit;
}

// Buscar apunte por archivo y por institución
$stmt = $conn->prepare("SELECT * FROM apuntes WHERE archivo = ? AND institucion = ?");
$stmt->bind_param("ss", $archivo, $institucion);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    echo "❌ El apunte no existe o no pertenece a tu institución.";
    exit;
}

$apunte = $resultado->fetch_assoc();
$id_apunte = $apunte['id'];
$titulo = htmlspecialchars($apunte['titulo']);
$precio = (int) $apunte['precio'];
$texto_precio = $precio > 0 ? "$" . number_format($precio, 0, ',', '.') . " CLP" : "Gratis";

// Verificar si ya compró el apunte
$stmt = $conn->prepare("SELECT * FROM compras WHERE usuario_id = ? AND id_apunte = ?");
$stmt->bind_param("ii", $id_alumno, $id_apunte);
$stmt->execute();
$ya_comprado = $stmt->get_result()->num_rows > 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Pago de Apunte</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
  <div class="card mx-auto shadow" style="max-width: 500px;">
    <div class="card-body text-center">
      <h3 class="mb-3">💳 Pago del Apunte</h3>
      <p><strong>Título:</strong> <?= $titulo ?></p>
      <p><strong>Precio:</strong> <?= $texto_precio ?></p>

      <?php if ($ya_comprado): ?>
        <div class="alert alert-success">
          Ya compraste este apunte. <br>
          <a href="ver_apunte.php?archivo=<?= urlencode($archivo) ?>" class="btn btn-success mt-2">Ver nuevamente</a>
        </div>
      <?php elseif ($precio === 0): ?>
        <div class="alert alert-info">
          Este apunte es gratuito.
          <a href="ver_apunte.php?archivo=<?= urlencode($archivo) ?>" class="btn btn-info mt-2">Ver ahora</a>
        </div>
      <?php else: ?>
        <a href="iniciar_pago.php?id_apunte=<?= $id_apunte ?>&archivo=<?= urlencode($archivo) ?>" class="btn btn-primary">
          💳 Pagar y acceder
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
