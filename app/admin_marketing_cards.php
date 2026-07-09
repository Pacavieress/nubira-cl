<?php
/**
 * NUBIRA 2.0 - ADMIN: MARKETING / CARDS
 * Grilla filtrable de servicios aprobados, reutilizando img_servicio.php (imagen tipo POST)
 * para armar un carrusel de imágenes descargables para redes sociales.
 */

// 1. DETECCIÓN INTELIGENTE DE RUTA
if (file_exists(__DIR__ . '/init_sesion.php')) {
    require_once __DIR__ . '/init_sesion.php';
    $app_dir = __DIR__;
} else {
    require_once __DIR__ . '/app/init_sesion.php';
    $app_dir = __DIR__ . '/app';
}

require_once $app_dir . '/iconos.php';

// 2. CANDADO ESTRICTO DE SESIÓN
if (function_exists('proteger_ruta')) {
    proteger_ruta();
} else {
    die("Error de seguridad: No se pudo cargar el control de sesión.");
}

// 2.5 CANDADO DE ROL ADMIN
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login"); exit;
}

// 3. CONEXIÓN Y HELPERS
if (!isset($conn)) require_once $app_dir . '/conexion.php';
require_once $app_dir . '/seguridad_url.php'; // nubira_encriptar_id()

// 4. FILTROS (GET, sin AJAX — panel de bajo tráfico, mismo criterio que admin_cuentas.php)
$filtro_categoria   = trim($_GET['categoria'] ?? '');
$filtro_institucion = trim($_GET['institucion'] ?? '');
$filtro_con_video   = ($_GET['con_video'] ?? '') === '1';
$filtro_fecha_desde = trim($_GET['fecha_desde'] ?? '');
$filtro_fecha_hasta = trim($_GET['fecha_hasta'] ?? '');

$condicion    = ["s.estado = 'aprobado'", "COALESCE(s.visible,1) = 1"];
$param_types  = '';
$param_values = [];

if ($filtro_categoria !== '') {
    $condicion[]    = 's.categoria = ?';
    $param_types   .= 's';
    $param_values[] = $filtro_categoria;
}
if ($filtro_institucion !== '') {
    $condicion[]    = 's.institucion = ?';
    $param_types   .= 's';
    $param_values[] = $filtro_institucion;
}
if ($filtro_con_video) {
    $condicion[] = "s.video_estado = 'aprobado'";
}
if ($filtro_fecha_desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_fecha_desde)) {
    $condicion[]    = 's.fecha_publicacion >= ?';
    $param_types   .= 's';
    $param_values[] = $filtro_fecha_desde . ' 00:00:00';
}
if ($filtro_fecha_hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_fecha_hasta)) {
    $condicion[]    = 's.fecha_publicacion <= ?';
    $param_types   .= 's';
    $param_values[] = $filtro_fecha_hasta . ' 23:59:59';
}

$where = 'WHERE ' . implode(' AND ', $condicion);

$sql = "SELECT s.id, s.titulo, s.categoria, s.institucion, s.fecha_publicacion, s.video_estado,
               a.nombre AS tutor_nombre
        FROM servicios s
        JOIN alumnos a ON s.alumno_id = a.id
        $where
        ORDER BY s.fecha_publicacion DESC";

$stmt = $conn->prepare($sql);
if ($param_types !== '') {
    $stmt->bind_param($param_types, ...$param_values);
}
$stmt->execute();
$resultado = $stmt->get_result();
$servicios = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
$total_servicios = count($servicios);

// 5. Preparar hash + URL de imagen por servicio (mismo endpoint que ya usa el sheet de compartir)
foreach ($servicios as &$s) {
    $hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id((int)$s['id']) : (string)$s['id'];
    $s['img_url'] = "/api/img/servicio/{$hash}/post.jpg";
}
unset($s);

// 6. Opciones de filtro (independientes del filtro activo, para no vaciar el dropdown)
$categorias_disponibles   = [];
$resCat = $conn->query("SELECT DISTINCT categoria FROM servicios WHERE estado = 'aprobado' AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
if ($resCat) { while ($r = $resCat->fetch_assoc()) $categorias_disponibles[] = $r['categoria']; }

$instituciones_disponibles = [];
$resInst = $conn->query("SELECT DISTINCT institucion FROM servicios WHERE estado = 'aprobado' AND institucion IS NOT NULL AND institucion != '' ORDER BY institucion ASC");
if ($resInst) { while ($r = $resInst->fetch_assoc()) $instituciones_disponibles[] = $r['institucion']; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin - Marketing / Cards | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
  </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden selection:bg-blue-100 selection:text-blue-700">

<?php
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-20 pb-40 md:pb-24 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

    <div class="mb-6">
        <span class="inline-block py-1 px-3 rounded-full bg-blue-50 text-[#54A6D8] text-[10px] md:text-xs font-bold mb-2 border border-blue-100">
            🛡️ Panel de Administración
        </span>
        <h1 class="text-3xl font-bold text-gray-900 tracking-tight">Marketing / Cards</h1>
        <p class="text-gray-500 text-sm mt-1">
            Selecciona servicios y arma un carrusel de imágenes para redes sociales. Total con estos filtros: <strong><?= $total_servicios ?></strong>
        </p>
    </div>

    <!-- Barra de filtros -->
    <form method="GET" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Categoría</label>
                <select name="categoria" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none">
                    <option value="">Todas</option>
                    <?php foreach ($categorias_disponibles as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $filtro_categoria === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Institución</label>
                <select name="institucion" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none">
                    <option value="">Todas</option>
                    <?php foreach ($instituciones_disponibles as $i): ?>
                        <option value="<?= htmlspecialchars($i) ?>" <?= $filtro_institucion === $i ? 'selected' : '' ?>><?= htmlspecialchars($i) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Desde</label>
                <input type="date" name="fecha_desde" value="<?= htmlspecialchars($filtro_fecha_desde) ?>"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Hasta</label>
                <input type="date" name="fecha_hasta" value="<?= htmlspecialchars($filtro_fecha_hasta) ?>"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none">
            </div>
            <div class="flex items-end gap-2">
                <label class="inline-flex items-center gap-2 px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white cursor-pointer select-none w-full">
                    <input type="checkbox" name="con_video" value="1" <?= $filtro_con_video ? 'checked' : '' ?> class="w-4 h-4 rounded accent-[#54A6D8]">
                    Solo con video
                </label>
            </div>
        </div>
        <div class="flex items-center gap-3 mt-3">
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors">
                Filtrar
            </button>
            <a href="/admin/marketing-cards" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-500 text-sm font-bold hover:bg-gray-50 transition-colors">
                Limpiar filtros
            </a>
        </div>
    </form>

    <!-- Control de selección -->
    <?php if ($total_servicios > 0): ?>
    <div class="flex items-center gap-3 mb-4">
        <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
            <input type="checkbox" id="check-all" class="w-4 h-4 rounded accent-[#54A6D8] cursor-pointer">
            Seleccionar todos los visibles
        </label>
    </div>
    <?php endif; ?>

    <!-- Grilla de cards -->
    <?php if ($total_servicios === 0): ?>
        <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400">
            No hay servicios que coincidan con estos filtros.
        </div>
    <?php else: ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            <?php foreach ($servicios as $s): ?>
                <div class="mkt-card relative bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden group"
                     data-id="<?= (int)$s['id'] ?>"
                     data-img-url="<?= htmlspecialchars($s['img_url'], ENT_QUOTES, 'UTF-8') ?>"
                     data-titulo="<?= htmlspecialchars($s['titulo'], ENT_QUOTES, 'UTF-8') ?>">

                    <label class="absolute top-2 left-2 z-10 w-6 h-6 rounded-md bg-white/90 backdrop-blur-sm border border-gray-200 flex items-center justify-center cursor-pointer shadow-sm">
                        <input type="checkbox" class="mkt-check w-4 h-4 rounded accent-[#54A6D8] cursor-pointer" value="<?= (int)$s['id'] ?>">
                    </label>

                    <?php if ($s['video_estado'] === 'aprobado'): ?>
                        <span class="absolute top-2 right-2 z-10 bg-black/60 text-white text-[9px] font-bold uppercase tracking-wide px-2 py-1 rounded-full flex items-center gap-1">
                            <i class="fa-solid fa-video"></i> Video
                        </span>
                    <?php endif; ?>

                    <div class="w-full aspect-square bg-gray-100">
                        <img src="<?= htmlspecialchars($s['img_url'], ENT_QUOTES, 'UTF-8') ?>"
                             loading="lazy" decoding="async" alt="<?= htmlspecialchars($s['titulo'], ENT_QUOTES, 'UTF-8') ?>"
                             class="w-full h-full object-cover">
                    </div>

                    <div class="p-3">
                        <p class="text-xs font-bold text-gray-900 line-clamp-2 leading-snug mb-1"><?= htmlspecialchars($s['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars($s['tutor_nombre'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-[9px] font-bold uppercase tracking-wide text-[#54A6D8] bg-blue-50 border border-blue-100 px-2 py-0.5 rounded-full truncate max-w-[70%]"><?= htmlspecialchars($s['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-[9px] text-gray-400 shrink-0"><?= date('d/m/Y', strtotime($s['fecha_publicacion'])) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<!-- Barra de selección fija -->
<div id="mkt-action-bar" class="hidden fixed bottom-0 left-0 right-0 lg:left-64 z-40 bg-white border-t border-gray-200 shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
    <div class="max-w-[1600px] mx-auto px-4 md:px-8 py-4 flex items-center justify-between gap-4">
        <p class="text-sm font-bold text-gray-700">
            <span id="mkt-bar-count">0</span> <span id="mkt-bar-plural">servicios</span> seleccionados
        </p>
        <button type="button" id="mkt-btn-carrusel"
                class="px-5 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors flex items-center gap-2">
            <i class="fa-solid fa-images"></i> Ver como carrusel
        </button>
    </div>
</div>

<?php
if (file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_carrusel_marketing.php';
?>

<script>
(function () {
    const checkAll   = document.getElementById('check-all');
    const rowChecks  = () => [...document.querySelectorAll('.mkt-check')];
    const actionBar  = document.getElementById('mkt-action-bar');
    const barCount   = document.getElementById('mkt-bar-count');
    const barPlural  = document.getElementById('mkt-bar-plural');
    const btnCarrusel = document.getElementById('mkt-btn-carrusel');

    function syncBar() {
        const marcados = rowChecks().filter(c => c.checked);
        const n = marcados.length;
        barCount.textContent = n;
        barPlural.textContent = n === 1 ? 'servicio' : 'servicios';
        actionBar.classList.toggle('hidden', n === 0);
    }

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            rowChecks().forEach(c => { c.checked = checkAll.checked; });
            syncBar();
        });
    }

    document.querySelectorAll('.mkt-check').forEach(c => c.addEventListener('change', syncBar));

    btnCarrusel.addEventListener('click', () => {
        const items = rowChecks()
            .filter(c => c.checked)
            .map(c => {
                const card = c.closest('.mkt-card');
                return {
                    id: card.dataset.id,
                    url: card.dataset.imgUrl,
                    titulo: card.dataset.titulo,
                };
            });

        if (items.length === 0) return;

        if (typeof window.abrirCarruselMarketing === 'function') {
            window.abrirCarruselMarketing(items);
        } else {
            // [PENDIENTE] modal_carrusel_marketing.php aún no está incluido
            console.warn('abrirCarruselMarketing() no está definida todavía — falta incluir modal_carrusel_marketing.php');
        }
    });
})();
</script>

</body>
</html>
