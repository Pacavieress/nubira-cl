<?php
session_start();
require_once 'config.php';  // Ajusta si config.php está en otro directorio

// Opcional: redirigir si no hay sesión activa
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
  <title>Pago Pendiente</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-yellow-50 flex items-center justify-center min-h-screen text-gray-800">

  <div class="bg-white p-8 rounded shadow max-w-md text-center border border-yellow-300">
    <h1 class="text-2xl font-bold text-yellow-600 mb-4">⏳ Tu pago está pendiente</h1>
    <p class="mb-6">MercadoPago aún no ha confirmado tu pago. Te notificaremos una vez se apruebe.</p>
    <div class="flex flex-col space-y-3">
      <a href="<?= BASE_URL; ?>/vitrina-apuntes" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded">Volver a la vitrina</a>
      <a href="<?= BASE_URL; ?>/dashboard" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded">Ir al inicio</a>
      <a href="<?= BASE_URL; ?>/iniciar-pago?reference=<?= urlencode($_SESSION['pago']['id_apunte'] ?? '') ?>" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded">Reintentar pago</a>
    </div>
  </div>

</body>
</html>
