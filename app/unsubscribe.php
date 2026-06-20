<?php
// Endpoint público de baja de correos (Ley 19.628). NO requiere login.
// URL: /unsubscribe?token=X&e=Y
// El token es hash_hmac('sha256', $correo, UNSUB_SECRET) — determinístico,
// así múltiples clics del mismo usuario funcionan siempre.
// Funciona en GET (clic del usuario) y POST (one-click de Gmail RFC 8058),
// porque los parámetros viajan en el query string → $_GET en ambos casos.

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php'; // define UNSUB_SECRET (desde .env)

// Fallback por si config.php aún no tiene la constante desplegada
if (!defined('UNSUB_SECRET')) {
    define('UNSUB_SECRET', '');
}

// Self-healing: crear tabla si no existe
$conn->query("CREATE TABLE IF NOT EXISTS unsubscribed (
  id INT AUTO_INCREMENT PRIMARY KEY,
  correo VARCHAR(100) NOT NULL UNIQUE,
  fecha_baja TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  motivo VARCHAR(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$correo = strtolower(trim($_GET['e'] ?? ''));
$token  = trim($_GET['token'] ?? '');

$valido = false;
if ($correo !== '' && $token !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    $esperado = hash_hmac('sha256', $correo, UNSUB_SECRET);
    // hash_equals: comparación timing-safe
    if (hash_equals($esperado, $token)) {
        $valido = true;
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO unsubscribed (correo, motivo) VALUES (?, 'link_email')"
        );
        $stmt->bind_param('s', $correo);
        $stmt->execute();
        $stmt->close();
    }
}
$conn->close();

http_response_code($valido ? 200 : 400);

$titulo = $valido ? "Te diste de baja" : "Enlace no válido";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <meta name="robots" content="noindex, nofollow">
  <title><?= $titulo ?> | Nubira</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-6">
  <div class="bg-white border border-gray-100 rounded-3xl shadow-md max-w-md w-full p-10 text-center">
    <div class="mb-6">
      <span class="text-2xl font-extrabold tracking-tight text-[#54A6D8]">nubira.cl</span>
    </div>
    <?php if ($valido): ?>
      <div class="mx-auto mb-5 w-16 h-16 rounded-full bg-sky-50 flex items-center justify-center">
        <svg class="w-8 h-8 text-[#54A6D8]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
        </svg>
      </div>
      <h1 class="text-xs font-bold tracking-[0.2em] uppercase text-gray-400 mb-2">Suscripción cancelada</h1>
      <h2 class="text-2xl font-bold tracking-tight text-gray-900 mb-3">Te has dado de baja correctamente</h2>
      <p class="text-gray-500 leading-relaxed mb-8">
        No volverás a recibir correos de invitación de Nubira en esta dirección.
        Si fue un error, puedes volver cuando quieras.
      </p>
      <a href="https://nubira.cl/explorar"
         class="inline-block bg-[#54A6D8] text-white font-bold px-6 py-3 rounded-xl transition-all hover:shadow-md hover:scale-[1.01]">
         Ir a Nubira
      </a>
    <?php else: ?>
      <div class="mx-auto mb-5 w-16 h-16 rounded-full bg-red-50 flex items-center justify-center">
        <svg class="w-8 h-8 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </div>
      <h1 class="text-xs font-bold tracking-[0.2em] uppercase text-gray-400 mb-2">Error</h1>
      <h2 class="text-2xl font-bold tracking-tight text-gray-900 mb-3">Enlace no válido</h2>
      <p class="text-gray-500 leading-relaxed mb-8">
        No pudimos procesar tu solicitud de baja. El enlace puede estar incompleto o dañado.
        Escríbenos a <a href="mailto:contacto@nubira.cl" class="text-[#54A6D8] font-semibold">contacto@nubira.cl</a> y te damos de baja manualmente.
      </p>
      <a href="https://nubira.cl/explorar"
         class="inline-block bg-gray-100 text-gray-700 font-bold px-6 py-3 rounded-xl transition-all hover:bg-gray-200">
         Volver a Nubira
      </a>
    <?php endif; ?>
  </div>
</body>
</html>
