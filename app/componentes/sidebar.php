<?php
/**
 * COMPONENTE: SIDEBAR (ESCRITORIO - LAZY REGISTRATION + NOTIFICACIONES)
 * UBICACIÓN: public_html/app/componentes/sidebar.php
 * 
 * [VISUAL POLISH v3] Diseño premium, estructura plana original
 */

$is_guest = !isset($_SESSION['usuario_id']);

// 1. HEREDAMOS LA VARIABLE DEL HEADER PARA AHORRAR CONSULTAS SQL
$alert_perfil = $alerta_encendida_php ?? false; 

// Fallback por si el sidebar carga en una vista sin header.php
if (!$is_guest && !isset($alerta_encendida_php)) {
    $uid_sb = $_SESSION['usuario_id'] ?? 0;
    if ($uid_sb > 0 && isset($conn)) {
        $stmt_sb = $conn->prepare("SELECT foto_perfil, bio FROM alumnos WHERE id = ? LIMIT 1");
        if ($stmt_sb) {
            $stmt_sb->bind_param("i", $uid_sb);
            $stmt_sb->execute();
            $stmt_sb->bind_result($f_sb, $b_sb);
            if ($stmt_sb->fetch()) {
                if (empty($f_sb) || empty(trim((string)$b_sb))) { $alert_perfil = true; }
            }
            $stmt_sb->close();
        }
      $stmt_banco_sb = $conn->prepare("SELECT banco, numero_cuenta FROM datos_pago_usuario WHERE usuario_id = ? LIMIT 1");
if ($stmt_banco_sb) {
    $stmt_banco_sb->bind_param("i", $uid_sb);
    $stmt_banco_sb->execute();
    $stmt_banco_sb->store_result();
    if ($stmt_banco_sb->num_rows > 0) {
        $stmt_banco_sb->bind_result($banco_sb, $cuenta_sb);
        $stmt_banco_sb->fetch();
        if (empty(trim((string)$banco_sb)) || empty(trim((string)$cuenta_sb))) {
            $alert_perfil = true;
        }
    } else {
        $alert_perfil = true;
    }
    $stmt_banco_sb->close();
}
    }
}

if (!function_exists('nav_class')) {
    function nav_class($path) {
        $ruta = $_SERVER['REQUEST_URI'] ?? '';
        $active = (strpos($ruta, $path) !== false);
        
        $base = 'group flex items-center gap-3 px-3 py-2.5 text-[13px] rounded-xl transition-all duration-200 relative ';
        
        $style = $active
            ? 'text-[#54A6D8] font-medium'
            : 'text-[#222222] hover:bg-gray-50/80 font-medium';
            
        return $base . $style;
    }
}

$ruta = $_SERVER['REQUEST_URI'] ?? '';
$is_inicio   = ($ruta === '/' || strpos($ruta, '/explorar') !== false || (strpos($ruta, '/vitrina') !== false && strpos($ruta, '/vitrina-') === false));
$is_mensajes = (strpos($ruta, 'bandeja') !== false);
$is_clases   = (strpos($ruta, '/servicios') !== false);
$is_apuntes  = (strpos($ruta, '/apuntes') !== false);
$is_guias    = (strpos($ruta, '/guias') !== false);
$is_perfil   = (strpos($ruta, '/perfil') !== false);
?>

<style>
    .sidebar-scroll::-webkit-scrollbar { width: 3px; }
    .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 10px; }
    .sidebar-scroll::-webkit-scrollbar-thumb:hover { background: #d1d5db; }
    .sidebar-nav-icon { 
        width: 32px; height: 32px; 
        display: flex; align-items: center; justify-content: center; 
        border-radius: 10px; 
        transition: all 0.2s ease;
        flex-shrink: 0;
    }
    .group:hover .sidebar-nav-icon { background: rgba(0,0,0,0.03); }
    .sidebar-active-icon { background: rgba(84,166,216,0.1) !important; }
</style>

<aside class="hidden lg:flex lg:flex-col fixed top-14 left-0 h-[calc(100%-3.5rem)] w-56 bg-white/95 backdrop-blur-sm border-r border-[#f0f0f0]/80 z-40 overflow-y-auto sidebar-scroll">
  <div class="px-4 py-5 flex flex-col h-full">
    
    <nav class="flex flex-col space-y-0.5 flex-1">

      <a href="/explorar" class="<?= nav_class('/explorar') ?>">
        <div class="sidebar-nav-icon <?= $is_inicio ? 'sidebar-active-icon' : '' ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px]">
              <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
          </svg>
        </div>
        <span class="tracking-[-0.01em]">Inicio</span>
      </a>

      <a href="<?= $is_guest ? '/login?redir=' . urlencode('/bandeja-entrada') : '/bandeja-entrada' ?>" class="<?= nav_class('bandeja') ?> justify-between">
        <div class="flex items-center gap-3">
            <div class="sidebar-nav-icon <?= $is_mensajes ? 'sidebar-active-icon' : '' ?>">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px]">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 20.25c4.97 0 9-3.694 9-8.25s-4.03-8.25-9-8.25S3 7.444 3 12c0 2.104.859 4.023 2.273 5.48.432.447.495 1.141.143 1.65-.6.866-1.42 1.586-2.38 2.115 1.576.166 3.09.043 4.41-.33.61-.171 1.256-.123 1.833.125A9.01 9.01 0 0 0 12 20.25Z" />
              </svg>
            </div>
            <span class="tracking-[-0.01em]">Mensajes</span>
        </div>
        
        <?php if (!$is_guest): ?>
        <span id="badge-mensajes-seguro" class="hidden bg-red-500 text-white text-[10px] font-bold h-5 min-w-[20px] px-1.5 rounded-full shadow-[0_1px_2px_rgba(0,0,0,0.08)] flex items-center justify-center">
            0
        </span>
        <?php endif; ?>
      </a>

      <a href="/servicios" class="<?= nav_class('/servicios') ?>">
        <div class="sidebar-nav-icon <?= $is_clases ? 'sidebar-active-icon' : '' ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px]">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.499 5.516 51.55 51.55 0 0 1-2.657.813m-15.482 0A50.923 50.923 0 0 1 12 13.489a50.92 50.92 0 0 1 10.491-3.342" />
          </svg>
        </div>
        <span class="tracking-[-0.01em]">Clases</span>
      </a>

      <a href="/apuntes" class="<?= nav_class('/apuntes') ?>">
        <div class="sidebar-nav-icon <?= $is_apuntes ? 'sidebar-active-icon' : '' ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px]">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
          </svg>
        </div>
        <span class="tracking-[-0.01em]">Apuntes</span>
      </a>

      <a href="/guias" class="<?= nav_class('/guias') ?>">
        <div class="sidebar-nav-icon <?= $is_guias ? 'sidebar-active-icon' : '' ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px]">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
          </svg>
        </div>
        <span class="tracking-[-0.01em]">Recursos</span>
      </a>

      <a href="<?= $is_guest ? '/login?redir=' . urlencode('/perfil') : '/perfil/' . $_SESSION['usuario_id'] ?>" class="<?= nav_class('/perfil') ?>">
        <div class="sidebar-nav-icon <?= $is_perfil ? 'sidebar-active-icon' : '' ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px]">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
          </svg>
        </div>
        
        <span class="tracking-[-0.01em] relative">
            Mi Perfil
            <span id="sidebar-alert-dot" class="<?= $alert_perfil ? '' : 'hidden' ?> absolute -top-0.5 -right-3.5 w-2 h-2 bg-red-500 rounded-full shadow-[0_1px_2px_rgba(0,0,0,0.08)] ring-2 ring-white" title="Completa tu perfil"></span>
        </span>
      </a>
      
    </nav>

    <?php if (!$is_guest): ?>
    <div class="mt-auto border-t border-[#f0f0f0]/70 pt-3.5">
        <a href="/app/logout.php" class="flex items-center gap-3 px-3.5 py-2.5 text-[13px] text-gray-400 hover:text-red-500 hover:bg-red-50/60 rounded-xl transition-all duration-200 group">
            <div class="sidebar-nav-icon group-hover:!bg-red-50">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-[18px] h-[18px] group-hover:translate-x-0.5 transition-transform duration-200">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
              </svg>
            </div>
            <span class="font-medium tracking-[-0.01em]">Cerrar Sesión</span>
        </a>
    </div>
    <?php endif; ?>

  </div>
</aside>

<script>
document.addEventListener("DOMContentLoaded", () => {
    <?php if (!$is_guest): ?>
    
    let maxMensajes = parseInt(localStorage.getItem('nubira_mensajes_pendientes')) || 0;

    function forzarBurbujaActiva(total) {
        const txt = total > 99 ? '99+' : total;
        
        const badgeSidebar = document.getElementById('badge-mensajes-seguro');
        const badgeMovil   = document.getElementById('badge-chats-bottom');

        if (badgeSidebar) {
            badgeSidebar.innerText = txt;
            badgeSidebar.classList.remove('hidden');
            badgeSidebar.style.setProperty('display', 'flex', 'important');
            badgeSidebar.style.setProperty('opacity', '1', 'important');
        }
        
        if (badgeMovil) {
            badgeMovil.innerText = txt;
            badgeMovil.classList.remove('hidden');
            badgeMovil.style.setProperty('display', 'flex', 'important');
            badgeMovil.style.setProperty('opacity', '1', 'important');
        }
    }

    if (maxMensajes > 0) {
        forzarBurbujaActiva(maxMensajes);
    }

    async function revisarChatsBlindado() {
        try {
            const res = await fetch('/app/check_notificaciones.php?t=' + Date.now(), { credentials: 'include' });
            if (!res.ok) return; 
            
            const data = await res.json();
            
            if (data.total !== undefined) {
                const total = parseInt(data.total);
                
                if (!isNaN(total) && total > 0) {
                    if (total !== maxMensajes) { 
                        maxMensajes = total; 
                        localStorage.setItem('nubira_mensajes_pendientes', maxMensajes); 
                        forzarBurbujaActiva(maxMensajes);
                    }
                } 
                else if (total === 0) {
                    maxMensajes = 0;
                    localStorage.setItem('nubira_mensajes_pendientes', 0);
                    
                    const bSidebar = document.getElementById('badge-mensajes-seguro');
                    const bMovil   = document.getElementById('badge-chats-bottom');
                    
                    if (bSidebar) {
                        bSidebar.classList.add('hidden');
                        bSidebar.style.display = 'none'; 
                    }
                    if (bMovil) {
                        bMovil.classList.add('hidden');
                        bMovil.style.display = 'none';
                    }
                }
            }
        } catch (e) {}
    }

   // [NUBIRA 2.0] Badge global del sidebar: 30s es suficiente.
    // El chat en vivo (chat_previo_contrato.php) tiene su propio polling de 3s.
    revisarChatsBlindado();
    setInterval(() => {
        if (!document.hidden) revisarChatsBlindado();
    }, 30000);
    
    // Al volver a la pestaña, refrescamos al instante
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) revisarChatsBlindado();
    });

    function renderizarPuntoSidebar(data) {
        const sidebarDot = document.getElementById('sidebar-alert-dot');
        if (!sidebarDot) return;
        
        let totalAlertas = 0;
        Object.keys(data).forEach(key => {
            const valor = parseInt(data[key]);
            if (!isNaN(valor) && valor > 0) { totalAlertas += valor; }
        });
        
        if (totalAlertas > 0) {
            sidebarDot.classList.remove('hidden');
        } else if (!<?= $alert_perfil ? 'true' : 'false' ?>) {
            sidebarDot.classList.add('hidden');
        }
    }

    window.addEventListener('nubira:alertas', e => renderizarPuntoSidebar(e.detail));

    <?php endif; ?>
});
</script>