<?php
// app/componentes/header_aula.php
// VERSIÓN "FOCUS" PARA EL AULA VIRTUAL (Sin buscador ni botones de publicar)

// 1. INICIALIZACIÓN
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }

$usuario_id = $_SESSION['usuario_id'] ?? null;
$es_visitante = ($usuario_id === null);

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
    $conn->query("UPDATE alumnos SET ultima_sesion = NOW() WHERE id = $uid_header");

    $foto_sesion = $_SESSION['foto_perfil'] ?? '';
    $stmt_h = $conn->prepare("SELECT foto_perfil, bio FROM alumnos WHERE id = ? LIMIT 1");
    if ($stmt_h) {
        $stmt_h->bind_param("i", $uid_header);
        $stmt_h->execute();
        $stmt_h->bind_result($foto_db, $bio_db);
        if ($stmt_h->fetch()) {
            $_SESSION['foto_perfil'] = $foto_db; 
            $foto_sesion = $foto_db;
            if (empty($foto_db) || empty(trim((string)$bio_db))) { 
                $perfil_incompleto = true; 
                $alerta_encendida_php = true;
            }
        }
        $stmt_h->close();
    }
}

$titulo_seccion = 'Aula Virtual'; // Fijo para dar contexto
$n_sesion = explode(' ', trim($_SESSION['usuario_nombre'] ?? 'U'));
$iniciales = mb_strtoupper(mb_substr($n_sesion[0] ?? 'U', 0, 1) . (isset($n_sesion[1]) ? mb_substr($n_sesion[1], 0, 1) : ''));

$foto_url_header = "";
if (!empty($foto_sesion)) {
    $foto_header_path = $_SERVER['DOCUMENT_ROOT'] . "/app/perfil/fotos/" . $foto_sesion;
    $foto_header_v = file_exists($foto_header_path) ? filemtime($foto_header_path) : time();
    $foto_url_header = "/app/perfil/fotos/" . $foto_sesion . "?v=" . $foto_header_v;
}

$url_perfil = $es_visitante ? '/login' : '/perfil/' . $usuario_id;
?>

<style>
    /* --- ESTILOS DE LIMPIEZA GLOBAL --- */
    * { -webkit-tap-highlight-color: transparent !important; }
    input:focus, select:focus, textarea:focus, button:focus, form:focus-within {
        outline: none !important;
    }
</style>

<nav class="fixed top-0 w-full bg-white/95 backdrop-blur-md border-b border-gray-100 z-50 h-20 transition-all">
    
<div class="w-full flex items-center justify-between px-6 md:px-10 h-full">

        <div class="flex items-center gap-4 flex-shrink-0">
            <a href="<?= $url_perfil ?>" class="flex items-center hover:opacity-80 transition-opacity">
                <img src="/img/logo.webp" alt="Nubira" class="h-7 md:h-8 w-auto object-contain">
            </a>
            <div class="hidden md:flex items-center gap-3 text-sm text-gray-500">
                 <a href="<?= $url_perfil ?>" class="hover:text-[#54A6D8] transition-colors font-medium">Dashboard</a>
                 <span class="text-gray-300">/</span>
                 <span class="text-gray-900 font-bold flex items-center gap-2">
                    <i class="fa-solid fa-chalkboard-user text-[#54A6D8]"></i> <?= htmlspecialchars($titulo_seccion) ?>
                 </span>
            </div>
        </div>

        <div class="flex-1"></div>

        <div class="flex flex-shrink-0 items-center">

            <a href="<?= $url_perfil ?>" class="relative group" title="Mi Perfil">
                <div class="w-10 h-10 md:w-11 md:h-11 rounded-full bg-blue-50 border border-gray-200 flex items-center justify-center text-[#54A6D8] text-sm font-bold overflow-hidden transition-all duration-300 shadow-sm hover:shadow-md hover:scale-105">
                    <?php if ($es_visitante): ?>
                        <?= icon('user', 'w-5 h-5') ?>
                    <?php elseif ($foto_url_header): ?>
                        <img src="<?= $foto_url_header ?>" alt="Perfil" class="w-full h-full object-cover">
                    <?php else: ?>
                        <?= htmlspecialchars($iniciales) ?>
                    <?php endif; ?>
                </div>
                
                <?php if (!$es_visitante): ?>
                    <span id="header-alert-dot" class="<?= $perfil_incompleto ? '' : 'hidden' ?> absolute top-0 right-0 -mr-0.5 -mt-0.5 w-3 h-3 bg-red-500 border-2 border-white rounded-full shadow-sm"></span>
                <?php endif; ?>
            </a>

        </div>

    </div>

  <?php if (!$es_visitante): ?>
    <script>
    // Se mantiene la lógica de alertas y favicon
    function updateFaviconBadge(showBadge) {
        let favicon = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
        if (!favicon) {
            favicon = document.createElement('link');
            favicon.rel = 'icon';
            favicon.href = '/favicon.ico';
            document.head.appendChild(favicon);
        }

        if (!window.originalFaviconUrl) {
            window.originalFaviconUrl = favicon.href;
        }

        if (!showBadge) {
            favicon.href = window.originalFaviconUrl;
            return;
        }

        const img = new Image();
        img.crossOrigin = "anonymous";
        img.src = window.originalFaviconUrl;
        
        img.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = 32;
            canvas.height = 32;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, 32, 32);

            ctx.beginPath();
            ctx.arc(26, 6, 6, 0, 2 * Math.PI);
            ctx.fillStyle = '#ef4444'; 
            ctx.fill();
            
            ctx.strokeStyle = '#ffffff'; 
            ctx.lineWidth = 2;
            ctx.stroke();

            favicon.href = canvas.toDataURL('image/png');
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

    document.addEventListener('DOMContentLoaded', () => {
        function checkHeaderAlerts() {
            fetch('/app/contar_alertas_sistema.php?v=' + Date.now())
                .then(res => res.json())
                .then(data => window.updateHeaderDot(data))
                .catch(e => {});
        }
        checkHeaderAlerts();
        setInterval(checkHeaderAlerts, 15000);
    });
    </script>
    <?php endif; ?>
</nav>