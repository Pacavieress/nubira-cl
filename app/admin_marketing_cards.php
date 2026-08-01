<?php
/**
 * NUBIRA 2.0 - ADMIN: MARKETING / CARDS
 * Fusión Fase 2: tabs "Servicios" (grilla + carrusel descargable) y "Novedades"
 * (redactar anuncios de plataforma + preview + historial), vía ?tab=servicios|novedades.
 * Recarga real de página al cambiar de tab (sin JS de show/hide) — cada tab ejecuta
 * y renderiza solo su propio bloque PHP/HTML/JS.
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
require_once $app_dir . '/helpers/imagen_compartir.php'; // nb_version_imagen_servicio()

// 4. TAB ACTIVO
$tab = (($_GET['tab'] ?? '') === 'novedades') ? 'novedades' : 'servicios';

if ($tab === 'servicios') {
    // FILTROS (GET, sin AJAX — panel de bajo tráfico, mismo criterio que admin_cuentas.php)
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

    // Preparar hash + URL de imagen por servicio (mismo endpoint que ya usa el sheet de compartir)
    foreach ($servicios as &$s) {
        $hash = function_exists('nubira_encriptar_id') ? nubira_encriptar_id((int)$s['id']) : (string)$s['id'];
        $v = nb_version_imagen_servicio((int)$s['id']);
        $s['img_url'] = "/api/img/servicio/{$hash}/post.jpg?v={$v}";
    }
    unset($s);

    // Opciones de filtro (independientes del filtro activo, para no vaciar el dropdown)
    $categorias_disponibles   = [];
    $resCat = $conn->query("SELECT DISTINCT categoria FROM servicios WHERE estado = 'aprobado' AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC");
    if ($resCat) { while ($r = $resCat->fetch_assoc()) $categorias_disponibles[] = $r['categoria']; }

    $instituciones_disponibles = [];
    $resInst = $conn->query("SELECT DISTINCT institucion FROM servicios WHERE estado = 'aprobado' AND institucion IS NOT NULL AND institucion != '' ORDER BY institucion ASC");
    if ($resInst) { while ($r = $resInst->fetch_assoc()) $instituciones_disponibles[] = $r['institucion']; }
} else {
    // Auto-migración: mismo criterio que admin_guardar_novedad.php / img_novedad.php —
    // nunca asumir que otro archivo ya se ejecutó antes y creó la tabla.
    $conn->query("CREATE TABLE IF NOT EXISTS novedades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(120) NOT NULL,
        cuerpo TEXT NOT NULL,
        icono VARCHAR(10) NULL,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Historial (últimas 50, sin editar/eliminar en esta fase)
    $novedades = [];
    $res = $conn->query("SELECT id, titulo, cuerpo, creado_en FROM novedades ORDER BY creado_en DESC LIMIT 50");
    if ($res) {
        while ($n = $res->fetch_assoc()) {
            $hash = nubira_encriptar_id((int)$n['id']);
            $n['post_url']    = "/api/img/novedad/{$hash}/post.jpg";
            $n['history_url'] = "/api/img/novedad/{$hash}/history.jpg";
            $novedades[] = $n;
        }
    }
}
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
    </div>

    <!-- Tabs -->
    <div class="flex items-center gap-2 mb-6 border-b border-gray-200">
        <a href="/admin/marketing-cards?tab=servicios"
           class="px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors <?= $tab === 'servicios' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            Servicios
        </a>
        <a href="/admin/marketing-cards?tab=novedades"
           class="px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors <?= $tab === 'novedades' ? 'border-[#54A6D8] text-[#54A6D8]' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">
            Novedades
        </a>
    </div>

    <?php if ($tab === 'servicios'): ?>

        <p class="text-gray-500 text-sm mt-1 mb-4">
            Selecciona servicios y arma un carrusel de imágenes para redes sociales. Total con estos filtros: <strong><?= $total_servicios ?></strong>
        </p>

        <!-- Barra de filtros -->
        <form method="GET" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-6">
            <input type="hidden" name="tab" value="servicios">
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
                <a href="/admin/marketing-cards?tab=servicios" class="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-500 text-sm font-bold hover:bg-gray-50 transition-colors">
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

    <?php else: ?>

        <div class="max-w-[1100px] mx-auto">
            <p class="text-gray-500 text-sm mt-1 mb-4">Redacta anuncios de plataforma y genera sus imágenes para redes sociales.</p>

            <!-- Formulario nueva novedad -->
            <section class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-8">
                <h2 class="text-base font-bold text-gray-900 mb-4">Nueva novedad</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Título</label>
                        <input id="f-titulo" type="text" maxlength="120" placeholder="Ej: Nuevo: Métricas para tus publicaciones"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none"
                               oninput="document.getElementById('f-counter-titulo').textContent = this.value.length + ' / 120'">
                        <p id="f-counter-titulo" class="text-[11px] text-gray-400 text-right mt-1">0 / 120</p>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Cuerpo</label>
                        <textarea id="f-cuerpo" maxlength="280" rows="4" placeholder="Describe la novedad en un par de frases..."
                                  class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none resize-none"
                                  oninput="document.getElementById('f-counter-cuerpo').textContent = this.value.length + ' / 280'"></textarea>
                        <p id="f-counter-cuerpo" class="text-[11px] text-gray-400 text-right mt-1">0 / 280</p>
                    </div>

                    <p id="f-error" class="hidden text-sm text-red-600 bg-red-50 border border-red-100 rounded-xl px-4 py-2.5"></p>

                    <button id="btn-guardar" type="button"
                            class="px-5 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Guardar y generar imágenes
                    </button>
                </div>
            </section>

            <!-- Preview de la novedad recién creada -->
            <section id="preview-novedad" class="hidden bg-white border border-gray-100 rounded-2xl shadow-sm p-6 mb-8">
                <h2 class="text-base font-bold text-gray-900 mb-4">Imágenes generadas</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div class="text-center">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">Post (4:5)</p>
                        <img id="preview-post-img" src="" alt="Preview POST" class="w-full max-w-[320px] mx-auto rounded-xl border border-gray-100 aspect-[4/5] object-cover bg-gray-50">
                        <div class="flex items-center justify-center gap-2 mt-3">
                            <button type="button" class="btn-compartir px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5" data-formato="post">
                                <i class="fa-solid fa-share-nodes"></i> Compartir
                            </button>
                            <a id="preview-post-descarga" href="" download class="px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5">
                                <i class="fa-solid fa-download"></i> Descargar
                            </a>
                        </div>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">History (9:16)</p>
                        <img id="preview-history-img" src="" alt="Preview HISTORY" class="w-full max-w-[220px] mx-auto rounded-xl border border-gray-100 aspect-[9/16] object-cover bg-gray-50">
                        <div class="flex items-center justify-center gap-2 mt-3">
                            <button type="button" class="btn-compartir px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5" data-formato="history">
                                <i class="fa-solid fa-share-nodes"></i> Compartir
                            </button>
                            <a id="preview-history-descarga" href="" download class="px-3 py-2 rounded-xl border border-gray-200 text-[#54A6D8] hover:bg-blue-50 text-xs font-bold flex items-center gap-1.5">
                                <i class="fa-solid fa-download"></i> Descargar
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Historial -->
            <section>
                <h2 class="text-base font-bold text-gray-900 mb-4">Historial</h2>
                <ul id="historial-lista" class="space-y-2">
                    <?php foreach ($novedades as $n): ?>
                        <li class="flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3"
                            data-post-url="<?= htmlspecialchars($n['post_url'], ENT_QUOTES, 'UTF-8') ?>"
                            data-history-url="<?= htmlspecialchars($n['history_url'], ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int)$n['id'] ?>">
                            <img src="<?= htmlspecialchars($n['post_url'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async"
                                 alt="" class="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-gray-50 shrink-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-gray-800 truncate"><?= htmlspecialchars($n['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-[10px] text-gray-400"><?= date('d/m/Y H:i', strtotime($n['creado_en'])) ?></p>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button type="button" class="btn-compartir-historial w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Compartir POST" aria-label="Compartir POST" data-formato="post">
                                    <i class="fa-solid fa-share-nodes text-xs"></i>
                                </button>
                                <a href="<?= htmlspecialchars($n['post_url'], ENT_QUOTES, 'UTF-8') ?>" download="nubira-novedad-<?= (int)$n['id'] ?>-post.jpg"
                                   class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar POST" aria-label="Descargar POST">
                                    <i class="fa-solid fa-download text-xs"></i>
                                </a>
                                <a href="<?= htmlspecialchars($n['history_url'], ENT_QUOTES, 'UTF-8') ?>" download="nubira-novedad-<?= (int)$n['id'] ?>-history.jpg"
                                   class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar HISTORY" aria-label="Descargar HISTORY">
                                    <i class="fa-solid fa-file-arrow-down text-xs"></i>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (empty($novedades)): ?>
                    <div class="bg-white border border-dashed border-gray-200 rounded-2xl p-12 text-center text-gray-400 text-sm">
                        Todavía no hay novedades creadas.
                    </div>
                <?php endif; ?>
            </section>
        </div>

    <?php endif; ?>

</main>

<?php if ($tab === 'servicios'): ?>
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
<?php endif; ?>

<?php
if (file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php';
if ($tab === 'servicios') require_once $app_dir . '/componentes/modal_carrusel_marketing.php';
?>

<script>
<?php if ($tab === 'servicios'): ?>
(function () {
    const checkAll   = document.getElementById('check-all');
    const rowChecks  = () => [...document.querySelectorAll('.mkt-check')];
    const actionBar  = document.getElementById('mkt-action-bar');
    const barCount   = document.getElementById('mkt-bar-count');
    const barPlural  = document.getElementById('mkt-bar-plural');
    const btnCarrusel = document.getElementById('mkt-btn-carrusel');
    const navBottom  = document.getElementById('nav-bottom');

    function syncBar() {
        const marcados = rowChecks().filter(c => c.checked);
        const n = marcados.length;
        barCount.textContent = n;
        barPlural.textContent = n === 1 ? 'servicio' : 'servicios';
        actionBar.classList.toggle('hidden', n === 0);
        // Evita que nav_bottom (z-[60]) tape la barra de selección (z-40) en móvil —
        // ambas son fixed bottom-0 de ancho completo y compiten por el mismo espacio.
        // Se oculta solo mientras hay selección activa; navegación normal intacta el resto del tiempo.
        if (navBottom) navBottom.classList.toggle('hidden', n > 0);
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
<?php else: ?>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

(function () {
    const fTitulo = document.getElementById('f-titulo');
    const fCuerpo = document.getElementById('f-cuerpo');
    const fError = document.getElementById('f-error');
    const btnGuardar = document.getElementById('btn-guardar');

    const preview = document.getElementById('preview-novedad');
    const previewPostImg = document.getElementById('preview-post-img');
    const previewHistoryImg = document.getElementById('preview-history-img');
    const previewPostDescarga = document.getElementById('preview-post-descarga');
    const previewHistoryDescarga = document.getElementById('preview-history-descarga');

    const historialLista = document.getElementById('historial-lista');

    function mostrarError(msg) {
        fError.textContent = msg;
        fError.classList.remove('hidden');
    }
    function limpiarError() {
        fError.classList.add('hidden');
        fError.textContent = '';
    }

    function crearItemHistorial(n) {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3';
        li.dataset.postUrl = n.post_url;
        li.dataset.historyUrl = n.history_url;
        li.dataset.id = n.id;
        li.innerHTML = `
            <img src="${n.post_url}" loading="lazy" decoding="async" alt="" class="w-14 h-14 rounded-lg object-cover border border-gray-200 bg-gray-50 shrink-0">
            <div class="flex-1 min-w-0">
                <p class="text-xs font-bold text-gray-800 truncate">${n.titulo}</p>
                <p class="text-[10px] text-gray-400">${n.fecha}</p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn-compartir-historial w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Compartir POST" aria-label="Compartir POST" data-formato="post">
                    <i class="fa-solid fa-share-nodes text-xs"></i>
                </button>
                <a href="${n.post_url}" download="nubira-novedad-${n.id}-post.jpg" class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar POST" aria-label="Descargar POST">
                    <i class="fa-solid fa-download text-xs"></i>
                </a>
                <a href="${n.history_url}" download="nubira-novedad-${n.id}-history.jpg" class="w-9 h-9 rounded-full bg-white border border-gray-200 text-[#54A6D8] hover:bg-blue-50 flex items-center justify-center transition-colors" title="Descargar HISTORY" aria-label="Descargar HISTORY">
                    <i class="fa-solid fa-file-arrow-down text-xs"></i>
                </a>
            </div>
        `;
        return li;
    }

    btnGuardar.addEventListener('click', async () => {
        limpiarError();
        const titulo = fTitulo.value.trim();
        const cuerpo = fCuerpo.value.trim();

        if (!titulo || titulo.length > 120) { mostrarError('El título es obligatorio y debe tener máximo 120 caracteres.'); return; }
        if (!cuerpo || cuerpo.length > 280) { mostrarError('El cuerpo es obligatorio y debe tener máximo 280 caracteres.'); return; }

        btnGuardar.disabled = true;
        btnGuardar.classList.add('opacity-60');

        try {
            const fd = new FormData();
            fd.append('titulo', titulo);
            fd.append('cuerpo', cuerpo);
            fd.append('csrf_token', CSRF_TOKEN);

            const r = await fetch('/app/admin_guardar_novedad.php', { method: 'POST', body: fd });
            const data = await r.json();

            if (!data.success) {
                mostrarError(data.error || 'No se pudo guardar la novedad.');
                return;
            }

            // Preview
            previewPostImg.src = data.post_url;
            previewHistoryImg.src = data.history_url;
            previewPostDescarga.href = data.post_url;
            previewPostDescarga.setAttribute('download', `nubira-novedad-${data.id}-post.jpg`);
            previewHistoryDescarga.href = data.history_url;
            previewHistoryDescarga.setAttribute('download', `nubira-novedad-${data.id}-history.jpg`);
            preview.classList.remove('hidden');
            preview.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Historial: se agrega arriba de la lista, sin recargar la página
            const ahora = new Date();
            const fecha = ahora.toLocaleDateString('es-CL') + ' ' + ahora.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
            historialLista.prepend(crearItemHistorial({
                id: data.id, titulo, fecha,
                post_url: data.post_url, history_url: data.history_url,
            }));

            // Reset del formulario
            fTitulo.value = '';
            fCuerpo.value = '';
            document.getElementById('f-counter-titulo').textContent = '0 / 120';
            document.getElementById('f-counter-cuerpo').textContent = '0 / 280';
        } catch (e) {
            mostrarError('Error de conexión. Intenta de nuevo.');
        } finally {
            btnGuardar.disabled = false;
            btnGuardar.classList.remove('opacity-60');
        }
    });

    // Compartir (preview grande + historial): fetch+Blob para navigator.share(), mismo
    // patrón que modal_carrusel_marketing.php. Si no hay soporte, cae a click() del <a download>.
    async function compartir(url, filename, tituloCompartir) {
        if (typeof navigator.share !== 'function' || typeof navigator.canShare !== 'function') {
            descargarDirecto(url, filename);
            return;
        }
        try {
            const resp = await fetch(url);
            const blob = await resp.blob();
            const file = new File([blob], filename, { type: blob.type || 'image/jpeg' });
            if (navigator.canShare({ files: [file] })) {
                await navigator.share({ files: [file], title: tituloCompartir });
                return;
            }
        } catch (err) {
            if (err && err.name === 'AbortError') return;
        }
        descargarDirecto(url, filename);
    }

    function descargarDirecto(url, filename) {
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    document.addEventListener('click', (e) => {
        const btnPreview = e.target.closest('#preview-novedad .btn-compartir');
        if (btnPreview) {
            const formato = btnPreview.dataset.formato;
            const img = formato === 'post' ? previewPostImg : previewHistoryImg;
            compartir(img.src, `nubira-novedad-${formato}.jpg`, fTitulo.value || 'Novedad Nubira');
            return;
        }
        const btnHist = e.target.closest('.btn-compartir-historial');
        if (btnHist) {
            const li = btnHist.closest('li');
            const formato = btnHist.dataset.formato;
            const url = formato === 'post' ? li.dataset.postUrl : li.dataset.historyUrl;
            const titulo = li.querySelector('p.font-bold')?.textContent || 'Novedad Nubira';
            compartir(url, `nubira-novedad-${li.dataset.id}-${formato}.jpg`, titulo);
        }
    });
})();
<?php endif; ?>
</script>

</body>
</html>
