<?php
session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';

$id_contrato = (int)($_GET['id'] ?? 0);
$user_id     = (int)($_SESSION['usuario_id'] ?? 0);

// 📦 Datos devueltos por Mercado Pago
$mp_status  = $_GET['collection_status'] ?? '';
$mp_ref     = $_GET['external_reference'] ?? '';
$mp_id      = $_GET['collection_id'] ?? '';
$status_det = $_GET['status_detail'] ?? '';

if ($id_contrato > 0) {
  // 🔍 Comprobar si ya se registró el evento hoy (evita duplicados)
  $check = $conn->prepare("
      SELECT COUNT(*) FROM contrato_eventos
      WHERE contrato_id=? AND evento='PAGO_PENDIENTE' AND DATE(fecha)=CURDATE()
  ");
  $check->bind_param("i", $id_contrato);
  $check->execute();
  $check->bind_result($existe);
  $check->fetch();
  $check->close();

  if (!$existe) {
    $detalle = "Pago iniciado en Mercado Pago | status={$mp_status} | id={$mp_id} | ref={$mp_ref}";
    $stmt = $conn->prepare("
      INSERT INTO contrato_eventos (contrato_id, usuario_id, evento, detalle)
      VALUES (?, ?, 'PAGO_PENDIENTE', ?)
    ");
    $stmt->bind_param("iis", $id_contrato, $user_id, $detalle);
    $stmt->execute();
    $stmt->close();
  }

  // ⚙️ Actualizar estado solo si aún no está en progreso
  $upd = $conn->prepare("
    UPDATE contratos
    SET estado = 'pendiente_pago'
    WHERE id = ? AND estado NOT IN ('en_progreso', 'liberado', 'cancelado')
  ");
  $upd->bind_param("i", $id_contrato);
  $upd->execute();
  $upd->close();

  // 🪵 Log de seguimiento
  file_put_contents(__DIR__ . '/../log_envio.txt',
    date("Y-m-d H:i:s") . " - ⏳ Pago pendiente contrato #$id_contrato | status={$mp_status} | id={$mp_id}\n",
    FILE_APPEND
  );
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Pago pendiente - Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen p-4">
  <div class="bg-white rounded-2xl shadow-lg p-6 text-center max-w-md">
    <h1 class="text-2xl font-bold text-yellow-500 mb-3">⏳ Pago pendiente</h1>
    <p class="text-gray-700 mb-4">
      Tu pago por el servicio se está procesando.<br>
      Mercado Pago puede tardar unos minutos en confirmarlo.<br>
      <?php if ($status_det): ?>
        <span class="text-sm text-gray-500 block mt-1">Detalle: <?= htmlspecialchars($status_det) ?></span>
      <?php endif; ?>
    </p>
    <div class="flex flex-col sm:flex-row gap-3 justify-center">
      <a href="<?= BASE_URL ?>/dashboard" 
         class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">
         Volver al panel
      </a>
    </div>
  </div>
</body>
</html>
