<?php
/**
 * VISTA: EXPLORAR APUNTES
 * ESTILO: Unificado con clases_servicios.php (Lazy Registration & SEO)
 * FIX NUBIRA 2.0: Huecos en grilla eliminados + Shuffle de 4 horas activado
 * UI: Estilo Flat / Enterprise aplicado
 */
session_start();

// === BLOQUE ANTI-CACHE ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1. SEGURIDAD MODIFICADA (LAZY REGISTRATION)
$is_guest = !isset($_SESSION['usuario_id']);
if ($is_guest) {
    $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];
}

// Carga inteligente de rutas
$app_dir = file_exists(__DIR__ . '/conexion.php') ? __DIR__ : __DIR__ . '/app';
require_once $app_dir . '/conexion.php';

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

require_once $app_dir . '/iconos.php'; 

// 2. DATOS USUARIO
$rol             = $_SESSION['rol'] ?? 'alumno';
$usuario_id      = !$is_guest ? (int)$_SESSION['usuario_id'] : 0;
$nombre_usuario  = $_SESSION['usuario_nombre'] ?? 'Invitado';
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));

// 3. FILTROS Y PARÁMETROS
$qs_q     = trim($_GET['q'] ?? '');
// [FIX NUBIRA] Dejamos el orden vacío por defecto para que el backend despierte el "Shuffle 4 hrs"
$qs_orden = trim($_GET['orden'] ?? ''); 
$qs_nivel = trim($_GET['nivel'] ?? '');
$niveles_validos = ['universitario', 'paes', 'escolar'];
if (!in_array($qs_nivel, $niveles_validos, true)) $qs_nivel = '';

// [NUBIRA] Chips de asignatura — mismo criterio de visibilidad que cargar_apuntes.php.
// Umbral >=5 para no fragmentar en decenas de chips de 1 solo apunte (ver distribución
// real: "Química"/"Química General"/"Química PAES" quedan sueltas sin normalizar, a propósito).
$asignaturas_chips = [];
$stmtAsig = $conn->prepare("
    SELECT ap.asignatura, COUNT(*) AS total
    FROM apuntes ap
    JOIN alumnos al ON al.id = ap.id_alumno
    WHERE ap.publico = 1 AND ap.visible = 1 AND al.visible = 1
      AND ap.asignatura IS NOT NULL AND ap.asignatura != ''
    GROUP BY ap.asignatura
    HAVING total >= 5
    ORDER BY total DESC
");
if ($stmtAsig) {
    $stmtAsig->execute();
    $resAsig = $stmtAsig->get_result();
    while ($ra = $resAsig->fetch_assoc()) $asignaturas_chips[] = $ra;
    $stmtAsig->close();
}

$qs_asignatura = trim($_GET['asignatura'] ?? '');
$asignaturas_validas = array_column($asignaturas_chips, 'asignatura');
if (!in_array($qs_asignatura, $asignaturas_validas, true)) $qs_asignatura = '';

// [SENSOR NUBIRA] REGISTRO DE ACTIVIDAD TOTAL (Visitas y Búsquedas)
if (file_exists($app_dir . '/logger.php')) {
    require_once $app_dir . '/logger.php';
    
    // 1. Filtro Anti-Bots
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_bot = preg_match('/bot|crawl|spider|slurp|yahoo|mediapartners/i', strtolower($user_agent));
    
    // 2. Solo registramos si NO es un bot
    if (!$is_bot) {
        $guest_hash = substr(md5(session_id()), 0, 8);
        
        if (!empty($qs_q)) {
            $termino = substr($qs_q, 0, 100);
            if ($is_guest) {
                registrar_actividad($conn, 0, 'BUSQUEDA_APUNTES_GUEST', "Invitado [$guest_hash] buscó: " . $termino);
            } else {
                registrar_actividad($conn, $usuario_id, 'BUSQUEDA_APUNTES', "Término: " . $termino);
            }
        } else {
            if ($is_guest) {
                registrar_actividad($conn, 0, 'VER_VITRINA_GUEST', "Invitado [$guest_hash] explorando vitrina general");
            } else {
                registrar_actividad($conn, $usuario_id, 'VER_VITRINA_APUNTES', "Explorando vitrina general de apuntes");
            }
        }
    }
}

// Constructor de URL de carga de apuntes
$initial_params = ['pagina' => 1];
if ($qs_orden) $initial_params['orden'] = $qs_orden;
if ($qs_q) $initial_params['q'] = $qs_q;
if ($qs_nivel) $initial_params['nivel'] = $qs_nivel;
if ($qs_asignatura) $initial_params['asignatura'] = $qs_asignatura;
$initial_params['_seed'] = time();
$initial_src = '/app/cargar_apuntes.php?' . http_build_query($initial_params);

// 4. BANNERS
$banners_inicio = [];
$sql = "SELECT id, titulo, imagen, enlace FROM banners WHERE activo = 1 AND posicion = 'inicio'";

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

// Nav Helper
$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        global $ruta_actual;
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo    = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/vitrina-apuntes') return $base . $activo; 
        return $base . (strpos($ruta_actual, $path) === 0 ? $activo : $inactivo);
    }
}

// Variables SEO Dinámicas
$seo_title = "Apuntes universitarios y resúmenes Chile | Nubira";
$seo_desc = "Descarga apuntes y resúmenes de estudiantes chilenos verificados. PAES, cálculo, química, derecho y más. Material filtrado por universidad y ramo.";

if ($qs_nivel === 'paes') {
    $seo_title = "Apuntes PAES | Preparación admisión universitaria | Nubira.cl";
    $seo_desc = "Apuntes, guías y flashcards para la PAES. Material creado por estudiantes para potenciar tu rendimiento en la prueba de admisión.";
} elseif ($qs_nivel === 'escolar') {
    $seo_title = "Apuntes Escolares | Nubira.cl";
    $seo_desc = "Material de estudio escolar creado por estudiantes universitarios.";
}

if (!empty($qs_q)) {
    $seo_title = "Apuntes de '" . htmlspecialchars($qs_q) . "' | Nubira";
    $seo_desc = "Explora material de estudio y apuntes sobre " . htmlspecialchars($qs_q) . " en Nubira.cl.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title><?= $seo_title ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>

  <meta name="description" content="<?= $seo_desc ?>" />
  <meta name="keywords" content="apuntes universitarios, resúmenes, guías de estudio, universidad, estudiantes, material de estudio, nubira, chile" />
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
    .sentinel { grid-column: 1 / -1; pointer-events: none; visibility: hidden; }
  </style>
</head>

<body class="bg-white text-gray-900 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
$page_title = "Explorar Apuntes";
$ocultar_modalidad = true;
require_once $app_dir . '/componentes/header.php'; 
?>
<?php require_once $app_dir . '/componentes/sidebar.php'; ?>

<main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

    <?php if (count($banners_inicio)): ?>
      <div class="mb-6 md:mb-10 rounded-2xl overflow-hidden border border-gray-200">
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

    <div class="mb-6">
    <?php 
    $h1_titulo = "Explorar Apuntes";
    $h1_subtitulo = "";
    if ($qs_nivel === 'paes') {
        $h1_titulo = "Apuntes PAES";
        $h1_subtitulo = "Material para la prueba de admisión universitaria";
    } elseif ($qs_nivel === 'escolar') {
        $h1_titulo = "Apuntes Escolares";
        $h1_subtitulo = "Material de estudio escolar";
    }
    ?>
    <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight"><?= $h1_titulo ?></h1>
    <?php if($h1_subtitulo): ?>
        <p class="text-sm text-gray-500 mt-1"><?= $h1_subtitulo ?></p>
    <?php endif; ?>
    <?php if($qs_q): ?>
        <p class="text-sm text-gray-500 mt-1">Resultados para "<span class="font-medium text-gray-800"><?= htmlspecialchars($qs_q) ?></span>"</p>
    <?php endif; ?>

    <?php if (!empty($asignaturas_chips)): ?>
    <div class="flex flex-wrap gap-2 mt-4" role="group" aria-label="Filtrar por asignatura">
      <?php
        $qs_sin_asignatura = $_GET; unset($qs_sin_asignatura['asignatura']);
        $href_todos = '?' . http_build_query($qs_sin_asignatura);
        $todos_activo = ($qs_asignatura === '');
      ?>
      <a href="<?= htmlspecialchars($href_todos) ?>"
         class="px-3.5 py-1.5 text-xs md:text-sm font-bold rounded-full border transition-colors duration-150 ease-out <?= $todos_activo ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400' ?>">
        Todos
      </a>
      <?php foreach ($asignaturas_chips as $ac):
        $chip_activo = ($qs_asignatura === $ac['asignatura']);
        $qs_chip = $qs_sin_asignatura;
        if (!$chip_activo) $qs_chip['asignatura'] = $ac['asignatura'];
        $href_chip = '?' . http_build_query($qs_chip);
      ?>
      <a href="<?= htmlspecialchars($href_chip) ?>"
         class="px-3.5 py-1.5 text-xs md:text-sm font-bold rounded-full border transition-colors duration-150 ease-out <?= $chip_activo ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400' ?>">
        <?= htmlspecialchars($ac['asignatura']) ?> (<?= (int)$ac['total'] ?>)
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

    <div id="contenedor-apuntes" 
         class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-8 w-full min-h-[400px]"
         data-src="<?= htmlspecialchars($initial_src, ENT_QUOTES) ?>">
         
         <?php for($i=0;$i<8;$i++): ?>
           <div class="aspect-[3/4] skeleton w-full bg-gray-50 rounded-2xl border border-gray-200"></div>
         <?php endfor; ?>
    </div>

</main>

<?php require_once $app_dir . '/componentes/nav_bottom.php'; ?>

<?php if(file_exists($app_dir . '/componentes/modal_publicar.php')) {
    require_once $app_dir . '/componentes/modal_publicar.php'; 
} ?>
<?php if(file_exists($app_dir . '/componentes/modal_explora.php')) {
    require_once $app_dir . '/componentes/modal_explora.php'; 
} ?>

<script>
window.addEventListener('pageshow', (event) => {
    if (event.persisted) { window.location.reload(); }
});

window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

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

document.addEventListener("DOMContentLoaded", async () => {
    const cont = document.getElementById("contenedor-apuntes");
    let url = cont.dataset.src;
    
    if (!url.includes('_seed')) {
        const separator = url.includes('?') ? '&' : '?';
        url += separator + '_seed_js=' + new Date().getTime();
    }

    try {
        const res = await fetch(url, { headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' } });
        const html = await res.text();
        
        if(html.trim()) cont.innerHTML = html;
        else cont.innerHTML = '<div class="col-span-full text-center text-gray-500 py-12">No encontramos apuntes.</div>';
        
        const last = cont.lastElementChild;
        if(last && window.__scrollObserver) window.__scrollObserver.observe(last);
    } catch {
        cont.innerHTML = `<div class="col-span-full text-center text-gray-500 py-10">Error de conexión.</div>`;
    }
});

let cargando = false;
let paginaActual = 1;

window.__scrollObserver = new IntersectionObserver(async entries => {
    const e = entries[0];
    if (!e.isIntersecting || cargando) return;
    cargando = true;
    const cont = document.getElementById("contenedor-apuntes");
    const base = cont.dataset.src.split("?")[0];
    const params = new URLSearchParams(cont.dataset.src.split("?")[1] || "");
    
    paginaActual++;
    params.set("pagina", paginaActual);
    params.set("_seed_scroll", new Date().getTime());

    try {
        const res = await fetch(base + "?" + params.toString());
        const html = await res.text();
        if (html.trim()) {
            window.__scrollObserver.unobserve(e.target);
            
            // [FIX NUBIRA] Destruir el centinela viejo para no romper la grilla
            e.target.remove(); 
            
            cont.insertAdjacentHTML("beforeend", html);
            const newLast = cont.lastElementChild;
            if(newLast) window.__scrollObserver.observe(newLast);
        } else {
            window.__scrollObserver.disconnect();
            e.target.remove(); // Elimina el muro invisible al llegar al final
        }
    } catch {}
    cargando = false;
}, { rootMargin: "400px" });
</script>

</body>
</html>