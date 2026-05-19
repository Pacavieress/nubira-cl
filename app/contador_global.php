<?php
require_once 'conexion.php';
$res = $conn->query("SELECT COUNT(*) AS total FROM alumnos");
$total = $res ? intval($res->fetch_assoc()['total']) : 0;
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Usuarios Totales</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <meta http-equiv="refresh" content="10">
  <style>body {background: #090b1a;}</style>
</head>
<body class="flex items-center justify-center min-h-screen">
  <div class="text-center">
    <div class="text-white text-lg mb-6">Usuarios totales</div>
    <div class="text-7xl md:text-9xl font-extrabold text-green-400 tracking-widest drop-shadow-xl"><?= number_format($total, 0, ',', '.') ?></div>
    <div class="text-gray-300 text-sm mt-6">Actualiza cada 10s · Nubira</div>
  </div>
</body>
</html>
