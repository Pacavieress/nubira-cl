<?php
session_start();
require_once __DIR__ . '/conexion.php';

$id = (int)($_GET['id'] ?? 0);
if (!isset($_SESSION['usuario_id']) || $id <= 0) {
  header('Location: /login');
  exit;
}

// 🔍 Obtener datos del contrato y servicio
$stmt = $conn->prepare("
  SELECT c.*, s.titulo AS servicio_titulo, a.nombre AS vendedor_nombre
  FROM contratos c
  JOIN servicios s ON c.servicio_id = s.id
  JOIN alumnos a ON c.vendedor_id = a.id
  WHERE c.id=? AND (c.comprador_id=? OR c.vendedor_id=?)
");
$stmt->bind_param("iii", $id, $_SESSION['usuario_id'], $_SESSION['usuario_id']);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) {
  echo "Contrato no encontrado.";
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Contrato #<?= $id ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-lg border">
    <h1 class="text-xl font-bold text-gray-800 mb-4">Contrato #<?= $id ?></h1>

    <p><strong>Servicio:</strong> <?= htmlspecialchars($c['servicio_titulo']) ?></p>
    <p><strong>Vendedor:</strong> <?= htmlspecialchars($c['vendedor_nombre']) ?></p>
    <p><strong>Monto:</strong> $<?= number_format($c['monto'], 0, ',', '.') ?></p>
    <p><strong>Estado:</strong> 
      <span class="font-semibold <?= $c['estado']==='en_progreso' ? 'text-blue-600' : ($c['estado']==='liberado' ? 'text-green-600' : 'text-gray-600') ?>">
        <?= ucfirst(str_replace('_',' ',$c['estado'])) ?>
      </span>
    </p>

    <hr class="my-4 border-gray-200">

    <?php if ($c['estado'] === 'en_progreso'): ?>
      <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
        <p>✅ El pago ha sido recibido y está retenido en Nubira.cl.</p>
        <p class="text-sm text-gray-600">Cuando ambas partes confirmen que el servicio terminó, se liberará automáticamente.</p>
      </div>

      <?php
        $soy_comprador = ($_SESSION['usuario_id'] == $c['comprador_id']);
        $soy_vendedor  = ($_SESSION['usuario_id'] == $c['vendedor_id']);
        $finalizadoPropio = ($soy_comprador && $c['confirmado_comprador']) || ($soy_vendedor && $c['confirmado_vendedor']);
      ?>

      <?php if (!$finalizadoPropio): ?>
        <form action="/app/marcar_finalizado.php" method="POST" class="mt-4">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
          <input type="hidden" name="contrato_id" value="<?= (int)$id ?>">
          <button type="submit"
            class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2 rounded-lg transition">
            Marcar como finalizado
          </button>
        </form>
      <?php else: ?>
        <div class="bg-green-50 border border-green-300 p-3 rounded-lg text-green-700 mt-4 text-center">
          ✅ Ya marcaste este servicio como finalizado.
        </div>
      <?php endif; ?>

      <?php if ($c['confirmado_comprador'] && $c['confirmado_vendedor']): ?>
        <div class="bg-blue-50 border border-blue-300 p-3 rounded-lg text-blue-700 mt-4 text-center">
          💰 Ambos han finalizado el servicio. Fondos liberados al vendedor.
        </div>
      <?php endif; ?>

    <?php elseif ($c['estado'] === 'liberado'): ?>
      <div class="bg-green-50 border border-green-300 p-3 rounded-lg text-green-700 mt-4 text-center">
        🎉 El pago fue liberado correctamente. Gracias por usar Nubira.cl.
      </div>

    <?php else: ?>
      <div class="bg-gray-50 border border-gray-300 p-3 rounded-lg text-gray-700 mt-4 text-center">
        ⏳ Este contrato está pendiente de pago o revisión.
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
