<?php
// app/guias.php — Centro de Recursos: hub general (/guias) e hub de categoría
// (/guias/{slug}), mismo mecanismo que landing_categoria.php resuelve tipo+cat
// en un solo archivo.
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/helpers/seo.php';
require_once __DIR__ . '/helpers/imagen_guia.php';

$slug = isset($_GET['cat']) ? strtolower(trim($_GET['cat'])) : null;

if ($slug === null) {
    /* ================= MODO 1: HUB GENERAL (/guias) ================= */
    $categorias = [];
    $res = $conn->query("SELECT c.id, c.nombre, c.slug, c.descripcion_corta, COUNT(a.id) AS total_articulos
                         FROM guias_categorias c
                         JOIN guias_articulos a ON a.categoria_id = c.id AND a.estado = 'publicado'
                         WHERE c.habilitada = 1
                         GROUP BY c.id, c.nombre, c.slug, c.descripcion_corta
                         ORDER BY c.orden");
    while ($res && $row = $res->fetch_assoc()) $categorias[] = $row;

    $noindex = empty($categorias);
    $seo_title = "Centro de Recursos | Nubira";
    $seo_desc  = "Guías, estrategias de estudio y recursos para estudiantes universitarios chilenos: Matemáticas, PAES, métodos de estudio y becas.";
    $h1 = "Centro de Recursos";
    $intro = "Guías y recursos para ayudarte a rendir mejor en la universidad.";
} else {
    /* ================= MODO 2: HUB DE CATEGORÍA (/guias/{slug}) ================= */
    $stmt = $conn->prepare("SELECT * FROM guias_categorias WHERE slug = ? AND habilitada = 1 LIMIT 1");
    $stmt->bind_param("s", $slug);
    $stmt->execute();
    $categoria = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$categoria) {
        header("HTTP/1.1 404 Not Found");
        echo "Página no encontrada.";
        exit;
    }

    $articulos = [];
    $stmt = $conn->prepare("SELECT id, titulo, slug, resumen, imagen_portada, fecha_publicacion
                            FROM guias_articulos
                            WHERE categoria_id = ? AND estado = 'publicado'
                            ORDER BY fecha_publicacion DESC");
    $stmt->bind_param("i", $categoria['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $articulos[] = $row;
    $stmt->close();

    $total = count($articulos);
    $noindex = ($total < 3); // mismo umbral anti-thin-content que landing_categoria.php

    $seo_title = "Guías de {$categoria['nombre']} | Nubira";
    $seo_desc  = $categoria['descripcion_corta']
        ?: "Guías y recursos sobre {$categoria['nombre']} para estudiantes universitarios en Chile.";
    $h1 = "Guías de {$categoria['nombre']}";
    $intro = $categoria['descripcion_corta'] ?: null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <?php
    echo nubira_seo_meta($seo_title, $seo_desc) . "\n  ";
    echo nubira_canonical_tag($slug === null ? "/guias" : "/guias/$slug") . "\n  ";
    if ($noindex) echo '<meta name="robots" content="noindex,follow" />' . "\n  ";

    $breadcrumb_items = [['name' => 'Inicio', 'item' => 'https://nubira.cl/explorar']];
    if ($slug === null) {
        $breadcrumb_items[] = ['name' => 'Guías'];
    } else {
        $breadcrumb_items[] = ['name' => 'Guías', 'item' => 'https://nubira.cl/guias'];
        $breadcrumb_items[] = ['name' => $categoria['nombre']];
    }
    echo nubira_breadcrumb_ld($breadcrumb_items) . "\n  ";
  ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
  <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-white text-gray-900 antialiased overflow-x-hidden flex flex-col min-h-screen">

<?php
$page_title = $h1;
require_once __DIR__ . '/componentes/header.php';
require_once __DIR__ . '/componentes/sidebar.php';
?>

<main class="flex flex-col flex-grow w-full pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

  <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
    <a href="/explorar" class="hover:text-gray-700">Inicio</a>
    <span class="mx-1">/</span>
    <?php if ($slug === null): ?>
      <span class="text-gray-800 font-medium">Guías</span>
    <?php else: ?>
      <a href="/guias" class="hover:text-gray-700">Guías</a>
      <span class="mx-1">/</span>
      <span class="text-gray-800 font-medium"><?= htmlspecialchars($categoria['nombre']) ?></span>
    <?php endif; ?>
  </nav>

  <header class="mb-6">
    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars($h1) ?></h1>
    <?php if (!empty($intro)): ?>
    <p class="text-sm md:text-base text-gray-600 mt-2 max-w-3xl leading-relaxed"><?= htmlspecialchars($intro) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($slug === null): ?>
    <!-- ===== HUB GENERAL: cards de categoría ===== -->
    <?php if (!empty($categorias)): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6 w-full">
      <?php foreach ($categorias as $cat): ?>
      <a href="/guias/<?= htmlspecialchars($cat['slug']) ?>"
         class="block bg-white border border-gray-100 rounded-2xl p-5 shadow-sm hover:shadow-md hover:scale-[1.01] transition-all min-w-0">
        <h2 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars($cat['nombre']) ?></h2>
        <?php if (!empty($cat['descripcion_corta'])): ?>
        <p class="text-sm text-gray-500 mb-2"><?= htmlspecialchars($cat['descripcion_corta']) ?></p>
        <?php endif; ?>
        <p class="text-xs text-gray-400 uppercase tracking-wide font-bold"><?= (int)$cat['total_articulos'] ?> artículo<?= $cat['total_articulos'] == 1 ? '' : 's' ?></p>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
      <p class="font-medium">Aún no hay guías publicadas.</p>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- ===== HUB DE CATEGORÍA: cards de artículo ===== -->
    <?php if ($total > 0): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6 w-full">
      <?php foreach ($articulos as $art): ?>
      <?php $portada_card = nb_resolver_portada_guia($art['imagen_portada'], 'card'); ?>
      <a href="/guias/<?= htmlspecialchars($slug) ?>/<?= htmlspecialchars($art['slug']) ?>"
         class="block bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:scale-[1.01] transition-all min-w-0">
        <?php if ($portada_card): ?>
        <img src="<?= htmlspecialchars($portada_card) ?>"
             alt="<?= htmlspecialchars($art['titulo']) ?>" class="w-full h-40 object-cover">
        <?php endif; ?>
        <div class="p-4">
          <h2 class="text-base font-bold text-gray-900 mb-1 leading-snug"><?= htmlspecialchars($art['titulo']) ?></h2>
          <?php if (!empty($art['resumen'])): ?>
          <p class="text-sm text-gray-500 line-clamp-2"><?= htmlspecialchars($art['resumen']) ?></p>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
      <p class="font-medium">Aún no hay guías publicadas en <?= htmlspecialchars($categoria['nombre']) ?>.</p>
      <a href="/guias" class="inline-block mt-4 text-[#54A6D8] font-semibold hover:underline">Ver todas las guías &rarr;</a>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="mt-auto">
    <?php require_once __DIR__ . '/componentes/footer_minimal.php'; ?>
  </div>
</main>
<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
</body>
</html>
