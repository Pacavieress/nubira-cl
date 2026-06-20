<?php
session_start();
require_once __DIR__ . '/conexion.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Cuenta no aprobada | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-10 max-w-md w-full text-center">
    <img src="/img/logo.webp" alt="Nubira" class="h-9 mx-auto mb-6">
    <div class="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-5">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
      </svg>
    </div>
    <h1 class="text-2xl font-bold text-gray-900 mb-3 tracking-tight">Cuenta no aprobada</h1>
    <p class="text-gray-500 text-sm leading-relaxed mb-8">
      Tu solicitud de acceso fue rechazada. Si crees que es un error, escríbenos a
      <a href="mailto:contacto@nubira.cl" class="text-[#54A6D8] font-bold hover:underline">contacto@nubira.cl</a>.
    </p>
  </div>
</body>
</html>
