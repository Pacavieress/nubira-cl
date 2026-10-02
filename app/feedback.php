<?php
// Endpoint público de feedback de correos de campaña. NO requiere login ni expone datos.
// URL: /feedback?c=CAMPAÑA&v=util|no_util&e=CORREO&token=HMAC
// GET  -> muestra una página con un botón de confirmar (los escáneres de enlaces de los proveedores de correo
//         hacen GET automáticamente; así no se registra un voto sin que la persona confirme).
// POST -> guarda el voto. El token es bearer: hash_hmac('sha256', 'feedback|campaña|voto|correo', UNSUB_SECRET).

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/config.php';            // define UNSUB_SECRET (desde .env)
require_once __DIR__ . '/helpers/campanas.php';  // feedbackToken()

if (!defined('UNSUB_SECRET')) {
    define('UNSUB_SECRET', '');
}

$correo  = strtolower(trim((string)($_GET['e'] ?? '')));
$campana = trim((string)($_GET['c'] ?? ''));
$voto    = trim((string)($_GET['v'] ?? ''));
$token   = trim((string)($_GET['token'] ?? ''));

$valido = $correo !== ''
    && filter_var($correo, FILTER_VALIDATE_EMAIL)
    && preg_match('/^[a-z0-9_]{1,60}$/', $campana)
    && in_array($voto, ['util', 'no_util'], true)
    && UNSUB_SECRET !== ''
    && hash_equals(feedbackToken($correo, $campana, $voto), $token);

$estado = $valido ? 'confirmar' : 'invalido';

if ($valido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO correo_feedback (correo, campana, voto) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE voto = VALUES(voto), fecha = CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('sss', $correo, $campana, $voto);
        $stmt->execute();
        $stmt->close();
        $estado = 'gracias';
    } catch (\Throwable $e) {
        error_log('feedback.php: ' . $e->getMessage());
        $estado = 'error';
    }
}
$conn->close();

http_response_code(in_array($estado, ['invalido'], true) ? 400 : ($estado === 'error' ? 500 : 200));
header('Cache-Control: no-store');
$etiqueta = $voto === 'util' ? 'Útil' : 'No es útil';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <meta name="robots" content="noindex, nofollow">
  <title>Tu opinión | Nubira</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-6">
  <div class="bg-white border border-gray-100 rounded-3xl shadow-md max-w-md w-full p-10 text-center">
    <div class="mb-6"><span class="text-2xl font-extrabold tracking-tight text-[#54A6D8]">nubira.cl</span></div>

    <?php if ($estado === 'confirmar'): ?>
      <h1 class="text-2xl font-bold tracking-tight text-gray-900 mb-3">¿Confirmas tu respuesta?</h1>
      <p class="text-gray-500 leading-relaxed mb-8">
        Quieres responder que el correo que te enviamos fue: <strong class="text-gray-900"><?= htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') ?></strong>.
      </p>
      <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit"
                class="inline-block bg-[#54A6D8] text-white font-bold px-6 py-3 rounded-xl transition-all hover:shadow-md hover:scale-[1.01]">
          Confirmar respuesta
        </button>
      </form>
    <?php elseif ($estado === 'gracias'): ?>
      <h1 class="text-2xl font-bold tracking-tight text-gray-900 mb-3">Gracias por tu respuesta</h1>
      <p class="text-gray-500 leading-relaxed mb-8">Nos ayuda a mejorar los correos que enviamos.</p>
      <a href="https://nubira.cl/explorar"
         class="inline-block bg-[#54A6D8] text-white font-bold px-6 py-3 rounded-xl transition-all hover:shadow-md hover:scale-[1.01]">Ir a Nubira</a>
    <?php elseif ($estado === 'error'): ?>
      <h1 class="text-2xl font-bold tracking-tight text-gray-900 mb-3">No pudimos guardar tu respuesta</h1>
      <p class="text-gray-500 leading-relaxed mb-8">Inténtalo de nuevo en unos minutos.</p>
    <?php else: ?>
      <h1 class="text-2xl font-bold tracking-tight text-gray-900 mb-3">Enlace no válido</h1>
      <p class="text-gray-500 leading-relaxed mb-8">El enlace puede estar incompleto o dañado.</p>
      <a href="https://nubira.cl/explorar"
         class="inline-block bg-gray-100 text-gray-700 font-bold px-6 py-3 rounded-xl transition-all hover:bg-gray-200">Volver a Nubira</a>
    <?php endif; ?>
  </div>
</body>
</html>
