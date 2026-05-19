<?php
session_start();
require_once __DIR__ . '/../app/conexion.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
  header("Location: /login");
  exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "ID inválido"; exit; }

$stmt = $conn->prepare("
  SELECT c.id, s.titulo AS servicio_titulo, comp.nombre AS comprador, vend.nombre AS vendedor
  FROM contratos c
  JOIN servicios s ON c.servicio_id = s.id
  JOIN alumnos comp ON c.comprador_id = comp.id
  JOIN alumnos vend ON c.vendedor_id = vend.id
  WHERE c.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) { echo "Contrato no encontrado."; exit; }

$evt = $conn->prepare("
  SELECT e.*, a.nombre AS usuario_nombre
  FROM contrato_eventos e
  LEFT JOIN alumnos a ON e.usuario_id = a.id
  WHERE e.contrato_id = ?
  ORDER BY e.fecha DESC
");
$evt->bind_param("i", $id);
$evt->execute();
$eventos = $evt->get_result();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Historial contrato #<?= $id ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen p-4">
  <div class="max-w-4xl mx-auto bg-white shadow-xl rounded-2xl p-6 border">
    <h1 class="text-xl font-bold text-gray-800 mb-4">🧾 Historial de contrato #<?= $id ?></h1>

    <div class="bg-gray-50 border rounded-lg p-4 mb-6">
      <p><strong>Servicio:</strong> <?= htmlspecialchars($c['servicio_titulo']) ?></p>
      <p><strong>Comprador:</strong> <?= htmlspecialchars($c['comprador']) ?></p>
      <p><strong>Vendedor:</strong> <?= htmlspecialchars($c['vendedor']) ?></p>
    </div>

    <?php if ($eventos->num_rows === 0): ?>
      <p class="text-gray-500">Aún no hay eventos registrados.</p>
    <?php else: ?>
      <ul class="divide-y divide-gray-200">
        <?php while ($e = $eventos->fetch_assoc()): ?>
          <li class="py-3">
            <div class="flex justify-between">
              <div>
                <p class="font-semibold text-gray-800">
                  <?= htmlspecialchars($e['evento']) ?>
                </p>
                <p class="text-gray-600 text-sm">
                  <?= htmlspecialchars($e['detalle']) ?>
                </p>
              </div>
              <div class="text-right">
                <p class="text-xs text-gray-500"><?= htmlspecialchars($e['usuario_nombre'] ?? 'Sistema') ?></p>
                <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($e['fecha'])) ?></p>
              </div>
            </div>
          </li>
        <?php endwhile; ?>
      </ul>
    <?php endif; ?>

    <div class="mt-6">
      <a href="/admin/admin_contratos.php" class="text-[#54A6D8] hover:underline">← Volver</a>
    </div>
    <hr class="my-6 border-gray-200">
<h2 class="text-lg font-semibold text-gray-800 mb-2">🗒️ Agregar nota de soporte</h2>
<form action="/admin/nota_soporte.php" method="POST" class="space-y-2">
  <input type="hidden" name="contrato_id" value="<?= $id ?>">
  <textarea name="nota" rows="3" required
    class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-[#54A6D8]"
    placeholder="Escribe una observación o comentario interno..."></textarea>
  <button type="submit"
    class="bg-[#54A6D8] text-white px-6 py-2 rounded-lg shadow hover:bg-blue-600 active:scale-[.98] transition">
    Guardar nota
  </button>
</form>

  </div>
</body>
</html>
