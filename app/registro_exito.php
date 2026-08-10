<?php
session_start();

// [NUBIRA 2.0] redir: si no hay sesión (recarga tardía de la página), cae al valor
// que venga en la URL; con sesión, usa el que quedó guardado desde register.php.
$redir = $_GET['redir'] ?? ($_SESSION['registro_pendiente']['redir'] ?? '');
if (!empty($redir) && (strpos($redir, '/') !== 0 || strpos($redir, '//') === 0)) {
    $redir = '';
}
$login_url = '/login' . (!empty($redir) ? '?redir=' . urlencode($redir) : '');

// Si no hay registro pendiente en sesión, redirigir a login
if (!isset($_SESSION['registro_pendiente'])) {
    header("Location: " . $login_url);
    exit;
}

$datos = $_SESSION['registro_pendiente'];
$correo = $datos['correo'];
$nombre = $datos['nombre'];
$envio_ok = $datos['envio_ok'];
$timestamp = $datos['timestamp'];

// Calcular tiempo transcurrido para el cooldown del reenvío (60s)
$segundos_transcurridos = time() - $timestamp;
$cooldown_inicial = max(0, 60 - $segundos_transcurridos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Confirma tu correo | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade-in { animation: fadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes pulseRing {
        0% { transform: scale(0.95); opacity: 1; }
        100% { transform: scale(1.4); opacity: 0; }
    }
    .pulse-ring::before {
        content: ''; position: absolute; inset: 0; border-radius: 9999px;
        background-color: #54A6D8; opacity: 0.3;
        animation: pulseRing 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
  </style>
</head>

<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 antialiased text-gray-800">

  <div class="bg-white p-8 md:p-10 rounded-[2rem] shadow-xl w-full max-w-md text-center border border-gray-100 animate-fade-in">
    
    <div class="mb-6">
        <img src="/img/logo.webp" alt="Nubira" class="h-9 w-auto mx-auto opacity-80">
    </div>

    <div class="relative w-20 h-20 mx-auto mb-6">
        <div class="pulse-ring relative w-20 h-20 rounded-full bg-sky-50 flex items-center justify-center">
            <i class="fa-solid fa-envelope-open-text text-[#54A6D8] text-3xl relative z-10"></i>
        </div>
    </div>

    <h1 class="text-2xl font-bold text-gray-900 mb-3 tracking-tight">Revisa tu correo</h1>
    
    <p class="text-gray-500 text-sm leading-relaxed mb-2">
        Enviamos un enlace de activación a:
    </p>
    <p class="text-gray-900 font-bold text-sm mb-6 break-all">
        <?= htmlspecialchars($correo) ?>
    </p>

    <div class="bg-amber-50 border border-amber-100 rounded-xl p-4 mb-6 text-left">
        <div class="flex gap-3 items-start">
            <i class="fa-solid fa-circle-info text-amber-500 mt-0.5"></i>
            <div class="text-xs text-amber-800 leading-relaxed">
                <strong>Puede tardar hasta 5 minutos.</strong> Si no lo ves, revisa tu carpeta de <strong>Spam</strong> o <strong>Promociones</strong>.
            </div>
        </div>
    </div>

    <div id="status-confirmacion" class="hidden mb-4 bg-emerald-50 border border-emerald-100 rounded-xl p-4 text-left">
        <div class="flex gap-3 items-start">
            <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5"></i>
            <div class="text-xs text-emerald-800 leading-relaxed">
                <strong>¡Cuenta activada!</strong> Te estamos redirigiendo al inicio de sesión...
            </div>
        </div>
    </div>

    <button id="btn-reenviar" type="button" 
            class="w-full bg-[#54A6D8] hover:bg-[#4592c0] text-white font-bold py-3.5 rounded-2xl shadow-md hover:shadow-lg hover:scale-[1.01] active:scale-[0.98] transition-all flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100">
        <i class="fa-solid fa-paper-plane text-xs"></i>
        <span id="btn-reenviar-texto">Reenviar correo</span>
    </button>

    <div class="mt-6 pt-6 border-t border-gray-100 flex flex-col gap-2 text-sm">
        <a href="<?= htmlspecialchars($login_url, ENT_QUOTES, 'UTF-8') ?>" class="text-[#54A6D8] font-bold hover:underline">
            Ya confirmé mi cuenta · Ir al login
        </a>
        <a href="/register" class="text-gray-400 font-medium hover:text-gray-600 transition text-xs">
            ¿Correo equivocado? Empezar de nuevo
        </a>
    </div>
  </div>

  <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 bg-slate-800 text-white px-5 py-3 rounded-xl flex items-center gap-3 opacity-0 translate-y-4 transition-all duration-300 pointer-events-none z-50">
    <i id="toast-icon" class="fa-solid fa-circle-check text-emerald-400"></i>
    <span id="toast-text" class="text-sm font-bold tracking-wide"></span>
  </div>

  <script>
    const correo = <?= json_encode($correo) ?>;
    const loginUrl = <?= json_encode($login_url) ?>;
    const btnReenviar = document.getElementById('btn-reenviar');
    const btnTexto = document.getElementById('btn-reenviar-texto');
    const statusConfirmacion = document.getElementById('status-confirmacion');
    let cooldown = <?= $cooldown_inicial ?>;
    let cooldownInterval = null;

    // Toast helper
    function showToast(mensaje, tipo = 'success') {
        const toast = document.getElementById('toast');
        const toastIcon = document.getElementById('toast-icon');
        const toastText = document.getElementById('toast-text');
        toastText.textContent = mensaje;
        toastIcon.className = tipo === 'success' 
            ? 'fa-solid fa-circle-check text-emerald-400' 
            : 'fa-solid fa-circle-exclamation text-red-400';
        toast.classList.remove('opacity-0', 'translate-y-4');
        setTimeout(() => toast.classList.add('opacity-0', 'translate-y-4'), 3000);
    }

    // Cooldown del botón
    function iniciarCooldown(segundos) {
        cooldown = segundos;
        btnReenviar.disabled = true;
        if (cooldownInterval) clearInterval(cooldownInterval);
        cooldownInterval = setInterval(() => {
            if (cooldown <= 0) {
                clearInterval(cooldownInterval);
                btnReenviar.disabled = false;
                btnTexto.textContent = 'Reenviar correo';
            } else {
                btnTexto.textContent = `Reenviar en ${cooldown}s`;
                cooldown--;
            }
        }, 1000);
    }

    // Reenviar
    btnReenviar.addEventListener('click', async () => {
        btnReenviar.disabled = true;
        btnTexto.textContent = 'Enviando...';
        try {
            const res = await fetch('/app/reenviar_correo_registro.php', { method: 'POST' });
            const data = await res.json();
            if (data.ok) {
                showToast('Correo reenviado correctamente');
                iniciarCooldown(60);
            } else {
                showToast(data.error || 'Error al reenviar', 'error');
                btnReenviar.disabled = false;
                btnTexto.textContent = 'Reenviar correo';
            }
        } catch (e) {
            showToast('Error de conexión', 'error');
            btnReenviar.disabled = false;
            btnTexto.textContent = 'Reenviar correo';
        }
    });

    // Auto-check de confirmación cada 8 segundos
    async function checkConfirmacion() {
        try {
            const res = await fetch('/app/check_confirmacion.php');
            const data = await res.json();
            if (data.confirmado) {
                statusConfirmacion.classList.remove('hidden');
                setTimeout(() => window.location.href = loginUrl, 2500);
                return true;
            }
        } catch (e) {}
        return false;
    }

    setInterval(checkConfirmacion, 8000);

    // Iniciar cooldown si corresponde
    if (cooldown > 0) iniciarCooldown(cooldown);
  </script>

</body>
</html>
