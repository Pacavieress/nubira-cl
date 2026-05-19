<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conexion.php';

// Si existe el sistema de iconos, lo cargamos.
if (file_exists(__DIR__ . '/app/iconos.php')) {
    require_once __DIR__ . '/app/iconos.php';
}
// Fallback para iconos si no está cargado en el entorno actual
if (!function_exists('icon')) {
    function icon($name) { return '<span>❖</span>'; } 
}

/* ----------------- SEGURIDAD ----------------- */
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: /login.php");
    exit;
}

/* ----------------- FUNCIONES ----------------- */
function obtenerTamanoYDimensionesImagen($ruta) {
    if (empty($ruta) || !file_exists($ruta) || !is_file($ruta)) return ['', ''];
    $size_bytes = filesize($ruta);
    $dimensiones = @getimagesize($ruta);
    if ($dimensiones === false) return ['', ''];
    $width = $dimensiones[0] ?? 0;
    $height = $dimensiones[1] ?? 0;

    if ($size_bytes >= 1048576) $tamano = number_format($size_bytes / 1048576, 2) . ' MB';
    elseif ($size_bytes >= 1024) $tamano = number_format($size_bytes / 1024, 1) . ' KB';
    else $tamano = $size_bytes . ' bytes';

    $dims = $width && $height ? "{$width}px × {$height}px" : '';
    return [$tamano, $dims];
}

function corregir_url($url) {
    if (empty($url)) return '#';
    if (!preg_match('#^https?://#i', $url)) return 'https://' . $url;
    return $url;
}

function convertir_a_webp($tmp_name, $destino_webp, $tipo) {
    switch ($tipo) {
        case 'image/jpeg': case 'image/jpg': $imagen = imagecreatefromjpeg($tmp_name); break;
        case 'image/png': 
            $imagen = imagecreatefrompng($tmp_name); 
            imagepalettetotruecolor($imagen); 
            imagealphablending($imagen, true); 
            imagesavealpha($imagen, true); 
            break;
        case 'image/gif': $imagen = imagecreatefromgif($tmp_name); break;
        default: return false;
    }
    if (!$imagen) return false;
    $ok = imagewebp($imagen, $destino_webp, 80);
    imagedestroy($imagen);
    return $ok;
}

/* ----------------- CONTROL Y ACCIONES ----------------- */
$rutaBase = $_SERVER['DOCUMENT_ROOT'] . '/upload/banners/';
$mensaje_error = ''; 
$mensaje_ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Guardar orden
    if (isset($_POST['guardar_orden'])) {
        if(isset($_POST['orden']) && is_array($_POST['orden'])) {
            foreach($_POST['orden'] as $id => $ord) {
                $id = (int)$id; 
                $ord = max(0, (int)$ord);
                $st = $conn->prepare("UPDATE banners SET orden=? WHERE id=?");
                $st->bind_param("ii", $ord, $id); 
                $st->execute(); 
                $st->close();
            }
            $_SESSION['alerta_ok'] = "Orden guardado correctamente.";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "#listaBanners");
        exit;
    }
    
    // Eliminar
    if (isset($_POST['eliminar_id'])) {
        $id = (int)$_POST['eliminar_id'];
        $st = $conn->prepare("SELECT imagen FROM banners WHERE id=?");
        $st->bind_param("i", $id); $st->execute(); $r = $st->get_result();
        if ($row = $r->fetch_assoc()) { 
            $f = $rutaBase . $row['imagen']; 
            if (file_exists($f)) unlink($f); 
        }
        $st->close();
        
        $st = $conn->prepare("DELETE FROM banners WHERE id=?");
        $st->bind_param("i", $id); 
        $st->execute();
        
        $_SESSION['alerta_ok'] = "Banner eliminado.";
        header("Location: " . $_SERVER['PHP_SELF'] . "#listaBanners");
        exit;
    }
    
    // Activar/desactivar
    if (isset($_POST['toggle_activo_id'])) {
        $id = (int)$_POST['toggle_activo_id'];
        $activo = ((int)$_POST['estado_actual'] === 1) ? 0 : 1;
        $st = $conn->prepare("UPDATE banners SET activo=? WHERE id=?");
        $st->bind_param("ii", $activo, $id); 
        $st->execute();
        $_SESSION['alerta_ok'] = $activo ? "Banner activado." : "Banner desactivado.";
        header("Location: " . $_SERVER['PHP_SELF'] . "#listaBanners");
        exit;
    }
    
    // Agregar o editar
    if (isset($_POST['editar_banner_id']) || isset($_POST['titulo'])) {
        $edit = isset($_POST['editar_banner_id']);
        $id = $edit ? (int)$_POST['editar_banner_id'] : null;
        $titulo = trim($_POST['titulo'] ?? '');
        $mensaje = trim($_POST['mensaje'] ?? '');
        $enlace = trim($_POST['enlace'] ?? '');
        $ubicacion = $_POST['ubicacion'] ?? 'apuntes';
        $activo = isset($_POST['activo']) ? 1 : 0;
        $orden = (int)($_POST['orden'] ?? 0);
        $frecuencia = (int)($_POST['frecuencia'] ?? 2);
        $imagen = null;

        if (strlen($mensaje) > 50) $mensaje_error = "❌ El mensaje no debe superar los 50 caracteres.";

        if (isset($_FILES['imagenes']) && $_FILES['imagenes']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['imagenes']['error'] === UPLOAD_ERR_OK) {
                $chk = getimagesize($_FILES['imagenes']['tmp_name']);
                // Se flexibiliza levemente la comprobación de ratio si se quiere, pero mantenemos tu regla estricta:
                if ($chk && $chk[0] == 340 && $chk[1] == 365) {
                    $mime = $chk['mime']; 
                    $base = uniqid();
                    if (!is_dir($rutaBase)) mkdir($rutaBase, 0755, true);
                    $dest = $rutaBase . $base . '.webp';
                    
                    if (convertir_a_webp($_FILES['imagenes']['tmp_name'], $dest, $mime)) {
                        $imagen = $base . '.webp';
                        if ($edit) {
                            $s = $conn->prepare("SELECT imagen FROM banners WHERE id=?");
                            $s->bind_param("i", $id); $s->execute(); $rr = $s->get_result();
                            if ($ri = $rr->fetch_assoc()) { 
                                $old = $rutaBase . $ri['imagen']; 
                                if (file_exists($old)) unlink($old);
                            }
                            $s->close();
                        }
                    } else {
                        $mensaje_error = "❌ Error al procesar la imagen a WebP.";
                    }
                } else {
                    $mensaje_error = "❌ Las dimensiones exactas deben ser 340x365px.";
                }
            } else {
                $mensaje_error = "❌ Error en la subida del archivo.";
            }
        } else {
            if ($edit) {
                $s = $conn->prepare("SELECT imagen FROM banners WHERE id=?");
                $s->bind_param("i", $id); $s->execute(); $rr = $s->get_result();
                if ($ri = $rr->fetch_assoc()) $imagen = $ri['imagen'];
                $s->close();
            } else {
                $mensaje_error = "❌ Debes subir una imagen para crear un banner.";
            }
        }

        if (empty($mensaje_error)) {
            if ($edit) {
                $st = $conn->prepare("UPDATE banners SET titulo=?, mensaje=?, enlace=?, ubicacion=?, activo=?, orden=?, imagen=?, frecuencia=? WHERE id=?");
                $st->bind_param("ssssiisii", $titulo, $mensaje, $enlace, $ubicacion, $activo, $orden, $imagen, $frecuencia, $id);
                $st->execute();
                $_SESSION['alerta_ok'] = "Banner actualizado exitosamente.";
            } else {
                $st = $conn->prepare("INSERT INTO banners (titulo, mensaje, enlace, ubicacion, activo, orden, imagen, frecuencia, clics) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $st->bind_param("ssssiisi", $titulo, $mensaje, $enlace, $ubicacion, $activo, $orden, $imagen, $frecuencia);
                $st->execute();
                $_SESSION['alerta_ok'] = "Banner creado exitosamente.";
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "#listaBanners");
            exit;
        }
    }
}

/* ----------------- LISTADO ----------------- */
$res = $conn->query("SELECT * FROM banners ORDER BY ubicacion, orden, id");
$banners = $res->fetch_all(MYSQLI_ASSOC);

if (!empty($_SESSION['alerta_ok'])) {
    $mensaje_ok = $_SESSION['alerta_ok'];
    unset($_SESSION['alerta_ok']);
}

// Helper nav
$current_uri = $_SERVER['REQUEST_URI'];
function nav_class($path, $current_uri) {
    return strpos($current_uri, $path) !== false 
        ? 'text-[#54A6D8] font-bold bg-sky-50' 
        : 'text-gray-600 hover:text-[#54A6D8] hover:bg-gray-50';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Banners - Nubira</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Animaciones base Nubira 2.0 */
        .fade-enter { opacity: 0; transform: translateY(20px); }
        .fade-enter-active { opacity: 1; transform: translateY(0); transition: all 0.3s ease-out; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased">

<header class="fixed top-0 left-0 right-0 h-16 bg-white/80 backdrop-blur-md border-b border-gray-100 z-50 flex items-center justify-between px-4 md:px-8 transition-all">
    <div class="flex items-center gap-3">
        <a href="/vitrina.php" class="text-2xl font-bold tracking-tight bg-gradient-to-r from-sky-400 to-[#54A6D8] bg-clip-text text-transparent">
            Nubira.cl
        </a>
        <span class="hidden md:inline text-gray-300 text-xl font-light">/</span>
        <span class="hidden md:inline text-gray-600 font-medium tracking-tight">Gestión de Banners</span>
    </div>
    
    <div class="flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-gray-200 border-2 border-white shadow-sm overflow-hidden flex-shrink-0 cursor-pointer hover:shadow-md transition-all">
            <img src="/assets/img/avatar_placeholder.webp" alt="Admin" class="w-full h-full object-cover">
        </div>
    </div>
</header>

<aside class="hidden md:flex fixed left-0 top-16 w-64 h-[calc(100vh-4rem)] bg-white border-r border-gray-100 flex-col z-40">
    <div class="p-6 overflow-y-auto h-full">
        <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4 block">Administración</span>
        <nav class="flex flex-col space-y-1">
            <a href="/vitrina.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all <?= nav_class('/vitrina', $current_uri) ?>">
                <?= icon('home') ?> <span class="font-medium">Inicio</span>
            </a>
            <a href="/admin_banners.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all <?= nav_class('/admin_banners', $current_uri) ?>">
                <?= icon('image') ?> <span class="font-medium">Banners</span>
            </a>
            <a href="/dashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-all <?= nav_class('/dashboard', $current_uri) ?>">
                <?= icon('user') ?> <span class="font-medium">Perfil</span>
            </a>
        </nav>
    </div>
</aside>

<main class="pt-24 pb-28 md:pb-12 md:ml-64 max-w-[1100px] mx-auto px-4 sm:px-6 w-full">

    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900">Banners Publicitarios</h1>
            <p class="text-sm text-gray-500 mt-1">Sube, edita y ordena la publicidad de los apartados.</p>
        </div>
    </div>

    <?php if(!empty($mensaje_ok)): ?>
        <div class="bg-green-50 border border-green-100 text-green-700 px-4 py-3 rounded-xl mb-6 shadow-sm flex items-center gap-3">
            <?= icon('check-circle') ?> <?= htmlspecialchars($mensaje_ok) ?>
        </div>
    <?php endif; ?>
    <?php if(!empty($mensaje_error)): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 px-4 py-3 rounded-xl mb-6 shadow-sm flex items-center gap-3">
            <?= icon('alert-triangle') ?> <?= htmlspecialchars($mensaje_error) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 mb-10 transition-all hover:shadow-md">
        <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <?= icon('plus-circle') ?> Agregar Nuevo Banner
        </h2>
        
        <form method="POST" enctype="multipart/form-data" onsubmit="return validarMensaje();" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Título Interno</label>
                    <input type="text" name="titulo" placeholder="Ej: Promo Invierno" required
                           class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Enlace de Destino</label>
                    <input type="text" name="enlace" placeholder="https://..." 
                           class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Mensaje (Opcional - Máx 50 char)</label>
                    <textarea id="mensaje" name="mensaje" maxlength="50" rows="2" placeholder="Un texto breve que acompañe al banner..."
                              class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none resize-none"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Ubicación</label>
                    <select name="ubicacion" class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none appearance-none">
                        <option value="apuntes">Vitrina Apuntes</option>
                        <option value="servicios">Vitrina Servicios</option>
                        <option value="oportunidades">Oportunidades</option>
                        <option value="inicio">Inicio / General</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Orden</label>
                        <input type="number" name="orden" value="0" class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Frecuencia</label>
                        <input type="number" name="frecuencia" value="2" min="1" class="w-full bg-gray-50 border border-gray-200 text-gray-900 rounded-xl px-4 py-3 focus:ring-2 focus:ring-[#54A6D8]/50 focus:border-[#54A6D8] transition-all outline-none">
                    </div>
                </div>

                <div class="md:col-span-2 bg-sky-50/50 border border-dashed border-sky-200 rounded-2xl p-6 text-center hover:bg-sky-50 transition-all group">
                    <label class="cursor-pointer block">
                        <span class="block text-sky-600 mb-2 font-semibold">Sube una imagen (340x365px)</span>
                        <span class="text-xs text-gray-500 block mb-4">PNG, JPG, GIF. Se convertirá a WebP automáticamente.</span>
                        <input type="file" name="imagenes" accept="image/*" required class="hidden" onchange="mostrarPreview(event)">
                        <div class="inline-flex items-center justify-center px-6 py-2 bg-white border border-sky-200 rounded-xl text-sky-700 text-sm font-semibold shadow-sm group-hover:shadow transition-all">
                            Seleccionar Archivo
                        </div>
                    </label>
                    <img id="preview" class="mt-4 mx-auto max-h-40 rounded-xl shadow-sm object-cover hidden animate-pulse bg-gray-200">
                </div>
                
                <div class="md:col-span-2 flex items-center justify-between mt-2">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="activo" checked class="w-5 h-5 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8]">
                        <span class="ml-3 text-sm font-semibold text-gray-700">Banner Activo (Visible)</span>
                    </label>
                    
                    <button type="submit" class="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white font-bold py-3 px-8 rounded-xl shadow-md hover:shadow-lg hover:scale-[1.02] transition-all">
                        Crear Banner
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div id="listaBanners" class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden mb-12">
        <div class="p-6 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <h2 class="text-xl font-bold text-gray-800">Banners Existentes</h2>
            <button form="formOrden" type="submit" name="guardar_orden" class="bg-gray-900 text-white text-sm font-semibold py-2 px-5 rounded-xl hover:bg-gray-800 transition-all hover:shadow-md">
                Guardar Ordenes
            </button>
        </div>

        <div class="overflow-x-auto">
            <form id="formOrden" method="POST" onsubmit="return validarFormOrdenes(event)">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 font-bold tracking-wider">
                        <tr>
                            <th class="px-6 py-4 rounded-tl-3xl">Banner</th>
                            <th class="px-6 py-4">Detalles</th>
                            <th class="px-6 py-4">Ubicación</th>
                            <th class="px-6 py-4 text-center">Estado</th>
                            <th class="px-6 py-4 text-center">Estadísticas</th>
                            <th class="px-6 py-4 text-right rounded-tr-3xl">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($banners)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                    <div class="flex flex-col items-center justify-center">
                                        <?= icon('image') ?>
                                        <p class="mt-2 text-sm">No hay banners configurados aún.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach($banners as $b):
                            $img = '/upload/banners/' . $b['imagen']; 
                            $full = $rutaBase . $b['imagen'];
                            $url = corregir_url($b['enlace']);
                        ?>
                        <tr class="hover:bg-gray-50/50 transition-colors group">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if(file_exists($full)): ?>
                                    <div class="relative w-20 h-24 rounded-xl overflow-hidden shadow-sm bg-gray-100 cursor-pointer hover:scale-105 transition-all" onclick="openPreview('<?= $img ?>','<?= htmlspecialchars($b['titulo'], ENT_QUOTES) ?>')">
                                        <img src="<?= $img ?>" alt="Banner" class="w-full h-full object-cover">
                                    </div>
                                <?php else: ?>
                                    <div class="w-20 h-24 rounded-xl bg-gray-100 border border-dashed border-gray-300 flex items-center justify-center text-gray-400 text-xs text-center p-2">
                                        Sin Imagen
                                    </div>
                                <?php endif; ?>
                            </td>
                            
                            <td class="px-6 py-4">
                                <p class="text-sm font-bold text-gray-900 truncate max-w-[150px]" title="<?= htmlspecialchars($b['titulo']) ?>">
                                    <?= htmlspecialchars($b['titulo']) ?>
                                </p>
                                <p class="text-xs text-gray-500 truncate max-w-[150px] mt-1" title="<?= htmlspecialchars($b['mensaje']) ?>">
                                    <?= htmlspecialchars($b['mensaje']) ?: 'Sin mensaje' ?>
                                </p>
                                <a href="<?= $url ?>" target="_blank" class="text-xs font-semibold text-[#54A6D8] hover:underline mt-1 inline-block truncate max-w-[150px]">
                                    Probar Enlace
                                </a>
                            </td>
                            
                            <td class="px-6 py-4">
                                <span class="inline-flex px-3 py-1 bg-sky-50 text-sky-700 text-xs font-bold rounded-lg uppercase tracking-wide">
                                    <?= htmlspecialchars($b['ubicacion']) ?>
                                </span>
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-500">Orden:</span>
                                    <input type="number" name="orden[<?= $b['id'] ?>]" value="<?= (int)$b['orden'] ?>" 
                                           class="w-16 bg-white border border-gray-200 text-center rounded-lg py-1 text-xs focus:ring-1 focus:ring-[#54A6D8] outline-none">
                                </div>
                            </td>
                            
                            <td class="px-6 py-4 text-center">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="toggle_activo_id" value="<?= $b['id'] ?>">
                                    <input type="hidden" name="estado_actual" value="<?= $b['activo'] ?>">
                                    <button type="submit" class="inline-flex items-center justify-center px-3 py-1 rounded-full text-xs font-bold transition-all shadow-sm
                                        <?= $b['activo'] ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                                        <?= $b['activo'] ? 'Activo' : 'Pausado' ?>
                                    </button>
                                </form>
                            </td>

                            <td class="px-6 py-4 text-center">
                                <p class="text-lg font-black text-gray-800"><?= number_format((int)$b['clics']) ?></p>
                                <p class="text-xs text-gray-500 font-medium">Clics (Freq: <?= (int)$b['frecuencia'] ?>)</p>
                            </td>
                            
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-2 opacity-100 sm:opacity-0 group-hover:opacity-100 transition-opacity">
                                    <a href="/app/admin_ver_clicks_banner.php?id=<?= $b['id'] ?>" class="p-2 bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-100 transition-colors tooltip" title="Ver Analítica">
                                        <?= icon('bar-chart') ?>
                                    </a>
                                    <button type="button" onclick="toggleEditForm(<?= $b['id'] ?>)" class="p-2 bg-sky-50 text-sky-600 rounded-lg hover:bg-sky-100 transition-colors tooltip" title="Editar">
                                        <?= icon('edit') ?>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('¿Seguro que deseas eliminar este banner? Esta acción no se puede deshacer.')">
                                        <input type="hidden" name="eliminar_id" value="<?= $b['id'] ?>">
                                        <button class="p-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors tooltip" title="Eliminar">
                                            <?= icon('trash') ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        
                        <tr id="edit-form-<?= $b['id'] ?>" class="hidden bg-gray-50/80 border-t border-gray-100">
                            <td colspan="6" class="p-6">
                                <form method="POST" enctype="multipart/form-data" class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200">
                                    <input type="hidden" name="editar_banner_id" value="<?= $b['id'] ?>">
                                    <h4 class="text-sm font-bold text-gray-800 mb-4 border-b border-gray-100 pb-2">Editar Banner #<?= $b['id'] ?></h4>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Título</label>
                                            <input name="titulo" value="<?= htmlspecialchars($b['titulo']) ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm mt-1 focus:ring-1 focus:ring-[#54A6D8] outline-none">
                                        </div>
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Enlace</label>
                                            <input name="enlace" value="<?= htmlspecialchars($b['enlace']) ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm mt-1 focus:ring-1 focus:ring-[#54A6D8] outline-none">
                                        </div>
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Ubicación</label>
                                            <select name="ubicacion" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm mt-1 focus:ring-1 focus:ring-[#54A6D8] outline-none">
                                                <?php foreach(['apuntes','servicios','oportunidades','inicio'] as $opt): ?>
                                                    <option value="<?= $opt ?>" <?= $b['ubicacion'] === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="md:col-span-3">
                                            <label class="text-xs font-semibold text-gray-600">Mensaje</label>
                                            <textarea name="mensaje" maxlength="50" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm mt-1 focus:ring-1 focus:ring-[#54A6D8] outline-none resize-none"><?= htmlspecialchars($b['mensaje']) ?></textarea>
                                        </div>
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Frecuencia</label>
                                            <input type="number" name="frecuencia" value="<?= (int)$b['frecuencia'] ?>" min="1" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm mt-1 focus:ring-1 focus:ring-[#54A6D8] outline-none">
                                        </div>
                                        <div>
                                            <label class="text-xs font-semibold text-gray-600">Nueva Imagen (Opcional)</label>
                                            <input type="file" name="imagenes" accept="image/*" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-sky-50 file:text-sky-700 hover:file:bg-sky-100 mt-1 cursor-pointer">
                                        </div>
                                        <div class="flex items-end gap-4">
                                            <label class="inline-flex items-center mb-2">
                                                <input type="checkbox" name="activo" <?= $b['activo'] ? 'checked' : '' ?> class="w-4 h-4 text-[#54A6D8] border-gray-300 rounded focus:ring-[#54A6D8]">
                                                <span class="ml-2 text-xs font-semibold text-gray-700">Activo</span>
                                            </label>
                                            <button type="submit" class="w-full bg-gray-900 text-white font-bold py-2 rounded-xl text-sm hover:bg-gray-800 transition-all hover:shadow-md">
                                                Guardar Cambios
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
</main>

<nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white/90 backdrop-blur-md border-t border-gray-100 z-50 flex justify-around items-center h-16 pb-safe shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
    <a href="/vitrina.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-[#54A6D8] transition-colors">
        <?= icon('home') ?>
    </a>
    <a href="/vitrina-apuntes.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-[#54A6D8] transition-colors">
        <?= icon('book') ?>
    </a>
    <div class="relative w-full h-full flex justify-center items-center">
        <a href="#top" class="absolute -top-5 flex items-center justify-center w-14 h-14 bg-gradient-to-tr from-sky-400 to-[#54A6D8] text-white rounded-full shadow-lg shadow-sky-400/40 hover:scale-105 transition-transform">
            <?= icon('plus') ?>
        </a>
    </div>
    <a href="/clases-servicios.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-[#54A6D8] transition-colors">
        <?= icon('briefcase') ?>
    </a>
    <a href="/dashboard.php" class="flex flex-col items-center justify-center w-full h-full text-gray-400 hover:text-[#54A6D8] transition-colors">
        <?= icon('user') ?>
    </a>
</nav>

<div id="bannerPreviewModal" class="fixed inset-0 z-[60] hidden">
    <div id="bannerPreviewBackdrop" class="absolute inset-0 bg-gray-900/40 backdrop-blur-sm opacity-0 transition-opacity duration-300 ease-out" onclick="closePreview()"></div>
    
    <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
        <div id="bannerPreviewCard" class="bg-white rounded-3xl w-full max-w-sm shadow-2xl transform translate-y-8 opacity-0 transition-all duration-300 ease-out pointer-events-auto overflow-hidden flex flex-col">
            
            <div class="px-4 py-3 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <span class="font-bold text-gray-800 text-sm">Vista Previa Real</span>
                <button onclick="closePreview()" class="text-gray-400 hover:text-red-500 transition-colors p-1 rounded-full hover:bg-red-50">
                    <?= icon('x') ?>
                </button>
            </div>

            <div class="p-6 bg-gray-100 flex justify-center items-center">
                <div id="bannerPreviewContent" class="w-[340px] h-[365px] max-w-full bg-white rounded-2xl shadow-sm overflow-hidden border border-gray-200">
                    </div>
            </div>
            
            <div class="p-4 text-center">
                <p id="bannerPreviewTitle" class="text-sm font-bold text-gray-900"></p>
                <button onclick="closePreview()" class="mt-4 w-full bg-gray-100 text-gray-800 font-semibold py-2 rounded-xl text-sm hover:bg-gray-200 transition-colors">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle de edición fluida en la tabla
function toggleEditForm(id) {
    const form = document.getElementById('edit-form-' + id);
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        // Pequeño hack para forzar reflujo y animar (opcional en UI estricta)
        setTimeout(() => form.classList.add('fade-enter-active'), 10);
    } else {
        form.classList.add('hidden');
        form.classList.remove('fade-enter-active');
    }
}

// Preview de imagen al subirla
function mostrarPreview(e) {
    const p = document.getElementById('preview');
    const f = e.target.files[0];
    if (f) {
        p.src = URL.createObjectURL(f);
        p.classList.remove('hidden', 'animate-pulse');
        p.classList.add('fade-enter-active');
    }
}

// Lógica de Modales estilo setupModal() (Vanilla JS sin jQuery)
function openPreview(imgSrc, title) {
    const modal = document.getElementById('bannerPreviewModal');
    const backdrop = document.getElementById('bannerPreviewBackdrop');
    const card = document.getElementById('bannerPreviewCard');
    const content = document.getElementById('bannerPreviewContent');
    const titleEl = document.getElementById('bannerPreviewTitle');
    
    // Inyectar data
    content.innerHTML = `<img src="${imgSrc}" class="w-full h-full object-cover" alt="Preview">`;
    titleEl.textContent = title;
    
    // Mostrar display: block
    modal.classList.remove('hidden');
    
    // Animar
    requestAnimationFrame(() => {
        backdrop.classList.remove('opacity-0');
        backdrop.classList.add('opacity-100');
        
        card.classList.remove('translate-y-8', 'opacity-0');
        card.classList.add('translate-y-0', 'opacity-100');
    });
}

function closePreview() {
    const modal = document.getElementById('bannerPreviewModal');
    const backdrop = document.getElementById('bannerPreviewBackdrop');
    const card = document.getElementById('bannerPreviewCard');
    
    // Revertir animaciones
    backdrop.classList.remove('opacity-100');
    backdrop.classList.add('opacity-0');
    
    card.classList.remove('translate-y-0', 'opacity-100');
    card.classList.add('translate-y-8', 'opacity-0');
    
    // Esperar a la transición para ocultar display
    setTimeout(() => {
        modal.classList.add('hidden');
        document.getElementById('bannerPreviewContent').innerHTML = '';
    }, 300);
}

// Validaciones
function validarMensaje() {
    const m = document.getElementById('mensaje');
    if (m && m.value.length > 50) {
        alert('El mensaje no debe superar los 50 caracteres.');
        m.focus();
        return false;
    }
    return true;
}

function validarFormOrdenes(e) {
    const inputs = document.querySelectorAll('input[name^="orden"]');
    for (const i of inputs) {
        if (i.value === '' || isNaN(i.value) || Number(i.value) < 0) {
            alert('Orden inválido detectado. Usa solo números positivos.');
            i.focus();
            e.preventDefault();
            return false;
        }
    }
    return true;
}
</script>
</body>
</html>