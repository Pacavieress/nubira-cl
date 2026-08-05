<?php
/**
 * VISTA: EDITAR DATOS BANCARIOS
 * UBICACIÓN: public_html/app/editar_datos_bancarios.php
 * ESTADO: Nubira 2.0 - App Nativa (Flat Design, Inputs Limpios, Sin Sombras)
 */
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login');
    exit;
}

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = $app_dir . '/app';
    elseif (file_exists(dirname($app_dir) . '/app')) $app_dir = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$usuario_id = (int)$_SESSION['usuario_id'];
$errores = [];

// CSRF: Generar token si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$sql = "SELECT * FROM datos_pago_usuario WHERE usuario_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$datos = $stmt->get_result()->fetch_assoc();
$stmt->close();

$bancos_query = $conn->query("SELECT nombre FROM bancos ORDER BY nombre ASC");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errores[] = "Tu sesión expiró o la solicitud no es válida. Vuelve a intentarlo.";
    } else {
        $banco   = trim($_POST['banco'] ?? '');
        $tipo    = trim($_POST['tipo_cuenta'] ?? '');
        $cuenta  = trim($_POST['numero_cuenta'] ?? '');
        $titular = trim($_POST['titular_nombre'] ?? '');
        $rut     = trim($_POST['rut'] ?? '');

        if (!$banco || !$tipo || !$cuenta || !$titular || !$rut) {
            $errores[] = "Todos los campos son obligatorios.";
        }
        if (!ctype_digit($cuenta)) {
            $errores[] = "El número de cuenta debe contener solo números.";
        }

        $rut_limpio = str_replace('.', '', $rut);

        if (!preg_match("/^\d{7,8}-[\dkK]$/", $rut_limpio)) {
            $errores[] = "El RUT debe tener el formato correcto (ejemplo: 12345678-9).";
        }

        if (empty($errores)) {
            if ($datos) {
                $sql = "UPDATE datos_pago_usuario SET banco=?, tipo_cuenta=?, numero_cuenta=?, titular_nombre=?, rut=? WHERE usuario_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssi", $banco, $tipo, $cuenta, $titular, $rut_limpio, $usuario_id);
            } else {
                $sql = "INSERT INTO datos_pago_usuario (usuario_id, banco, tipo_cuenta, numero_cuenta, titular_nombre, rut) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssss", $usuario_id, $banco, $tipo, $cuenta, $titular, $rut_limpio);
            }
            $stmt->execute();

            header("Location: /datos_bancarios");
            exit;
        } else {
            $datos = [
                'banco' => $banco,
                'tipo_cuenta' => $tipo,
                'numero_cuenta' => $cuenta,
                'titular_nombre' => $titular,
                'rut' => $rut
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurar Cuenta | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent; }
        .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
        .animate-fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* Eliminar estilos por defecto de select en webkit */
        select { -webkit-appearance: none; -moz-appearance: none; appearance: none; }
    </style>
</head>
<body class="text-gray-800 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-gray-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
// [NUBIRA 2.0] Ocultar header global en móvil — mismo patrón que las demás páginas de gestión
echo '<div class="hidden md:block">';
require_once $app_dir . '/componentes/header.php';
echo '</div>';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-4 md:pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8 w-full animate-fade-in-up">
  <div class="w-full max-w-[800px] mx-auto">

    <div class="sticky top-0 md:top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-[#f0f0f0] py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-3">
            <button type="button" onclick="navegacionSeguraNubira()"
                    class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
                    aria-label="Volver">
                <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
            </button>
            <div>
                <h1 class="text-xl md:text-2xl font-medium tracking-[-0.01em] text-[#222222]">Datos Bancarios</h1>
                <p class="text-gray-400 text-xs font-medium mt-0.5">Configura dónde recibirás tus ganancias.</p>
            </div>
        </div>
    </div>

    <?php if (!empty($errores)): ?>
        <div class="bg-red-50 border border-red-100 rounded-2xl p-5 mb-6">
            <div class="flex items-center gap-2 mb-2">
                <i class="fa-solid fa-triangle-exclamation text-red-500"></i>
                <h3 class="font-bold text-red-800 text-sm">Hay problemas con tu solicitud:</h3>
            </div>
            <ul class="space-y-1 pl-6 text-sm text-red-600 font-medium list-disc">
                <?php foreach ($errores as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-[#f0f0f0] rounded-3xl p-6 md:p-8">
        <form method="POST" class="space-y-6" id="banco-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 md:gap-6">
                
                <div class="space-y-2">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Institución Bancaria</label>
                    <div class="relative">
                        <select name="banco" required class="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors cursor-pointer outline-none">
                            <option value="" disabled <?= empty($datos['banco']) ? 'selected' : '' ?>>Selecciona tu banco...</option>
                            <?php
                                if($bancos_query) {
                                    $bancos_query->data_seek(0);
                                    while ($b = $bancos_query->fetch_assoc()):
                                        $selected = ($datos['banco'] ?? '') === $b['nombre'] ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($b['nombre']) ?>" <?= $selected ?>><?= htmlspecialchars($b['nombre']) ?></option>
                            <?php 
                                    endwhile;
                                } 
                            ?>
                        </select>
                        <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Tipo de Cuenta</label>
                    <div class="relative">
                        <select name="tipo_cuenta" required class="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors cursor-pointer outline-none">
                            <?php
                                $tipos = ['Cuenta Rut', 'Cuenta Corriente', 'Cuenta Vista', 'Cuenta de Ahorro'];
                                foreach ($tipos as $tipo):
                                    $selected = ($datos['tipo_cuenta'] ?? '') === $tipo ? 'selected' : '';
                                    echo "<option value=\"$tipo\" $selected>$tipo</option>";
                                endforeach;
                            ?>
                        </select>
                        <i class="fa-solid fa-chevron-down absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Número de Cuenta</label>
                    <input type="text" name="numero_cuenta" value="<?= htmlspecialchars($datos['numero_cuenta'] ?? '') ?>" required
                           placeholder="Ej: 123456789"
                           class="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors placeholder-gray-300 outline-none"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    <p class="text-[10px] font-medium text-gray-400 pl-1 mt-1">Solo números, sin guiones.</p>
                </div>

                <div class="space-y-2">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">Nombre del Titular</label>
                    <input type="text" name="titular_nombre" value="<?= htmlspecialchars($datos['titular_nombre'] ?? '') ?>" required
                           placeholder="Juan Pérez González"
                           class="w-full border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors placeholder-gray-300 outline-none">
                </div>

                <div class="space-y-2 md:col-span-2">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest pl-1">RUT del Titular</label>
                    <input type="text" name="rut" value="<?= htmlspecialchars($datos['rut'] ?? '') ?>" required
                           placeholder="Ej: 12345678-9"
                           class="w-full md:w-1/2 border border-gray-200 bg-gray-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-gray-800 font-medium transition-colors placeholder-gray-300 uppercase outline-none"
                           oninput="this.value=this.value.replace(/[^0-9kK-]/g,'')">
                    <p class="text-[10px] font-medium text-gray-400 pl-1 mt-1">Debe coincidir exactamente con el titular del banco.</p>
                </div>

            </div>

            <div class="pt-6 mt-8 border-t border-[#f0f0f0] flex items-center justify-between">
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest flex items-center gap-1.5 hidden sm:flex">
                    <i class="fa-solid fa-shield-halved"></i> Conexión Segura
                </span>
                <button type="submit" class="w-full sm:w-auto bg-[#54A6D8] text-white active:bg-blue-600 md:hover:bg-[#4392c3] font-bold py-3.5 px-8 rounded-2xl active:scale-[0.98] transition-all flex items-center justify-center gap-2 text-sm shadow-none border border-transparent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar Cambios
                </button>
            </div>
        </form>
    </div>
  </div>
</main>

<?php 
// INYECCIÓN MODULAR OFICIAL DE NUBIRA 2.0
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
    // 1. UI Loader
    window.onload = () => {
        const l = document.getElementById('loader');
        if(l) { l.style.opacity='0'; setTimeout(()=>l.style.display='none', 300); }
    };

    // [NUBIRA 2.0] Volver — mismo patrón que las demás páginas de gestión, con fallback
    // a /datos_bancarios (único origen real de esta página).
    window.navegacionSeguraNubira = function() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '/datos_bancarios';
        }
    };

    // Formateo RUT
    document.querySelector('input[name="rut"]').addEventListener('blur', function(e) {
        let val = this.value.replace(/[^0-9kK]/g, '');
        if (val.length > 1) {
            val = val.slice(0, -1) + '-' + val.slice(-1);
        }
        this.value = val.toUpperCase();
    });

    // 2. MODALES OFICIALES 
    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);

        if(!btn || !modal) return;

        const open = () => { 
            modal.classList.remove('hidden'); 
            requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
            document.body.style.overflow = 'hidden'; 
        };

        const shut = () => { 
            card.classList.add('translate-y-full', 'opacity-0'); 
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
        };

        btn.onclick = (e) => { e.preventDefault(); open(); }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    // 3. Inicialización de Modales
    document.addEventListener('DOMContentLoaded', () => {
        setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
        setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
    });
</script>
</body>
</html>
