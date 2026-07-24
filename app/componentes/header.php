<?php
// app/componentes/header.php

// 1. INICIALIZACIÓN
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }

// [NUBIRA 2.0] Captura de búsqueda con sanitización reforzada
if (isset($_GET['q'])) {
    $termino_raw = trim($_GET['q']);
    if (strlen($termino_raw) > 2 && strlen($termino_raw) <= 100) {
        $termino = strip_tags($termino_raw);
        $termino = preg_replace('/[^\p{L}\p{N}\s\-\.\,]/u', '', $termino);
        $termino = trim(preg_replace('/\s+/', ' ', $termino));
        if (!empty($termino)) {
            $_SESSION['ultimo_termino_buscado'] = $termino;
        }
    }
}

$usuario_id = $_SESSION['usuario_id'] ?? null;
$es_visitante = ($usuario_id === null);
$current_url = urlencode($_SERVER['REQUEST_URI'] ?? '/vitrina');

if (!isset($conn)) {
    $dirs = [__DIR__, dirname(__DIR__)];
    foreach ($dirs as $d) { if (file_exists($d . '/conexion.php')) { require_once $d . '/conexion.php'; break; } }
}

$perfil_incompleto = false;
$foto_sesion = '';
$alerta_encendida_php = false; 

// 2. LÓGICA DE USUARIO
if (!$es_visitante && isset($conn)) {
    $uid_header = (int)$usuario_id;
    
    // Actualizamos ultima_sesion cada 5 min (Fail-Safe)
    $ts_ahora = time();
    $ts_ultimo = $_SESSION['ts_ultima_sesion_actualizada'] ?? 0;

    if (($ts_ahora - $ts_ultimo) > 300) { 
        try {
            $stmt_ult = $conn->prepare("UPDATE alumnos SET ultima_sesion = NOW() WHERE id = ?");
            if ($stmt_ult) {
                $stmt_ult->bind_param("i", $uid_header);
                $stmt_ult->execute();
                $stmt_ult->close();
                $_SESSION['ts_ultima_sesion_actualizada'] = $ts_ahora;
            }
        } catch (Throwable $e) {} 
    }

    $foto_sesion = $_SESSION['foto_perfil'] ?? '';

    // [NUBIRA 2.0] Cache de sesión compartida con nav_bottom.php (mismo cache_key y TTL de 5 min):
    // evita re-consultar alumnos/datos_pago_usuario en cada request. Si header.php corre primero
    // (caso normal), deja la caché ya poblada para que nav_bottom.php/sidebar.php la reutilicen.
    $cache_key = 'nav_cache_' . $uid_header;
    $cache_ttl = 300; // 5 minutos
    $ahora = time();

    $cache_invalido = (
        empty($_SESSION[$cache_key])
        || ($ahora - ($_SESSION[$cache_key]['ts'] ?? 0)) > $cache_ttl
        || !empty($_SESSION['nav_cache_invalidar'])
    );

    if ($cache_invalido) {
        $alert_calc = false;
        $foto_calc  = '';
        $mtime_foto = 0;

        try {
            $stmt_h = $conn->prepare("SELECT foto_perfil, bio FROM alumnos WHERE id = ? LIMIT 1");
            if ($stmt_h) {
                $stmt_h->bind_param("i", $uid_header);
                $stmt_h->execute();
                $stmt_h->bind_result($foto_db, $bio_db);
                if ($stmt_h->fetch()) {
                    $_SESSION['foto_perfil'] = $foto_db;
                    $foto_sesion = $foto_db;
                    if (empty($foto_db) || empty(trim((string)$bio_db))) { $alert_calc = true; }
                    if (!empty($foto_db)) {
                        $foto_calc = "/app/perfil/fotos/" . $foto_db;
                        $ruta_fis_foto = $_SERVER['DOCUMENT_ROOT'] . $foto_calc;
                        if (file_exists($ruta_fis_foto)) {
                            $mtime_foto = filemtime($ruta_fis_foto);
                        }
                    }
                }
                $stmt_h->close();
            }

            if (!$alert_calc) {
                $stmt_banco = $conn->prepare("SELECT banco, numero_cuenta FROM datos_pago_usuario WHERE usuario_id = ? LIMIT 1");
                if ($stmt_banco) {
                    $stmt_banco->bind_param("i", $uid_header);
                    $stmt_banco->execute();
                    $stmt_banco->store_result();
                    if ($stmt_banco->num_rows > 0) {
                        $stmt_banco->bind_result($banco_db, $cuenta_db);
                        $stmt_banco->fetch();
                        if (empty(trim((string)$banco_db)) || empty(trim((string)$cuenta_db))) {
                            $alert_calc = true;
                        }
                    } else {
                        $alert_calc = true;
                    }
                    $stmt_banco->close();
                }
            }
        } catch (Throwable $e) {}

        $_SESSION[$cache_key] = [
            'ts'         => $ahora,
            'alerta'     => $alert_calc,
            'foto_path'  => $foto_calc,
            'foto_mtime' => $mtime_foto,
        ];
        unset($_SESSION['nav_cache_invalidar']);
    }

    $perfil_incompleto    = $_SESSION[$cache_key]['alerta'];
    $alerta_encendida_php = $perfil_incompleto;
}

$titulo_seccion = $page_title ?? 'Vitrina';
$n_sesion = explode(' ', trim($_SESSION['usuario_nombre'] ?? 'U'));
$iniciales = mb_strtoupper(mb_substr($n_sesion[0] ?? 'U', 0, 1) . (isset($n_sesion[1]) ? mb_substr($n_sesion[1], 0, 1) : ''));

$foto_url_header = "";
if (!empty($foto_sesion)) {
    $foto_header_path = $_SERVER['DOCUMENT_ROOT'] . "/app/perfil/fotos/" . $foto_sesion;
    $foto_header_v = file_exists($foto_header_path) ? filemtime($foto_header_path) : time();
    $foto_url_header = "/app/perfil/fotos/" . $foto_sesion . "?v=" . $foto_header_v;
}

$mostrar_buscador = !isset($ocultar_buscador) || $ocultar_buscador === false;
$mostrar_botones = !isset($ocultar_botones_publicar) || $ocultar_botones_publicar === false;

$url_subir_apunte   = $es_visitante ? '/login?redir=' . $current_url : '/formulario-subir-apunte';
$url_publicar_clase = $es_visitante ? '/login?redir=' . $current_url : '/publicar-servicio';
$url_perfil         = $es_visitante ? '/login?redir=' . $current_url : '/perfil/' . $usuario_id;
?>
<style>
    * { -webkit-tap-highlight-color: transparent !important; touch-action: manipulation; }
    button:active, a:active { opacity: 0.75; transition: opacity 0.05s; }
    input:focus, select:focus, textarea:focus, button:focus, form:focus-within { outline: none !important; }
</style>

<nav class="fixed top-0 w-full bg-white/95 backdrop-blur-md border-b border-gray-100/80 z-50 h-14 transition-all">
    <div class="w-full flex items-center justify-between px-4 md:px-8 h-full gap-3 md:gap-6">
        <div class="flex items-center gap-4 flex-shrink-0">
            <a href="/vitrina" class="flex items-center">
                <img src="/img/logo.webp" alt="Nubira" class="h-6 md:h-7 w-auto object-contain"> 
            </a>
            <div class="hidden lg:flex items-center gap-2 text-xs text-gray-500">
                 <a href="/vitrina" class="hover:text-[#54A6D8] transition-colors duration-200">Inicio</a>
                 <span class="text-gray-300">/</span>
                 <span class="text-gray-900 font-semibold"><?= htmlspecialchars($titulo_seccion) ?></span>
            </div>
        </div>

        <div class="flex-1 max-w-xl mx-1 md:mx-4">
            <?php if ($mostrar_buscador): ?>
            <form action="/busqueda" method="GET" role="search"
                  onsubmit="if(this.q && this.q.value === '') this.q.disabled = true;"
                  class="w-full flex items-center bg-gray-50 border border-gray-100 rounded-full focus-within:border-[#54A6D8] focus-within:bg-white transition-colors duration-200 overflow-hidden relative z-10 outline-none">

                <div class="pl-3 text-gray-400 shrink-0 pointer-events-none">
                    <?= icon('search', 'w-3.5 h-3.5 md:w-4 md:h-4') ?>
                </div>

                <input type="search" name="q"
                       class="w-full py-1.5 md:py-2 pl-2 pr-4 bg-transparent border-none focus:ring-0 text-gray-900 placeholder-gray-400 text-base md:text-sm cursor-pointer focus:cursor-text outline-none"
                       placeholder="¿Qué buscas?"
                       autocomplete="off" enterkeyhint="search" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">

                <button type="submit" class="sr-only"></button>
            </form>
            <?php endif; ?>
        </div>

        <div class="flex flex-shrink-0 items-center gap-2 md:gap-4">
            <?php if ($mostrar_botones): ?>
            <div class="hidden lg:flex items-center gap-3">
                <a href="<?= $url_subir_apunte ?>" class="px-4 py-1.5 bg-blue-50 hover:bg-blue-100 border border-blue-100 text-[#54A6D8] text-xs font-semibold rounded-xl transition-all duration-200 flex items-center gap-2">
                    <?= icon('publish-doc', 'w-4 h-4 text-[#54A6D8]') ?> <span>Publicar Apunte</span>
                </a>
                <a href="<?= $url_publicar_clase ?>" class="px-4 py-1.5 bg-[#54A6D8] hover:bg-blue-600 text-white text-xs font-semibold rounded-xl transition-all duration-200 flex items-center gap-2">
                    <?= icon('publish-class', 'w-4 h-4 text-white') ?> <span>Publicar Clase</span>
                </a>
            </div>
            <?php endif; ?>

            <button type="button"
                    id="btn-abrir-onboarding"
                    class="flex items-center gap-1.5 px-2 md:px-3 py-1.5 rounded-full text-[#54A6D8] hover:bg-blue-50 transition text-sm font-medium"
                    title="¿Cómo funciona?">
                <?= icon('info-circle', 'w-5 h-5') ?>
                <span class="hidden md:inline">Cómo funciona</span>
            </button>

            <a href="<?= $url_perfil ?>" class="relative hidden lg:block group" title="<?= $es_visitante ? 'Invitado - Iniciar Sesión' : 'Mi Perfil' ?>">
                <div class="w-8 h-8 md:w-9 md:h-9 rounded-full bg-blue-50 border border-gray-100 flex items-center justify-center text-[#54A6D8] text-[10px] md:text-xs font-semibold overflow-hidden transition-transform duration-200 hover:scale-105">
                    <?php if ($es_visitante): ?>
                        <?= icon('user', 'w-4 h-4 md:w-4 md:h-4') ?>
                    <?php elseif ($foto_url_header): ?>
                        <img src="<?= $foto_url_header ?>" alt="Perfil" class="w-full h-full object-cover">
                    <?php else: ?>
                        <?= htmlspecialchars($iniciales) ?>
                    <?php endif; ?>
                </div>
                <?php if (!$es_visitante): ?>
                    <span id="header-alert-dot" class="<?= $perfil_incompleto ? '' : 'hidden' ?> absolute top-0 right-0 -mr-0.5 -mt-0.5 w-2.5 h-2.5 bg-red-500 border-2 border-white rounded-full"></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
</nav>

<?php if (!$es_visitante): ?>
<script>
let _faviconBadgeDataUrl = null;
function updateFaviconBadge(showBadge) {
    let favicon = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
    if (!favicon) {
        favicon = document.createElement('link');
        favicon.rel = 'icon';
        favicon.href = '/favicon.ico';
        document.head.appendChild(favicon);
    }
    if (!window.originalFaviconUrl) window.originalFaviconUrl = favicon.href;
    if (!showBadge) { favicon.href = window.originalFaviconUrl; return; }
    if (_faviconBadgeDataUrl) { favicon.href = _faviconBadgeDataUrl; return; }

    const img = new Image();
    img.crossOrigin = "anonymous";
    img.src = window.originalFaviconUrl;
    img.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = 32; canvas.height = 32;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, 32, 32);
        ctx.beginPath();
        ctx.arc(26, 6, 6, 0, 2 * Math.PI);
        ctx.fillStyle = '#ef4444'; ctx.fill();
        ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 2; ctx.stroke();
        _faviconBadgeDataUrl = canvas.toDataURL('image/png');
        favicon.href = _faviconBadgeDataUrl;
    };
}

window.updateHeaderDot = function(data) {
    const dot = document.getElementById('header-alert-dot');
    let totalAlertas = 0;
    Object.keys(data).forEach(key => {
        const valor = parseInt(data[key]);
        if (!isNaN(valor) && valor > 0) totalAlertas += valor;
    });
    if (totalAlertas > 0) {
        if (dot) dot.classList.remove('hidden');
        updateFaviconBadge(true);
    } else if (!<?= $alerta_encendida_php ? 'true' : 'false' ?>) {
        if (dot) dot.classList.add('hidden');
        updateFaviconBadge(false);
    }
};

window.addEventListener('nubira:alertas', e => window.updateHeaderDot(e.detail));
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const storageKey = 'nubira_device_id';
    let deviceId = localStorage.getItem(storageKey) || document.cookie.split('; ').find(row => row.startsWith(storageKey + '='))?.split('=')[1];
    if (!deviceId) deviceId = 'DEV-' + (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).substring(2, 15));
    localStorage.setItem(storageKey, deviceId);
    document.cookie = `nubira_device_id=${deviceId}; max-age=31536000; path=/; SameSite=Lax`;

    const scheduleTracker = window.requestIdleCallback || ((cb) => setTimeout(cb, 1500));
    scheduleTracker(() => {
        const payload = new URLSearchParams({ device_id: deviceId, ruta_actual: window.location.pathname + window.location.search });
        fetch('/app/api/tracker_silencioso.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload, keepalive: true }).catch(() => {});
    });
});
</script>

<?php
if (!defined('NUBIRA_TOAST_LOADED')) {
    define('NUBIRA_TOAST_LOADED', true);
    if (file_exists(__DIR__ . '/toast_system.php')) { require_once __DIR__ . '/toast_system.php'; }
}

// =========================================================================
// [NUBIRA SHIELD] SISTEMA GLOBAL DE AVISOS OFICIALES BLINDADO
// =========================================================================
require_once __DIR__ . '/../helpers/avisos_bbcode.php';
$avisos_oficiales = [];
$debug_error_db = null;

if (isset($conn) && isset($_SESSION['usuario_id']) && ($_SESSION['rol'] ?? '') !== 'admin') {
    try {
        $uid_actual = (int)$_SESSION['usuario_id'];
        
        // CORRECCIÓN ARQUITECTÓNICA: Seleccionamos solo las columnas universales y ordenamos por ID. 
        // Esto evita que la página muera (Fatal Error) si la columna fecha_creacion no existe.
$stmt_aviso = $conn->prepare("
    SELECT aa.id, aa.mensaje, aa.tipo, aa.campana_id, ac.titulo
    FROM avisos_admin aa
    LEFT JOIN avisos_campanas ac ON ac.id = aa.campana_id
    WHERE aa.destino_id = ? AND aa.leido = 0 
    ORDER BY aa.id ASC
");
        
       if ($stmt_aviso) {
            $stmt_aviso->bind_param("i", $uid_actual);
            $stmt_aviso->execute();
            $res_aviso = $stmt_aviso->get_result();
            while($row_aviso = $res_aviso->fetch_assoc()) {
                $row_aviso['imagenes'] = [];
                
                // Si tiene campaña asociada, traer imágenes
                if (!empty($row_aviso['campana_id'])) {
                    $stmt_img = $conn->prepare("SELECT archivo FROM avisos_imagenes WHERE campana_id = ? ORDER BY orden ASC");
                    if ($stmt_img) {
                        $stmt_img->bind_param("i", $row_aviso['campana_id']);
                        $stmt_img->execute();
                        $res_img = $stmt_img->get_result();
                        while ($row_img = $res_img->fetch_assoc()) {
                            $row_aviso['imagenes'][] = '/upload/avisos/' . $row_aviso['campana_id'] . '/' . $row_img['archivo'];
                        }
                        $stmt_img->close();
                    }
                }
                
                $avisos_oficiales[] = $row_aviso;
            }
            $stmt_aviso->close();
        } else {
            $debug_error_db = "Error preparando SQL: " . $conn->error;
        }
    } catch (Throwable $e) {
        $debug_error_db = $e->getMessage();
    }
}
?>

<?php if ($debug_error_db): ?>
<!-- LOGGER SILENCIOSO: Esto envía el error a la consola del navegador del admin/dev (F12) sin romper la web visualmente -->
<script>console.error("NUBIRA DB ERROR: <?= htmlspecialchars(addslashes($debug_error_db)) ?>");</script>
<?php endif; ?>

<?php if (!empty($avisos_oficiales)):
    $total_avisos = count($avisos_oficiales);
?>
<?php if ($total_avisos === 1):
    $aviso_actual = $avisos_oficiales[0]; // Mostramos solo el más antiguo (FIFO)
?>
<div id="modal-aviso-oficial" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-md">
    <div id="aviso-card-<?= (int)$aviso_actual['id'] ?>" class="bg-white w-full max-w-md rounded-2xl shadow-xl border border-gray-200 overflow-hidden transform transition-all duration-300 scale-95 opacity-0 max-h-[90vh] flex flex-col" data-aviso-id="<?= (int)$aviso_actual['id'] ?>">
        
        <?php
        $tipo_aviso = $aviso_actual['tipo'] ?? 'info';
        $imagenes_aviso = $aviso_actual['imagenes'] ?? [];
        $tiene_imagenes = !empty($imagenes_aviso);
        
        // Etiqueta de tipo
        $etiqueta_tipo = ['info' => 'Mensaje', 'novedad' => 'Novedad', 'importante' => 'Importante'][$tipo_aviso] ?? 'Mensaje';
        $color_punto = ['info' => 'bg-gray-400', 'novedad' => 'bg-[#54A6D8]', 'importante' => 'bg-rose-500'][$tipo_aviso] ?? 'bg-gray-400';
        ?>
        
   <!-- Header minimalista -->
<div class="px-4 pt-4 pb-0 shrink-0">
    <h2 class="text-lg font-semibold text-gray-900 tracking-tight">
        <?= !empty($aviso_actual['titulo']) ? htmlspecialchars($aviso_actual['titulo']) : 'Nubira' ?>
    </h2>
</div>

<!-- Contenido (scrolleable si pasa) -->
<div class="px-4 pt-1 pb-4 overflow-y-auto flex-1">
<p class="text-[15px] text-gray-700 leading-snug break-words whitespace-pre-line">
    <?= nb_renderizar_aviso_bbcode(htmlspecialchars($aviso_actual['mensaje'], ENT_QUOTES, 'UTF-8')) ?>
</p>
</div>

<!-- Carrusel de imágenes (si hay) -->
<?php if ($tiene_imagenes): ?>
<div class="relative bg-gray-50 shrink-0 mx-4 mb-2 rounded-xl overflow-hidden" id="carrusel-aviso">
   <div class="overflow-hidden bg-gray-50">
    <?php foreach ($imagenes_aviso as $idx => $img_url): ?>
    <img src="<?= htmlspecialchars($img_url) ?>" 
         class="aviso-slide w-full max-h-[400px] object-contain block <?= $idx > 0 ? 'hidden' : '' ?>"
         data-idx="<?= $idx ?>">
    <?php endforeach; ?>
</div>
    
    <?php if (count($imagenes_aviso) > 1): ?>
    <button onclick="cambiarSlide(-1)" class="hidden md:flex absolute left-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-white/90 hover:bg-white shadow-md items-center justify-center text-gray-700 transition-all">
        <?= icon('chevron-left', 'w-4 h-4') ?>
    </button>
    <button onclick="cambiarSlide(1)" class="hidden md:flex absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-white/90 hover:bg-white shadow-md items-center justify-center text-gray-700 transition-all">
        <?= icon('chevron-right', 'w-4 h-4') ?>
    </button>
    
    <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-1.5">
        <?php foreach ($imagenes_aviso as $idx => $img_url): ?>
        <button onclick="irASlide(<?= $idx ?>)" 
                class="aviso-dot w-1.5 h-1.5 rounded-full transition-all <?= $idx === 0 ? 'bg-white w-4' : 'bg-white/60' ?>"
                data-idx="<?= $idx ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

        <!-- Footer con CTA + contador -->
<div class="px-4 pb-4 pt-3 flex items-center justify-between gap-4 shrink-0">
            <?php if ($total_avisos > 1): ?>
                <p class="text-[11px] text-gray-400 font-medium">
                    <?= $total_avisos - 1 ?> más por leer
                </p>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            
            <button onclick="marcarAvisoLeido(<?= (int)$aviso_actual['id'] ?>)" 
                    class="px-5 py-2.5 bg-gray-900 hover:bg-black text-white text-[13px] font-medium rounded-lg transition-all active:scale-[0.98]">
                Entendido
            </button>
        </div>
    </div>
</div>

<script>
// Animación de entrada del modal
document.addEventListener('DOMContentLoaded', () => {
    const card = document.querySelector('[data-aviso-id]');
    if (card) {
        requestAnimationFrame(() => {
            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        });
        document.body.style.overflow = 'hidden';
    }
});
// Carrusel de imágenes
let slideActual = 0;

function irASlide(idx) {
    const slides = document.querySelectorAll('.aviso-slide');
    const dots = document.querySelectorAll('.aviso-dot');
    if (slides.length === 0) return;
    
    slides.forEach((s, i) => s.classList.toggle('hidden', i !== idx));
    dots.forEach((d, i) => {
        d.classList.toggle('bg-white', i === idx);
        d.classList.toggle('w-4', i === idx);
        d.classList.toggle('bg-white/60', i !== idx);
    });
    slideActual = idx;
}

function cambiarSlide(delta) {
    const slides = document.querySelectorAll('.aviso-slide');
    if (slides.length === 0) return;
    
    let nuevo = slideActual + delta;
    if (nuevo < 0) nuevo = slides.length - 1;
    if (nuevo >= slides.length) nuevo = 0;
    irASlide(nuevo);
}

// Swipe en móvil
document.addEventListener('DOMContentLoaded', () => {
    const carrusel = document.getElementById('carrusel-aviso');
    if (!carrusel) return;
    
    let touchStartX = 0;
    carrusel.addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX);
    carrusel.addEventListener('touchend', e => {
        const diff = touchStartX - e.changedTouches[0].screenX;
        if (Math.abs(diff) > 50) cambiarSlide(diff > 0 ? 1 : -1);
    });
});
async function marcarAvisoLeido(idAviso) {
    const modal = document.getElementById('modal-aviso-oficial');
    const card = document.getElementById('aviso-card-' + idAviso);
    if (!modal || !card) return;

    // Animación de salida
    card.classList.add('scale-95', 'opacity-0');
    modal.classList.add('opacity-0');
    modal.style.transition = 'opacity 0.3s ease';
    
    setTimeout(() => {
        modal.remove();
        document.body.style.overflow = '';
    }, 300);

    try {
        const fd = new FormData();
        fd.append('aviso_id', idAviso);
        fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
        const res = await fetch('/app/marcar_aviso_leido.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        // Si hay más avisos pendientes, recargar para mostrar el siguiente
        if (data.success && <?= $total_avisos ?? 0 ?> > 1) {
            setTimeout(() => location.reload(), 400);
        }
    } catch (error) {
        console.error('Error al silenciar aviso:', error);
    }
}
</script>
<?php else: ?>
<!-- Modal resumen: 2+ avisos pendientes, lista scrolleable + marcar todos como leídos -->
<div id="modal-aviso-oficial" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-gray-900/40 backdrop-blur-md">
    <div id="aviso-resumen-card" class="bg-white w-full max-w-md rounded-2xl shadow-xl border border-gray-200 overflow-hidden transform transition-all duration-300 scale-95 opacity-0 max-h-[90vh] flex flex-col">

        <div class="px-4 pt-4 pb-0 shrink-0">
            <h2 class="text-lg font-semibold text-gray-900 tracking-tight">
                Tienes <?= (int)$total_avisos ?> avisos nuevos
            </h2>
        </div>

        <div class="px-4 pt-2 pb-4 overflow-y-auto flex-1 space-y-3">
            <?php foreach ($avisos_oficiales as $av_resumen):
                $tipo_av_resumen = $av_resumen['tipo'] ?? 'info';
                $color_punto_resumen = ['info' => 'bg-gray-400', 'novedad' => 'bg-[#54A6D8]', 'importante' => 'bg-rose-500'][$tipo_av_resumen] ?? 'bg-gray-400';
            ?>
            <div class="border border-gray-100 rounded-xl p-3 bg-gray-50/60">
                <div class="flex items-center gap-2 mb-1">
                    <span class="w-1.5 h-1.5 rounded-full <?= $color_punto_resumen ?> shrink-0"></span>
                    <h3 class="text-sm font-semibold text-gray-900 truncate">
                        <?= !empty($av_resumen['titulo']) ? htmlspecialchars($av_resumen['titulo']) : 'Nubira' ?>
                    </h3>
                </div>
                <p id="msg-resumen-<?= (int)$av_resumen['id'] ?>" class="text-[13px] text-gray-600 leading-snug break-words whitespace-pre-line line-clamp-3">
                    <?= nb_renderizar_aviso_bbcode(htmlspecialchars($av_resumen['mensaje'], ENT_QUOTES, 'UTF-8')) ?>
                </p>
                <button type="button"
                        id="btn-vermas-<?= (int)$av_resumen['id'] ?>"
                        onclick="toggleVerMasAviso(<?= (int)$av_resumen['id'] ?>)"
                        class="hidden mt-1 text-[12px] font-medium text-[#54A6D8] hover:underline">
                    Ver más
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="px-4 pb-4 pt-3 flex items-center justify-end gap-4 shrink-0 border-t border-gray-100">
            <button onclick="marcarTodosAvisosLeidos([<?= implode(',', array_map(fn($a) => (int)$a['id'], $avisos_oficiales)) ?>])"
                    class="px-5 py-2.5 bg-gray-900 hover:bg-black text-white text-[13px] font-medium rounded-lg transition-all active:scale-[0.98]">
                Entendido
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('aviso-resumen-card');
    if (card) {
        requestAnimationFrame(() => {
            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        });
        document.body.style.overflow = 'hidden';
    }
});

async function marcarTodosAvisosLeidos(ids) {
    const modal = document.getElementById('modal-aviso-oficial');
    const card = document.getElementById('aviso-resumen-card');
    if (!modal || !card) return;

    card.classList.add('scale-95', 'opacity-0');
    modal.classList.add('opacity-0');
    modal.style.transition = 'opacity 0.3s ease';

    setTimeout(() => {
        modal.remove();
        document.body.style.overflow = '';
    }, 300);

    try {
        const fd = new FormData();
        ids.forEach(id => fd.append('aviso_ids[]', id));
        fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
        await fetch('/app/marcar_aviso_leido.php', { method: 'POST', body: fd });
    } catch (error) {
        console.error('Error al silenciar avisos:', error);
    }
}

// "Ver más/menos" por item — solo muestra el botón si el texto realmente desborda 3 líneas
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[id^="msg-resumen-"]').forEach(p => {
        if (p.scrollHeight > p.clientHeight + 1) {
            const id = p.id.replace('msg-resumen-', '');
            const btn = document.getElementById('btn-vermas-' + id);
            if (btn) btn.classList.remove('hidden');
        }
    });
});

function toggleVerMasAviso(id) {
    const p = document.getElementById('msg-resumen-' + id);
    const btn = document.getElementById('btn-vermas-' + id);
    if (!p || !btn) return;
    const expandir = p.classList.contains('line-clamp-3');
    p.classList.toggle('line-clamp-3', !expandir);
    p.classList.toggle('line-clamp-none', expandir);
    btn.textContent = expandir ? 'Ver menos' : 'Ver más';
}
</script>
<?php endif; ?>
<?php endif; ?>

<?php
// Modal global "Cómo funciona Nubira" (botón Tutorial del topbar).
// Va FUERA del <nav>, como hermano flotante, disponible en toda página que incluya el header.
// El auto-abrir solo ocurre en /explorar (script en vitrina.php).
require_once __DIR__ . '/onboarding_modal.php';
?>