<?php
// app/guias.php — Centro de Recursos: hub general (/guias) e hub de categoría
// (/guias/{slug}), mismo mecanismo que landing_categoria.php resuelve tipo+cat
// en un solo archivo.
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/helpers/seo.php';
require_once __DIR__ . '/helpers/imagen_guia.php';
require_once __DIR__ . '/helpers/roles.php';

// Gating de "Para Tutores" (guias_categorias.solo_tutores) — mismo criterio
// exacto que $es_creador en perfil.php:382, extraído a nb_es_tutor_activo().
$usuario_id_sesion = $_SESSION['usuario_id'] ?? null;
$es_tutor_activo = $usuario_id_sesion ? nb_es_tutor_activo($conn, (int)$usuario_id_sesion) : false;

$slug = isset($_GET['cat']) ? strtolower(trim($_GET['cat'])) : null;

if ($slug === null) {
    /* ================= MODO 1: HUB GENERAL (/guias) ================= */
    // Categorías solo_tutores=1 NUNCA aparecen en este catálogo público, sin importar
    // quién mire — es contenido interno con su propio acceso directo (tile del panel
    // de gestión → /guias/para-tutores), no un ítem más del catálogo. Distinto del
    // gating de MODO 2/guia_post.php (líneas de abajo), que sigue decidiendo el acceso
    // por URL directa según $es_tutor_activo — eso no cambia acá.
    $categorias = [];
    $res = $conn->query("SELECT c.id, c.nombre, c.slug, c.descripcion_corta, COUNT(a.id) AS total_articulos
                         FROM guias_categorias c
                         JOIN guias_articulos a ON a.categoria_id = c.id AND a.estado = 'publicado'
                         WHERE c.habilitada = 1 AND c.solo_tutores = 0
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

    if ((int)$categoria['solo_tutores'] === 1) {
        if (!$usuario_id_sesion) {
            header("Location: /login?redir=" . urlencode($_SERVER['REQUEST_URI'])); exit;
        }
        if (!$es_tutor_activo) {
            header("Location: /publicar-servicio"); exit;
        }
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

    // "Para Tutores" ya es no-indexable en la práctica (gating de login+tutor en Fase 3
    // redirige a Googlebot siempre, y nunca se lista en el hub general) — el BreadcrumbList
    // no aporta nada ahí. $mostrar_breadcrumb también controla el <nav> visible más abajo.
    $mostrar_breadcrumb = !($slug !== null && (int)$categoria['solo_tutores'] === 1);
    if ($mostrar_breadcrumb) {
        $breadcrumb_items = [['name' => 'Inicio', 'item' => 'https://nubira.cl/explorar']];
        if ($slug === null) {
            $breadcrumb_items[] = ['name' => 'Guías'];
        } else {
            $breadcrumb_items[] = ['name' => 'Guías', 'item' => 'https://nubira.cl/guias'];
            $breadcrumb_items[] = ['name' => $categoria['nombre']];
        }
        echo nubira_breadcrumb_ld($breadcrumb_items) . "\n  ";
    }
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
// [NUBIRA 2.0] Ocultar header global en móvil — mismo patrón que las páginas de gestión
// (mis_compras.php, datos_bancarios.php, metricas_detalle.php). Página pública, sin
// sesión requerida: header.php ya maneja $es_visitante en cada rama, y $ocultar_buscador/
// $ocultar_botones_publicar no se definen acá, así que defaultean a mostrar en desktop.
echo '<div class="hidden md:block">';
require_once __DIR__ . '/componentes/header.php';
echo '</div>';
require_once __DIR__ . '/componentes/sidebar.php';
?>

<main class="flex flex-col flex-grow w-full pt-4 md:pt-16 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

  <?php if ($mostrar_breadcrumb): ?>
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
  <?php endif; ?>

  <div class="sticky top-0 md:top-16 z-30 bg-white/95 backdrop-blur-sm border-b border-gray-100 -mx-4 md:-mx-8 px-4 md:px-8 py-3 mb-6 flex items-center gap-3">
    <button type="button" onclick="navegacionSeguraNubira()"
            class="lg:hidden shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-[#54A6D8] focus-visible:ring-offset-2"
            aria-label="Volver">
        <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
    </button>
    <h1 class="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em] truncate min-w-0 flex-1"><?= htmlspecialchars($h1) ?></h1>
  </div>

  <header class="mb-6">
    <?php if (!empty($intro)): ?>
    <p class="sr-only md:not-sr-only text-sm md:text-base font-normal tracking-[0.01em] text-gray-600 mt-2 max-w-3xl leading-relaxed"><?= htmlspecialchars($intro) ?></p>
    <?php endif; ?>
    <?php if ($slug === null && !empty($categorias)): ?>
    <p class="text-xs font-normal tracking-[0.01em] text-gray-400 mt-1">Más categorías y guías próximamente.</p>
    <?php endif; ?>
  </header>

  <?php if ($slug === null): ?>
    <!-- ===== HUB GENERAL: cards de categoría ===== -->
    <?php if (!empty($categorias)): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6 w-full">
      <?php foreach ($categorias as $cat): ?>
      <a href="/guias/<?= htmlspecialchars($cat['slug']) ?>"
         class="group relative block overflow-hidden rounded-2xl p-6 pl-7 bg-[#54A6D8]/[0.06] border border-[#54A6D8]/10 hover:bg-[#54A6D8]/10 hover:border-[#54A6D8]/30 transition-colors duration-150 ease-out min-w-0">
        <span class="absolute left-0 top-0 bottom-0 w-[5px] bg-[#54A6D8]"></span>
        <h2 class="text-lg font-extrabold text-gray-900 tracking-tight mb-1.5"><?= htmlspecialchars($cat['nombre']) ?></h2>
        <?php if (!empty($cat['descripcion_corta'])): ?>
        <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars($cat['descripcion_corta']) ?></p>
        <?php endif; ?>
        <div class="flex items-center justify-between mt-6">
          <span class="text-xs font-bold text-[#2f84ba] uppercase tracking-wide"><?= (int)$cat['total_articulos'] ?> artículo<?= $cat['total_articulos'] == 1 ? '' : 's' ?></span>
          <span class="text-xs font-semibold text-gray-500 group-hover:text-[#2f84ba] transition-colors duration-150 ease-out">Ver guías &rarr;</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
      <p class="font-medium">Aún no hay guías publicadas.</p>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- ===== HUB DE CATEGORÍA: lista de artículos (formato horizontal, tipo Airbnb/Uber) ===== -->
    <?php if ($total > 0): ?>
    <div class="flex flex-col gap-3 w-full">
      <?php foreach ($articulos as $art): ?>
      <?php $portada_card = nb_resolver_portada_guia($art['imagen_portada'], 'card'); ?>
      <a href="/guias/<?= htmlspecialchars($slug) ?>/<?= htmlspecialchars($art['slug']) ?>"
         class="group flex items-center gap-4 p-3 sm:p-4 rounded-2xl border border-gray-100 hover:border-gray-200 hover:bg-gray-50/60 transition-colors duration-150 ease-out min-w-0">
        <?php if ($portada_card): ?>
        <img src="<?= htmlspecialchars($portada_card) ?>"
             alt="<?= htmlspecialchars($art['titulo']) ?>" class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl object-cover shrink-0">
        <?php else: ?>
        <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-xl bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-300 shrink-0">
          <?= icon('camera', 'w-6 h-6') ?>
        </div>
        <?php endif; ?>
        <div class="min-w-0 flex-1">
          <h2 class="text-base font-medium tracking-[-0.01em] text-[#222222] leading-snug line-clamp-2"><?= htmlspecialchars($art['titulo']) ?></h2>
          <?php if (!empty($art['resumen'])): ?>
          <p class="text-sm font-light tracking-[0.01em] text-gray-500 leading-relaxed line-clamp-2 mt-1"><?= htmlspecialchars($art['resumen']) ?></p>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
      <p class="font-medium">Aún no hay guías publicadas en <?= htmlspecialchars($categoria['nombre']) ?>.</p>
      <?php if ((int)$categoria['solo_tutores'] === 1): ?>
      <a href="/guias/para-tutores" class="inline-block mt-4 text-[#54A6D8] font-semibold hover:underline">Ver todos los recursos para tutores &rarr;</a>
      <?php else: ?>
      <a href="/guias" class="inline-block mt-4 text-[#54A6D8] font-semibold hover:underline">Ver todas las guías &rarr;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="mt-auto">
    <?php require_once __DIR__ . '/componentes/footer_minimal.php'; ?>
  </div>
</main>
<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>

<?php
require_once __DIR__ . '/componentes/modal_publicar.php';
require_once __DIR__ . '/componentes/modal_explora.php';
?>

<script>
    // [NUBIRA 2.0] Volver — mismo patrón que las páginas de gestión (history.back() con
    // fallback), pero el fallback es contextual en vez de un destino fijo genérico: desde
    // una categoría vuelve al hub general (/guias), desde el hub general sale a /explorar
    // (mismo criterio que metricas_detalle.php usa un link fijo a su página padre).
    window.navegacionSeguraNubira = function() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = <?= json_encode($slug === null ? '/explorar' : '/guias') ?>;
        }
    };

    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
        if(!btn || !modal) return;
        const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
        const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
        btn.onclick = (e) => { e.preventDefault(); open(); };
        if(close) close.onclick = shut;
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    document.addEventListener('DOMContentLoaded', () => {
        <?php if (!isset($_SESSION['usuario_id'])): ?>
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
    });
</script>
</body>
</html>
