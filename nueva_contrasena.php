<?php
require_once __DIR__ . '/app/conexion.php';
session_start();

$token = $_GET['token'] ?? '';
$mensaje = '';
$token_valido = false;

if ($token) {
    // Buscar usuario con el token vigente
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
} else {
    $mensaje = "❌ Token no proporcionado.";
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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-gray-100 flex flex-col items-center justify-center min-h-screen px-4">

<div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
  <div class="flex justify-center mb-4">
    <img src="/img/logo.webp" alt="Logo Nubira" class="h-12">
  </div>

  <h1 class="text-2xl font-bold text-center mb-4">Restablecer contraseña</h1>

  <?php if (!$token_valido): ?>
    <div class="bg-red-100 border border-red-300 text-red-700 p-3 rounded text-sm text-center">
      <?= htmlspecialchars($mensaje) ?>
    </div>
    <div class="text-center mt-6">
      <a href="/recuperar" class="text-blue-600 hover:underline text-sm">Volver a recuperar contraseña</a>
    </div>
  <?php else: ?>
    <form action="procesar_cambio.php" method="POST" class="space-y-4" id="formNuevaContrasena">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

      <div>
        <label for="nueva" class="block font-medium mb-1">Nueva contraseña</label>
        <p class="text-xs text-gray-500 mt-1 mb-1.5">Mínimo 6 caracteres.</p>
        <div class="relative">
          <input type="password" name="nueva" id="nueva" required minlength="6"
                 class="w-full border border-gray-300 focus:ring-2 focus:ring-[#54A6D8] rounded px-3 py-2 pr-10"
                 placeholder="••••••••">
          <button type="button" id="toggleNueva" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#54A6D8]">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>

      <div>
        <label for="confirmar" class="block font-medium mb-1">Confirmar contraseña</label>
        <div class="relative">
          <input type="password" name="confirmar" id="confirmar" required minlength="6"
                 class="w-full border border-gray-300 focus:ring-2 focus:ring-[#54A6D8] rounded px-3 py-2 pr-10"
                 placeholder="••••••••">
          <button type="button" id="toggleConfirmar" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#54A6D8]">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
        <p id="matchMsg" class="text-xs mt-1.5 font-medium"></p>
      </div>

      <button type="submit"
              class="w-full bg-[#54A6D8] hover:bg-[#4895c3] text-white font-bold py-3.5 rounded-2xl shadow-md hover:shadow-lg hover:scale-[1.01] active:scale-[0.98] transition-all duration-200">
        Guardar nueva contraseña
      </button>
    </form>
  <?php endif; ?>
</div>

<?php if ($token_valido): ?>
<script>
  // Ícono mostrar/ocultar contraseña — mismo patrón de login.php, replicado para ambos campos
  function setupTogglePassword(toggleId, inputId) {
    const toggleBtn = document.getElementById(toggleId);
    const passwordInput = document.getElementById(inputId);
    if (toggleBtn && passwordInput) {
      toggleBtn.addEventListener('click', () => {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        toggleBtn.querySelector('i').classList.toggle('fa-eye');
        toggleBtn.querySelector('i').classList.toggle('fa-eye-slash');
      });
    }
  }
  setupTogglePassword('toggleNueva', 'nueva');
  setupTogglePassword('toggleConfirmar', 'confirmar');

  // Validación en tiempo real de coincidencia (solo visual — la validación real sigue en el backend)
  const inputNueva = document.getElementById('nueva');
  const inputConfirmar = document.getElementById('confirmar');
  const matchMsg = document.getElementById('matchMsg');

  function checkMatch() {
    if (!inputConfirmar.value) {
      matchMsg.textContent = '';
      return;
    }
    if (inputNueva.value === inputConfirmar.value) {
      matchMsg.textContent = 'Coinciden';
      matchMsg.className = 'text-xs mt-1.5 font-medium text-green-600';
    } else {
      matchMsg.textContent = 'Las contraseñas no coinciden';
      matchMsg.className = 'text-xs mt-1.5 font-medium text-red-600';
    }
  }

  inputNueva.addEventListener('input', checkMatch);
  inputConfirmar.addEventListener('input', checkMatch);
</script>
<?php endif; ?>

</body>
</html>
