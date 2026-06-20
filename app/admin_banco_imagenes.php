<?php
/**
 * VISTA ADMIN: BANCO DE IMÁGENES DE SERVICIOS
 * - Sube imágenes profesionales al banco, genera 3 tamaños (thumb/card/main) y las asocia a categoría.
 * - Activar/desactivar y eliminar. Las imágenes activas alimentan el carrusel de publicar/editar servicio.
 */
session_start();
require_once __DIR__ . '/conexion.php';

if (file_exists(__DIR__ . '/iconos.php')) require_once __DIR__ . '/iconos.php';
if (!function_exists('icon')) { function icon($n, $c = '') { return '<span>❖</span>'; } }

/* ----------------- SEGURIDAD ----------------- */
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header("Location: /login");
    exit;
}

/* ----------------- CONSTANTES ----------------- */
$CATEGORIAS = ['Matemáticas','Química','Física','Biología','Programación','Idiomas','Historia','Lenguaje','Economía','Diseño','Derecho','Asesoría','Otros'];
$DIR_FS  = $_SERVER['DOCUMENT_ROOT'] . '/upload/banco/';
$DIR_WEB = '/upload/banco/';
$PLACEHOLDER = 'placeholder.webp'; // compartido por todas las categorías: NUNCA borrar el archivo físico

/* ----------------- CSRF ----------------- */
if (empty($_SESSION['csrf_banco'])) $_SESSION['csrf_banco'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_banco'];

/* ----------------- HELPERS ----------------- */
function slug_categoria(string $txt): string {
    $txt = strtolower(trim($txt));
    $txt = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $txt);
    $txt = preg_replace('/[^a-z0-9]/', '', $txt);
    return $txt !== '' ? $txt : 'otro';
}

// Redimensiona (si hace falta) y guarda WebP. Devuelve bool.
function banco_generar_tamano($src, int $w0, int $h0, int $max_w, string $dest, int $q): bool {
    if ($w0 <= $max_w) {
        return imagewebp($src, $dest, $q);
    }
    $new_w = $max_w;
    $new_h = (int) round(($h0 / $w0) * $max_w);
    $dst = imagecreatetruecolor($new_w, $new_h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $w0, $h0);
    $ok = imagewebp($dst, $dest, $q);
    imagedestroy($dst);
    return $ok;
}

/* ----------------- ACCIONES (POST + PRG) ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Candado CSRF para TODAS las acciones
    if (!hash_equals($_SESSION['csrf_banco'] ?? '', $_POST['csrf'] ?? '')) {
        $_SESSION['alerta_error'] = "Sesión expirada. Recarga la página e intenta de nuevo.";
        header("Location: /admin/banco-imagenes"); exit;
    }

    $accion = $_POST['accion'] ?? '';

    // --- TOGGLE ACTIVA ---
    if ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare("UPDATE banco_imagenes SET activa = NOT activa WHERE id = ?");
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
            $_SESSION['alerta_ok'] = "Estado de la imagen actualizado.";
        }
        header("Location: /admin/banco-imagenes"); exit;
    }

    // --- ELIMINAR ---
    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare("SELECT archivo FROM banco_imagenes WHERE id = ? LIMIT 1");
            $st->bind_param("i", $id);
            $st->execute();
            $st->bind_result($archivo);
            $st->fetch();
            $st->close();

            // Borrar los 3 archivos físicos — salvo el placeholder compartido
            if (!empty($archivo) && $archivo !== $PLACEHOLDER) {
                $base = pathinfo($archivo, PATHINFO_FILENAME);
                foreach ([$archivo, $base . '_card.webp', $base . '_thumb.webp'] as $f) {
                    $ruta = $DIR_FS . basename($f);
                    if (is_file($ruta)) @unlink($ruta);
                }
            }

            $st = $conn->prepare("DELETE FROM banco_imagenes WHERE id = ?");
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
            $_SESSION['alerta_ok'] = "Imagen eliminada del banco.";
        }
        header("Location: /admin/banco-imagenes"); exit;
    }

    // --- SUBIR NUEVA ---
    if ($accion === 'subir') {
        $categoria   = trim($_POST['categoria'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');

        if (!in_array($categoria, $CATEGORIAS, true)) {
            $_SESSION['alerta_error'] = "Categoría inválida.";
            header("Location: /admin/banco-imagenes"); exit;
        }
        if (mb_strlen($descripcion) > 200) {
            $_SESSION['alerta_error'] = "La descripción no puede superar 200 caracteres.";
            header("Location: /admin/banco-imagenes"); exit;
        }
        if (empty($_FILES['imagen']['name']) || ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $_SESSION['alerta_error'] = "Debes seleccionar una imagen válida.";
            header("Location: /admin/banco-imagenes"); exit;
        }
        if ($_FILES['imagen']['size'] > 15 * 1024 * 1024) {
            $_SESSION['alerta_error'] = "La imagen no puede superar 15MB.";
            header("Location: /admin/banco-imagenes"); exit;
        }

        $tmp = $_FILES['imagen']['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false) {
            $_SESSION['alerta_error'] = "El archivo no es una imagen válida.";
            header("Location: /admin/banco-imagenes"); exit;
        }
        $mime = $info['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $_SESSION['alerta_error'] = "Formato no permitido. Usa JPG, PNG o WebP.";
            header("Location: /admin/banco-imagenes"); exit;
        }
        if ((int)$info[0] < 800) {
            $_SESSION['alerta_error'] = "La imagen es muy chica ({$info[0]}px de ancho). Mínimo 800px.";
            header("Location: /admin/banco-imagenes"); exit;
        }

        // Cargar recurso GD
        $img = null;
        if     ($mime === 'image/jpeg') $img = @imagecreatefromjpeg($tmp);
        elseif ($mime === 'image/png')  $img = @imagecreatefrompng($tmp);
        elseif ($mime === 'image/webp') $img = @imagecreatefromwebp($tmp);

        if (!$img) {
            $_SESSION['alerta_error'] = "No se pudo procesar la imagen.";
            header("Location: /admin/banco-imagenes"); exit;
        }

        if (!is_dir($DIR_FS)) @mkdir($DIR_FS, 0755, true);

        $w0 = imagesx($img);
        $h0 = imagesy($img);
        $base = 'banco_' . slug_categoria($categoria) . '_' . uniqid();

        // 3 tamaños responsivos (igual que publicar_servicio.php)
        $ok_thumb = banco_generar_tamano($img, $w0, $h0, 400,  $DIR_FS . $base . '_thumb.webp', 85);
        $ok_card  = banco_generar_tamano($img, $w0, $h0, 800,  $DIR_FS . $base . '_card.webp',  85);
        $ok_main  = banco_generar_tamano($img, $w0, $h0, 1600, $DIR_FS . $base . '.webp',       85);
        imagedestroy($img);

        if (!$ok_main) {
            $_SESSION['alerta_error'] = "Error al generar el archivo WebP.";
            header("Location: /admin/banco-imagenes"); exit;
        }

        $archivo = $base . '.webp';

        $conn->begin_transaction();

        try {
            // 1. INSERT imagen nueva
            $st = $conn->prepare("INSERT INTO banco_imagenes (categoria, archivo, descripcion, activa) VALUES (?, ?, ?, 1)");
            $st->bind_param("sss", $categoria, $archivo, $descripcion);
            $st->execute();
            $nuevo_id = $conn->insert_id;
            $st->close();

            // 2. PASO A — desactivar imágenes anteriores de la MISMA categoría
            $st_a = $conn->prepare("UPDATE banco_imagenes SET activa = 0 WHERE categoria = ? AND id != ?");
            $st_a->bind_param("si", $categoria, $nuevo_id);
            $st_a->execute();
            $st_a->close();

            // 3. PASO B — reasignar servicios de la categoría a la nueva imagen
            $st_b = $conn->prepare("UPDATE servicios SET imagen_banco_id = ? WHERE categoria COLLATE utf8mb4_unicode_ci = ?");
            $st_b->bind_param("is", $nuevo_id, $categoria);
            $st_b->execute();
            $st_b->close();

            $conn->commit();
            $_SESSION['alerta_ok'] = "Imagen subida al banco ($categoria). Reemplazó la anterior y se asignó a todos los servicios de la categoría.";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['alerta_error'] = "Error al actualizar el banco: " . $e->getMessage();
        }

        header("Location: /admin/banco-imagenes");
        exit;
    }

    // Acción desconocida
    header("Location: /admin/banco-imagenes"); exit;
}

/* ----------------- LISTADO ----------------- */
$banco_por_cat = array_fill_keys($CATEGORIAS, []);
$res = $conn->query("SELECT id, categoria, archivo, descripcion, activa, created_at FROM banco_imagenes ORDER BY categoria, id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cat = $row['categoria'];
        if (!isset($banco_por_cat[$cat])) $banco_por_cat[$cat] = []; // por si hay categoría fuera de las 12
        $banco_por_cat[$cat][] = $row;
    }
}
$total_imgs = 0;
foreach ($banco_por_cat as $arr) $total_imgs += count($arr);

$mensaje_ok = $mensaje_error = '';
if (!empty($_SESSION['alerta_ok']))    { $mensaje_ok    = $_SESSION['alerta_ok'];    unset($_SESSION['alerta_ok']); }
if (!empty($_SESSION['alerta_error'])) { $mensaje_error = $_SESSION['alerta_error']; unset($_SESSION['alerta_error']); }

$app_dir = __DIR__; // para incluir los componentes compartidos (header/sidebar/nav_bottom)

// Resuelve la miniatura del banco (thumb → main) con cache-busting
function thumb_banco(string $archivo, string $dir_fs, string $dir_web): string {
    $base = pathinfo($archivo, PATHINFO_FILENAME);
    $thumb = $dir_fs . $base . '_thumb.webp';
    if (is_file($thumb)) return $dir_web . $base . '_thumb.webp?v=' . filemtime($thumb);
    $main = $dir_fs . basename($archivo);
    if (is_file($main)) return $dir_web . basename($archivo) . '?v=' . filemtime($main);
    return $dir_web . 'placeholder.webp';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <title>Banco de Imágenes - Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased">

<?php
require_once $app_dir . '/componentes/header.php';
require_once $app_dir . '/componentes/sidebar.php';
?>

<main class="pt-16 pb-32 md:pb-16 lg:ml-64 px-4 md:px-6 w-full md:w-[calc(100%-16rem)]">
  <div class="max-w-7xl mx-auto space-y-6">

    <div class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-gray-900">Banco de Imágenes</h1>
        <p class="text-sm text-gray-500 mt-1"><?= $total_imgs ?> imágen<?= $total_imgs === 1 ? '' : 'es' ?> en el banco. Las activas aparecen en el carrusel al publicar/editar un servicio.</p>
    </div>

    <?php if ($mensaje_ok): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm flex items-center gap-3">
            <?= icon('check-circle') ?> <?= htmlspecialchars($mensaje_ok) ?>
        </div>
    <?php endif; ?>
    <?php if ($mensaje_error): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm flex items-center gap-3">
            <?= icon('alert-triangle') ?> <?= htmlspecialchars($mensaje_error) ?>
        </div>
    <?php endif; ?>

    <!-- FORMULARIO SUBIR -->
    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 mb-10 transition-all hover:shadow-md">
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2"><?= icon('plus-circle') ?> Subir nueva imagen</h2>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="accion" value="subir">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Categoría</label>
                    <select name="categoria" required class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none appearance-none">
                        <option value="">Selecciona una categoría...</option>
                        <?php foreach ($CATEGORIAS as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Descripción (opcional, máx 200)</label>
                    <textarea name="descripcion" maxlength="200" rows="1" placeholder="Ej: Pizarra con ecuaciones, tonos azules"
                              class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none resize-none"></textarea>
                </div>

                <div class="md:col-span-2 bg-sky-50/50 border border-dashed border-sky-200 rounded-2xl p-6 text-center hover:bg-sky-50 transition-all group">
                    <label class="cursor-pointer block">
                        <span class="block text-sky-600 mb-2 font-semibold">Sube una imagen</span>
                        <span class="text-xs text-gray-500 block mb-4">JPG, PNG o WebP · mín. 800px de ancho · máx. 15MB · se generan 3 tamaños WebP</span>
                        <input type="file" name="imagen" accept="image/jpeg,image/png,image/webp" required class="hidden" onchange="previewBanco(event)">
                        <div class="inline-flex items-center justify-center px-6 py-2 bg-white border border-sky-200 rounded-xl text-sky-700 text-sm font-semibold shadow-sm group-hover:shadow transition-all">Seleccionar archivo</div>
                    </label>
                    <img id="previewBancoImg" class="mt-4 mx-auto max-h-44 rounded-xl shadow-sm object-cover hidden">
                </div>

                <div class="md:col-span-2 flex justify-end">
                    <button type="submit" class="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white font-bold py-3 px-8 rounded-xl shadow-md hover:shadow-lg hover:scale-[1.02] transition-all">
                        Subir al banco
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- LISTADO POR CATEGORÍA -->
    <?php foreach ($CATEGORIAS as $cat): $imgs = $banco_por_cat[$cat] ?? []; ?>
        <section class="mb-10">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($cat) ?></h2>
                <span class="text-xs font-semibold text-gray-400"><?= count($imgs) ?> imagen<?= count($imgs) === 1 ? '' : 'es' ?></span>
            </div>

            <?php if (empty($imgs)): ?>
                <div class="bg-gray-50 border border-dashed border-gray-200 rounded-2xl py-8 text-center text-sm text-gray-400">
                    Sin imágenes en esta categoría todavía.
                </div>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php foreach ($imgs as $im):
                        $thumb = thumb_banco($im['archivo'], $DIR_FS, $DIR_WEB);
                        $activa = (int)$im['activa'] === 1;
                    ?>
                        <div class="group relative bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden hover:shadow-md transition-all">
                            <div class="relative w-full h-[130px] bg-gray-100 overflow-hidden">
                                <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($im['descripcion'] ?: $cat) ?>" class="w-full h-full object-cover <?= $activa ? '' : 'opacity-40 grayscale' ?>" loading="lazy">
                                <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $activa ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' ?>">
                                    <?= $activa ? 'Activa' : 'Inactiva' ?>
                                </span>
                                <!-- Acciones (hover) -->
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                                    <form method="POST">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="accion" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$im['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 bg-white text-gray-800 text-xs font-bold rounded-lg shadow hover:bg-gray-100 transition" title="Activar/Desactivar">
                                            <?= $activa ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('¿Eliminar esta imagen del banco? Se borran los 3 tamaños. Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= (int)$im['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 bg-red-500 text-white text-xs font-bold rounded-lg shadow hover:bg-red-600 transition" title="Eliminar">
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="p-3">
                                <p class="text-[11px] font-mono text-gray-400 truncate" title="<?= htmlspecialchars($im['archivo']) ?>"><?= htmlspecialchars($im['archivo']) ?></p>
                                <p class="text-xs text-gray-600 mt-1 line-clamp-2 min-h-[2rem]"><?= htmlspecialchars($im['descripcion'] ?: '—') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

  </div>
</main>

<?php require_once $app_dir . '/componentes/nav_bottom.php'; ?>

<script>
function previewBanco(e) {
    const p = document.getElementById('previewBancoImg');
    const f = e.target.files[0];
    if (f) { p.src = URL.createObjectURL(f); p.classList.remove('hidden'); }
}
</script>
</body>
</html>
