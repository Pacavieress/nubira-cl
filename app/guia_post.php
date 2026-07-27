<?php
// app/guia_post.php — Centro de Recursos: artículo individual (/guias/{cat}/{slug}).
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/helpers/seo.php';
require_once __DIR__ . '/helpers/imagen_guia.php';
require_once __DIR__ . '/helpers/institucion.php';

$cat_slug = strtolower(trim($_GET['cat'] ?? ''));
$art_slug = strtolower(trim($_GET['slug'] ?? ''));

if ($cat_slug === '' || $art_slug === '') {
    header("HTTP/1.1 404 Not Found"); echo "Página no encontrada."; exit;
}

$stmt = $conn->prepare("SELECT * FROM guias_categorias WHERE slug = ? AND habilitada = 1 LIMIT 1");
$stmt->bind_param("s", $cat_slug);
$stmt->execute();
$categoria = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$categoria) {
    header("HTTP/1.1 404 Not Found"); echo "Página no encontrada."; exit;
}

$stmt = $conn->prepare("SELECT * FROM guias_articulos WHERE categoria_id = ? AND slug = ? AND estado = 'publicado' LIMIT 1");
$stmt->bind_param("is", $categoria['id'], $art_slug);
$stmt->execute();
$articulo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$articulo) {
    header("HTTP/1.1 404 Not Found"); echo "Página no encontrada."; exit;
}

// FAQs del artículo
$faqs_articulo = [];
$stmt = $conn->prepare("SELECT pregunta, respuesta FROM guias_articulo_faqs WHERE articulo_id = ? ORDER BY orden");
$stmt->bind_param("i", $articulo['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $faqs_articulo[] = $row;
$stmt->close();

/* ================= CONTENIDO RELACIONADO (Parte 4 del plan) ================= */
$where_base_servicios = "TRIM(LOWER(s.estado)) IN ('aprobado','publicado','activo')
                         AND s.visible = 1 AND COALESCE(a.visible,1) = 1 AND a.bloqueado = 0";

$tutores_relacionados = [];
$apuntes_relacionados = [];
$link_ver_clases = null;

if (!empty($categoria['categoria_relacionada']) || !empty($categoria['filtro_relacionado'])) {
    // --- Tutores/servicios relacionados ---
    if (!empty($categoria['filtro_relacionado'])) {
        $sql = "SELECT s.id, s.slug, s.titulo, a.nombre AS nombre_tutor, a.foto_perfil,
                       COALESCE(dp.institucion, a.institucion) AS institucion_maestra
                FROM servicios s
                JOIN alumnos a ON a.id = s.alumno_id
                LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                WHERE $where_base_servicios AND s.titulo LIKE ?
                ORDER BY s.id DESC LIMIT 4";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria['filtro_relacionado']);
    } else {
        $sql = "SELECT s.id, s.slug, s.titulo, a.nombre AS nombre_tutor, a.foto_perfil,
                       COALESCE(dp.institucion, a.institucion) AS institucion_maestra
                FROM servicios s
                JOIN alumnos a ON a.id = s.alumno_id
                LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
                WHERE $where_base_servicios AND s.categoria = ?
                ORDER BY s.id DESC LIMIT 4";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria['categoria_relacionada']);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $tutores_relacionados[] = $row;
    $stmt->close();

    // --- Apuntes relacionados ---
    $where_base_apuntes = "ap.publico = 1 AND ap.visible = 1 AND al.visible = 1 AND al.bloqueado = 0";
    if (!empty($categoria['filtro_relacionado'])) {
        $sql = "SELECT ap.id, ap.titulo FROM apuntes ap JOIN alumnos al ON al.id = ap.id_alumno
                WHERE $where_base_apuntes AND ap.titulo LIKE ? ORDER BY ap.fecha_subida DESC LIMIT 4";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria['filtro_relacionado']);
    } else {
        $sql = "SELECT ap.id, ap.titulo FROM apuntes ap JOIN alumnos al ON al.id = ap.id_alumno
                WHERE $where_base_apuntes AND ap.categoria = ? ORDER BY ap.fecha_subida DESC LIMIT 4";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria['categoria_relacionada']);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $apuntes_relacionados[] = $row;
    $stmt->close();

    // --- Link "Ver todas las clases de X" (solo si esa landing está indexable=1) ---
    $stmt = $conn->prepare("SELECT indexable FROM seo_categorias_contenido WHERE categoria = ? AND tipo IN ('clases','ambos') ORDER BY (tipo='ambos') ASC LIMIT 1");
    $stmt->bind_param("s", $categoria['nombre']);
    $stmt->execute();
    $cfg_seo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($cfg_seo && $cfg_seo['indexable']) {
        $slug_por_categoria = array_flip(nubira_categorias_seo());
        $link_ver_clases = $slug_por_categoria[$categoria['nombre']] ?? null;
    }
}

// --- Artículos relacionados (misma categoría, cruce en caliente por categoria_id) ---
$articulos_relacionados = [];
$stmt = $conn->prepare("SELECT id, slug, titulo, imagen_portada FROM guias_articulos
                        WHERE categoria_id = ? AND id != ? AND estado = 'publicado'
                        ORDER BY fecha_publicacion DESC LIMIT 3");
$stmt->bind_param("ii", $categoria['id'], $articulo['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $articulos_relacionados[] = $row;
$stmt->close();

/* ================= METADATA ================= */
$titulo_completo = $articulo['titulo'] . ' | Guías Nubira';
$seo_title = mb_strlen($titulo_completo) > 65 ? mb_substr($titulo_completo, 0, 62) . '...' : $titulo_completo;

$desc_fuente = $articulo['meta_description'] ?: ($articulo['resumen'] ?: strip_tags($articulo['cuerpo']));
$seo_desc = mb_strlen($desc_fuente) > 155 ? mb_substr($desc_fuente, 0, 152) . '...' : $desc_fuente;

$url_canonical = "https://nubira.cl/guias/$cat_slug/$art_slug";
$portada_main = nb_resolver_portada_guia($articulo['imagen_portada'], 'main');
$og_image = $portada_main ? 'https://nubira.cl' . $portada_main : null;

$fecha_iso = null;
if (!empty($articulo['fecha_publicacion'])) {
    $dt = new DateTime($articulo['fecha_publicacion']);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $fecha_iso = $dt->format('Y-m-d\TH:i:sP');
}

$article_ld = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $articulo['titulo'],
    'description' => $seo_desc,
    'author' => ['@type' => 'Organization', 'name' => $articulo['autor_nombre']],
    'publisher' => ['@type' => 'Organization', 'name' => 'Nubira', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://nubira.cl/img/logo.webp']],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url_canonical],
];
if ($og_image) { $article_ld['image'] = [$og_image]; } // sin portada: se omite, no se manda un placeholder
if ($fecha_iso) { $article_ld['datePublished'] = $fecha_iso; }

$faqs_para_ld = array_map(fn($f) => ['q' => $f['pregunta'], 'a' => $f['respuesta']], $faqs_articulo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <?php
    echo nubira_seo_meta($seo_title, $seo_desc) . "\n  ";
    echo nubira_canonical_tag("/guias/$cat_slug/$art_slug") . "\n  ";
    echo '<meta property="og:type" content="article" />' . "\n  ";
    if ($og_image) echo '<meta property="og:image" content="' . htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') . '" />' . "\n  ";
    echo '<meta property="og:url" content="' . htmlspecialchars($url_canonical, ENT_QUOTES, 'UTF-8') . '" />' . "\n  ";
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n  ";
    if ($fecha_iso) echo '<meta property="article:published_time" content="' . htmlspecialchars($fecha_iso, ENT_QUOTES, 'UTF-8') . '" />' . "\n  ";
    echo '<meta property="article:author" content="' . htmlspecialchars($articulo['autor_nombre'], ENT_QUOTES, 'UTF-8') . '" />' . "\n  ";

    echo '<script type="application/ld+json">' . json_encode($article_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n  ";
    echo nubira_faq_ld($faqs_para_ld) . "\n  ";
    echo nubira_breadcrumb_ld([
        ['name' => 'Inicio', 'item' => 'https://nubira.cl/explorar'],
        ['name' => 'Guías', 'item' => 'https://nubira.cl/guias'],
        ['name' => $categoria['nombre'], 'item' => "https://nubira.cl/guias/$cat_slug"],
        ['name' => $articulo['titulo']],
    ]) . "\n  ";
  ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
  <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-white text-gray-900 antialiased overflow-x-hidden flex flex-col min-h-screen">

<?php
$page_title = $articulo['titulo'];
require_once __DIR__ . '/componentes/header.php';
require_once __DIR__ . '/componentes/sidebar.php';
?>

<main class="flex flex-col flex-grow w-full pt-20 pb-28 md:pb-10 lg:ml-64 px-4 md:px-8">

  <div class="max-w-[900px] w-full">

  <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
    <a href="/explorar" class="hover:text-gray-700">Inicio</a>
    <span class="mx-1">/</span>
    <a href="/guias" class="hover:text-gray-700">Guías</a>
    <span class="mx-1">/</span>
    <a href="/guias/<?= htmlspecialchars($cat_slug) ?>" class="hover:text-gray-700"><?= htmlspecialchars($categoria['nombre']) ?></a>
  </nav>

  <header class="mb-6">
    <h1 class="text-2xl md:text-4xl font-bold text-gray-900 tracking-tight leading-tight"><?= htmlspecialchars($articulo['titulo']) ?></h1>
    <?php if (!empty($articulo['resumen'])): ?>
    <p class="text-base text-gray-600 mt-3 leading-relaxed"><?= htmlspecialchars($articulo['resumen']) ?></p>
    <?php endif; ?>
    <p class="text-xs text-gray-400 mt-3 uppercase tracking-wide font-bold">
      Por <?= htmlspecialchars($articulo['autor_nombre']) ?>
      <?php if ($fecha_iso): ?> · <?= htmlspecialchars(date('d/m/Y', strtotime($articulo['fecha_publicacion']))) ?><?php endif; ?>
    </p>
  </header>

  <?php if ($portada_main): ?>
  <img src="<?= htmlspecialchars($portada_main) ?>"
       alt="<?= htmlspecialchars($articulo['titulo']) ?>" class="w-full rounded-2xl object-cover mb-8" style="max-height:420px">
  <?php endif; ?>

  <article class="prose prose-lg max-w-none text-gray-800 leading-relaxed">
    <?= /* Sin htmlspecialchars(): cuerpo ya viene sanitizado por nb_sanitizar_html() antes de
           guardarse (único punto de escritura, ver admin_guias.php:99) — es HTML seguro,
           whitelisteado, y acá se renderiza como HTML real, no como texto escapado. */
        $articulo['cuerpo'] ?>
  </article>

  <?php if (!empty($faqs_articulo)): ?>
  <section class="mt-10 max-w-3xl">
    <h2 class="text-xl md:text-2xl font-bold text-gray-900 mb-4">Preguntas frecuentes</h2>
    <div class="space-y-3">
      <?php foreach ($faqs_articulo as $f): ?>
      <div class="bg-gray-50 border border-gray-100 rounded-xl p-4">
        <p class="font-bold text-gray-900 text-sm mb-1"><?= htmlspecialchars($f['pregunta']) ?></p>
        <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars($f['respuesta']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($tutores_relacionados) || !empty($apuntes_relacionados)): ?>
  <section class="mt-10 border-t border-gray-100 pt-8">
    <h2 class="text-xl font-bold text-gray-900 mb-4">Tutores y recursos relacionados</h2>

    <?php if (!empty($tutores_relacionados)): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
      <?php foreach ($tutores_relacionados as $t): ?>
      <a href="/servicios/<?= htmlspecialchars($t['slug'] ?? '') ?>-<?= (int)$t['id'] ?>"
         class="flex items-center gap-3 bg-white border border-gray-100 rounded-xl p-3 hover:shadow-md transition-all min-w-0">
        <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center text-[#54A6D8] font-bold text-sm shrink-0 overflow-hidden">
          <?php if (!empty($t['foto_perfil'])): ?>
            <img src="/app/perfil/fotos/<?= htmlspecialchars($t['foto_perfil']) ?>" class="w-full h-full object-cover">
          <?php else: ?>
            <?= htmlspecialchars(mb_substr($t['nombre_tutor'] ?? '?', 0, 1)) ?>
          <?php endif; ?>
        </div>
        <div class="min-w-0">
          <p class="text-sm font-bold text-gray-900 truncate"><?= htmlspecialchars($t['titulo']) ?></p>
          <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars(institucion_tutor($t['institucion_maestra'] ?? '', false)) ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($link_ver_clases): ?>
    <a href="/clases/<?= htmlspecialchars($link_ver_clases) ?>" class="inline-flex items-center gap-1.5 text-sm font-bold text-[#54A6D8] hover:underline mb-4">
      Ver todas las clases de <?= htmlspecialchars($categoria['nombre']) ?>
      <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
    <?php endif; ?>

    <?php if (!empty($apuntes_relacionados)): ?>
    <div class="mt-2">
      <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Apuntes relacionados</p>
      <ul class="space-y-1">
        <?php foreach ($apuntes_relacionados as $ap): ?>
        <li><a href="/apunte/<?= (int)$ap['id'] ?>" class="text-sm text-[#54A6D8] hover:underline"><?= htmlspecialchars($ap['titulo']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($articulos_relacionados)): ?>
  <section class="mt-10 border-t border-gray-100 pt-8">
    <h2 class="text-xl font-bold text-gray-900 mb-4">Más guías de <?= htmlspecialchars($categoria['nombre']) ?></h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <?php foreach ($articulos_relacionados as $rel): ?>
      <?php $portada_rel = nb_resolver_portada_guia($rel['imagen_portada'], 'thumb'); ?>
      <a href="/guias/<?= htmlspecialchars($cat_slug) ?>/<?= htmlspecialchars($rel['slug']) ?>"
         class="block bg-white border border-gray-100 rounded-xl overflow-hidden hover:shadow-md transition-all min-w-0">
        <?php if ($portada_rel): ?>
        <img src="<?= htmlspecialchars($portada_rel) ?>" class="w-full h-24 object-cover">
        <?php endif; ?>
        <p class="text-sm font-bold text-gray-900 p-3 leading-snug"><?= htmlspecialchars($rel['titulo']) ?></p>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  </div>

  <div class="mt-auto w-full">
    <?php require_once __DIR__ . '/componentes/footer_minimal.php'; ?>
  </div>
</main>
<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
</body>
</html>
