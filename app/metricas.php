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

$uid = (int)$_SESSION['usuario_id'];

// ── Publicaciones del usuario ──────────────────────────────────────────────

$publicaciones = [];

$stmt = $conn->prepare("SELECT id, titulo, precio, imagen, fecha_publicacion FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND visible = 1 ORDER BY fecha_publicacion DESC LIMIT 60");
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

$stmt = $conn->prepare("SELECT id, titulo, precio, portada, fecha_subida FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND visible = 1 ORDER BY fecha_subida DESC LIMIT 60");
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

// ── Visitas 30d por publicación ────────────────────────────────────────────

$stmt_v = $conn->prepare("SELECT COUNT(*) as total FROM vistas_detalle WHERE tipo = ? AND publicacion_id = ? AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
foreach ($publicaciones as &$pub) {
    $pub['visitas_30d'] = 0;
    if ($stmt_v) {
        $t = $pub['tipo']; $i = $pub['id'];
        $stmt_v->bind_param("si", $t, $i);
        $stmt_v->execute();
        $pub['visitas_30d'] = (int)($stmt_v->get_result()->fetch_assoc()['total'] ?? 0);
    }
}
unset($pub);
if ($stmt_v) $stmt_v->close();
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
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-16 pb-32 md:pb-12 lg:ml-64 mx-auto max-w-[720px] fade-in">

  <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-4">
    <h1 class="text-xl font-extrabold text-gray-900 tracking-tight">Métricas</h1>
    <p class="text-xs text-gray-400 mt-0.5">Últimos 30 días</p>
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
            $img_url = !empty($pub['imagen'])  ? '/upload/servicios/' . basename($pub['imagen'])  : '/img/logo2.webp';
        } else {
            $img_url = !empty($pub['portada']) ? '/upload/portadas/'  . basename($pub['portada']) : '/img/logo2.webp';
        }

        $badge_label = $tipo === 'servicio' ? 'TUTORÍA' : 'APUNTE';
        $href = '/app/metricas_detalle.php?tipo=' . urlencode($tipo) . '&id=' . (int)$pub['id'];
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

<?php require_once $app_dir . '/componentes/nav_bottom.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const l = document.getElementById('loader');
    if (l) { l.style.opacity = '0'; setTimeout(() => l.style.display = 'none', 300); }
});
</script>
</body>
</html>
