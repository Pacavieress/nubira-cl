<?php
/**
 * VISTA: MÉTRICAS — DETALLE DE PUBLICACIÓN
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

$uid  = (int)$_SESSION['usuario_id'];
$tipo = $_GET['tipo'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($tipo, ['servicio', 'apunte'], true) || $id <= 0) {
    header("Location: /app/metricas.php"); exit;
}

// ── Verificar propiedad ────────────────────────────────────────────────────

$pub = null;
if ($tipo === 'servicio') {
    $stmt = $conn->prepare("SELECT id, titulo, precio, imagen FROM servicios WHERE id = ? AND alumno_id = ? AND estado = 'aprobado' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid);
        $stmt->execute();
        $pub = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT id, titulo, precio, portada FROM apuntes WHERE id = ? AND id_alumno = ? AND estado = 'aprobado' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $id, $uid);
        $stmt->execute();
        $pub = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$pub) { header("Location: /app/metricas.php"); exit; }

// ── Stats principales ──────────────────────────────────────────────────────

$stats = ['visitas_30d' => 0, 'visitas_total' => 0, 'tiempo_promedio' => 0, 'pct_leyo' => 0.0];

$stmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        COALESCE(ROUND(AVG(tiempo_segundos)), 0) as tiempo_prom,
        COALESCE(ROUND(SUM(leyo_completo) / COUNT(*) * 100, 1), 0) as pct_leyo
    FROM vistas_detalle
    WHERE tipo = ? AND publicacion_id = ?
      AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
if ($stmt) {
    $stmt->bind_param("si", $tipo, $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stats['visitas_30d']     = (int)$r['total'];
    $stats['tiempo_promedio'] = (int)$r['tiempo_prom'];
    $stats['pct_leyo']        = (float)$r['pct_leyo'];
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM vistas_detalle WHERE tipo = ? AND publicacion_id = ?");
if ($stmt) {
    $stmt->bind_param("si", $tipo, $id);
    $stmt->execute();
    $stats['visitas_total'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

// ── Visitas por día (sparkline) ────────────────────────────────────────────

$daily_raw = [];
$stmt = $conn->prepare("
    SELECT DATE(fecha_inicio) as dia, COUNT(*) as total
    FROM vistas_detalle
    WHERE tipo = ? AND publicacion_id = ?
      AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(fecha_inicio) ORDER BY dia");
if ($stmt) {
    $stmt->bind_param("si", $tipo, $id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $dr) {
        $daily_raw[$dr['dia']] = (int)$dr['total'];
    }
    $stmt->close();
}

$daily_vals = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $daily_vals[] = $daily_raw[$d] ?? 0;
}

// ── Distribución por dispositivo ──────────────────────────────────────────

$dispositivos = ['movil' => 0, 'tablet' => 0, 'desktop' => 0];
$stmt = $conn->prepare("
    SELECT dispositivo, COUNT(*) as total
    FROM vistas_detalle
    WHERE tipo = ? AND publicacion_id = ?
      AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND dispositivo IS NOT NULL
    GROUP BY dispositivo");
if ($stmt) {
    $stmt->bind_param("si", $tipo, $id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $dr) {
        if (isset($dispositivos[$dr['dispositivo']])) {
            $dispositivos[$dr['dispositivo']] = (int)$dr['total'];
        }
    }
    $stmt->close();
}
$total_disp = array_sum($dispositivos) ?: 1;

// ── Top 5 orígenes ─────────────────────────────────────────────────────────

$origenes = [];
$stmt = $conn->prepare("
    SELECT origen, COUNT(*) as total
    FROM vistas_detalle
    WHERE tipo = ? AND publicacion_id = ?
      AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND origen IS NOT NULL AND origen != ''
    GROUP BY origen ORDER BY total DESC LIMIT 5");
if ($stmt) {
    $stmt->bind_param("si", $tipo, $id);
    $stmt->execute();
    $origenes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ── Helpers ────────────────────────────────────────────────────────────────

function det_format_tiempo(int $s): string {
    if ($s <= 0) return '—';
    if ($s < 60)  return "{$s}s";
    return floor($s / 60) . 'm ' . ($s % 60) . 's';
}

function det_parse_origen(?string $o): string {
    if (empty($o)) return 'Directo';
    $host = parse_url($o, PHP_URL_HOST) ?: $o;
    return preg_replace('/^www\./', '', $host) ?: $o;
}

function det_sparkline(array $vals): string {
    $max = max(array_merge([1], $vals));
    $n   = count($vals);
    $W   = 300; $H = 48; $pad = 3;
    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $x    = round($pad + ($i / ($n - 1)) * ($W - 2 * $pad), 1);
        $y    = round($H - $pad - ($vals[$i] / $max) * ($H - 2 * $pad), 1);
        $pts[] = "$x,$y";
    }
    $line = implode(' ', $pts);
    $area = "0,{$H} " . $line . " {$W},{$H}";
    return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="w-full h-12" preserveAspectRatio="none">'
        . '<defs><linearGradient id="spk_grad" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="#54A6D8" stop-opacity="0.15"/>'
        . '<stop offset="100%" stop-color="#54A6D8" stop-opacity="0"/></linearGradient></defs>'
        . '<polygon points="' . $area . '" fill="url(#spk_grad)"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="#54A6D8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

// ── Imagen y meta ──────────────────────────────────────────────────────────

if ($tipo === 'servicio') {
    $img_url    = !empty($pub['imagen'])  ? '/upload/servicios/' . basename($pub['imagen'])  : '/img/logo2.webp';
    $badge_lbl  = 'TUTORÍA';
    $edit_href  = '/app/editar_servicio.php?id=' . $pub['id'];
} else {
    $img_url    = !empty($pub['portada']) ? '/upload/portadas/'  . basename($pub['portada']) : '/img/logo2.webp';
    $badge_lbl  = 'APUNTE';
    $edit_href  = '/app/editar_apunte.php?id=' . $pub['id'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title><?= htmlspecialchars($pub['titulo'], ENT_QUOTES, 'UTF-8') ?> — Métricas | Nubira</title>
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

  <!-- Topbar -->
  <div class="sticky top-16 bg-white/95 backdrop-blur-sm z-30 border-b border-gray-100 px-4 md:px-6 py-3 flex items-center gap-3">
    <a href="/app/metricas.php" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 transition-colors shrink-0">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
      </svg>
      Métricas
    </a>
  </div>

  <div class="px-4 md:px-6 mt-5 space-y-4">

    <!-- Header publicación -->
    <div class="bg-white rounded-2xl border border-gray-100 p-4 flex items-start gap-4">
      <img src="<?= htmlspecialchars($img_url) ?>"
           class="w-20 h-20 rounded-xl object-cover shrink-0 bg-gray-100"
           loading="lazy" alt=""
           onerror="this.src='/img/logo2.webp'">
      <div class="flex-1 min-w-0">
        <span class="inline-block text-[9px] font-bold tracking-widest px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-500 mb-1.5"><?= $badge_lbl ?></span>
        <p class="font-bold text-gray-900 text-base leading-snug"><?= htmlspecialchars($pub['titulo'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php if (!empty($pub['precio'])): ?>
        <p class="text-sm text-gray-400 mt-0.5">CLP <?= number_format((int)$pub['precio'], 0, ',', '.') ?></p>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($edit_href) ?>" class="inline-block mt-2.5 text-xs font-semibold text-[#54A6D8] hover:underline">Editar publicación</a>
      </div>
    </div>

    <!-- Bloque 1: Stats principales -->
    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
      <div class="px-4 pt-4 pb-2">
        <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Resumen</p>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-y md:divide-y-0 divide-gray-50 border-t border-gray-50">
        <div class="flex flex-col items-center justify-center px-3 py-4 text-center">
          <span class="text-2xl font-extrabold text-gray-900 leading-none"><?= number_format($stats['visitas_30d']) ?></span>
          <span class="text-[11px] text-gray-400 mt-1 leading-tight">Visitas<br>30 días</span>
        </div>
        <div class="flex flex-col items-center justify-center px-3 py-4 text-center">
          <span class="text-2xl font-extrabold text-gray-900 leading-none"><?= det_format_tiempo($stats['tiempo_promedio']) ?></span>
          <span class="text-[11px] text-gray-400 mt-1 leading-tight">Tiempo<br>promedio</span>
        </div>
        <div class="flex flex-col items-center justify-center px-3 py-4 text-center">
          <span class="text-2xl font-extrabold text-gray-900 leading-none"><?= number_format($stats['pct_leyo'], 1) ?>%</span>
          <span class="text-[11px] text-gray-400 mt-1 leading-tight">Leyó<br>completo</span>
        </div>
        <div class="flex flex-col items-center justify-center px-3 py-4 text-center">
          <span class="text-2xl font-extrabold text-gray-900 leading-none"><?= number_format($stats['visitas_total']) ?></span>
          <span class="text-[11px] text-gray-400 mt-1 leading-tight">Visitas<br>total</span>
        </div>
      </div>
    </div>

    <!-- Bloque 2: Gráfico de visitas por día -->
    <?php if ($stats['visitas_30d'] > 0): ?>
    <div class="bg-white rounded-2xl border border-gray-100 p-4">
      <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Visitas por día — últimos 30 días</p>
      <div class="flex items-end gap-1 h-12">
        <?= det_sparkline($daily_vals) ?>
      </div>
      <div class="flex justify-between mt-1.5">
        <span class="text-[10px] text-gray-300"><?= date('d M', strtotime('-29 days')) ?></span>
        <span class="text-[10px] text-gray-300">Hoy</span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Bloque 3: Dispositivos -->
    <div class="bg-white rounded-2xl border border-gray-100 p-4">
      <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Dispositivos — últimos 30 días</p>
      <?php
        $labels = ['movil' => 'Móvil', 'tablet' => 'Tablet', 'desktop' => 'Escritorio'];
        foreach ($labels as $key => $label):
            $cnt = $dispositivos[$key];
            $pct = $total_disp > 0 ? round($cnt / $total_disp * 100) : 0;
      ?>
      <div class="mb-2.5">
        <div class="flex justify-between items-center mb-1">
          <span class="text-xs text-gray-600"><?= $label ?></span>
          <span class="text-xs font-semibold text-gray-700"><?= $cnt ?> <span class="font-normal text-gray-400">(<?= $pct ?>%)</span></span>
        </div>
        <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full bg-[#54A6D8] rounded-full" style="width:<?= $pct ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (array_sum($dispositivos) === 0): ?>
        <p class="text-xs text-gray-400">Sin datos de dispositivo aún.</p>
      <?php endif; ?>
    </div>

    <!-- Bloque 4: Top 5 orígenes -->
    <?php if (!empty($origenes)): ?>
    <div class="bg-white rounded-2xl border border-gray-100 p-4">
      <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Orígenes — últimos 30 días</p>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-50">
            <th class="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">Fuente</th>
            <th class="text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">Visitas</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($origenes as $og): ?>
          <tr>
            <td class="py-2 text-gray-700 text-xs"><?= htmlspecialchars(det_parse_origen($og['origen']), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="py-2 text-right font-semibold text-gray-900 text-xs"><?= (int)$og['total'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div><!-- /px-4 -->
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
