<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id']) || empty($_SESSION['cuenta_express'])) {
    header("Location: /vitrina"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['redir'])) {
    $_SESSION['redir_completar_express'] = filter_var($_GET['redir'], FILTER_SANITIZE_URL);
}

$usuario_id = (int)$_SESSION['usuario_id'];
$nombre     = htmlspecialchars($_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['csrf_completar_express'])) {
    $_SESSION['csrf_completar_express'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_completar_express'];

$errores  = [];
$guardado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $errores[] = 'Sesión inválida. Recarga la página e intenta nuevamente.';
    } else {
        $pass1     = $_POST['contrasena']         ?? '';
        $pass2     = $_POST['contrasena_confirm']  ?? '';
        $terminos  = !empty($_POST['terminos']);

        if (strlen($pass1) < 6)        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        if ($pass1 !== $pass2)         $errores[] = 'Las contraseñas no coinciden.';
        if (!$terminos)                $errores[] = 'Debes aceptar los términos y condiciones.';

        if (empty($errores)) {
            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE alumnos SET password = ?, cuenta_express = 0 WHERE id = ?");
            $stmt->bind_param("si", $hash, $usuario_id);
            if ($stmt->execute()) {
                $stmt->close();
                $_SESSION['cuenta_express'] = false;
                $redir = $_SESSION['redir_completar_express'] ?? '/vitrina';
                unset($_SESSION['redir_completar_express']);
                header("Location: " . $redir); exit;
            }
            $stmt->close();
            $errores[] = 'Error al guardar. Intenta nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Completa tu registro | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @keyframes fadeIn { from { opacity: 0; transform: scale(0.98) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    .animate-fade-in { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
  </style>
</head>

<body class="bg-white min-h-screen flex antialiased text-gray-800">

  <div class="hidden md:flex w-1/2 h-screen sticky top-0 bg-cover bg-center relative"
       style="background-image: url('https://images.unsplash.com/photo-1562774053-701939374585?q=80&w=1986&auto=format&fit=crop');">
    <div class="absolute inset-0 bg-gradient-to-t from-[#54A6D8]/90 via-blue-900/40 to-transparent"></div>
    <div class="absolute bottom-16 left-12 text-white pr-12 z-10">
      <h2 class="text-4xl font-bold mb-4 tracking-tight leading-tight">Un último paso.</h2>
      <p class="text-lg opacity-90 font-medium">Protege tu cuenta con una contraseña y empieza a usar Nubira.</p>
    </div>
  </div>

  <div class="w-full md:w-1/2 bg-white min-h-screen overflow-y-auto">
    <div class="w-full max-w-[420px] mx-auto px-6 pt-8 pb-6 md:pt-16 md:pb-12 animate-fade-in">

      <div class="mb-6 text-center md:text-left">
        <a href="/" class="inline-block transition-transform hover:scale-105">
          <img src="/img/logo.webp" alt="Nubira" class="h-9 w-auto">
        </a>
      </div>

      <h1 class="text-3xl font-bold text-gray-900 mb-2 tracking-tight">Crea tu contraseña</h1>
      <p class="text-gray-500 mb-6 text-sm">Hola, <strong><?= $nombre ?></strong>. Tu cuenta está lista. Solo necesitas una contraseña para protegerla.</p>

      <?php if ($errores): ?>
        <div class="mb-6 p-4 rounded-xl text-sm flex gap-3 items-start border bg-red-50 text-red-700 border-red-100">
          <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i>
          <ul class="space-y-1"><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">

        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Contraseña</label>
          <input type="password" name="contrasena" required minlength="6"
                 class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all placeholder-gray-400"
                 placeholder="Mínimo 6 caracteres">
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Confirmar contraseña</label>
          <input type="password" name="contrasena_confirm" required minlength="6"
                 class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all placeholder-gray-400"
                 placeholder="Repite tu contraseña">
        </div>

        <div class="flex items-start gap-3 pt-1">
          <input type="checkbox" name="terminos" id="terminos" required
                 class="mt-0.5 w-4 h-4 rounded border-gray-300 text-[#54A6D8] focus:ring-[#54A6D8]">
          <label for="terminos" class="text-sm text-gray-600">
            Acepto los <a href="/terminos" target="_blank" class="text-[#54A6D8] font-medium hover:underline">términos y condiciones</a>
          </label>
        </div>

        <button type="submit"
                class="w-full bg-[#54A6D8] hover:bg-[#4895c3] text-white font-bold py-3.5 rounded-2xl shadow-md hover:shadow-lg hover:scale-[1.01] active:scale-[0.98] transition-all duration-200 flex justify-center items-center gap-2 mt-3">
          <span>Completar registro</span>
          <i class="fa-solid fa-arrow-right text-xs"></i>
        </button>
      </form>

    </div>
  </div>

</body>
</html>
