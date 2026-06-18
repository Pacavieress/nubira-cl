<?php
require_once __DIR__ . '/app/conexion.php';

$token = $_GET['token'] ?? '';
$mensaje = '';
$token_valido = false;

if ($token) {
    $stmt = $conn->prepare("SELECT id, nombre FROM alumnos WHERE token_recuperacion = ? AND expiracion_token > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $usuario = $resultado->fetch_assoc();
        $token_valido = true;
    } else {
        $mensaje = "❌ El enlace ha expirado o es inválido.";
    }
    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Restablecer contraseña - Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/app/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex flex-col items-center justify-center min-h-screen px-4">

<div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
  <div class="flex justify-center mb-4">
    <img src="/img/logo.png" alt="Logo Nubira" class="h-12">
  </div>

  <h1 class="text-2xl font-bold text-center mb-4">Restablecer contraseña</h1>

  <?php if (!$token_valido): ?>
    <div class="bg-red-100 border border-red-300 text-red-700 p-3 rounded text-sm text-center">
      <?= htmlspecialchars($mensaje) ?>
    </div>
    <div class="text-center mt-6">
      <a href="/recuperar.php" class="text-blue-600 hover:underline text-sm">Volver a recuperar contraseña</a>
    </div>
  <?php else: ?>
    <form action="procesar_cambio.php" method="POST" class="space-y-4">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

      <div>
        <label for="nueva" class="block font-medium mb-1">Nueva contraseña</label>
        <input type="password" name="nueva" id="nueva" required
               class="w-full border border-gray-300 focus:ring-2 focus:ring-blue-500 rounded px-3 py-2"
               placeholder="••••••••">
      </div>

      <div>
        <label for="confirmar" class="block font-medium mb-1">Confirmar contraseña</label>
        <input type="password" name="confirmar" id="confirmar" required
               class="w-full border border-gray-300 focus:ring-2 focus:ring-blue-500 rounded px-3 py-2"
               placeholder="••••••••">
      </div>

      <button type="submit"
              class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded transition">
        Guardar nueva contraseña
      </button>
    </form>
  <?php endif; ?>
</div>

</body>
</html>
