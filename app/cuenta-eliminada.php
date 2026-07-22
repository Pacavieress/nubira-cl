<?php
// cuenta-eliminada.php

// --- Seguridad: invalidar cualquier sesión residual ---
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// --- Anti-cache + privacidad ---
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Cuenta eliminada</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <meta name="robots" content="noindex, nofollow" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-tr from-blue-100 via-purple-100 to-green-100 min-h-screen flex items-center justify-center text-gray-800">

  <main class="w-full max-w-md mx-4">
    <div class="bg-white/90 backdrop-blur rounded-2xl shadow-xl p-8 text-center border border-gray-100">
      <div class="mx-auto mb-4 w-16 h-16 flex items-center justify-center rounded-full bg-red-50">
        <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>

      <h1 class="text-2xl font-extrabold text-red-600 mb-2">Tu cuenta ha sido eliminada</h1>
      <p class="text-gray-700 mb-6">
        Hemos anoninimizado tus datos y cerrado tu sesión. Esta acción es irreversible.
      </p>

      <div class="flex flex-col sm:flex-row gap-3 justify-center">
        <a href="/login"
           class="inline-block px-5 py-2 rounded-lg bg-blue-600 text-white font-semibold shadow hover:bg-blue-700 transition">
          Iniciar sesión con otra cuenta
        </a>
        <a href="/"
           class="inline-block px-5 py-2 rounded-lg bg-white text-blue-700 font-semibold border border-blue-200 hover:bg-blue-50 transition">
          Volver al inicio
        </a>
      </div>

      <p class="text-xs text-gray-400 mt-6">
        Si necesitas ayuda, contáctanos en <a href="/soporte" class="underline">Soporte</a>.
      </p>
    </div>
  </main>

</body>
</html>
