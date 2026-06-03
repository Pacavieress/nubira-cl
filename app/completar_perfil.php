<?php
session_start();
require_once __DIR__ . '/conexion.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }
$nombre = htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Completar Perfil | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 max-w-md w-full text-center">
    <img src="/img/logo.webp" alt="Nubira" class="h-9 mx-auto mb-6">
    <div class="w-14 h-14 bg-sky-50 rounded-full flex items-center justify-center mx-auto mb-5">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-[#54A6D8]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
      </svg>
    </div>
    <h1 class="text-2xl font-bold text-gray-900 mb-3 tracking-tight">Verificación de cuenta</h1>
    <p class="text-gray-500 text-sm leading-relaxed mb-8">
      <?= $nombre ? "Hola $nombre, estamos" : "Estamos" ?> preparando el formulario de verificación.<br>
      Pronto podrás completar tu perfil para que nuestro equipo lo revise.<br><br>
      Mientras tanto, puedes navegar la plataforma sin restricciones.
    </p>
    <a href="/vitrina" class="block w-full bg-[#54A6D8] hover:bg-[#4592c0] text-white font-bold py-3.5 rounded-xl transition-all">
      Ir a la vitrina
    </a>
  </div>
</body>
</html>
