<?php
require_once '../app/conexion.php';
session_start();

// Solo admin
if ($_SESSION['rol'] !== 'admin') {
  header('Location: /login');
  exit;
}

// Cargar configuración actual
$res = $conn->query("SELECT * FROM config_publicaciones LIMIT 1");
$config = $res->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $max = (int)$_POST['max_publicaciones_diarias'];
  $min = (int)$_POST['min_caracteres_descripcion'];
  $mod = isset($_POST['moderacion_activa']) ? 1 : 0;

  $conn->query("
    UPDATE config_publicaciones
    SET max_publicaciones_diarias = $max,
        min_caracteres_descripcion = $min,
        moderacion_activa = $mod,
        fecha_actualizacion = NOW()
  ");
  header("Location: admin_publicaciones.php?ok=1");
  exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Configuración de Publicaciones - Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen p-8">

<div class="max-w-xl mx-auto bg-white shadow p-6 rounded-xl border">
  <h1 class="text-2xl font-bold text-[#54A6D8] mb-6">⚙️ Configuración de Publicaciones</h1>

  <?php if (!empty($_GET['ok'])): ?>
    <div class="bg-green-100 border border-green-300 text-green-700 p-3 rounded mb-4">
      ✅ Configuración actualizada correctamente.
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-6">
    <div>
      <label class="font-semibold">Máximo de publicaciones por día</label>
      <input type="number" name="max_publicaciones_diarias" value="<?= $config['max_publicaciones_diarias'] ?>"
             class="w-full border px-3 py-2 rounded mt-1 focus:ring-2 focus:ring-[#54A6D8]" min="0" required>
    </div>

    <div>
      <label class="font-semibold">Mínimo de caracteres en descripción</label>
      <input type="number" name="min_caracteres_descripcion" value="<?= $config['min_caracteres_descripcion'] ?>"
             class="w-full border px-3 py-2 rounded mt-1 focus:ring-2 focus:ring-[#54A6D8]" min="50" required>
    </div>

    <div class="flex items-center gap-2">
      <input type="checkbox" name="moderacion_activa" id="moderacion_activa"
             <?= $config['moderacion_activa'] ? 'checked' : '' ?>>
      <label for="moderacion_activa" class="font-semibold">Revisión manual por administradores</label>
    </div>

    <button type="submit"
      class="bg-[#54A6D8] hover:bg-[#3d91c7] text-white font-semibold px-6 py-2 rounded-lg shadow">
      Guardar Cambios
    </button>
  </form>
</div>

</body>
</html>
