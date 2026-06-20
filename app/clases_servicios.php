<?php
/**
 * VISTA: EXPLORAR CLASES Y SERVICIOS
 * UBICACIÓN: public_html/app/clases_servicios.php
 */
session_start();

// === BLOQUE ANTI-CACHE ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1. SEGURIDAD MODIFICADA (LAZY REGISTRATION)
// Permitimos acceso a invitados. Solo guardamos la URL por si deciden loguearse después.
$is_guest = !isset($_SESSION['usuario_id']);
if ($is_guest) {
    $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];
}

require_once __DIR__ . '/conexion.php';

// =========================================================================
// 🛡️ [NUBIRA SHIELD] MIDDLEWARE ANTI-BOT (Nivel Arquitectura)
// Se ejecuta AQUÍ, antes de enviar HTML o hacer queries pesadas.
// =========================================================================
if (isset($conn)) {
    $antibot_path = $app_dir . '/middleware/antibot.php';
    if (file_exists($antibot_path)) {
        require_once $antibot_path;
        if (function_exists('check_nubira_shield')) {
            check_nubira_shield($conn); // Si es bot, corta aquí y devuelve 403 puro
        }
    }
}
// =========================================================================

require_once __DIR__ . '/iconos.php'; 

// 2. DATOS USUARIO
$rol             = $_SESSION['rol'] ?? 'alumno';
$usuario_id      = !$is_guest ? (int)$_SESSION['usuario_id'] : 0;
$nombre_usuario  = $_SESSION['usuario_nombre'] ?? 'Invitado';
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));
$nombres_inst    = ['uc'=>'UC','aiep'=>'AIEP','uss'=>'USS','udp'=>'UDP'];
$nombre_institucion = $institucion_session ? ($nombres_inst[$institucion_session] ?? ucfirst($institucion_session)) : 'Nubira';

// Carrera (Solo si está logueado)
$carrera_usuario = $_SESSION['carrera'] ?? '';
if (!$is_guest && empty($carrera_usuario)) {
    $stmt = $conn->prepare("SELECT carrera FROM alumnos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $stmt->bind_result($c_db);
    if ($stmt->fetch()) { $carrera_usuario = $c_db; $_SESSION['carrera'] = $c_db; }
    $stmt->close();
}
$display_carrera = $carrera_usuario ?: 'Estudiante';

// 3. FILTROS
$qs_q         = trim($_GET['q'] ?? '');
$qs_mod       = trim($_GET['modalidad'] ?? '');
$qs_inst      = trim($_GET['institucion'] ?? ''); 
$qs_orden     = trim($_GET['orden'] ?? ''); //
$orden = trim($_GET['orden'] ?? '');

// [SENSOR NUBIRA] REGISTRO DE ACTIVIDAD TOTAL (Visitas y Búsquedas)
if (file_exists(__DIR__ . '/logger.php')) {
    require_once __DIR__ . '/logger.php';
    
    // 1. Filtro Anti-Bots
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_bot = preg_match('/bot|crawl|spider|slurp|yahoo|mediapartners/i', strtolower($user_agent));
    
    // 2. Solo registramos si NO es un bot
    if (!$is_bot) {
        $guest_hash = substr(md5(session_id()), 0, 8);
        
        if (!empty($qs_q)) {
            // El usuario hizo una BÚSQUEDA
            $termino = substr($qs_q, 0, 100);
            if ($is_guest) {
                registrar_actividad($conn, 0, 'BUSQUEDA_SERVICIOS_GUEST', "Invitado [$guest_hash] buscó: " . $termino);
            } else {
                registrar_actividad($conn, $usuario_id, 'BUSQUEDA_SERVICIOS', "Término: " . $termino);
            }
        } else {
            // El usuario solo entró a MIRAR LA VITRINA DE SERVICIOS
            if ($is_guest) {
                registrar_actividad($conn, 0, 'VER_VITRINA_SERVICIOS_GUEST', "Invitado [$guest_hash] explorando vitrina general de servicios");
            } else {
                registrar_actividad($conn, $usuario_id, 'VER_VITRINA_SERVICIOS', "Explorando vitrina general de servicios");
            }
        }
    }
}

$initial_params = ['pagina' => 1, 'limit' => 12];
if ($qs_q)       $initial_params['q'] = $qs_q;
if ($qs_mod)     $initial_params['modalidad'] = $qs_mod;
if ($qs_orden)   $initial_params['orden'] = $qs_orden; // [NUBIRA 2.0] Se lo pasamos al lazy load
$initial_params['_seed'] = time();

if ($rol === 'admin') {
    if ($qs_inst && strtolower($qs_inst) !== 'todas') $initial_params['institucion'] = $qs_inst;
} else {
    // Si es invitado, por defecto ve todo, igual que un usuario normal que no especifica institución
    if ($qs_inst !== '' && strtolower($qs_inst) !== 'todas') {
        $initial_params['institucion'] = $qs_inst;
    } else {
        $initial_params['ver_todas'] = '1';
    }
}
$initial_src = '/app/cargar_servicios.php?' . http_build_query($initial_params);

// 4. BANNERS
$banners_inicio = [];
$sql = "SELECT id, titulo, imagen, enlace FROM banners WHERE activo = 1 AND posicion = 'inicio'";

// Si es invitado, mostramos todos los banners generales (los que no tienen institución específica o son para la inst. seleccionada)
if ($rol !== 'admin') {
    if ($is_guest) {
        $sql .= " AND (institucion IS NULL OR institucion = '')";
    } else {
        $sql .= " AND (institucion = ? OR institucion IS NULL)";
    }
}
$sql .= " ORDER BY orden ASC";
$stmt = $conn->prepare($sql);

if ($rol !== 'admin' && !$is_guest) {
    $stmt->bind_param("s", $institucion_session);
}

$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $banners_inicio[] = $r;
$stmt->close();

// Instituciones (Admin)
$insts = [];
if ($rol === 'admin') {
    $q = $conn->query("SELECT DISTINCT institucion FROM dominios_permitidos WHERE institucion<>'' ORDER BY institucion");
    while($r = $q->fetch_assoc()) $insts[] = (string)$r['institucion'];
}

// Nav Helper
$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        global $ruta_actual;
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo    = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/clases-servicios') return $base . $activo; 
        return $base . (strpos($ruta_actual, $path) === 0 ? $activo : $inactivo);
    }
}

// Variables SEO Dinámicas
$seo_title = "Clases particulares y tutorías online Chile | Nubira";
$seo_desc = "Tutores universitarios chilenos verificados. Clases particulares en matemáticas, química, programación, idiomas. Pago protegido con Garantía Nubira.";
if (!empty($qs_q)) {
    $seo_title = "Resultados para '" . htmlspecialchars($qs_q) . "' | Servicios Nubira";
    $seo_desc = "Explora los mejores servicios y clases particulares sobre " . htmlspecialchars($qs_q) . " en Nubira.cl.";
}

// [NUBIRA 2.0 PERF] Desbloquear la sesión. Permite que las peticiones AJAX corran en paralelo.
session_write_close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title><?= $seo_title ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>

  <meta name="description" content="<?= $seo_desc ?>" />
  <meta name="keywords" content="clases particulares, servicios universitarios, tutorías, ayudantías, estudiantes, universidad, nubira, chile" />
  <meta property="og:title" content="<?= $seo_title ?>" />
  <meta property="og:description" content="<?= $seo_desc ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:url" content="https://nubira.cl<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>" />
  <meta property="og:image" content="https://nubira.cl/img/logo2.webp" />
  <meta name="robots" content="index, follow" />

  <script src="https://cdn.tailwindcss.com"></script>
  <?php require_once __DIR__ . '/helpers/seo.php'; echo nubira_canonical_tag(); ?>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
    .skeleton { position:relative; overflow:hidden; background:#f9fafb; }
    .skeleton::after { content:""; position:absolute; inset:0; background-image:linear-gradient(90deg, transparent, rgba(255,255,255,.6), transparent); transform:translateX(-100%); animation:shimmer 1.5s infinite; }
    @keyframes shimmer{ 100%{ transform:translateX(100%); } }
  </style>
</head>

<body class="bg-white text-gray-900 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
$page_title = "Explorar Clases y Servicios";
require_once __DIR__ . '/componentes/header.php'; 
?>
<?php require_once __DIR__ . '/componentes/sidebar.php'; ?>

<main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

    <?php if (count($banners_inicio)): ?>
      <div class="mb-6 md:mb-10 rounded-2xl overflow-hidden shadow-sm border border-gray-100">
        <div class="flex overflow-x-auto snap-x snap-mandatory scroll-smooth no-scrollbar gap-0">
          <?php foreach ($banners_inicio as $b): ?>
            <a href="<?= htmlspecialchars($b['enlace'] ?? '#') ?>" class="snap-start flex-shrink-0 w-full relative">
              <img src="/upload/banners/<?= htmlspecialchars($b['imagen']) ?>" 
                   alt="Banner" 
                   class="h-32 md:h-64 w-full object-cover">
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    
      <div class="mb-4">
        <?php if ($qs_orden === 'nuevos'): ?>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Recién publicados ✨</h1>
            <p class="text-sm text-gray-500 mt-1">Explora los últimos servicios y tutorías subidos a la plataforma.</p>
        <?php else: ?>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Explorar Clases y Servicios</h1>
            <?php if($qs_q): ?>
                <p class="text-sm text-gray-500 mt-1">Resultados para "<span class="font-medium text-gray-800"><?= htmlspecialchars($qs_q) ?></span>"</p>
            <?php endif; ?>
        <?php endif; ?>
      </div>

    <div id="contenedor-servicios" 
         class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full min-h-[400px]"
         data-src="<?= htmlspecialchars($initial_src, ENT_QUOTES) ?>">
         
         <?php for($i=0;$i<8;$i++): ?>
           <div class="h-[260px] skeleton w-full bg-gray-100 rounded-2xl border border-gray-100"></div>
         <?php endfor; ?>
    </div>

</main>

<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
<?php require_once __DIR__ . '/componentes/modal_publicar.php'; ?>
<?php require_once __DIR__ . '/componentes/modal_explora.php'; ?>

<script>
// --- PARCHE SAFARI ---
window.addEventListener('pageshow', (event) => {
    if (event.persisted) { window.location.reload(); }
});

// [NUBIRA 2.0 PERF] 1. INICIAR FETCH AL INSTANTE (Pre-fetch paralelo)
// Lanzamos la red antes de que el navegador siquiera termine de leer el HTML.
const urlServicios = "<?= htmlspecialchars($initial_src, ENT_QUOTES) ?>&_seed_js=" + Date.now();
const preCargaServicios = fetch(urlServicios, { headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' } })
    .then(r => r.text())
    .catch(() => null);

// [NUBIRA 2.0 UX] 2. QUITAR LOADER RÁPIDO
// Usamos DOMContentLoaded para revelar la pantalla apenas exista el esqueleto (sin esperar banners pesados).
document.addEventListener("DOMContentLoaded", async () => {
    const l = document.getElementById('loader'); 
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'), 300); }

    // Imprimir los servicios que ya se estaban descargando en segundo plano
    const cont = document.getElementById("contenedor-servicios");
    if(cont) {
        const html = await preCargaServicios;
        if(html && html.trim()) {
            cont.innerHTML = html;
            const last = cont.lastElementChild;
            if(last && window.__scrollObserver) window.__scrollObserver.observe(last);
        } else if(html !== null) {
            cont.innerHTML = '<div class="col-span-full text-center text-slate-400 py-16 font-bold tracking-widest text-xs uppercase">No hay servicios disponibles.</div>';
        } else {
            cont.innerHTML = '<div class="col-span-full text-center text-rose-400 py-16 font-bold tracking-widest text-xs uppercase">Error de red al cargar servicios.</div>';
        }
    }
    
    actualizarBadgeChats(); 
    setInterval(actualizarBadgeChats, 10000);
});

// --- MODALES UNIVERSALES ---
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
    if(!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
    const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
    btn.onclick = (e) => { e.preventDefault(); open(); }; 
    if(close) close.onclick = shut; 
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}

<?php if ($is_guest): ?>
    const btnPublicar = document.getElementById('btn-publicar');
    if(btnPublicar) {
        btnPublicar.onclick = (e) => { 
            e.preventDefault(); 
            window.location.href = '/login?redir=' + encodeURIComponent(window.location.pathname + window.location.search);
        };
    }
<?php else: ?>
    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
<?php endif; ?>

setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

// --- BADGE CHATS ---
async function actualizarBadgeChats() {
    <?php if ($is_guest) echo 'return;'; ?>
    try {
        const res = await fetch('/app/contar_mensajes_nuevos.php');
        const data = await res.json();
        const total = parseInt(data.total || 0);
        ['badge-chats-sidebar', 'badge-chats-bottom'].forEach(id => {
            const el = document.getElementById(id);
            if(el) { 
                if(id === 'badge-chats-sidebar') el.innerText = total;
                total > 0 ? el.classList.remove('hidden') : el.classList.add('hidden'); 
            }
        });
    } catch {}
}

function abrirMisChats() { 
    <?php if ($is_guest): ?>
        window.location.href = '/login?redir=' + encodeURIComponent(window.location.pathname + window.location.search); return;
    <?php endif; ?>
    window.open("/app/mis_chats.php", "mis_chats", "width=440,height=640,resizable=yes,scrollbars=yes"); 
}

// --- SCROLL INFINITO ---
let cargando = false;
let paginaActual = 1;

window.__scrollObserver = new IntersectionObserver(async entries => {
    const e = entries[0];
    if (!e.isIntersecting || cargando) return;
    cargando = true;
    
    const cont = document.getElementById("contenedor-servicios");
    const rawSrc = cont.dataset.src;
    const base = rawSrc.split("?")[0];
    const params = new URLSearchParams(rawSrc.split("?")[1] || "");
    
    paginaActual++;
    params.set("pagina", paginaActual);
    params.set("_seed_scroll", Date.now());

    try {
        const res = await fetch(base + "?" + params.toString());
        const html = await res.text();
        if (html.trim()) {
            window.__scrollObserver.unobserve(e.target);
            cont.insertAdjacentHTML("beforeend", html);
            const newLast = cont.lastElementChild;
            if(newLast) window.__scrollObserver.observe(newLast);
        } else {
            window.__scrollObserver.disconnect();
        }
    } catch {}
    cargando = false;
}, { rootMargin: "400px" });
</script>

</body>
</html>