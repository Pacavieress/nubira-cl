<?php
/**
 * VISTA: MÉTRICAS — LISTA DE PUBLICACIONES
 * ARQUITECTURA: NUBIRA 2.0 (Estricto)
 */
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php'))  $app_dir .= '/app';
    elseif (file_exists(dirname($app_dir) . '/app'))  $app_dir  = dirname($app_dir) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';
require_once $app_dir . '/helpers/portada_helper.php';
require_once $app_dir . '/helpers/imagen_servicio.php'; // [BANCO] resolver unificado de servicios

$uid = (int)$_SESSION['usuario_id'];

// ── Publicaciones del usuario ──────────────────────────────────────────────

$publicaciones = [];

$stmt = $conn->prepare("SELECT s.id, s.titulo, s.precio, s.imagen, s.imagen_banco_id, s.categoria, s.fecha_publicacion, bi.archivo AS banco_archivo FROM servicios s LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id WHERE s.alumno_id = ? AND s.estado = 'aprobado' AND s.visible = 1 ORDER BY s.fecha_publicacion DESC LIMIT 60");
if ($stmt) {
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['tipo']        = 'servicio';
        $row['fecha_orden'] = strtotime($row['fecha_publicacion'] ?? 'now');
        $publicaciones[]    = $row;
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT id, titulo, precio, portada, preview, archivo, fecha_subida FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND visible = 1 ORDER BY fecha_subida DESC LIMIT 60");
if ($stmt) {
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['tipo']        = 'apunte';
        $row['fecha_orden'] = strtotime($row['fecha_subida'] ?? 'now');
        $publicaciones[]    = $row;
    }
    $stmt->close();
}

usort($publicaciones, fn($a, $b) => $b['fecha_orden'] - $a['fecha_orden']);

// ── Visitas 30d por publicación (agregado por tipo, evita N+1) ─────────────

$visitas_map = [];
foreach (['servicio', 'apunte'] as $tipo_v) {
    $ids = array_values(array_map(fn($p) => (int)$p['id'], array_filter($publicaciones, fn($p) => $p['tipo'] === $tipo_v)));
    if (empty($ids)) continue;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt_v = $conn->prepare("SELECT publicacion_id, COUNT(*) as total FROM vistas_detalle WHERE tipo = ? AND publicacion_id IN ($placeholders) AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY publicacion_id");
    if ($stmt_v) {
        $types  = 's' . str_repeat('i', count($ids));
        $params = array_merge([$types], [$tipo_v], $ids);
        $tmp = [];
        foreach ($params as $k => $v) $tmp[$k] = &$params[$k];
        call_user_func_array([$stmt_v, 'bind_param'], $tmp);
        $stmt_v->execute();
        $res_v = $stmt_v->get_result();
        while ($row = $res_v->fetch_assoc()) {
            $visitas_map[$tipo_v . ':' . $row['publicacion_id']] = (int)$row['total'];
        }
        $stmt_v->close();
    }
}

foreach ($publicaciones as &$pub) {
    $pub['visitas_30d'] = $visitas_map[$pub['tipo'] . ':' . $pub['id']] ?? 0;
}
unset($pub);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Métricas | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, user-scalable=0" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; -webkit-tap-highlight-color: transparent; }
        .fade-in { animation: fi 0.35s ease-out forwards; }
        @keyframes fi { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="text-gray-900 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-9 w-9 border-4 border-gray-100 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php
// [NUBIRA 2.0] Ocultar header global en móvil — mismo patrón que las demás páginas de gestión
echo '<div class="hidden md:block">';
require_once $app_dir . '/componentes/header.php';
echo '</div>';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-4 md:pt-16 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[720px] fade-in">

  <div class="sticky top-0 md:top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-4">
    <div class="flex items-center gap-3">
      <button type="button" onclick="navegacionSeguraNubira()"
              class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
              aria-label="Volver">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-gray-700">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
      </button>
      <div>
        <h1 class="text-xl font-extrabold text-gray-900 tracking-tight">Métricas</h1>
        <p class="text-xs text-gray-400 mt-0.5">Últimos 30 días</p>
      </div>
    </div>
  </div>

  <div class="px-4 md:px-6 mt-4 space-y-2">

    <?php if (empty($publicaciones)): ?>
    <div class="flex flex-col items-center justify-center py-20 text-center bg-white rounded-2xl border border-dashed border-gray-200 mt-4">
      <p class="font-semibold text-gray-700 text-sm">Aún no tienes publicaciones aprobadas</p>
      <p class="text-xs text-gray-400 mt-1">Cuando publiques una tutoría o apunte aparecerá aquí.</p>
    </div>

    <?php else: foreach ($publicaciones as $pub):
        $tipo = $pub['tipo'];

        if ($tipo === 'servicio') {
            $img_url = url_portada($pub); // [BANCO] banco → legacy → placeholder (ignora imagen_estado)
        } else {
            $img_url = obtenerMiniaturaApunte($pub['id'], $pub['portada'] ?? '', $pub['preview'] ?? '', $pub['archivo'] ?? '');
        }

        $badge_label = $tipo === 'servicio' ? 'TUTORÍA' : 'APUNTE';
        $href = '/metricas/' . urlencode($tipo) . '/' . (int)$pub['id'];
    ?>
    <a href="<?= $href ?>" class="flex items-center gap-4 bg-white rounded-2xl border border-gray-100 px-4 py-3 hover:border-gray-200 hover:shadow-sm hover:scale-[1.005] active:scale-[0.998] transition-all duration-150 group">

      <img src="<?= htmlspecialchars($img_url) ?>"
           class="w-14 h-14 rounded-xl object-cover shrink-0 bg-gray-100"
           loading="lazy" alt=""
           onerror="this.src='/img/logo2.webp'">

      <div class="flex-1 min-w-0">
        <span class="inline-block text-[9px] font-bold tracking-widest px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-500 mb-1"><?= $badge_label ?></span>
        <p class="font-semibold text-gray-900 text-sm leading-snug truncate"><?= htmlspecialchars($pub['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-xs text-gray-400 mt-0.5">
          <?php if (!empty($pub['precio'])): ?>CLP <?= number_format((int)$pub['precio'], 0, ',', '.') ?> &nbsp;·&nbsp; <?php endif; ?>
          <?= $pub['visitas_30d'] ?> visitas · últimos 30 días
        </p>
      </div>

      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-gray-300 group-hover:text-gray-400 shrink-0 transition-colors">
        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
      </svg>

    </a>
    <?php endforeach; endif; ?>

  </div>
</main>

<?php
require_once $app_dir . '/componentes/nav_bottom.php';
require_once $app_dir . '/componentes/modal_publicar.php';
require_once $app_dir . '/componentes/modal_explora.php';
?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const l = document.getElementById('loader');
    if (l) { l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 300); }

    // [NUBIRA 2.0] Volver — mismo patrón que las demás páginas de gestión, con fallback
    // a /perfil (tile "Métricas" en panel_gestion.php).
    window.navegacionSeguraNubira = function() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '/perfil';
        }
    };

    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
        if (!btn || !modal) return;
        const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
        const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
        btn.onclick = (e) => { e.preventDefault(); open(); };
        if (close) close.onclick = shut;
        modal.onclick = (e) => { if (e.target === modal) shut(); };
    }
    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});
</script>
</body>
</html>
