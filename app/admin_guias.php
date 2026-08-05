<?php
/**
 * PANEL ADMIN: CENTRO DE RECURSOS — Guías Nubira
 * CRUD de artículos (guias_articulos) + FAQs anidadas + generación asistida por IA.
 * Patrón: RBAC de 1 línea + PRG, mismo criterio que admin_avisos.php/admin_banco_imagenes.php.
 */
require_once __DIR__ . '/init_sesion.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/helpers/seo.php';           // generar_slug()
require_once __DIR__ . '/helpers/sanitizar_html.php'; // nb_sanitizar_html()
require_once __DIR__ . '/helpers/guias_imagenes.php'; // nb_guia_subir_portada() / nb_guia_subir_imagen_inline()

if (($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /"); exit;
}

$csrf = $_SESSION['csrf_token'];
$DIR_FS  = $_SERVER['DOCUMENT_ROOT'] . '/upload/guias/';
$DIR_WEB = '/upload/guias/';

// Tags + atributos permitidos en el cuerpo del artículo — único lugar que
// define esta lista (usado tanto al guardar acá como en el borrador de IA,
// ajax_generar_borrador_guia.php, que hoy usa el default más chico sin img).
$TAGS_CUERPO = ['p','h2','h3','ul','ol','li','strong','em','img'];
$ATRIBUTOS_CUERPO = [
    'img' => [
        'src' => '#^/upload/guias/[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|webp)$#',
        'alt' => null,
    ],
];

/* ----------------- CATEGORÍAS HABILITADAS (para el <select>) ----------------- */
$categorias_habilitadas = [];
$res_cat = $conn->query("SELECT id, nombre, slug, solo_tutores FROM guias_categorias WHERE habilitada = 1 ORDER BY orden");
while ($res_cat && $row = $res_cat->fetch_assoc()) $categorias_habilitadas[] = $row;

// Categoría "Para Tutores" — resuelta por el flag de schema (solo_tutores=1),
// no por un id hardcodeado (el auto_increment puede diferir entre local y producción).
$categoria_tutores = null;
foreach ($categorias_habilitadas as $c) {
    if ((int)$c['solo_tutores'] === 1) { $categoria_tutores = $c; break; }
}

// Tab del listado admin: "todas" (default, sin filtro) o "tutores" (aísla la
// categoría Para Tutores). Se propaga por los links de navegación de abajo
// para que moverse dentro del panel no pierda el filtro activo.
$tab = (($_GET['tab'] ?? '') === 'tutores' && $categoria_tutores) ? 'tutores' : 'todas';
$tab_amp = $tab === 'tutores' ? '&tab=tutores' : ''; // para anexar a URLs con "?xxx=" existente
$tab_qs  = $tab === 'tutores' ? '?tab=tutores' : ''; // para URLs base sin querystring

/* ----------------- ACCIONES (POST + PRG) ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        $_SESSION['alerta_error'] = "Sesión expirada. Recarga la página e intenta de nuevo.";
        header("Location: /admin/guias"); exit;
    }

    $accion = $_POST['accion'] ?? '';

    // --- GUARDAR (crear o actualizar) — NUNCA deja pasar estado='publicado' acá ---
    if ($accion === 'guardar') {
        $id           = (int)($_POST['id'] ?? 0);
        $categoria_id = (int)($_POST['categoria_id'] ?? 0);
        $titulo       = trim($_POST['titulo'] ?? '');
        $resumen      = trim($_POST['resumen'] ?? '');
        $cuerpo_raw   = $_POST['cuerpo'] ?? '';
        $meta_desc    = trim($_POST['meta_description'] ?? '');
        $autor_nombre = trim($_POST['autor_nombre'] ?? '') ?: 'Equipo Nubira';
        $fuente_ia    = !empty($_POST['fuente_ia']) ? 1 : 0;
        $revisado     = !empty($_POST['revisado_humano']) ? 1 : 0;

        $categoria_valida = false;
        foreach ($categorias_habilitadas as $c) { if ((int)$c['id'] === $categoria_id) $categoria_valida = true; }

        if ($titulo === '' || !$categoria_valida || trim(strip_tags($cuerpo_raw)) === '') {
            $_SESSION['alerta_error'] = "Faltan campos obligatorios (título, categoría o cuerpo).";
            header("Location: /admin/guias?" . ($id ? "editar=$id" : "nuevo=1") . $tab_amp); exit;
        }

        // Sanitización — momento 2 de 2 (el 1 ocurrió en ajax_generar_borrador_guia.php si vino de IA;
        // acá se aplica SIEMPRE, sin importar el origen, por si se editó a mano).
        $cuerpo = nb_sanitizar_html($cuerpo_raw, $TAGS_CUERPO, $ATRIBUTOS_CUERPO);

        $imagen_portada = null;
        if (!empty($_FILES['imagen_portada']['name'])) {
            $imagen_portada = nb_guia_subir_portada($_FILES['imagen_portada'], $DIR_FS);
        }

        if ($id > 0) {
            $slug = trim($_POST['slug'] ?? '') ?: generar_slug($titulo);
            $sql = "UPDATE guias_articulos SET categoria_id=?, titulo=?, slug=?, resumen=?, cuerpo=?,
                    autor_nombre=?, meta_description=?, fuente_ia=?, revisado_humano=?" .
                    ($imagen_portada ? ", imagen_portada=?" : "") . " WHERE id=?";
            $stmt = $conn->prepare($sql);
            if ($imagen_portada) {
                $stmt->bind_param("issssssiisi", $categoria_id, $titulo, $slug, $resumen, $cuerpo,
                    $autor_nombre, $meta_desc, $fuente_ia, $revisado, $imagen_portada, $id);
            } else {
                $stmt->bind_param("issssssiii", $categoria_id, $titulo, $slug, $resumen, $cuerpo,
                    $autor_nombre, $meta_desc, $fuente_ia, $revisado, $id);
            }
            $stmt->execute();
            $stmt->close();
            $articulo_id = $id;
        } else {
            $slug = generar_slug($titulo);
            $stmt = $conn->prepare("INSERT INTO guias_articulos
                (categoria_id, titulo, slug, resumen, cuerpo, imagen_portada, autor_nombre, meta_description, fuente_ia, revisado_humano)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("issssssiii", $categoria_id, $titulo, $slug, $resumen, $cuerpo,
                $imagen_portada, $autor_nombre, $meta_desc, $fuente_ia, $revisado);
            $stmt->execute();
            $articulo_id = $stmt->insert_id;
            $stmt->close();
        }

        // FAQs: se borran y re-insertan (volumen chico, más simple que un diff)
        $stmt_del = $conn->prepare("DELETE FROM guias_articulo_faqs WHERE articulo_id = ?");
        $stmt_del->bind_param("i", $articulo_id);
        $stmt_del->execute();
        $stmt_del->close();

        $preguntas  = $_POST['faq_pregunta'] ?? [];
        $respuestas = $_POST['faq_respuesta'] ?? [];
        foreach ($preguntas as $i => $p) {
            $p = trim($p);
            $r = trim($respuestas[$i] ?? '');
            if ($p === '' || $r === '') continue;
            $stmt_faq = $conn->prepare("INSERT INTO guias_articulo_faqs (articulo_id, pregunta, respuesta, orden) VALUES (?,?,?,?)");
            $stmt_faq->bind_param("issi", $articulo_id, $p, $r, $i);
            $stmt_faq->execute();
            $stmt_faq->close();
        }

        $_SESSION['alerta_ok'] = "Artículo guardado como borrador.";
        header("Location: /admin/guias?editar=$articulo_id"); exit;
    }

    // --- PUBLICAR — el gate real vive acá, a nivel de servidor ---
    if ($accion === 'publicar') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT fuente_ia, revisado_humano, fecha_publicacion FROM guias_articulos WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $_SESSION['alerta_error'] = "Artículo no encontrado.";
            header("Location: /admin/guias"); exit;
        }
        if ((int)$row['fuente_ia'] === 1 && (int)$row['revisado_humano'] === 0) {
            $_SESSION['alerta_error'] = "No se puede publicar: este artículo viene de IA y falta marcar 'Revisé y edité este contenido'.";
            header("Location: /admin/guias?editar=$id"); exit;
        }

        if ($row['fecha_publicacion'] === null) {
            $stmt = $conn->prepare("UPDATE guias_articulos SET estado='publicado', fecha_publicacion=NOW() WHERE id=?");
        } else {
            $stmt = $conn->prepare("UPDATE guias_articulos SET estado='publicado' WHERE id=?");
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['alerta_ok'] = "Artículo publicado.";
        header("Location: /admin/guias?editar=$id"); exit;
    }

    // --- ARCHIVAR (soft-delete, sin borrado físico) ---
    if ($accion === 'archivar') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE guias_articulos SET estado='archivado' WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['alerta_ok'] = "Artículo archivado.";
        header("Location: /admin/guias{$tab_qs}"); exit;
    }
}

/* ----------------- CARGA PARA RENDER (GET) ----------------- */
$modo = 'listado';
$articulo = null;
$faqs_articulo = [];

if (isset($_GET['nuevo'])) {
    $modo = 'form';
} elseif (isset($_GET['editar'])) {
    $modo = 'form';
    $id_editar = (int)$_GET['editar'];
    $stmt = $conn->prepare("SELECT * FROM guias_articulos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id_editar);
    $stmt->execute();
    $articulo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($articulo) {
        $stmt = $conn->prepare("SELECT pregunta, respuesta FROM guias_articulo_faqs WHERE articulo_id = ? ORDER BY orden");
        $stmt->bind_param("i", $id_editar);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $faqs_articulo[] = $row;
        $stmt->close();
    } else {
        $modo = 'listado';
    }
}

// $categoria_candado: candado del <select> real de categoría — SOLO al crear desde
// el tab "Para Tutores" (nunca al editar, para no trabar una recategorización legítima).
$categoria_candado = ($tab === 'tutores' && !$articulo && $categoria_tutores);

// $es_categoria_tutores: gate de "esto es contenido interno para tutores" — true al
// crear desde el tab (vía $categoria_candado) O al editar un artículo cuya categoría
// real ya es "Para Tutores" (se mira $articulo['categoria_id'] en BD, no el tab de la
// URL — se puede llegar a editar ese artículo desde el tab "todas" también). Controla
// ocultar IA / meta description / FAQs, que no tienen sentido para este contenido sin
// importar si estás creando o editando.
$es_categoria_tutores = $categoria_candado
    || ($articulo && $categoria_tutores && (int)($articulo['categoria_id'] ?? 0) === (int)$categoria_tutores['id']);

$articulos_listado = [];
if ($modo === 'listado') {
    $sql_listado = "SELECT a.id, a.titulo, a.estado, a.fecha_publicacion, a.fuente_ia, a.revisado_humano, c.nombre AS categoria_nombre
                     FROM guias_articulos a
                     JOIN guias_categorias c ON c.id = a.categoria_id";
    // "todas" = todas las categorías PÚBLICAS, nunca incluye contenido de tutores —
    // los 2 tabs quedan mutuamente excluyentes, un artículo aparece en uno u otro, nunca en ambos.
    $sql_listado .= ($tab === 'tutores') ? " WHERE c.solo_tutores = 1" : " WHERE c.solo_tutores = 0";
    $sql_listado .= " ORDER BY a.fecha_actualizacion DESC";
    $res = $conn->query($sql_listado);
    while ($res && $row = $res->fetch_assoc()) $articulos_listado[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <title>Centro de Recursos | Admin Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;background:#f8fafc}</style>
</head>
<body class="text-gray-900">

<?php require_once __DIR__ . '/componentes/header.php'; ?>
<?php require_once __DIR__ . '/componentes/sidebar.php'; ?>

<main class="pt-20 pb-28 md:pb-10 lg:ml-64 px-4 max-w-[1100px] mx-auto md:px-8">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1.5 h-1.5 rounded-full bg-[#54A6D8]"></span>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Admin · Centro de Recursos</p>
            </div>
            <h1 class="text-2xl font-semibold tracking-tight">Guías Nubira</h1>
        </div>
        <?php if ($modo === 'listado'): ?>
        <a href="/admin/guias?nuevo=1<?= $tab_amp ?>" class="px-4 py-2 rounded-full bg-[#54A6D8] text-white text-sm font-bold hover:opacity-90 transition">Nuevo artículo</a>
        <?php else: ?>
        <a href="/admin/guias<?= $tab_qs ?>" class="text-sm font-bold text-gray-500 hover:text-gray-700">← Volver al listado</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['alerta_ok'])): ?>
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 text-sm font-medium rounded-xl p-3"><?= htmlspecialchars($_SESSION['alerta_ok']) ?></div>
        <?php unset($_SESSION['alerta_ok']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['alerta_error'])): ?>
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 text-sm font-medium rounded-xl p-3"><?= htmlspecialchars($_SESSION['alerta_error']) ?></div>
        <?php unset($_SESSION['alerta_error']); ?>
    <?php endif; ?>

    <?php if ($modo === 'listado'): ?>
    <?php if ($categoria_tutores): ?>
    <div class="flex gap-2 mb-4">
        <a href="/admin/guias" class="px-4 py-1.5 rounded-full text-sm font-bold transition <?= $tab === 'todas' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' ?>">Recursos públicos</a>
        <a href="/admin/guias?tab=tutores" class="px-4 py-1.5 rounded-full text-sm font-bold transition <?= $tab === 'tutores' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' ?>">Para Tutores</a>
    </div>
    <?php endif; ?>
    <section class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="p-3">Título</th>
                    <th class="p-3">Categoría</th>
                    <th class="p-3">Estado</th>
                    <th class="p-3">Origen</th>
                    <th class="p-3">Publicado</th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($articulos_listado as $a): ?>
                <tr>
                    <td class="p-3 font-semibold"><?= htmlspecialchars($a['titulo']) ?></td>
                    <td class="p-3 text-gray-500"><?= htmlspecialchars($a['categoria_nombre']) ?></td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[11px] font-bold
                            <?= $a['estado'] === 'publicado' ? 'bg-green-100 text-green-700' : ($a['estado'] === 'archivado' ? 'bg-gray-100 text-gray-500' : 'bg-amber-100 text-amber-700') ?>">
                            <?= htmlspecialchars($a['estado']) ?>
                        </span>
                    </td>
                    <td class="p-3 text-gray-500">
                        <?php if ($a['fuente_ia']): ?>
                            IA <?= $a['revisado_humano'] ? '✓ revisado' : '⚠ sin revisar' ?>
                        <?php else: ?>manual<?php endif; ?>
                    </td>
                    <td class="p-3 text-gray-500"><?= $a['fecha_publicacion'] ? htmlspecialchars($a['fecha_publicacion']) : '—' ?></td>
                    <td class="p-3"><a href="/admin/guias?editar=<?= (int)$a['id'] ?><?= $tab_amp ?>" class="text-[#54A6D8] font-bold hover:underline">Editar</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($articulos_listado)): ?>
                <tr><td colspan="6" class="p-6 text-center text-gray-400">Todavía no hay artículos.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php else: /* ---------- FORMULARIO CREAR/EDITAR ---------- */ ?>

    <?php if (!$es_categoria_tutores): // "Para Tutores" es redacción manual, sin asistencia IA ?>
    <section class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="text-base font-semibold mb-4">Generar borrador con IA</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
            <div>
                <label class="text-xs font-bold text-gray-500 uppercase">Categoría</label>
                <select id="ia_categoria" class="w-full border border-gray-200 rounded-xl p-2 mt-1">
                    <?php foreach ($categorias_habilitadas as $c): ?>
                    <option value="<?= htmlspecialchars($c['nombre']) ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs font-bold text-gray-500 uppercase">Tono</label>
                <select id="ia_tono" class="w-full border border-gray-200 rounded-xl p-2 mt-1">
                    <option value="default">Premium equilibrado</option>
                    <option value="academico">Académico formal</option>
                    <option value="persuasivo">Persuasivo profesional</option>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 uppercase">Tema del artículo</label>
            <input type="text" id="ia_tema" placeholder="Ej: Cómo estudiar Cálculo I desde cero" class="w-full border border-gray-200 rounded-xl p-2 mt-1">
        </div>
        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 uppercase">Puntos clave (opcional)</label>
            <textarea id="ia_puntos_clave" rows="2" placeholder="Bullets que querés que el artículo cubra" class="w-full border border-gray-200 rounded-xl p-2 mt-1"></textarea>
        </div>
        <button type="button" id="btn_generar_ia" class="px-4 py-2 rounded-full bg-gray-900 text-white text-sm font-bold hover:opacity-90 transition">Generar con IA</button>
        <span id="ia_estado" class="ml-3 text-xs text-gray-500"></span>
    </section>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl border border-gray-200 p-6">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= (int)($articulo['id'] ?? 0) ?>">
        <input type="hidden" name="fuente_ia" id="campo_fuente_ia" value="<?= (int)($articulo['fuente_ia'] ?? 0) ?>">

        <?php // $categoria_candado ya calculado más arriba ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
            <div>
                <label class="text-xs font-bold text-gray-500 uppercase">Categoría</label>
                <?php if ($categoria_candado): ?>
                <!-- Un <select disabled> no viaja en el POST del <form>, así que el valor real
                     se manda por input hidden y el select se reemplaza por un badge de solo lectura. -->
                <input type="hidden" name="categoria_id" value="<?= (int)$categoria_tutores['id'] ?>">
                <div class="w-full border border-gray-200 bg-gray-50 rounded-xl p-2 mt-1 text-gray-600 font-medium">
                    <?= htmlspecialchars($categoria_tutores['nombre']) ?>
                    <span class="text-xs text-gray-400 font-normal">(fijo — creado desde el tab "Para Tutores")</span>
                </div>
                <?php else: ?>
                <select name="categoria_id" required class="w-full border border-gray-200 rounded-xl p-2 mt-1">
                    <?php foreach ($categorias_habilitadas as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (($articulo['categoria_id'] ?? null) == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div>
                <label class="text-xs font-bold text-gray-500 uppercase">Autor</label>
                <input type="text" name="autor_nombre" value="<?= htmlspecialchars($articulo['autor_nombre'] ?? 'Equipo Nubira') ?>" class="w-full border border-gray-200 rounded-xl p-2 mt-1">
            </div>
        </div>

        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 uppercase">Título</label>
            <input type="text" name="titulo" id="campo_titulo" required value="<?= htmlspecialchars($articulo['titulo'] ?? '') ?>" class="w-full border border-gray-200 rounded-xl p-2 mt-1">
        </div>
        <?php if (!empty($articulo['slug'])): ?>
        <input type="hidden" name="slug" value="<?= htmlspecialchars($articulo['slug']) ?>">
        <p class="text-xs text-gray-400 mb-3">Slug: /guias/.../<?= htmlspecialchars($articulo['slug']) ?> (no cambia al editar)</p>
        <?php endif; ?>

        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 uppercase">Resumen (dek, máx 300 caracteres)</label>
            <textarea name="resumen" id="campo_resumen" rows="2" maxlength="300" class="w-full border border-gray-200 rounded-xl p-2 mt-1"><?= htmlspecialchars($articulo['resumen'] ?? '') ?></textarea>
        </div>

        <div class="mb-3">
            <div class="flex items-center justify-between">
                <label class="text-xs font-bold text-gray-500 uppercase">Cuerpo (HTML simple: p, h2, h3, ul, ol, li, strong, em, img)</label>
                <button type="button" id="btn_insertar_imagen" class="text-xs font-bold text-[#54A6D8] hover:underline">+ Insertar imagen</button>
            </div>
            <input type="file" id="input_imagen_inline" accept="image/jpeg,image/png,image/webp" class="hidden">
            <p id="estado_imagen_inline" class="text-xs text-gray-400 mt-1 hidden"></p>
            <textarea name="cuerpo" id="campo_cuerpo" rows="14" required class="w-full border border-gray-200 rounded-xl p-2 mt-1 font-mono text-sm"><?= /* Sin htmlspecialchars(): cuerpo ya viene sanitizado por nb_sanitizar_html() antes de guardarse (único punto de escritura, ver admin_guias.php:99) — envolverlo de nuevo produce doble-escape (&#039; visible). */ $articulo['cuerpo'] ?? '' ?></textarea>
        </div>

        <?php if (!$es_categoria_tutores): // contenido interno para tutores: sin SEO ?>
        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 uppercase">Meta description (máx 155 caracteres)</label>
            <textarea name="meta_description" id="campo_meta" rows="2" maxlength="200" class="w-full border border-gray-200 rounded-xl p-2 mt-1"><?= htmlspecialchars($articulo['meta_description'] ?? '') ?></textarea>
        </div>
        <?php endif; ?>

        <div class="mb-3">
            <label class="text-xs font-bold text-gray-500 uppercase">Imagen de portada</label>
            <input type="file" name="imagen_portada" accept="image/jpeg,image/png,image/webp" class="w-full border border-gray-200 rounded-xl p-2 mt-1">
            <?php if (!empty($articulo['imagen_portada'])): ?>
            <p class="text-xs text-gray-400 mt-1">Actual: <?= htmlspecialchars($articulo['imagen_portada']) ?> (sube una nueva para reemplazarla)</p>
            <?php endif; ?>
        </div>

        <?php if (!$es_categoria_tutores): // contenido interno para tutores: sin FAQs de SEO ?>
        <div class="mb-4 border-t border-gray-100 pt-4">
            <label class="text-xs font-bold text-gray-500 uppercase mb-2 block">FAQs (opcional)</label>
            <div id="lista_faqs">
                <?php foreach ($faqs_articulo as $faq): ?>
                <div class="faq-row grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                    <input type="text" name="faq_pregunta[]" placeholder="Pregunta" value="<?= htmlspecialchars($faq['pregunta']) ?>" class="border border-gray-200 rounded-xl p-2">
                    <input type="text" name="faq_respuesta[]" placeholder="Respuesta" value="<?= htmlspecialchars($faq['respuesta']) ?>" class="border border-gray-200 rounded-xl p-2">
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="btn_agregar_faq" class="text-xs font-bold text-[#54A6D8] hover:underline">+ Agregar pregunta</button>
        </div>
        <?php endif; ?>

        <div class="mb-4 border-t border-gray-100 pt-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="revisado_humano" id="campo_revisado" value="1" <?= !empty($articulo['revisado_humano']) ? 'checked' : '' ?>>
                Revisé y edité este contenido, es apto para publicar.
            </label>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="px-4 py-2 rounded-full bg-gray-900 text-white text-sm font-bold hover:opacity-90 transition">Guardar borrador</button>
        </div>
    </form>

    <?php if (!empty($articulo['id'])): ?>
    <div class="mt-4 flex items-center gap-3">
        <form method="POST" onsubmit="return confirm('¿Publicar este artículo?');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="publicar">
            <input type="hidden" name="id" value="<?= (int)$articulo['id'] ?>">
            <button type="submit" <?= ($articulo['fuente_ia'] && !$articulo['revisado_humano']) ? 'disabled title="Marca revisado_humano primero"' : '' ?>
                class="px-4 py-2 rounded-full bg-green-600 text-white text-sm font-bold hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed">
                Publicar
            </button>
        </form>
        <form method="POST" onsubmit="return confirm('¿Archivar este artículo?');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="archivar">
            <input type="hidden" name="id" value="<?= (int)$articulo['id'] ?>">
            <button type="submit" class="px-4 py-2 rounded-full bg-gray-100 text-gray-600 text-sm font-bold hover:bg-gray-200 transition">Archivar</button>
        </form>
    </div>
    <?php endif; ?>

    <script>
    // btn_agregar_faq / btn_generar_ia no existen en el DOM cuando el formulario está
    // bloqueado en "Para Tutores" (secciones ocultas más arriba) — sin este guard,
    // .addEventListener sobre null corta la ejecución de TODO el script de acá para
    // abajo, incluyendo el handler de "+ Insertar imagen" que sí debe correr siempre.
    var btnAgregarFaq = document.getElementById('btn_agregar_faq');
    if (btnAgregarFaq) btnAgregarFaq.addEventListener('click', function() {
        var div = document.createElement('div');
        div.className = 'faq-row grid grid-cols-1 md:grid-cols-2 gap-2 mb-2';
        div.innerHTML = '<input type="text" name="faq_pregunta[]" placeholder="Pregunta" class="border border-gray-200 rounded-xl p-2">' +
                        '<input type="text" name="faq_respuesta[]" placeholder="Respuesta" class="border border-gray-200 rounded-xl p-2">';
        document.getElementById('lista_faqs').appendChild(div);
    });

    var btnGenerarIa = document.getElementById('btn_generar_ia');
    if (btnGenerarIa) btnGenerarIa.addEventListener('click', function() {
        var btn = this;
        var estado = document.getElementById('ia_estado');
        var tema = document.getElementById('ia_tema').value.trim();
        if (!tema) { estado.textContent = 'Escribe un tema primero.'; return; }

        btn.disabled = true;
        estado.textContent = 'Generando...';

        fetch('/app/ajax_generar_borrador_guia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                categoria_nombre: document.getElementById('ia_categoria').value,
                tema: tema,
                puntos_clave: document.getElementById('ia_puntos_clave').value,
                tono: document.getElementById('ia_tono').value
            })
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (!data.exito) { estado.textContent = data.error || 'Error al generar.'; return; }

            document.getElementById('campo_titulo').value = data.titulo_h1 || '';
            document.getElementById('campo_resumen').value = data.resumen || '';
            document.getElementById('campo_cuerpo').value = data.cuerpo_html || '';
            document.getElementById('campo_meta').value = data.meta_description || '';
            document.getElementById('campo_fuente_ia').value = '1';
            document.getElementById('campo_revisado').checked = false;

            var listaFaqs = document.getElementById('lista_faqs');
            listaFaqs.innerHTML = '';
            (data.faqs || []).forEach(function(faq) {
                var div = document.createElement('div');
                div.className = 'faq-row grid grid-cols-1 md:grid-cols-2 gap-2 mb-2';
                div.innerHTML = '<input type="text" name="faq_pregunta[]" class="border border-gray-200 rounded-xl p-2">' +
                                '<input type="text" name="faq_respuesta[]" class="border border-gray-200 rounded-xl p-2">';
                listaFaqs.appendChild(div);
                div.querySelectorAll('input')[0].value = faq.pregunta;
                div.querySelectorAll('input')[1].value = faq.respuesta;
            });

            var avisos = [data.aviso_sanitizacion, data.aviso_resumen_incompleto, data.aviso_meta_incompleto].filter(Boolean);
            estado.textContent = avisos.length ? avisos.join(' ') : 'Borrador generado. Revisa y edita antes de tildar "Revisé".';
        })
        .catch(() => { btn.disabled = false; estado.textContent = 'Error de red. Intenta de nuevo.'; });
    });

    document.getElementById('btn_insertar_imagen').addEventListener('click', function() {
        document.getElementById('input_imagen_inline').click();
    });

    document.getElementById('input_imagen_inline').addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;

        var estado = document.getElementById('estado_imagen_inline');
        var campoCuerpo = document.getElementById('campo_cuerpo');
        estado.classList.remove('hidden');
        estado.textContent = 'Subiendo imagen...';

        var formData = new FormData();
        formData.append('imagen', file);

        fetch('/app/ajax_subir_imagen_guia.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            this.value = ''; // permite volver a elegir el mismo archivo si falla
            if (!data.exito) { estado.textContent = data.error || 'Error al subir la imagen.'; return; }

            var alt = window.prompt('Texto alternativo de la imagen (describe qué muestra):', '') || '';
            var tag = '<img src="' + data.url + '" alt="' + alt.replace(/"/g, '') + '">';

            var inicio = campoCuerpo.selectionStart ?? campoCuerpo.value.length;
            var fin = campoCuerpo.selectionEnd ?? campoCuerpo.value.length;
            campoCuerpo.value = campoCuerpo.value.slice(0, inicio) + tag + campoCuerpo.value.slice(fin);
            campoCuerpo.focus();

            estado.textContent = 'Imagen insertada en el cuerpo.';
        })
        .catch(() => { this.value = ''; estado.textContent = 'Error de red. Intenta de nuevo.'; });
    });
    </script>
    <?php endif; ?>

</main>
<?php require_once __DIR__ . '/componentes/nav_bottom.php'; ?>
</body>
</html>
