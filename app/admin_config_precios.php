<?php
/**
 * VISTA: ADMIN CONFIGURACIÓN DE PRECIOS
 * ESTADO: Nubira 2.0 (App Nativa, Flat Design, Modulares Correctos)
 */
session_start();

// 1. SEGURIDAD Y RUTAS
$app_dir = dirname(__DIR__) . '/app'; 
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = __DIR__;

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// Solo admins
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: /login');
    exit;
}

$page_title = "Configuración Global";

/* ---------------- CSRF simple ---------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

$mensajes = [];

// 2. ACTUALIZAR PRECIO / OFERTA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF token inválido");
    }

    // Editar precio normal
    if (isset($_POST['precio'])) {
        $nuevoPrecio = (int)($_POST['precio'] ?? 0);
        if ($nuevoPrecio < 100) {
            $mensajes[] = ['tipo' => 'error', 'texto' => 'El precio base debe ser al menos $100 CLP.'];
        } elseif ($nuevoPrecio > 99999) {
            $mensajes[] = ['tipo' => 'error', 'texto' => 'El precio no puede superar los $99.999 CLP.'];
        } else {
            $stmt = $conn->prepare("UPDATE config SET valor = ? WHERE clave = 'precio_desbloqueo_contacto'");
            $stmt->bind_param('s', $nuevoPrecio);
            $stmt->execute();
            $stmt->close();
            $mensajes[] = ['tipo' => 'ok', 'texto' => 'Precio base actualizado correctamente.'];
        }
    }

    // Editar oferta gratis hasta (permite vacío para desactivar)
    if (isset($_POST['oferta_gratis_hasta'])) {
        $ofertaGratisHasta = trim($_POST['oferta_gratis_hasta']);
        if ($ofertaGratisHasta !== '') {
            // Formatea a "Y-m-d H:i:s" desde datetime-local (formato HTML5)
            $fechaFormateada = str_replace('T', ' ', $ofertaGratisHasta) . ':00';
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fechaFormateada);
            if ($dt && $dt->getTimestamp() > time()) {
                $stmt = $conn->prepare("UPDATE config SET valor = ? WHERE clave = 'oferta_gratis_hasta'");
                $stmt->bind_param('s', $fechaFormateada);
                $stmt->execute();
                $stmt->close();
                $mensajes[] = ['tipo' => 'ok', 'texto' => 'Promoción "Costo Cero" activada hasta ' . $dt->format('d/m/Y H:i')];
            } else {
                $mensajes[] = ['tipo' => 'error', 'texto' => 'La fecha de la oferta debe ser futura y válida.'];
            }
        } else {
            // Si está vacío, desactiva la oferta gratis
            $stmt = $conn->prepare("UPDATE config SET valor = '' WHERE clave = 'oferta_gratis_hasta'");
            $stmt->execute();
            $stmt->close();
            $mensajes[] = ['tipo' => 'ok', 'texto' => 'Promoción "Costo Cero" desactivada.'];
        }
    }
}

// 3. CONSULTAR VALORES ACTUALES
$precioActual = '';
$ofertaGratisHasta = '';

$result = $conn->query("SELECT clave, valor FROM config WHERE clave IN ('precio_desbloqueo_contacto','oferta_gratis_hasta')");
while ($row = $result->fetch_assoc()) {
    if ($row['clave'] === 'precio_desbloqueo_contacto') $precioActual = $row['valor'];
    if ($row['clave'] === 'oferta_gratis_hasta') $ofertaGratisHasta = $row['valor'];
}
$result->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title><?= $page_title ?> | Nubira Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; -webkit-tap-highlight-color: transparent;}
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .force-no-shadow * { text-shadow: none !important; }
    /* Estilizar campos date nativos */
    input[type="datetime-local"]::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.6; transition: opacity 0.2s; }
    input[type="datetime-local"]::-webkit-calendar-picker-indicator:hover { opacity: 1; }
  </style>
</head>
<body class="text-slate-800 antialiased overflow-x-hidden force-no-shadow bg-white">

<div id="loader" class="fixed inset-0 bg-white flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-8 w-8 border-4 border-slate-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
// INTEGRACIÓN DE MÓDULOS OFICIALES
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-3xl mx-auto space-y-6">

    <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-slate-100 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 tracking-tight">Finanzas y Pricing</h1>
            <p class="text-slate-400 text-xs font-medium mt-0.5">Ajusta los valores de monetización global.</p>
        </div>
    </div>

    <?php if (!empty($mensajes)): ?>
        <div class="space-y-3 mb-6">
            <?php foreach ($mensajes as $msg): 
                $bg_color = $msg['tipo'] === 'ok' ? 'bg-emerald-50 border-emerald-100 text-emerald-700' : 'bg-red-50 border-red-100 text-red-700';
                $icon = $msg['tipo'] === 'ok' ? 'check-circle' : 'triangle-exclamation';
            ?>
                <div class="<?= $bg_color ?> px-5 py-4 rounded-2xl border flex items-center gap-3">
                    <i class="fa-solid fa-<?= $icon ?>"></i>
                    <span class="font-bold text-sm tracking-wide"><?= htmlspecialchars($msg['texto']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-slate-100 rounded-3xl p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6 border-b border-slate-50 pb-4">
            <div class="w-10 h-10 rounded-full bg-blue-50 text-[#54A6D8] flex items-center justify-center shrink-0">
                <i class="fa-solid fa-coins text-lg"></i>
            </div>
            <div>
                <h2 class="text-base font-bold text-slate-900">Tarifa de Desbloqueo</h2>
                <p class="text-xs text-slate-400 font-medium">Costo estándar por abrir una conversación de servicio.</p>
            </div>
        </div>

        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
            
            <div class="space-y-2">
                <label for="precio" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Precio Base ($ CLP)</label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-slate-400">$</span>
                    <input type="number" min="100" max="99999" name="precio" id="precio"
                           value="<?= htmlspecialchars((int)$precioActual) ?>" required
                           class="w-full border border-slate-200 bg-slate-50 pl-8 pr-4 py-3.5 rounded-2xl focus:ring-0 focus:border-[#54A6D8] focus:bg-white text-slate-800 font-black tracking-tight transition-colors outline-none text-base" />
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full sm:w-auto bg-[#54A6D8] active:bg-blue-600 text-white font-bold py-3.5 px-8 rounded-2xl transition-colors text-sm shadow-none border border-transparent flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar Tarifa
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white border border-slate-100 rounded-3xl p-6 md:p-8 mt-6">
        <div class="flex items-center gap-3 mb-6 border-b border-slate-50 pb-4">
            <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-gift text-lg"></i>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-base font-bold text-slate-900">Promoción "Costo Cero"</h2>
                <p class="text-xs text-slate-400 font-medium truncate">Todos los servicios gratuitos hasta la fecha fijada.</p>
            </div>
            
            <?php if ($ofertaGratisHasta && date('Y-m-d H:i:s') <= $ofertaGratisHasta): ?>
                <span class="shrink-0 bg-emerald-500 text-white px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest animate-pulse shadow-sm">Activa</span>
            <?php elseif ($ofertaGratisHasta): ?>
                <span class="shrink-0 bg-slate-100 text-slate-400 px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-widest">Inactiva</span>
            <?php endif; ?>
        </div>

        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">
            
            <div class="space-y-2">
                <label for="oferta_gratis_hasta" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest pl-1">Fecha de Término de Promoción</label>
                <input type="datetime-local" name="oferta_gratis_hasta" id="oferta_gratis_hasta"
                       value="<?= $ofertaGratisHasta ? htmlspecialchars(str_replace(' ', 'T', substr($ofertaGratisHasta, 0, 16))) : '' ?>"
                       class="w-full border border-slate-200 bg-slate-50 px-4 py-3.5 rounded-2xl focus:ring-0 focus:border-emerald-400 focus:bg-white text-slate-800 font-medium transition-colors outline-none text-sm" />
                
                <?php if ($ofertaGratisHasta && date('Y-m-d H:i:s') <= $ofertaGratisHasta): ?>
                    <p class="text-[10px] font-bold text-emerald-600 pl-1 mt-1"><i class="fa-solid fa-clock mr-1"></i> Termina el <?= date('d/m/Y a las H:i', strtotime($ofertaGratisHasta)) ?> hrs.</p>
                <?php elseif ($ofertaGratisHasta): ?>
                    <p class="text-[10px] font-medium text-slate-400 pl-1 mt-1">La oferta finalizó el <?= date('d/m/Y', strtotime($ofertaGratisHasta)) ?>.</p>
                <?php else: ?>
                    <p class="text-[10px] font-medium text-slate-400 pl-1 mt-1">Deja el campo vacío y guarda para desactivar la promoción.</p>
                <?php endif; ?>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full sm:w-auto bg-slate-800 active:bg-slate-900 text-white font-bold py-3.5 px-8 rounded-2xl transition-colors text-sm shadow-none border border-transparent flex items-center justify-center gap-2">
                    <i class="fa-solid fa-bolt"></i> Actualizar Promoción
                </button>
            </div>
        </form>
    </div>

    <div class="text-[10px] font-bold text-slate-300 uppercase tracking-widest text-center mt-8 pb-4">
        <i class="fa-solid fa-circle-info mr-1"></i> Estos valores aplican globalmente a Nubira
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
window.onload = () => { 
    const l = document.getElementById('loader'); 
    if(l) { l.style.opacity='0'; setTimeout(()=>l.style.display='none',300); } 
};

// --- LÓGICA DE MODALES NUBIRA 2.0 ---
document.addEventListener('DOMContentLoaded', () => {
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

    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});
</script>

</body>
</html>