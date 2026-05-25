<?php
/**
 * NUBIRA 2.0 - VISTA: EDITAR SERVICIO
 * Version: 16.0
 * 
 * Cambios aplicados (replicando Camino D de publicar_servicio.php):
 * - Quitado display_errors en producción (seguridad)
 * - [FASE 1] Sanitización con strip_tags + validación mb_strlen
 * - [FASE 2] Protección CSRF con token + hash_equals
 * - [FASE 3] Input de precio con formato chileno ($15.000)
 * - [FASE 4] Botón sticky móvil + Modo Task (X derecha + nav oculto + sin búsqueda)
 * - [FASE 5] Compresión client-side de imágenes
 * - [BACKEND IMAGEN] 3 tamaños responsivos + imagecopyresampled (igual que publicar)
 * - [PRESERVADO] Botón IA con efecto Gemini ring intacto
 */

// 1. CONFIGURACIÓN DE ERRORES — solo en desarrollo. En producción NUNCA mostrar errores.
// Logs van a Hostinger error_log automáticamente.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

// 2. RUTAS BLINDADAS
$app_dir = __DIR__; 
if (!file_exists($app_dir . '/conexion.php')) {
    if (file_exists($app_dir . '/app/conexion.php')) $app_dir = __DIR__ . '/app';
    elseif (file_exists(dirname(__DIR__) . '/app/conexion.php')) $app_dir = dirname(__DIR__) . '/app';
}

if (!file_exists($app_dir . '/conexion.php')) {
    $app_dir = __DIR__ . '/../app';
}

require_once $app_dir . '/conexion.php';

// Iconos (Fallback si no existe el archivo)
if (file_exists($app_dir . '/iconos.php')) {
    require_once $app_dir . '/iconos.php';
} else {
    if (!function_exists('icon')) { function icon($n, $c='') { return "<i class='fa-solid fa-$n $c'></i>"; } }
}

// 3. SEGURIDAD DE SESIÓN
if (!isset($_SESSION['usuario_id'])) { header("Location: /login"); exit; }

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';
$es_admin   = ($rol === 'admin');
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';

// 4. OBTENER ID SERVICIO
$id_servicio = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id_servicio <= 0) { header("Location: /clases-servicios"); exit; }

// 5. CARGAR DATOS
$stmt = $conn->prepare("SELECT * FROM servicios WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_servicio);
$stmt->execute();
$servicio = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$servicio) { die("Servicio no encontrado."); }

if (!$es_admin && (int)$servicio['alumno_id'] !== $usuario_id) {
    die("No tienes permiso para editar este servicio.");
}

// Variables
$titulo          = $servicio['titulo'];
$descripcion     = $servicio['descripcion'];
$categoria       = $servicio['categoria'];
$modalidad       = $servicio['modalidad'];
$ubicacion       = $servicio['ubicacion'];
$precio          = $servicio['precio'];
$imagen_actual   = $servicio['imagen'];
$imagen_estado   = $servicio['imagen_estado'];
$institucion     = $servicio['institucion'];
$correo          = $servicio['correo'];
$nombre_oferente = $servicio['nombre_oferente'];

// [NUBIRA 2.0] CSRF TOKEN — Protección contra Cross-Site Request Forgery
if (empty($_SESSION['csrf_token_editar'])) {
    $_SESSION['csrf_token_editar'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token_editar'];

// Datos bancarios

// Función Anti-Contacto
function contiene_contacto($texto) {
    $patrones = [
        '/\b\d{8,}\b/',
        '/\b(?:\d[\s\-.]?){7}\d/',
        '/\+56/',
        '/@/',
        '/\barroba\b/iu',
        '/\b(gmail|hotmail|yahoo|outlook|protonmail|live|icloud)\b/i',
        '/(https?:\/\/|www\.)/i',
        '/wa\.me|t\.me/i',
        '/\b(whatsapp|wsp|wpp|telegram|instagram|insta|tiktok|snapchat|discord|facebook)\b/i',
        '/\b(contact[aá]me|escrí?beme|mi\s+n[uú]mero|fuera\s+de\s+la\s+plataforma)\b/iu',
    ];
    foreach ($patrones as $p) { if (preg_match($p, $texto)) return true; }
    return false;
}

// 6. PROCESAMIENTO POST
$mensaje = "";
$exito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // [NUBIRA 2.0] Validación CSRF
    $token_recibido = $_POST['csrf_token'] ?? '';
    if (empty($token_recibido) || !hash_equals($_SESSION['csrf_token_editar'] ?? '', $token_recibido)) {
        error_log("Nubira CSRF Alert (editar) - Token inválido. usuario_id={$usuario_id}, servicio_id={$id_servicio}, IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $mensaje = "Sesión expirada. Por favor recarga la página e intenta nuevamente.";
        goto fin_post;
    }
    
    // [NUBIRA 2.0] Sanitización: guardamos LIMPIO, escapamos al renderizar
    $titulo      = trim(strip_tags($_POST['titulo'] ?? ''));
    $descripcion = trim(strip_tags($_POST['descripcion'] ?? ''));
    $categoria   = trim(strip_tags($_POST['categoria'] ?? ''));
    $modalidad   = trim(strip_tags($_POST['modalidad'] ?? ''));
    $ubicacion   = trim(strip_tags($_POST['ubicacion'] ?? ''));
    $precio      = (float)($_POST['precio'] ?? 0);
    $preview     = mb_substr($descripcion, 0, 80) . (mb_strlen($descripcion) > 80 ? "..." : "");

    // Validación defensiva de longitud en backend
    if (mb_strlen($titulo) > 70 || mb_strlen($descripcion) > 1500) {
        $mensaje = "El título o descripción exceden el límite permitido.";
        $titulo = '';
    }

    if (!$titulo || !$descripcion || !$categoria || !$modalidad) {
        if (empty($mensaje)) $mensaje = "Faltan campos obligatorios.";
    } elseif (mb_strlen($descripcion) < 50) {
        $mensaje = "Descripción muy corta (mínimo 50 caracteres).";
    } elseif (contiene_contacto($titulo) || contiene_contacto($descripcion)) {
        $mensaje = "No incluyas teléfonos ni correos.";
    } else {
        $nombreArchivo = $imagen_actual;
        $nuevo_estado_img = $imagen_estado;

        // Manejo Imagen — replicado de publicar_servicio.php (3 tamaños + resampling alta calidad)
        if (!empty($_FILES['imagen']['name'])) {
            if (isset($_FILES['imagen']['error']) && $_FILES['imagen']['error'] === UPLOAD_ERR_INI_SIZE) {
                $mensaje = "La imagen es demasiado grande. El máximo permitido es 4MB.";
                goto fin_post;
            }
            if ($_FILES['imagen']['size'] > 4 * 1024 * 1024) {
                $mensaje = "La imagen no puede superar 4MB.";
                goto fin_post;
            }
            $file_tmp = $_FILES['imagen']['tmp_name'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $file_type = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);

            if (in_array($file_type, ['image/jpeg', 'image/png', 'image/webp'])) {
                $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
                $upload_dir = $docRoot . '/upload/servicios/';
                if (!file_exists($upload_dir)) @mkdir($upload_dir, 0755, true);

                $file_name = uniqid('serv_') . '.webp';
                $upload_path = $upload_dir . $file_name;

                if (extension_loaded('gd')) {
                    $img = null;
                    if ($file_type === 'image/jpeg') $img = @imagecreatefromjpeg($file_tmp);
                    elseif ($file_type === 'image/png') $img = @imagecreatefrompng($file_tmp);
                    elseif ($file_type === 'image/webp') $img = @imagecreatefromwebp($file_tmp);

                    if ($img) {
                        $w_orig = imagesx($img); 
                        $h_orig = imagesy($img);
                        
                        // Aviso si la imagen original es demasiado chica
                        if ($w_orig < 800) {
                            error_log("Nubira UploadInfo (editar) - Imagen baja resolución: usuario_id={$usuario_id}, dimensiones={$w_orig}x{$h_orig}");
                        }
                        
                        $base_name = pathinfo($file_name, PATHINFO_FILENAME);
                        
                        // Helper de redimensionado con resampling de alta calidad
                        $generar_tamano = function($img, $w_orig, $h_orig, $max_width, $ruta_destino, $calidad) {
                            if ($w_orig <= $max_width) {
                                return imagewebp($img, $ruta_destino, $calidad);
                            }
                            
                            $new_w = $max_width;
                            $new_h = (int)(($h_orig / $w_orig) * $max_width);
                            
                            $resized = imagecreatetruecolor($new_w, $new_h);
                            imagealphablending($resized, false);
                            imagesavealpha($resized, true);
                            
                            imagecopyresampled(
                                $resized, $img,
                                0, 0, 0, 0,
                                $new_w, $new_h,
                                $w_orig, $h_orig
                            );
                            
                            $ok = imagewebp($resized, $ruta_destino, $calidad);
                            imagedestroy($resized);
                            return $ok;
                        };
                        
                        // Generar 3 tamaños responsivos (Nubira 2.0)
                        $generar_tamano($img, $w_orig, $h_orig, 400, $upload_dir . $base_name . '_thumb.webp', 82);
                        $generar_tamano($img, $w_orig, $h_orig, 800, $upload_dir . $base_name . '_card.webp', 85);
                        $generar_tamano($img, $w_orig, $h_orig, 1600, $upload_path, 85);
                        
                        imagedestroy($img);
                        $nombreArchivo = $file_name;
                        $nuevo_estado_img = 'oculta'; // Vuelve a revisión por nueva imagen
                    }
                } else {
                    move_uploaded_file($file_tmp, $upload_path);
                    $nombreArchivo = $file_name;
                }
            }
        }

        $sql = "UPDATE servicios SET 
                titulo=?, preview=?, descripcion=?, categoria=?, modalidad=?, 
                ubicacion=?, precio=?, imagen=?, imagen_estado=?, 
                estado='pendiente', fecha_revision=NOW() 
                WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssdssi", $titulo, $preview, $descripcion, $categoria, $modalidad, $ubicacion, $precio, $nombreArchivo, $nuevo_estado_img, $id_servicio);
        
        if ($stmt->execute()) {
            $mensaje = "Cambios guardados. Pendiente de revisión.";
            $exito = true;
            $imagen_actual = $nombreArchivo;
        } else {
            error_log("Nubira Error - Update Servicio: " . $stmt->error);
            $mensaje = "Error al guardar.";
        }
        $stmt->close();
    }
    
    fin_post:; // Punto de salida CSRF
}

// Helper Nav
$ruta_actual = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_class')) {
    function nav_class(string $path): string {
        global $ruta_actual;
        $base = 'group flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all border border-transparent';
        $activo = ' bg-blue-50 text-[#54A6D8] border-blue-100';
        $inactivo = ' text-gray-500 hover:bg-gray-50 hover:text-gray-900';
        if ($path === '/dashboard' || strpos($ruta_actual, 'editar_servicio') !== false) return $base . $inactivo; 
        return $base . (strpos($ruta_actual, $path) !== false ? $activo : $inactivo);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Servicio | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
    
    /* [NUBIRA 2.0] Modo "Task" en móvil: form enfocado sin distracciones. */
    @media (max-width: 767px) {
        nav.fixed.bottom-0,
        .fixed.bottom-0[id*="nav"] {
            display: none !important;
        }
        
        header input[type="search"],
        header input[type="text"][placeholder*="usca"],
        header form[action*="busqueda"],
        header form[action*="buscar"],
        header .search-bar,
        header [id*="busqueda"],
        header [id*="search"] {
            display: none !important;
        }
    }
    
   
  </style>
</head>
<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden selection:bg-blue-100 selection:text-blue-700">

  <div id="loader" class="fixed inset-0 flex items-center justify-center bg-white z-50 transition-opacity duration-300">
    <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-100 border-t-[#54A6D8]"></div>
  </div>

  <?php 
  if(file_exists($app_dir . '/componentes/header.php')) require_once $app_dir . '/componentes/header.php'; 
  if(file_exists($app_dir . '/componentes/sidebar.php')) require_once $app_dir . '/componentes/sidebar.php'; 
  ?>

  <main class="pt-16 md:pt-20 pb-32 md:pb-12 md:ml-64 px-4 max-w-[1100px] mx-auto md:px-8">

    <!-- [NUBIRA 2.0] Header del form: título a la izquierda, X a la derecha (móvil) -->
    <div class="flex items-center justify-between gap-3 mb-6 min-h-[44px]">
        
        <div class="min-w-0 flex-1">
            <span class="inline-block py-0.5 px-2.5 rounded-full bg-blue-50 text-blue-600 text-[10px] md:text-xs font-bold mb-1.5 border border-blue-100">
                Modo Edición
            </span>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight leading-tight truncate">Editar Servicio</h1>
            <p class="text-gray-500 text-[11px] md:text-sm mt-0.5 font-medium md:font-normal uppercase md:normal-case tracking-wide md:tracking-normal truncate">
                Actualiza tu publicación
            </p>
        </div>
        
        <a href="/clases-servicios" 
           class="md:hidden flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 active:scale-95 transition-all"
           aria-label="Cerrar">
            <svg class="w-5 h-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </a>
        
        <a href="/detalle-servicio/<?= $id_servicio ?>" target="_blank" class="hidden md:flex text-sm font-bold text-[#54A6D8] hover:underline items-center gap-1 flex-shrink-0">
            <?= icon('eye', 'w-4 h-4') ?> Ver publicación
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        <div class="lg:col-span-10 lg:col-start-2">
            




            <form id="form-servicio" method="POST" enctype="multipart/form-data" class="space-y-6" autocomplete="off">
                <input type="hidden" name="id" value="<?= $id_servicio ?>">
                
                <!-- [NUBIRA 2.0] CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                
                <div class="bg-white border border-gray-100 rounded-2xl p-6 md:p-8 shadow-sm">
                    
                    <?php if ($mensaje): ?>
                        <div id="toast" class="mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm <?= $exito ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                            <?= icon($exito ? 'check-circle' : 'alert', 'w-5 h-5 flex-shrink-0') ?>
                            <span class="text-sm font-bold flex-1"><?= htmlspecialchars($mensaje); ?></span>
                            <?php if(!$exito): ?><button type="button" onclick="document.getElementById('toast').remove()" class="text-sm opacity-70 hover:opacity-100">✕</button><?php endif; ?>
                        </div>
                        <?php if($exito): ?><script>setTimeout(()=>window.location.href='/clases-servicios', 2500);</script><?php endif; ?>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Categoría</label>
                            <div class="relative">
                                <select name="categoria" id="categoria" class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 pr-10 transition outline-none appearance-none cursor-pointer">
                                    <option value="">Selecciona...</option>
                                    <?php
                                    $cats = ['Clases', 'Tutoría', 'Asesoría', 'Idiomas', 'Otros'];
                                    foreach($cats as $c) {
                                        $sel = ($categoria === $c) ? 'selected' : '';
                                        echo "<option value='$c' $sel>$c</option>";
                                    }
                                    ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                                    <?= icon('chevron-down', 'w-4 h-4 text-gray-400') ?>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Modalidad</label>
                            <div class="relative">
                                <select name="modalidad" id="modalidad" class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 pr-10 transition outline-none appearance-none cursor-pointer">
                                    <option value="">Selecciona...</option>
                                    <?php
                                    $mods = ['Online', 'Presencial', 'Híbrido'];
                                    foreach($mods as $m) {
                                        $sel = ($modalidad === $m) ? 'selected' : '';
                                        echo "<option value='$m' $sel>$m</option>";
                                    }
                                    ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                                    <?= icon('chevron-down', 'w-4 h-4 text-gray-400') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="campo-ubicacion" class="<?= in_array($modalidad, ['Presencial','Híbrido']) ? '' : 'hidden' ?> mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Ubicación</label>
                        <input type="text" name="ubicacion" id="ubicacion" value="<?= htmlspecialchars($ubicacion) ?>" placeholder="Ej: Santiago Centro, Campus San Joaquín..."
                               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
                    </div>

                    <div class="mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Título del anuncio</label>
                        <div class="relative">
                            <input type="text" name="titulo" id="titulo" required maxlength="70" value="<?= htmlspecialchars($titulo) ?>" placeholder="Ej: Clases de Cálculo / Asesoría de Tesis"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
                            <div class="text-right text-xs text-gray-400 mt-1 absolute right-0 -bottom-5"><span id="titulo-count">0</span>/70</div>
                        </div>
                    </div>

                    <div class="mb-6 mt-8">
    <div class="mb-2">
        <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide">Descripción</label>
    </div>
                        
                        <textarea name="descripcion" id="descripcion" rows="8" maxlength="1500" required placeholder="Describe tu servicio..."
                                  class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-4 resize-none transition outline-none leading-relaxed"><?= htmlspecialchars($descripcion) ?></textarea>
                        
                        <div class="flex justify-between mt-2">
                            <div id="security-warning" class="hidden text-[10px] text-red-500 font-bold items-center gap-1 bg-red-50 px-2 py-1 rounded-lg">
                                <?= icon('alert', 'w-3 h-3') ?> No incluyas teléfonos ni correos.
                            </div>
                            <div class="text-xs text-gray-400 ml-auto"><span id="descripcion-count">0</span>/1500</div>
                        </div>
                    </div>

                    <!-- [NUBIRA 2.0] Precio con formato chileno ($15.000) -->
                    <div class="mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Precio Base (CLP)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold pointer-events-none">$</span>
                            
                            <input type="text" 
                                   id="precio_visible" 
                                   inputmode="numeric"
                                   autocomplete="off"
                                   placeholder="0 = A convenir"
                                   value="<?= $precio > 0 ? number_format($precio, 0, ',', '.') : '' ?>"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-lg font-bold rounded-xl pl-8 p-3.5 focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition outline-none">
                            
                            <input type="hidden" name="precio" id="precio" value="<?= (int)$precio ?>">
                        </div>
                        <p class="text-xs text-gray-400 mt-1 ml-1">Pon "0" si el precio es a convenir o gratuito.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Foto de portada</label>
                        
                        <?php if(!empty($imagen_actual)): ?>
                            <div id="preview-actual" class="mb-3 relative w-full h-48 rounded-2xl overflow-hidden border border-gray-200 group">
                                <img src="/upload/servicios/<?= htmlspecialchars($imagen_actual) ?>?t=<?= time() ?>" class="w-full h-full object-cover">
                                <div class="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                    <span class="text-white text-xs font-bold">Imagen Actual</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="relative border-2 border-dashed border-gray-300 rounded-2xl bg-gray-50 hover:bg-white hover:border-[#54A6D8] transition-all duration-300 cursor-pointer group" onclick="document.getElementById('imagen').click()">
                            <input type="file" name="imagen" id="imagen" class="hidden" accept="image/jpeg,image/png,image/webp">
                            <div class="flex flex-col items-center justify-center py-6">
                                <div class="mb-2 text-gray-300 group-hover:text-[#54A6D8] transition-colors transform group-hover:scale-110 duration-300">
                                    <?= icon('image', 'w-8 h-8') ?>
                                </div>
                                <p class="mb-1 text-sm font-medium text-gray-700">Cambiar Imagen</p>
                                <p class="text-xs text-gray-400">JPG, PNG, WebP (Máx. 4MB)</p>
                            </div>
                        </div>
                        
                        <div id="preview" class="mt-4 hidden text-center animate-fade-in-up">
                            <div class="relative inline-block w-full">
                                <img id="previewImg" src="" class="h-64 w-full object-cover rounded-xl shadow-sm border border-gray-200 transition-opacity duration-300">
                                
                                <div id="compresion-overlay" class="absolute inset-0 flex items-center justify-center bg-white/40 backdrop-blur-sm rounded-xl pointer-events-none opacity-0 transition-opacity duration-300">
                                    <div class="bg-white px-4 py-2 rounded-full shadow-lg flex items-center gap-2">
                                        <div class="animate-spin h-4 w-4 border-2 border-gray-200 border-t-[#54A6D8] rounded-full"></div>
                                        <span class="text-xs font-bold text-gray-700">Optimizando imagen...</span>
                                    </div>
                                </div>
                            </div>
                            <button type="button" onclick="document.getElementById('imagen').value=''; document.getElementById('preview').classList.add('hidden');" class="text-xs text-red-500 mt-2 hover:underline">Cancelar cambio</button>
                        </div>
                    </div>
                </div>

                <!-- [NUBIRA 2.0] Wrapper sticky solo en móvil -->
                <div class="md:static md:bg-transparent md:p-0 md:border-0 
                            fixed bottom-0 left-0 right-0 z-40 
                            bg-white/95 backdrop-blur-md 
                            px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]
                            border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)]">
<button id="btn-submit" type="submit" 
        class="w-full text-white bg-[#54A6D8] hover:bg-sky-600 font-bold rounded-2xl text-base px-5 py-4 text-center shadow-lg shadow-blue-200 hover:shadow-blue-300 transform active:scale-[0.99] transition-all flex items-center justify-center gap-2 [&>*]:pointer-events-none">
    <span id="btn-text">Guardar Cambios</span> 
    <?= icon('arrow-right', 'w-5 h-5') ?>
</button>
                </div>

            </form>
        </div>
    </div>
</main>

<?php 
if(file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php'; 
?>

<script>
// --- 0. CONFIGURACIÓN ---
const USER_CONTEXT = { nombre: "<?= htmlspecialchars($nombre_usuario ?? 'Estudiante') ?>" };

window.onload = () => { 
    const l = document.getElementById('loader'); 
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
};



// --- 2. ELEMENTOS DOM ---
const ui = {
   
    titulo: document.getElementById('titulo'),
    desc: document.getElementById('descripcion'),
    modalidad: document.getElementById('modalidad'),
    imgInput: document.getElementById('imagen'),
    imgPreview: document.getElementById('preview'),
    imgTag: document.getElementById('previewImg'),
    ubicacionBox: document.getElementById('campo-ubicacion'),
    ubicacionInput: document.getElementById('ubicacion'),
    warning: document.getElementById('security-warning'),
    btnSubmit: document.getElementById('btn-submit'),

    countDesc: document.getElementById('descripcion-count'),
    countTitulo: document.getElementById('titulo-count')
};





// --- EVENTOS UI ---
ui.modalidad?.addEventListener('change', function() {
    if (this.value === 'Presencial' || this.value === 'Híbrido') { ui.ubicacionBox.classList.remove('hidden'); ui.ubicacionInput.required = true; } 
    else { ui.ubicacionBox.classList.add('hidden'); ui.ubicacionInput.required = false; ui.ubicacionInput.value = ''; }
});
ui.desc?.addEventListener('input', function() {
    const isBad = [/\d{8,}/, /@/, /\.cl/].some(p => p.test(this.value));
    ui.warning.classList.toggle('hidden', !isBad);
    ui.warning.classList.toggle('flex', isBad);
    ui.btnSubmit.disabled = isBad;
    if(isBad) ui.btnSubmit.classList.add('opacity-50'); else ui.btnSubmit.classList.remove('opacity-50');
    if(ui.countDesc) ui.countDesc.textContent = this.value.length;
});
ui.titulo?.addEventListener('input', function() { if(ui.countTitulo) ui.countTitulo.textContent = this.value.length; });

// [NUBIRA 2.0] Formato chileno de precio en vivo ($15.000)
(function setupPrecioFormat() {
    const visible = document.getElementById('precio_visible');
    const hidden = document.getElementById('precio');
    if (!visible || !hidden) return;
    
    let formateando = false;
    
    visible.addEventListener('input', function() {
        if (formateando) return;
        formateando = true;
        
        try {
            const cursorPos = this.selectionStart;
            const oldLength = this.value.length;
            
            const digits = this.value.replace(/\D/g, '');
            const limitedDigits = digits.slice(0, 9);
            const numero = limitedDigits === '' ? 0 : parseInt(limitedDigits, 10);
            
            hidden.value = numero;
            this.value = numero === 0 ? '' : numero.toLocaleString('es-CL');
            
            const newLength = this.value.length;
            const diff = newLength - oldLength;
            const newCursor = Math.max(0, cursorPos + diff);
            this.setSelectionRange(newCursor, newCursor);
        } finally {
            formateando = false;
        }
    });
})();

// [NUBIRA 2.0] Compresión client-side de imágenes
const NubiraImageCompressor = {
    MAX_WIDTH: 1920,
    MAX_HEIGHT: 1920,
    QUALITY: 0.85,
    MAX_INPUT_MB: 20,
    
    async compress(file) {
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            throw new Error('Formato no soportado. Usa JPG, PNG o WebP.');
        }
        
        if (file.size > this.MAX_INPUT_MB * 1024 * 1024) {
            throw new Error(`La imagen es demasiado pesada (máximo ${this.MAX_INPUT_MB}MB).`);
        }
        
        const img = await this._loadImage(file);
        let { width, height } = this._calculateSize(img.width, img.height);
        
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, width, height);
        
        URL.revokeObjectURL(img.src);
        
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(
                b => b ? resolve(b) : reject(new Error('No se pudo procesar la imagen.')),
                'image/jpeg',
                this.QUALITY
            );
        });
        
        const newName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
        return new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() });
    },
    
    _loadImage(file) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => resolve(img);
            img.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('No se pudo leer la imagen.'));
            };
            img.src = url;
        });
    },
    
    _calculateSize(originalW, originalH) {
        if (originalW <= this.MAX_WIDTH && originalH <= this.MAX_HEIGHT) {
            return { width: originalW, height: originalH };
        }
        
        const ratio = Math.min(this.MAX_WIDTH / originalW, this.MAX_HEIGHT / originalH);
        return {
            width: Math.round(originalW * ratio),
            height: Math.round(originalH * ratio)
        };
    }
};

// Listener del input de imagen con compresión transparente
ui.imgInput?.addEventListener('change', async function() {
    if (!this.files || this.files.length === 0) return;
    
    const fileOriginal = this.files[0];

    ui.imgTag.src = URL.createObjectURL(fileOriginal);
    ui.imgPreview.classList.remove('hidden');
    
    ui.imgTag.style.opacity = '0.5';
    const overlay = document.getElementById('compresion-overlay');
    if (overlay) overlay.style.opacity = '1';
    
    try {
        const fileComprimido = await NubiraImageCompressor.compress(fileOriginal);

        const dt = new DataTransfer();
        dt.items.add(fileComprimido);
        this.files = dt.files;

        ui.imgTag.src = URL.createObjectURL(fileComprimido);
        ui.imgTag.style.opacity = '1';
        const overlayOff = document.getElementById('compresion-overlay');
        if (overlayOff) overlayOff.style.opacity = '0';

    } catch (err) {
        ui.imgTag.style.opacity = '1';
        const overlayErr = document.getElementById('compresion-overlay');
        if (overlayErr) overlayErr.style.opacity = '0';
        
        console.error('[Nubira] Error compresión:', err);
        
        const errBox = document.createElement('div');
        errBox.className = 'mt-2 text-xs text-red-500 font-medium bg-red-50 border border-red-200 px-3 py-2 rounded-lg';
        errBox.textContent = '⚠️ ' + err.message + ' Sube otra imagen.';
        ui.imgPreview.appendChild(errBox);
        setTimeout(() => errBox.remove(), 5000);
        
        this.value = '';
        ui.imgPreview.classList.add('hidden');
    }
});

// Scroll into view en focus (móvil)
if (window.innerWidth < 768) {
    document.querySelectorAll('input, textarea, select').forEach(el => {
        el.addEventListener('focus', () => {
            setTimeout(() => {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        });
    });
}

// Modal Logic
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn=document.getElementById(triggerId), modal=document.getElementById(modalId), card=document.getElementById(cardId), close=document.getElementById(closeId);
    if(!btn||!modal) return;
    const open=()=>{modal.classList.remove('hidden'); requestAnimationFrame(()=>card.classList.remove('translate-y-3','opacity-0')); document.body.style.overflow='hidden';};
    const shut=()=>{card.classList.add('translate-y-3','opacity-0'); setTimeout(()=>{modal.classList.add('hidden');document.body.style.overflow='';},300);};
    btn.onclick=(e)=>{e.preventDefault();open();}; 
    if(close) close.onclick=shut; 
    modal.onclick=(e)=>{if(e.target===modal)shut();};
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
</script>

<?php 
if(file_exists($app_dir . '/componentes/modal_publicar.php')) require_once $app_dir . '/componentes/modal_publicar.php';
if(file_exists($app_dir . '/componentes/modal_explora.php')) require_once $app_dir . '/componentes/modal_explora.php';
?>

</body>
</html>