<?php
/**
 * VISTA: EDITAR PERFIL
 * UBICACIÓN: public_html/app/editar_datos.php
 */
session_start();

// 1. SEGURIDAD Y RUTAS
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';

// Header Vars
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));
$nombres_inst = ['uc'=>'UC','aiep'=>'AIEP','uss'=>'USS','udp'=>'UDP'];
$nombre_institucion = $nombres_inst[$institucion_session] ?? ucfirst($institucion_session);

// Carrera
$carrera_usuario = $_SESSION['carrera'] ?? '';
if (empty($carrera_usuario)) {
    $stmt = $conn->prepare("SELECT carrera FROM alumnos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $stmt->bind_result($c_db);
    if ($stmt->fetch()) { $carrera_usuario = $c_db; $_SESSION['carrera'] = $c_db; }
    $stmt->close();
}
$display_carrera = $carrera_usuario ?: 'Estudiante';

// Helper Nav
$page_title = "Editar Perfil";
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        $ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/dashboard') return $base . $activo; 
        return $base . $inactivo;
    }
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// Obtener datos actuales
$stmt = $conn->prepare("SELECT nombre, correo, carrera, password FROM alumnos WHERE id = ?");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$stmt->bind_result($nombre_actual, $correo_actual, $carrera_actual, $hash_password);
$stmt->fetch();
$stmt->close();

$mensaje = '';
$exito   = false;

// --- PROCESAMIENTO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
        $mensaje = '❌ Sesión inválida.';
    } 
    // 1. Editar Datos
    elseif (isset($_POST['editar_datos'])) {
        $nuevo_nombre  = trim($_POST['nombre']  ?? '');
        $nueva_carrera = trim($_POST['carrera'] ?? '');
        
        if ($nuevo_nombre === '' || $nueva_carrera === '') {
            $mensaje = '❌ Todos los campos son obligatorios.';
        } else {
            $u = $conn->prepare("UPDATE alumnos SET nombre = ?, carrera = ? WHERE id = ?");
            $u->bind_param("ssi", $nuevo_nombre, $nueva_carrera, $usuario_id);
            if ($u->execute()) {
                $mensaje = '✅ Datos actualizados correctamente.';
                $exito = true;
                $nombre_actual = $nuevo_nombre;
                $carrera_actual = $nueva_carrera;
                $_SESSION['usuario_nombre'] = $nuevo_nombre;
                $_SESSION['carrera'] = $nueva_carrera;
            } else {
                $mensaje = '❌ Error al actualizar.';
            }
            $u->close();
        }
    }
    // 2. Cambiar Password
    elseif (isset($_POST['cambiar_password'])) {
        $actual = $_POST['actual'] ?? '';
        $nueva  = $_POST['nueva']  ?? '';
        $nueva2 = $_POST['nueva2'] ?? '';

        if (!$actual || !$nueva || !$nueva2) {
            $mensaje = '❌ Completa todos los campos.';
        } elseif (!password_verify($actual, $hash_password)) {
            $mensaje = '❌ La contraseña actual es incorrecta.';
        } elseif ($nueva !== $nueva2) {
            $mensaje = '❌ Las contraseñas no coinciden.';
        } elseif (strlen($nueva) < 8) {
            $mensaje = '❌ Mínimo 8 caracteres.';
        } else {
            $nuevo_hash = password_hash($nueva, PASSWORD_DEFAULT);
            $u = $conn->prepare("UPDATE alumnos SET password = ? WHERE id = ?");
            $u->bind_param("si", $nuevo_hash, $usuario_id);
            if ($u->execute()) {
                $mensaje = '✅ Contraseña actualizada.';
                $exito = true;
                $hash_password = $nuevo_hash;
            } else {
                $mensaje = '❌ Error al actualizar.';
            }
            $u->close();
        }
    }
    // 3. Eliminar Cuenta (Actualizado Nubira 2.0: Soft Delete)
    elseif (isset($_POST['eliminar_cuenta'])) {
        $frase  = trim($_POST['frase'] ?? '');
        $acepto = !empty($_POST['acepto_irreversible']);
        $pwd    = $_POST['pwd_confirm'] ?? '';

        if ($frase !== 'ELIMINAR MI CUENTA') {
            $mensaje = '❌ Frase de confirmación incorrecta.';
        } elseif (!$acepto) {
            $mensaje = '❌ Debes aceptar las consecuencias.';
        } elseif (!password_verify($pwd, $hash_password)) {
            $mensaje = '❌ Contraseña incorrecta.';
        } else {
            $nombre_borrado = 'Cuenta eliminada';
            $correo_borrado = 'deleted+' . $usuario_id . '@nubira.cl';
            $carrera_null   = null;
            $hash_random    = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $visible_cero   = 0; // Ocultar del sistema
            $bloqueado_uno  = 1; // Prevenir futuros accesos

            // Actualizamos la consulta para incluir 'visible' y 'bloqueado'
            $u = $conn->prepare("UPDATE alumnos SET nombre = ?, correo = ?, carrera = ?, password = ?, visible = ?, bloqueado = ? WHERE id = ?");
            $u->bind_param("ssssiii", $nombre_borrado, $correo_borrado, $carrera_null, $hash_random, $visible_cero, $bloqueado_uno, $usuario_id);
            
            if ($u->execute()) {
                session_destroy();
                header('Location: /cuenta-eliminada');
                exit;
            } else {
                $mensaje = '❌ Error al eliminar cuenta.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Configurar Cuenta | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    /* NUBIRA FIX: Evitar zoom automático en móviles en los inputs */
    @media screen and (max-width: 768px) {
      input, select, textarea { font-size: 16px !important; }
    }
  </style>
</head>

<body class="bg-gray-50 min-h-screen text-gray-800 font-sans overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-32 md:pb-12 lg:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-3xl mx-auto space-y-8">

    <div class="mb-4">
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Configuración de Cuenta</h1>
        <p class="text-sm text-gray-500 mt-0.5">Gestiona tu información personal y seguridad.</p>
    </div>

    <?php if ($mensaje): ?>
      <div id="toast" class="rounded-xl px-4 py-3 shadow-sm flex items-center gap-3 <?= $exito ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
         <?= icon($exito ? 'check-circle' : 'exclamation', 'w-5 h-5') ?>
         <span class="font-medium text-sm flex-1"><?= htmlspecialchars($mensaje) ?></span>
         <button onclick="document.getElementById('toast').remove()" class="text-sm underline hover:no-underline">Cerrar</button>
      </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
        <h2 class="text-lg font-bold text-gray-900 mb-6 flex items-center gap-2">
            <?= icon('user', 'w-5 h-5 text-[#54A6D8]') ?> Información Básica
        </h2>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="editar_datos" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Correo Institucional</label>
                <input type="email" value="<?= htmlspecialchars($correo_actual) ?>" readonly
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-500 cursor-not-allowed select-none text-sm">
                <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1"><?= icon('lock', 'w-3 h-3') ?> No editable por seguridad.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Nombre Completo</label>
                    <input type="text" name="nombre" value="<?= htmlspecialchars($nombre_actual) ?>" required
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Carrera</label>
                    <input type="text" name="carrera" value="<?= htmlspecialchars($carrera_actual) ?>" required
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-[#54A6D8] focus:border-[#54A6D8] transition outline-none">
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="bg-[#54A6D8] hover:bg-blue-600 text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition transform active:scale-95 text-sm">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 md:p-8">
        <h2 class="text-lg font-bold text-gray-900 mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
            Seguridad
        </h2>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="cambiar_password" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Contraseña Actual</label>
                <input type="password" name="actual" required
                       class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-yellow-400 focus:border-yellow-400 transition outline-none">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Nueva Contraseña</label>
                    <input type="password" name="nueva" required minlength="8"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-yellow-400 focus:border-yellow-400 transition outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Confirmar Nueva</label>
                    <input type="password" name="nueva2" required minlength="8"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-gray-900 text-sm focus:ring-yellow-400 focus:border-yellow-400 transition outline-none">
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="bg-gray-900 hover:bg-black text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition transform active:scale-95 text-sm">
                    Actualizar Contraseña
                </button>
            </div>
        </form>
    </div>

    <div class="bg-red-50 border border-red-100 rounded-2xl shadow-sm p-6 md:p-8">
        <h2 class="text-lg font-bold text-red-700 mb-2 flex items-center gap-2">
            <?= icon('exclamation', 'w-5 h-5') ?> Eliminar Cuenta
        </h2>
        <p class="text-sm text-red-600/80 mb-6 max-w-xl">
            Esta acción es <strong>irreversible</strong>. Eliminaremos tus datos personales, publicaciones y acceso a la plataforma de forma permanente.
        </p>

        <form method="POST" class="space-y-5" id="form-eliminar">
            <input type="hidden" name="eliminar_cuenta" value="1">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES) ?>">

            <div>
                <label class="block text-xs font-bold text-red-800 mb-1.5 uppercase tracking-wide">
                    Escribe la frase: <span class="bg-white px-1.5 py-0.5 rounded border border-red-200 font-mono select-all">ELIMINAR MI CUENTA</span>
                </label>
                <input type="text" name="frase" required placeholder="ELIMINAR MI CUENTA"
                       class="w-full bg-white border border-red-200 rounded-xl px-4 py-2.5 text-red-900 text-sm focus:ring-red-400 focus:border-red-400 transition outline-none placeholder-red-300">
            </div>

            <div>
                <label class="block text-xs font-bold text-red-800 mb-1.5 uppercase tracking-wide">Contraseña Actual</label>
                <input type="password" name="pwd_confirm" required
                       class="w-full bg-white border border-red-200 rounded-xl px-4 py-2.5 text-red-900 text-sm focus:ring-red-400 focus:border-red-400 transition outline-none">
            </div>

            <label class="flex items-start gap-3 text-sm text-red-700 cursor-pointer select-none">
                <input type="checkbox" name="acepto_irreversible" id="chk-irrev" class="mt-0.5 w-4 h-4 text-red-600 border-red-300 rounded focus:ring-red-500">
                <span>Entiendo y acepto que esta acción no se puede deshacer.</span>
            </label>

            <div class="flex justify-end pt-2">
                <button type="submit" id="btn-eliminar" disabled
                        class="bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-sm transition opacity-50 cursor-not-allowed text-sm">
                    Eliminar Definitivamente
                </button>
            </div>
        </form>
    </div>

  </div>
</main>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

function setupModal(triggerId, modalId, cardId, closeId) {
    const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
    if(!btn||!modal) return;
    const open=()=>{modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-full','opacity-0')); document.body.style.overflow='hidden';};
    const shut=()=>{card.classList.add('translate-y-full','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300);};
    btn.onclick=(e)=>{e.preventDefault();open();}; 
    if(close) close.onclick=shut; 
    modal.onclick=(e)=>{if(e.target===modal)shut();};
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

// Activar botón eliminar
document.getElementById('chk-irrev')?.addEventListener('change', function() {
    const btn = document.getElementById('btn-eliminar');
    if(this.checked) {
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
    } else {
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
    }
});

function abrirMisChats() { window.open("/app/mis_chats.php", "mis_chats", "width=440,height=640,resizable=yes,scrollbars=yes"); }
</script>

</body>
</html>