<?php
// app/landing_categoria.php — Landing SEO por categoría (pSEO Fase 1)
// Ruteado desde .htaccess: /clases/<slug>  (apuntes diferido hasta recategorizar)
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/seguridad_url.php';
require_once __DIR__ . '/helpers/seo.php';
require_once __DIR__ . '/helpers/ofertas.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/componentes/card_servicio_grid.php';  // render_card_servicio_grid()

// 1. VALIDACIÓN DE PARÁMETROS
$tipo = $_GET['tipo'] ?? '';
$slug = strtolower(trim($_GET['cat'] ?? ''));
$MAPA = nubira_categorias_seo();           // slug => nombre canónico (sin "Otros")

if (!in_array($tipo, ['clases', 'apuntes'], true) || !isset($MAPA[$slug])) {
    header("HTTP/1.1 404 Not Found");
    echo "Página no encontrada.";
    exit;
}
$categoria = $MAPA[$slug];

// 2. CONSULTA PÚBLICA POR CATEGORÍA → filas completas para la card (filtros = cargar_servicios)
$filas = [];
if ($tipo === 'clases') {
    // SELECT maestro: mismas columnas que cargar_servicios.php que necesita render_card_servicio_grid()
    $sql = "SELECT s.*,
                   COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
                   (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS total_votos,
                   (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS rating_promedio,
                   a.foto_perfil,
                   a.nombre AS nombre_tutor,
                   bi.archivo AS banco_archivo
            FROM servicios s
            JOIN alumnos a ON a.id = s.alumno_id
            LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
            LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
            WHERE TRIM(LOWER(s.estado)) IN ('aprobado','publicado','activo')
              AND s.visible = 1
              AND COALESCE(a.visible, 1) = 1
              AND s.categoria = ?
            ORDER BY s.fecha_publicacion DESC";
} else { // apuntes — diferido (no ruteado). TODO: card propia de apunte al activar.
    $sql = "SELECT ap.*
            FROM apuntes ap
            JOIN alumnos al ON al.id = ap.id_alumno
            WHERE ap.publico = 1
              AND ap.visible = 1
              AND al.visible = 1
              AND ap.categoria = ?
            ORDER BY ap.fecha_subida DESC";
}
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $categoria);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $filas[] = $row; }
$stmt->close();
$total = count($filas);
$noindex = ($total < 3);

// 3. CONTENIDO SEO EDITABLE (override desde BD si existe; específico gana a 'ambos')
// Degrada con gracia si la tabla seo_categorias_contenido aún no fue migrada.
$titulo_h1 = $parrafo_intro = $meta_desc_db = null;
try {
    $st = $conn->prepare("SELECT titulo_h1, parrafo_intro, meta_description
                          FROM seo_categorias_contenido
                          WHERE categoria = ? AND tipo IN (?, 'ambos')
                          ORDER BY (tipo = 'ambos') ASC
                          LIMIT 1");
    if ($st) {
        $st->bind_param("ss", $categoria, $tipo);
        $st->execute();
        $rc = $st->get_result()->fetch_assoc();
        $st->close();
        if ($rc) {
            $titulo_h1     = $rc['titulo_h1']     ?: null;
            $parrafo_intro = $rc['parrafo_intro'] ?: null;
            $meta_desc_db  = $rc['meta_description'] ?: null;
        }
    }
} catch (Throwable $e) {
    // Tabla aún no migrada → se usan los textos por defecto (paso 4).
}

// 4. METADATA (defaults si no hay override en BD)
$tipo_palabra  = ($tipo === 'clases') ? 'Clases' : 'Apuntes';
$tipo_servicio = ($tipo === 'clases') ? 'clases particulares y tutorías' : 'apuntes y resúmenes';
$seo_title = "$tipo_palabra de $categoria universidad Chile | Nubira";
$seo_desc  = $meta_desc_db
    ?: "Encuentra $tipo_servicio de $categoria en universidades chilenas (PUC, USACH, U. de Chile, UNAB y más). Pago protegido con Garantía Nubira.";
$h1    = $titulo_h1 ?: "$tipo_palabra de $categoria en Chile";
$intro = $parrafo_intro ?: "Próximamente más información sobre $categoria en Nubira.";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#ffffff" />
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <?php
    echo nubira_seo_meta($seo_title, $seo_desc) . "\n  ";
    echo nubira_canonical_tag("/$tipo/$slug") . "\n  ";
    if ($noindex) echo '<meta name="robots" content="noindex,follow" />' . "\n  ";
  ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
  <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-white text-gray-900 antialiased overflow-x-hidden">

<?php
$page_title = $h1;
require_once __DIR__ . '/componentes/header.php';
require_once __DIR__ . '/componentes/sidebar.php';
?>

<main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1600px] mx-auto md:px-8">

  <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
    <a href="/explorar" class="hover:text-gray-700">Inicio</a>
    <span class="mx-1">/</span>
    <a href="/servicios" class="hover:text-gray-700"><?= htmlspecialchars($tipo_palabra) ?></a>
    <span class="mx-1">/</span>
    <span class="text-gray-800 font-medium"><?= htmlspecialchars($categoria) ?></span>
  </nav>

  <header class="mb-4">
    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 tracking-tight"><?= htmlspecialchars($h1) ?></h1>
    <p class="text-sm md:text-base text-gray-600 mt-2 max-w-3xl leading-relaxed"><?= htmlspecialchars($intro) ?></p>
    <?php if ($total > 0): ?>
      <p class="text-xs text-gray-400 mt-2 uppercase tracking-wide font-bold"><?= $total ?> resultado<?= $total === 1 ? '' : 's' ?></p>
    <?php endif; ?>
  </header>

  <?php if ($total > 0): ?>
    <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6 w-full">
      <?php foreach ($filas as $fila): ?>
        <?= render_card_servicio_grid($fila, ['hide_inst' => false, 'compacto' => false]) ?>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl p-10 text-center text-gray-500">
      <p class="font-medium">Aún no hay <?= htmlspecialchars(strtolower($tipo_palabra)) ?> de <?= htmlspecialchars($categoria) ?> publicados.</p>
      <a href="/explorar" class="inline-block mt-4 text-[#54A6D8] font-semibold hover:underline">Explorar todo &rarr;</a>
    </div>
  <?php endif; ?>

</main>

<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
</body>
</html>
