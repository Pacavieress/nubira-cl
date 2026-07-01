<?php
/**
 * COMPONENTE: NAV BOTTOM (BLUE EDITION - 100% NATIVE FEEL)
 * ESTADO: FINAL (Micro-interacciones nativas, botón central elevado, SQL blindado)
 * [NUBIRA 2.0] Optimizado: cache sesión de alertas, cache-busting estable, polling inteligente
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

// 1. RUTAS Y VARIABLES VISUALES
$uri_actual = $_SERVER['REQUEST_URI'] ?? '/';
// [NUBIRA 2.0] Coherente con sidebar: /explorar también cuenta como inicio
$es_inicio  = (
    $uri_actual === '/' 
    || (strpos($uri_actual, '/vitrina') === 0 && strpos($uri_actual, '/vitrina-') === false)
    || strpos($uri_actual, '/explorar') === 0
);
$es_perfil  = (strpos($uri_actual, '/perfil') === 0);
$es_mensaje = (strpos($uri_actual, 'bandeja') !== false || strpos($uri_actual, 'chat') !== false);

// Lógica Híbrida (Visitante vs Usuario)
$uid_nb = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
$es_visita_nb = ($uid_nb === 0);

// Destinos Dinámicos (Lazy Registration)
$link_home   = '/vitrina';
$link_chat   = $es_visita_nb ? '/login?redir=' . urlencode('/bandeja-entrada') : '/bandeja-entrada';
$link_perfil = $es_visita_nb ? '/login?redir=' . urlencode('/perfil') : '/perfil/' . $uid_nb;

// Lógica del Botón Publicar
$onclick_publicar = $es_visita_nb ? "event.preventDefault(); window.location.href='/login?redir=" . urlencode($uri_actual) . "';" : "";

// --- ESTILOS NATIVOS UNIFICADOS ---
$cls_base     = 'flex flex-col items-center justify-center gap-1 w-full outline-none select-none transition-transform duration-150 active:scale-[0.92] relative';
$cls_activo   = 'text-[#54A6D8] font-semibold'; 
$cls_inactivo = 'text-gray-400 font-medium';

// 2. LÓGICA DE ALERTA DE PERFIL Y FOTO
$alert_perfil_movil = $alerta_encendida_php ?? false;
$foto_url_nav = $foto_url_header ?? ""; 

// [NUBIRA 2.0] Cache de sesión: evita 2 queries SQL por cada navegación
// Se refresca cada 5 minutos o cuando se fuerza con $_SESSION['nav_cache_invalidar'] = true
if (!isset($alerta_encendida_php) && !$es_visita_nb && isset($conn)) {
    $cache_key = 'nav_cache_' . $uid_nb;
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
        
        $stmt_nb = $conn->prepare("SELECT foto_perfil, bio FROM alumnos WHERE id = ? LIMIT 1");
        if ($stmt_nb) {
            $stmt_nb->bind_param("i", $uid_nb);
            $stmt_nb->execute();
            $stmt_nb->bind_result($f_db, $b_db);
            if ($stmt_nb->fetch()) {
                if (empty($f_db) || empty(trim((string)$b_db))) { $alert_calc = true; }
                if (!empty($f_db)) { 
                    $foto_calc = "/app/perfil/fotos/" . $f_db;
                    // [NUBIRA 2.0] Cache-busting estable: filemtime en vez de time()
                    $ruta_fis_foto = $_SERVER['DOCUMENT_ROOT'] . $foto_calc;
                    if (file_exists($ruta_fis_foto)) {
                        $mtime_foto = filemtime($ruta_fis_foto);
                    }
                }
            }
            $stmt_nb->close();
        }
        
        if (!$alert_calc) {
            $stmt_banco = $conn->prepare("SELECT banco, numero_cuenta FROM datos_pago_usuario WHERE usuario_id = ? LIMIT 1");
            if ($stmt_banco) {
                $stmt_banco->bind_param("i", $uid_nb);
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
        
        // Guardamos en sesión
        $_SESSION[$cache_key] = [
            'ts'         => $ahora,
            'alerta'     => $alert_calc,
            'foto_path'  => $foto_calc,
            'foto_mtime' => $mtime_foto,
        ];
        unset($_SESSION['nav_cache_invalidar']);
    }
    
    // Leemos de cache
    $alert_perfil_movil = $_SESSION[$cache_key]['alerta'];
    $foto_path_nav  = $_SESSION[$cache_key]['foto_path'] ?? '';
    $foto_mtime_nav = $_SESSION[$cache_key]['foto_mtime'] ?? 0;
    
    if (!empty($foto_path_nav)) {
        $foto_url_nav = htmlspecialchars($foto_path_nav, ENT_QUOTES, 'UTF-8');
        if ($foto_mtime_nav > 0) {
            $foto_url_nav .= "?v=" . $foto_mtime_nav;
        }
    }
}
?>

<style>
    /* PREVENCIÓN DE COMPORTAMIENTO WEB EN MÓVILES */
    .nav-native-feel * {
        -webkit-tap-highlight-color: transparent; 
        touch-action: manipulation; 
    }

    /* Brillo nativo y sutil para el botón central */
    @keyframes shine-move { 0% { left: -100%; } 20% { left: 100%; } 100% { left: 100%; } }
    .animate-shine { animation: shine-move 6s ease-in-out infinite; }

    /* [NUBIRA 2.0] Respeta preferencia del sistema de reducir animaciones */
    @media (prefers-reduced-motion: reduce) {
        .animate-shine { animation: none; }
    }

    /* Pop nativo estilo iOS */
    .badge-pop { animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    @keyframes popIn { 0% { transform: scale(0); } 100% { transform: scale(1); } }

    /* PWA standalone: bajar íconos acercándolos al home indicator */
    @media (display-mode: standalone) {
        .nav-native-feel {
            padding-top: 0.5rem !important;
            padding-bottom: max(4px, env(safe-area-inset-bottom)) !important;
        }

    }
</style>

<nav class="nav-native-feel lg:hidden fixed bottom-0 left-0 right-0 z-[60] bg-white/90 backdrop-blur-xl border-t border-gray-100/80 pb-[env(safe-area-inset-bottom)] pt-2 px-1" aria-label="Navegación principal">
  <ul class="grid grid-cols-5 text-[11px] text-center pb-1 items-end relative">

    <li>
        <a href="<?= htmlspecialchars($link_home, ENT_QUOTES, 'UTF-8') ?>" aria-label="Inicio" class="<?= $cls_base ?> <?= $es_inicio ? $cls_activo : $cls_inactivo ?>">
            <div class="w-6 h-6 flex items-center justify-center">
                <?php if ($es_inicio): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6" aria-hidden="true">
                      <path d="M11.47 3.84a.75.75 0 011.06 0l8.99 9a.75.75 0 11-1.06 1.06l-1.46-1.46V21a.75.75 0 01-.75.75h-4.5a.75.75 0 01-.75-.75v-4.5a.75.75 0 00-.75-.75h-2.25a.75.75 0 00-.75.75V21a.75.75 0 01-.75.75H4.5A.75.75 0 013.75 21v-8.56l-1.46 1.46a.75.75 0 01-1.06-1.06l8.99-9z" />
                    </svg>
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                <?php endif; ?>
            </div>
            <span class="tracking-tight leading-none mt-0.5">Inicio</span>
        </a>
    </li>

    <li>
        <button id="btn-explora" aria-label="Descubrir" class="<?= $cls_base ?> <?= $cls_inactivo ?>">
            <div class="w-6 h-6 flex items-center justify-center relative">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
            </div>
            <span class="tracking-tight leading-none mt-0.5">Descubrir</span>
        </button>
    </li>

    <li class="relative w-full h-full flex justify-center">
        <button id="btn-publicar" 
                aria-label="Publicar"
                onclick="<?= $onclick_publicar ?>" 
                class="outline-none relative transition-transform duration-150 active:scale-[0.88] select-none h-full w-full">
            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 w-14 h-14 bg-[#54A6D8] rounded-[18px] flex items-center justify-center text-white z-10 overflow-hidden shadow-md">
                <div class="absolute top-0 -left-[100%] w-full h-full bg-gradient-to-r from-transparent via-white/30 to-transparent -skew-x-12 animate-shine" aria-hidden="true"></div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-7 h-7 relative z-20" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </div>
        </button>
    </li>

    <li>
        <a href="<?= htmlspecialchars($link_chat, ENT_QUOTES, 'UTF-8') ?>" aria-label="Mensajes" class="<?= $cls_base ?> <?= $es_mensaje ? $cls_activo : $cls_inactivo ?>">
            <div class="w-6 h-6 flex items-center justify-center relative">
                <?php if ($es_mensaje): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6" aria-hidden="true">
                      <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75c1.173 0 2.298-.207 3.344-.582l3.785 1.514a.75.75 0 00.99-.99l-1.514-3.785A9.715 9.715 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75z" clip-rule="evenodd" />
                    </svg>
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.495 1.141.143 1.65-.6.866-1.42 1.586-2.38 2.115 1.576.166 3.09.043 4.41-.33.61-.171 1.256-.123 1.833.125A9.01 9.01 0 0 0 12 20.25Z" />
                    </svg>
                <?php endif; ?>
                
                <?php if (!$es_visita_nb): ?>
                <span id="badge-chats-bottom" class="hidden absolute -top-1 -right-2 bg-red-500 text-white text-[10px] font-bold h-4 min-w-[16px] px-1 rounded-full flex items-center justify-center border-2 border-white z-20 badge-pop leading-none" aria-live="polite">0</span>
                <?php endif; ?>
            </div>
            <span class="tracking-tight leading-none mt-0.5">Mensajes</span>
        </a>
    </li>

    <li>
        <a href="<?= htmlspecialchars($link_perfil, ENT_QUOTES, 'UTF-8') ?>" aria-label="Perfil" class="<?= $cls_base ?> <?= $es_perfil ? $cls_activo : $cls_inactivo ?>">
            <div class="w-6 h-6 flex items-center justify-center relative shrink-0 aspect-square">
                <?php if (!empty($foto_url_nav)): ?>
                    <div class="w-6 h-6 rounded-full overflow-hidden shrink-0 <?= $es_perfil ? 'border-2 border-[#54A6D8]' : 'border-2 border-transparent' ?> transition-all">
                        <img src="<?= $foto_url_nav ?>" alt="" width="24" height="24" decoding="async" loading="lazy" class="w-full h-full object-cover">
                    </div>
                <?php else: ?>
                    <?php if ($es_perfil): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6" aria-hidden="true">
                          <path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM3.751 20.105a8.25 8.25 0 0116.498 0 .75.75 0 01-.437.695A18.683 18.683 0 0112 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 01-.437-.695z" clip-rule="evenodd" />
                        </svg>
                    <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$es_visita_nb): ?>
                    <span id="nav-alert-dot" class="<?= $alert_perfil_movil ? '' : 'hidden' ?> absolute -top-0.5 -right-0.5 w-[10px] h-[10px] bg-red-500 border-2 border-white rounded-full z-30 badge-pop" aria-label="Tienes alertas pendientes"></span>
                <?php endif; ?>
            </div>
            <span class="tracking-tight leading-none mt-0.5">Perfil</span>
        </a>
    </li>

  </ul>
</nav>

<?php if (!$es_visita_nb): ?>
<script>
(function() {
    const navDot = document.getElementById('nav-alert-dot');
    const alertaBasePHP = <?= $alert_perfil_movil ? 'true' : 'false' ?>;
    
    function renderizarPuntoNav(data) {
        if (!navDot) return;
        let totalAlertas = 0;
        
        Object.keys(data).forEach(key => {
            const valor = parseInt(data[key]);
            if (!isNaN(valor) && valor > 0) totalAlertas += valor;
        });
        
        if (totalAlertas > 0) {
            navDot.classList.remove('hidden');
        } else if (!alertaBasePHP) {
            navDot.classList.add('hidden');
        }
    }

    if (typeof window.updateHeaderDot === 'function') {
        const originalUpdate = window.updateHeaderDot;
        window.updateHeaderDot = function(data) {
            originalUpdate(data);
            renderizarPuntoNav(data);
        };
    }

    function checkNavAlerts() {
        // [NUBIRA 2.0] No consultar si la pestaña está oculta (ahorra batería + servidor)
        if (document.hidden) return;
        
        fetch('/app/contar_alertas_sistema.php?v=' + Date.now())
            .then(res => res.json())
            .then(data => renderizarPuntoNav(data))
            .catch(() => {});
    }

    // [NUBIRA 2.0] Polling inteligente:
    // - Primera consulta tras un pequeño delay (no competimos con el render inicial)
    // - Intervalo 45s (antes 15s) alineado con el badge de chats
    // - Al volver a la pestaña, refrescamos inmediato
    const scheduleIdle = window.requestIdleCallback || ((cb) => setTimeout(cb, 600));
    scheduleIdle(checkNavAlerts);
    
    setInterval(checkNavAlerts, 45000);
    
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) checkNavAlerts();
    });
})();
</script>
<?php endif; ?>