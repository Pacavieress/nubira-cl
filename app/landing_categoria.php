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

// 2. CONTENIDO SEO — se lee PRIMERO para obtener filtro_titulo antes del query principal
$titulo_h1 = $parrafo_intro = $meta_desc_db = $filtro_like = null;
try {
    $st = $conn->prepare("SELECT titulo_h1, parrafo_intro, meta_description, filtro_titulo
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
            $filtro_like   = $rc['filtro_titulo']  ?: null;
        }
    }
} catch (Throwable $e) {
    // Tabla o columna aún no migrada → filtro_like=null usa categoria exacta.
}

// 3. CONSULTA PÚBLICA POR CATEGORÍA (o por título LIKE si filtro_like está definido)
$filas = [];
// [PAES] Solo en la landing de PAES específicamente (no contamina otras categorías):
// suma servicios/apuntes marcados es_paes/nivel_academico='paes' aunque su categoría
// de materia sea otra (ej. un servicio de Matemáticas marcado "Prepara para la PAES").
if ($tipo === 'clases') {
    $sql_select = "SELECT s.*,
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
              AND a.bloqueado = 0";
    if ($categoria === 'PAES') {
        // [PAES] Mismo criterio amplio que busqueda.php: LIKE sobre 6 campos + es_paes=1,
        // en vez de categoria exacta — cubre servicios de otras categorías que mencionan PAES
        // sin tener el flag es_paes marcado. Usa la palabra completa "PAES" (no la raíz
        // recortada de plurales que usa busqueda.php para texto libre de usuario).
        $like_paes = '%PAES%';
        $sql  = $sql_select . " AND (s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ? OR s.materia LIKE ? OR s.asignatura LIKE ? OR s.area LIKE ? OR s.es_paes = 1) ORDER BY s.fecha_publicacion DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $like_paes, $like_paes, $like_paes, $like_paes, $like_paes, $like_paes);
    } elseif ($filtro_like) {
        $sql  = $sql_select . " AND (s.titulo LIKE ?) ORDER BY s.fecha_publicacion DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $filtro_like);
    } else {
        $sql  = $sql_select . " AND (s.categoria = ?) ORDER BY s.fecha_publicacion DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria);
    }
} else { // apuntes
    $sql_select_ap = "SELECT ap.*
            FROM apuntes ap
            JOIN alumnos al ON al.id = ap.id_alumno
            WHERE ap.publico = 1
              AND ap.visible = 1
              AND al.visible = 1
              AND al.bloqueado = 0";
    if ($categoria === 'PAES') {
        // [PAES] Mismo criterio que busqueda.php usa para apuntes: LIKE sobre
        // titulo/descripcion/asignatura/materia + nivel_academico='paes'. Apuntes no
        // tiene columna 'area', y busqueda.php tampoco incluye 'categoria' en su LIKE
        // de apuntes pese a que la columna existe — replicamos ese mismo criterio, no uno más ancho.
        $like_paes_ap = '%PAES%';
        $sql  = $sql_select_ap . " AND (ap.titulo LIKE ? OR ap.descripcion LIKE ? OR ap.asignatura LIKE ? OR ap.materia LIKE ? OR ap.nivel_academico = 'paes') ORDER BY ap.fecha_subida DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $like_paes_ap, $like_paes_ap, $like_paes_ap, $like_paes_ap);
    } elseif ($filtro_like) {
        $sql  = $sql_select_ap . " AND (ap.titulo LIKE ?) ORDER BY ap.fecha_subida DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $filtro_like);
    } else {
        $sql  = $sql_select_ap . " AND (ap.categoria = ?) ORDER BY ap.fecha_subida DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $categoria);
    }
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $filas[] = $row; }
$stmt->close();
$total = count($filas);
$noindex = ($total < 3);

// 4. METADATA (defaults si no hay override en BD)
$tipo_palabra  = ($tipo === 'clases') ? 'Clases' : 'Apuntes';
$tipo_servicio = ($tipo === 'clases') ? 'clases particulares y tutorías' : 'apuntes y resúmenes';
$seo_title = "$tipo_palabra de $categoria universidad Chile | Nubira";
$seo_desc  = $meta_desc_db
    ?: "Encuentra $tipo_servicio de $categoria en universidades chilenas (PUC, USACH, U. de Chile, UNAB y más). Pago protegido con Garantía Nubira.";
$h1    = $titulo_h1 ?: "$tipo_palabra de $categoria en Chile";
$intro = $parrafo_intro ?: "Próximamente más información sobre $categoria en Nubira.";

// FAQ curado por categoría (Fase quick-win: solo Tesis por ahora)
$FAQS_POR_CATEGORIA = [
    'Tesis' => [
        ['q' => '¿Cuánto cuesta una asesoría de tesis en Chile?',
         'a' => 'El precio varía según el alcance (revisión metodológica, corrección de estilo, apoyo estadístico, etc.) y lo define cada tutor en su perfil. En Nubira puedes comparar precios y elegir la asesoría que se ajuste a tu presupuesto, siempre con pago protegido.'],
        ['q' => '¿Qué incluye una asesoría de tesis en Nubira?',
         'a' => 'Acompañamiento académico: revisión de metodología, corrección de redacción y estilo, apoyo en análisis estadístico y orientación en la estructura del trabajo. El estudiante mantiene siempre la autoría de su tesis.'],
        ['q' => '¿Asesoría de tesis o tesis por encargo?',
         'a' => 'Nubira no permite ni promueve la elaboración de tesis por encargo. Los tutores ofrecen acompañamiento, corrección y orientación metodológica; la investigación y redacción final son siempre responsabilidad del estudiante.'],
        ['q' => '¿Es seguro pagar por una asesoría de tesis en Nubira?',
         'a' => 'Sí. Todos los pagos quedan protegidos con la Garantía Nubira: el dinero se libera al tutor solo cuando confirmas que recibiste el servicio acordado.'],
    ],
];
$faqs = $FAQS_POR_CATEGORIA[$categoria] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <?php
    echo nubira_seo_meta($seo_title, $seo_desc) . "\n  ";
    echo nubira_canonical_tag("/$tipo/$slug") . "\n  ";
    if ($noindex) echo '<meta name="robots" content="noindex,follow" />' . "\n  ";
    if (!empty($faqs)) {
        $faq_ld = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(fn($f) => [
                '@type'          => 'Question',
                'name'           => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $faqs),
        ];
        echo '<script type="application/ld+json">'
           . json_encode($faq_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
           . '</script>' . "\n  ";
    }
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

  <?php if (!empty($faqs)): ?>
    <section class="mt-10 max-w-3xl">
      <h2 class="text-xl md:text-2xl font-bold text-gray-900 mb-4">Preguntas frecuentes</h2>
      <div class="space-y-3">
        <?php foreach ($faqs as $f): ?>
          <details class="bg-gray-50 border border-gray-100 rounded-2xl p-4">
            <summary class="font-semibold text-gray-900 cursor-pointer"><?= htmlspecialchars($f['q']) ?></summary>
            <p class="text-sm text-gray-600 mt-2 leading-relaxed"><?= htmlspecialchars($f['a']) ?></p>
          </details>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

</main>

<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
</body>
</html>
