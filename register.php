<?php
session_start();
require_once(__DIR__ . '/app/conexion.php');
require_once(__DIR__ . '/app/correo.php');

$mensaje = '';
$tipo_alerta = ''; // 'error' o 'success'

// Prefill del correo desde el link de campaña (/register?email=...)
$correo = strtolower(trim($_GET['email'] ?? ''));

// --- NUEVO: Atrapar respuestas del ticket de soporte ---
if (isset($_GET['ticket'])) {
    if ($_GET['ticket'] === 'exito') {
        $mensaje = 'Hemos recibido tu reporte. Lo revisaremos lo antes posible.';
        $tipo_alerta = 'success';
    } elseif ($_GET['ticket'] === 'correo_invalido') {
        $mensaje = 'El correo de contacto ingresado no es válido.';
        $tipo_alerta = 'error';
    } else {
        $mensaje = 'Hubo un problema al enviar tu reporte. Intenta nuevamente.';
        $tipo_alerta = 'error';
    }
}
// --------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = strtolower(trim($_POST['correo'] ?? ''));
    $contrasena = $_POST['contrasena'] ?? '';
    $carrera = ''; // Campo eliminado del registro; se completa en /completar_perfil
    $ip_actual = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $dominio = substr(strrchr($correo, "@"), 1);

    // 1. Validaciones básicas
    if (!$nombre || !$correo || !$contrasena) {
        $mensaje = 'Por favor, completa todos los campos.';
        $tipo_alerta = 'error';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $mensaje = 'El formato del correo no es válido.';
        $tipo_alerta = 'error';
    } elseif (strlen($contrasena) < 6) {
        $mensaje = 'La contraseña debe tener al menos 6 caracteres.';
        $tipo_alerta = 'error';
    } else {
        // 2. Verificar VIP (excepciones manuales del admin)
        $esExcepcion = false;
        $stmt_exc = $conn->prepare("SELECT id FROM excepciones_email WHERE correo = ? AND activo = 1");
        $stmt_exc->bind_param("s", $correo);
        $stmt_exc->execute();
        if ($stmt_exc->get_result()->num_rows === 1) {
            $esExcepcion = true;
        }
        $stmt_exc->close();

        // 3. Determinar tipo y verificacion_estado (3 ramas)
        $tipo_registro = 'particular';
        $verificacion_registro = 'pendiente';

        if ($esExcepcion) {
            $verificacion_registro = 'aprobado'; // Rama 2: VIP — admin ya confió en este correo
        } else {
            $stmt_dom = $conn->prepare("SELECT institucion FROM dominios_permitidos WHERE dominio = ?");
            $stmt_dom->bind_param("s", $dominio);
            $stmt_dom->execute();
            if ($stmt_dom->get_result()->num_rows === 1) {
                $tipo_registro = 'estudiante';   // Rama 1: dominio institucional
                $verificacion_registro = 'aprobado';
            }
            // else: queda 'particular' + 'pendiente' (Rama 3: no-VIP, no institucional)
            $stmt_dom->close();
        }

        // 4. Registro final
        if (empty($mensaje)) {
           // Buscamos el correo incluyendo soft-deleted para distinguir 3 escenarios
$stmt = $conn->prepare("SELECT id, visible, bloqueado FROM alumnos WHERE correo = ?");
$stmt->bind_param("s", $correo);
$stmt->execute();
$res_existente = $stmt->get_result();
$usuario_existente = $res_existente->fetch_assoc();
$stmt->close();

if ($usuario_existente && (int)$usuario_existente['visible'] === 1) {
    // Caso 1: Cuenta activa real → bloqueamos registro
    $mensaje = 'Este correo ya se encuentra registrado.';
    $tipo_alerta = 'error';
} else {
    $hash = password_hash($contrasena, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));
    $ok_db = false;

    if ($usuario_existente && (int)$usuario_existente['visible'] === 0) {
        // Caso 2: Cuenta soft-deleted → REACTIVAR con los nuevos datos
        $stmt_react = $conn->prepare("UPDATE alumnos
            SET nombre = ?, password = ?, carrera = ?, dominio = ?, token = ?,
                confirmado = 0, visible = 1, bloqueado = 0, ultimo_reenvio = NULL,
                tipo = ?, verificacion_estado = ?
            WHERE id = ?");
        $stmt_react->bind_param("sssssssi", $nombre, $hash, $carrera, $dominio, $token, $tipo_registro, $verificacion_registro, $usuario_existente['id']);
        $ok_db = $stmt_react->execute();
        $stmt_react->close();
    } else {
        // Caso 3: Correo nuevo → INSERT normal
        $stmt_insert = $conn->prepare("INSERT INTO alumnos (nombre, correo, password, carrera, dominio, confirmado, token, tipo, verificacion_estado) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)");
        $stmt_insert->bind_param("ssssssss", $nombre, $correo, $hash, $carrera, $dominio, $token, $tipo_registro, $verificacion_registro);
        $ok_db = $stmt_insert->execute();
        $stmt_insert->close();
    }

   if ($ok_db) {
        $envio_ok = enviarCorreoConfirmacion($correo, $nombre, $token);
        // Guardamos el contexto en sesión para registro_exito.php
        $_SESSION['registro_pendiente'] = [
            'correo' => $correo,
            'nombre' => $nombre,
            'envio_ok' => $envio_ok,
            'timestamp' => time()
        ];
  header("Location: /app/registro_exito.php");
        exit;
    } else {
        $mensaje = 'Ocurrió un error técnico. Inténtalo más tarde.';
        $tipo_alerta = 'error';
    }
}
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Crear Cuenta | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
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
           <h2 class="text-4xl font-bold mb-4 tracking-tight leading-tight">Únete a Nubira.</h2>
           <p class="text-lg opacity-90 font-medium">Compra clases, vende apuntes y conecta con tutores universitarios.</p>
       </div>
  </div>

  <div class="w-full md:w-1/2 bg-white min-h-screen overflow-y-auto">
    <div class="w-full max-w-[420px] mx-auto px-6 pt-8 pb-6 md:pt-16 md:pb-12">
        
        <div class="mb-6 text-center md:text-left">
            <a href="/" class="inline-block transition-transform hover:scale-105">
                <img src="/img/logo.webp" alt="Nubira" class="h-9 w-auto">
            </a>
        </div>

        <h1 class="text-3xl font-bold text-gray-900 mb-2 tracking-tight">Crea tu cuenta</h1>
        <p class="text-gray-500 mb-6 text-sm">Crea tu cuenta en segundos</p>

        <?php if ($mensaje): ?>
            <div class="mb-6 p-4 rounded-xl text-sm flex gap-3 items-start border <?= $tipo_alerta === 'error' ? 'bg-red-50 text-red-700 border-red-100' : 'bg-green-50 text-green-700 border-green-100' ?>">
                <i class="fa-solid <?= $tipo_alerta === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?> mt-0.5"></i>
                <span><?= $mensaje ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4" id="registerForm" autocomplete="off">
            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Nombre Completo</label>
                <input type="text" name="nombre" required value="<?= htmlspecialchars($nombre ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all placeholder-gray-400" 
                       placeholder="Ej: Javiera Pérez">
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Correo electrónico</label>
                <input type="email" name="correo" id="inputCorreo" required value="<?= htmlspecialchars($correo ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all placeholder-gray-400"
                       placeholder="tucorreo@ejemplo.com">
                <p class="text-[11px] text-gray-400 mt-2 font-medium flex items-center gap-1">
                    <i class="fa-solid fa-lock text-[10px]"></i> Te enviaremos un correo de confirmación
                </p>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wide mb-1.5">Contraseña</label>
                <input type="password" name="contrasena" required minlength="6"
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none transition-all placeholder-gray-400" 
                       placeholder="••••••••">
            </div>

            <button type="submit" id="btnSubmit"
                    class="w-full bg-[#54A6D8] hover:bg-[#4895c3] text-white font-bold py-3.5 rounded-2xl shadow-md hover:shadow-lg hover:scale-[1.01] active:scale-[0.98] transition-all duration-200 flex justify-center items-center gap-2 mt-3">
                <span>Crear cuenta</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </form>

        <p class="mt-5 text-center text-sm text-gray-500 font-medium">
            ¿Ya tienes cuenta? <a href="/login" class="text-[#54A6D8] font-bold hover:underline">Inicia sesión</a>
        </p>
    </div>
  </div>

  
 <script>
    const registerForm = document.getElementById('registerForm');
    const btnSubmit = document.getElementById('btnSubmit');

    registerForm.addEventListener('submit', function() {
        // Cambiamos el texto y el ícono
        btnSubmit.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Preparando tu cuenta...</span>';
        // Deshabilitamos el botón para evitar doble clic
        btnSubmit.classList.add('opacity-75', 'cursor-not-allowed');
        btnSubmit.disabled = true;
    });
 </script>

</body>
</html>