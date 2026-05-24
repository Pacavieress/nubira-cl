<?php
/**
 * NUBIRA 2.0 - UPLOAD SYSTEM (BLINDADO & AUTO-RUTAS)
 * Version: 16.0
 * 
 * Cambios respecto a v15:
 * - [FASE 1] Sanitización con strip_tags (no htmlspecialchars al guardar)
 * - [FASE 1] Validación de longitud defensiva en backend (mb_strlen)
 * - [FASE 2] Protección CSRF con token único por sesión + hash_equals
 * - [FASE 3] Input de precio con formato chileno ($15.000) en vivo
 */

// 1. DETECCIÓN INTELIGENTE DE RUTA
if (file_exists(__DIR__ . '/init_sesion.php')) {
    require_once __DIR__ . '/init_sesion.php';
    $app_dir = __DIR__; 
} else {
    require_once __DIR__ . '/app/init_sesion.php';
    $app_dir = __DIR__ . '/app';
}

require_once $app_dir . '/iconos.php';
require_once $app_dir . '/helpers/usuario_helper.php';

// 2. CANDADO ESTRICTO (Visitantes fuera)
if (function_exists('proteger_ruta')) {
    proteger_ruta(); 
} else {
    die("Error de seguridad: No se pudo cargar el control de sesión.");
}

// 3. CONEXIÓN
if (!isset($conn)) require_once $app_dir . '/conexion.php';

// =========================================================================
// 🛡️ [NUBIRA SHIELD] MIDDLEWARE ANTI-BOT (Nivel Arquitectura)
// Se ejecuta AQUÍ, antes de enviar HTML o hacer queries pesadas.
// =========================================================================
if (isset($conn)) {
    $antibot_path = $app_dir . '/middleware/antibot.php';
    if (file_exists($antibot_path)) {
        require_once $antibot_path;
        if (function_exists('check_nubira_shield')) {
            check_nubira_shield($conn); // Si es bot, corta aquí y devuelve 403 puro
        }
    }
}
// =========================================================================

// 5. DATOS DE USUARIO
$usuario_id          = (int)$_SESSION['usuario_id']; 
$nombre_usuario      = $_SESSION['usuario_nombre'] ?? 'Estudiante';
$institucion_session = strtolower(trim($_SESSION['institucion'] ?? ''));
$institucion         = $_SESSION['institucion'] ?? '';
$correo              = $_SESSION['email'] ?? '';

// [NUBIRA 2.0] CSRF TOKEN — Protección contra Cross-Site Request Forgery
if (empty($_SESSION['csrf_token_publicar'])) {
    $_SESSION['csrf_token_publicar'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token_publicar'];

// 6. LÓGICA DE NEGOCIO
$mensaje = "";
$exito   = false;
$ya_publico_hoy = false;

// Límite diario
$hoy = date('Y-m-d');
$stmt = $conn->prepare("SELECT COUNT(*) FROM servicios WHERE alumno_id = ? AND DATE(fecha_publicacion) = ?");
$stmt->bind_param("is", $usuario_id, $hoy);
$stmt->execute();
$stmt->bind_result($pubs_hoy);
$stmt->fetch();
$stmt->close();
if ($pubs_hoy >= 2) $ya_publico_hoy = true;

// Función Anti-Contacto
function contiene_contacto($texto) {
    $patrones = [
        // Teléfonos: 8+ dígitos consecutivos
        '/\b\d{8,}\b/',
        // Teléfonos con separadores: "9 1234 5678", "9-1234-5678"
        '/\b(?:\d[\s\-.]?){7}\d/',
        // Prefijo de país chileno
        '/\+56/',
        // Arroba directa (emails y @handles)
        '/@/',
        // "arroba" escrita para evadir el filtro
        '/\barroba\b/iu',
        // Dominios de email comunes (sin dominios .cl para evitar falsos positivos)
        '/\b(gmail|hotmail|yahoo|outlook|protonmail|live|icloud)\b/i',
        // URLs con protocolo o www
        '/(https?:\/\/|www\.)/i',
        // Dominios de mensajería directa sin protocolo
        '/wa\.me|t\.me/i',
        // Apps de mensajería y redes sociales
        '/\b(whatsapp|wsp|wpp|telegram|instagram|insta|tiktok|snapchat|discord|facebook)\b/i',
        // Frases de evasión explícita
        '/\b(contact[aá]me|escrí?beme|mi\s+n[uú]mero|fuera\s+de\s+la\s+plataforma)\b/iu',
    ];
    foreach ($patrones as $p) { if (preg_match($p, $texto)) return true; }
    return false;
}

// PROCESAMIENTO POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$ya_publico_hoy) {
    
    // [NUBIRA 2.0] Validación CSRF — primer candado de seguridad
    $token_recibido = $_POST['csrf_token'] ?? '';
    if (empty($token_recibido) || !hash_equals($_SESSION['csrf_token_publicar'] ?? '', $token_recibido)) {
        error_log("Nubira CSRF Alert - Token inválido. usuario_id={$usuario_id}, IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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
    $nombre_oferente = $nombre_usuario;

    // Validación defensiva de longitud en backend
    if (mb_strlen($titulo) > 70 || mb_strlen($descripcion) > 1500) {
        $mensaje = "El título o descripción exceden el límite permitido.";
        $titulo = '';
    }

    if (!$titulo || !$descripcion || !$categoria || !$modalidad) {
        if (empty($mensaje)) $mensaje = "Faltan campos obligatorios.";
    } elseif (contiene_contacto($titulo) || contiene_contacto($descripcion)) {
        $mensaje = "Por seguridad, no incluyas teléfonos ni correos.";
    } else {
        $nombreArchivo = ''; 
        $imagen_estado = 'oculta'; 
        
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $upload_dir = $docRoot . '/upload/servicios/';
        if (!file_exists($upload_dir)) @mkdir($upload_dir, 0755, true);

        if (!empty($_FILES['imagen']['name'])) {
            if (isset($_FILES['imagen']['error']) && $_FILES['imagen']['error'] === UPLOAD_ERR_INI_SIZE) {
                $mensaje = "La imagen es demasiado grande. El máximo permitido es 4MB.";
                goto fin_post;
            }
            if ($_FILES['imagen']['size'] > 4 * 1024 * 1024) {
                $mensaje = "La imagen no puede superar 4MB.";
                goto fin_post;
            }
            $file_tmp  = $_FILES['imagen']['tmp_name'];
            $file_type = mime_content_type($file_tmp);
            
            if (in_array($file_type, ['image/jpeg', 'image/png', 'image/webp'])) {
                $file_name    = uniqid('serv_') . '.webp';
                $upload_path = $upload_dir . $file_name;

                $img = null;
                if ($file_type === 'image/jpeg') $img = @imagecreatefromjpeg($file_tmp);
                elseif ($file_type === 'image/png') $img = @imagecreatefrompng($file_tmp);
                elseif ($file_type === 'image/webp') $img = @imagecreatefromwebp($file_tmp);

                if ($img) {
                    $w_orig = imagesx($img); 
                    $h_orig = imagesy($img);
                    
                    // Aviso si la imagen original es demasiado chica
                    if ($w_orig < 800) {
                        error_log("Nubira UploadInfo - Imagen baja resolución: usuario_id={$usuario_id}, dimensiones={$w_orig}x{$h_orig}");
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
                    $imagen_estado = 'visible';
                }
            }
        }

        $sql = "INSERT INTO servicios (alumno_id, institucion, titulo, descripcion, nombre_oferente, categoria, modalidad, ubicacion, precio, correo, imagen, imagen_estado, estado, fecha_publicacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("isssssssdsss", $usuario_id, $institucion, $titulo, $descripcion, $nombre_oferente, $categoria, $modalidad, $ubicacion, $precio, $correo, $nombreArchivo, $imagen_estado);
            
            if ($stmt->execute()) {
                $nuevo_servicio_id = $stmt->insert_id;
                actualizar_score_servicio($conn, $nuevo_servicio_id);

                $mensaje = "¡Excelente! Tu servicio ha sido enviado a revisión.";
                $exito   = true;
                $ya_publico_hoy = true;
            } else {
                error_log("Nubira Error - Insert Servicio: " . $stmt->error);
                $mensaje = "Error en base de datos al guardar.";
            }
            $stmt->close();
        }
    }
    
    fin_post:; // Punto de salida CSRF
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Publicar Servicio | Nubira</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
 <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
    
    /* [NUBIRA 2.0] Modo "Task" en móvil: form enfocado sin distracciones.
       - Oculta nav_bottom (la X reemplaza la salida)
       - Oculta barra de búsqueda del header (no es contexto de explorar) */
    @media (max-width: 767px) {
        /* Ocultar nav inferior */
        nav.fixed.bottom-0,
        .fixed.bottom-0[id*="nav"] {
            display: none !important;
        }
        
        /* Ocultar barra de búsqueda del header global */
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

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-16 md:pt-20 pb-32 md:pb-8 md:ml-64 px-4 max-w-[1100px] w-full mx-auto md:px-8 min-h-[calc(100vh-80px)] flex flex-col transition-all duration-300">

  <!-- [NUBIRA 2.0] Header del form: título a la izquierda, X a la derecha (móvil). En escritorio sin X. -->
<div class="flex items-center justify-between gap-3 mb-6 min-h-[44px]">
    
    <!-- Título a la izquierda (siempre) -->
    <div class="min-w-0 flex-1">
        <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight leading-tight truncate">Publicar Servicio</h1>
        <p class="text-gray-500 text-[11px] md:text-sm mt-0.5 font-medium md:font-normal uppercase md:normal-case tracking-wide md:tracking-normal truncate">
            Configura tu oferta académica
        </p>
    </div>
    
    <!-- Botón X cerrar (solo móvil, a la derecha) -->
    <a href="/explorar" 
       class="md:hidden flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 active:scale-95 transition-all"
       aria-label="Cerrar">
        <svg class="w-5 h-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    </a>
</div>

    <?php if ($ya_publico_hoy && !$exito): ?>
      <div class="bg-white p-10 rounded-2xl shadow-[0_4px_14px_rgba(0,0,0,0.06)] border border-gray-100 text-center max-w-2xl mx-auto mt-8">
         <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4 text-[#54A6D8]">
            <?= icon('sparkles', 'w-10 h-10') ?>
         </div>
         <h2 class="text-2xl font-bold text-gray-900 mb-2">¡Meta diaria cumplida!</h2>
         <p class="text-gray-500 max-w-md mx-auto text-sm">Para mantener la calidad, permitimos máximo 2 servicios por día. ¡Vuelve mañana!</p>
         <a href="/dashboard" class="inline-block mt-6 px-6 py-3 bg-[#54A6D8] text-white font-bold rounded-xl hover:bg-sky-600 transition shadow-lg shadow-blue-200">Ir a mi perfil</a>
      </div>
    <?php else: ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 flex-grow mb-6">
        
        <div class="lg:col-span-10 lg:col-start-2 flex flex-col">
            
            <div class="mb-6 bg-white border border-gray-100 rounded-xl px-4 py-2.5 shadow-sm flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <?= icon('sparkles', 'w-4 h-4 text-[#54A6D8]') ?>
                    <span class="text-xs font-bold text-gray-700 uppercase tracking-wide">Calidad</span>
                </div>
                <div class="flex items-center gap-3 flex-1 max-w-[200px] justify-end">
                    <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                        <div id="barra-progreso" class="h-1.5 rounded-full transition-all duration-500 ease-out w-0 bg-gray-300"></div>
                    </div>
                    <span id="calidad-score" class="font-bold text-xs text-gray-500 min-w-[32px] text-right">0%</span>
                </div>
            </div>

            <form id="form-servicio" method="POST" enctype="multipart/form-data" class="space-y-6" autocomplete="off">
                
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
                                    <option value="">Selecciona una opción...</option>
                                    <option value="Clases">Clases Particulares</option>
                                    <option value="Tutoría">Tutorías / Ayudantías</option>
                                    <option value="Asesoría">Asesoría Tesis/Proyectos</option>
                                    <option value="Idiomas">Idiomas</option>
                                    <option value="Otros">Otros Servicios</option>
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
                                    <option value="Online">Online</option>
                                    <option value="Presencial">Presencial</option>
                                    <option value="Híbrido">Híbrido</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                                    <?= icon('chevron-down', 'w-4 h-4 text-gray-400') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="campo-ubicacion" class="hidden mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Ubicación</label>
                        <input type="text" name="ubicacion" id="ubicacion" placeholder="Ej: Santiago Centro, Campus San Joaquín..."
                               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
                    </div>

                    <div class="mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Título del anuncio</label>
                        <div class="relative">
                            <input type="text" name="titulo" id="titulo" required maxlength="70" placeholder="Ej: Clases de Cálculo / Asesoría de Tesis"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
                            <div class="text-right text-xs text-gray-400 mt-1 absolute right-0 -bottom-5"><span id="titulo-count">0</span>/70</div>
                        </div>
                    </div>

                    <div class="mb-6 mt-8">
                        <div class="mb-2">
                            <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide">Descripción</label>
                        </div>
                        
                        <textarea name="descripcion" id="descripcion" rows="8" maxlength="1500" required placeholder="Describe detalladamente el servicio que ofreces..."
                                  class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-4 resize-none transition outline-none leading-relaxed"></textarea>
                        
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
                            
                            <!-- Input visible: formato chileno con puntos -->
                            <input type="text" 
                                   id="precio_visible" 
                                   inputmode="numeric"
                                   autocomplete="off"
                                   placeholder="0 = A convenir"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-lg font-bold rounded-xl pl-8 p-3.5 focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition outline-none">
                            
                            <!-- Input real que viaja al backend (siempre numérico puro) -->
                            <input type="hidden" name="precio" id="precio" value="0">
                        </div>
                        <p class="text-xs text-gray-400 mt-1 ml-1">Pon "0" si el precio es a convenir o gratuito.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Foto de portada</label>
                        <div class="relative border-2 border-dashed border-gray-300 rounded-2xl bg-gray-50 hover:bg-white hover:border-[#54A6D8] transition-all duration-300 cursor-pointer group" onclick="document.getElementById('imagen').click()">
                            <input type="file" name="imagen" id="imagen" class="hidden" accept="image/jpeg,image/png,image/webp">
                            
                            <div class="flex flex-col items-center justify-center py-10">
                                <div class="mb-3 text-gray-300 group-hover:text-[#54A6D8] transition-colors transform group-hover:scale-110 duration-300">
                                    <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <p class="mb-1 text-sm font-medium text-gray-700">Haz clic para subir o arrastra</p>
                                <p class="text-xs text-gray-400">JPG, PNG, WebP (Máx. 4MB)</p>
                            </div>
                        </div>
                       <div id="preview" class="mt-4 hidden text-center animate-fade-in-up">
    <div class="relative inline-block w-full">
        <img id="previewImg" src="" class="h-64 w-full object-cover rounded-xl shadow-sm border border-gray-200 transition-opacity duration-300">
        
        <!-- Overlay de compresión (se muestra automáticamente cuando img tiene opacity 0.5) -->
        <div id="compresion-overlay" class="absolute inset-0 flex items-center justify-center bg-white/40 backdrop-blur-sm rounded-xl pointer-events-none opacity-0 transition-opacity duration-300">
            <div class="bg-white px-4 py-2 rounded-full shadow-lg flex items-center gap-2">
                <div class="animate-spin h-4 w-4 border-2 border-gray-200 border-t-[#54A6D8] rounded-full"></div>
                <span class="text-xs font-bold text-gray-700">Optimizando imagen...</span>
            </div>
        </div>
    </div>
    <button type="button" onclick="document.getElementById('imagen').value=''; document.getElementById('preview').classList.add('hidden');" class="text-xs text-red-500 mt-2 hover:underline">Eliminar imagen</button>
</div>
                    </div>
                </div>

                <!-- [NUBIRA 2.0] Wrapper sticky solo en móvil. En escritorio queda inline. -->
<div class="md:static md:bg-transparent md:p-0 md:border-0 
            fixed bottom-0 left-0 right-0 z-40 
            bg-white/95 backdrop-blur-md 
            px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]
            border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)]">
    <button id="btn-submit" type="submit" 
        class="w-full text-white bg-[#54A6D8] hover:bg-sky-600 font-bold rounded-2xl text-base px-5 py-4 text-center shadow-lg shadow-blue-200 hover:shadow-blue-300 transform active:scale-[0.99] transition-all flex items-center justify-center gap-2 [&>*]:pointer-events-none">
    <span id="btn-text">Publicar Servicio</span> 
    <?= icon('arrow-right', 'w-5 h-5') ?>
</button>
</div>

            </form>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php 
$rutas_footer = [
    $app_dir . '/includes/footer.php',
    __DIR__ . '/includes/footer.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php',
    $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'
];

$footer_encontrado = false;
foreach ($rutas_footer as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $footer_encontrado = true;
        break;
    }
}
?>

<script>
// --- 0. CONFIGURACIÓN ---
const USER_CONTEXT = { nombre: "<?= htmlspecialchars($nombre_usuario ?? 'Estudiante') ?>" };

window.onload = () => { 
    const l = document.getElementById('loader'); 
    if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
};

// --- LÓGICA DE MODALES NUBIRA 2.0 ---
document.addEventListener('DOMContentLoaded', () => {
    function setupModal(triggerId, modalId, cardId, closeId) {
        const btn = document.getElementById(triggerId);
        const modal = document.getElementById(modalId);
        const card = document.getElementById(cardId);
        const close = document.getElementById(closeId);

        if(!btn || !modal) return;

        const open = () => { 
            modal.classList.remove('hidden'); 
            requestAnimationFrame(() => { card.classList.remove('translate-y-full', 'opacity-0'); });
            document.body.style.overflow = 'hidden'; 
        };

        const shut = () => { 
            card.classList.add('translate-y-full', 'opacity-0'); 
            setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); 
        };

        btn.onclick = (e) => { e.preventDefault(); open(); }; 
        if(close) close.onclick = shut; 
        modal.onclick = (e) => { if(e.target === modal) shut(); };
    }

    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});

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
    bar: document.getElementById('barra-progreso'),
    scoreText: document.getElementById('calidad-score'),
    countDesc: document.getElementById('descripcion-count'),
    countTitulo: document.getElementById('titulo-count')
};

// [NUBIRA 2.0] Compresión client-side de imágenes (estilo app nativa)
// Comprime en el navegador ANTES de subir → upload 5-10x más rápido en 4G chileno.
// Backend recibe normalmente, no requiere cambios en publicar_servicio.php
const NubiraImageCompressor = {
    MAX_WIDTH: 1920,        // Coincide con el "main" 1600 del backend + buffer
    MAX_HEIGHT: 1920,
    QUALITY: 0.85,          // 85% JPEG → equilibrio calidad/peso óptimo
    MAX_INPUT_MB: 20,       // Acepta hasta 20MB de entrada (cámara moderna iPhone)
    
    async compress(file) {
        // Validación de tipo
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            throw new Error('Formato no soportado. Usa JPG, PNG o WebP.');
        }
        
        // Validación de tamaño máximo de entrada
        if (file.size > this.MAX_INPUT_MB * 1024 * 1024) {
            throw new Error(`La imagen es demasiado pesada (máximo ${this.MAX_INPUT_MB}MB).`);
        }
        
        // Cargar imagen en memoria
        const img = await this._loadImage(file);
        
        // Calcular nuevas dimensiones manteniendo proporción
        let { width, height } = this._calculateSize(img.width, img.height);
        
        // Renderizar en canvas con resampling de calidad
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, width, height);
        
        // Liberar memoria de la imagen original
        URL.revokeObjectURL(img.src);
        
        // Exportar como Blob JPEG
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(
                b => b ? resolve(b) : reject(new Error('No se pudo procesar la imagen.')),
                'image/jpeg',
                this.QUALITY
            );
        });
        
        // Convertir Blob a File (con nombre original pero forzado .jpg)
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
        // Si ya está dentro del límite, no redimensionamos
        if (originalW <= this.MAX_WIDTH && originalH <= this.MAX_HEIGHT) {
            return { width: originalW, height: originalH };
        }
        
        // Mantener proporción
        const ratio = Math.min(this.MAX_WIDTH / originalW, this.MAX_HEIGHT / originalH);
        return {
            width: Math.round(originalW * ratio),
            height: Math.round(originalH * ratio)
        };
    }
};

// [NUBIRA 2.0] Listener del input de imagen con compresión transparente
ui.imgInput?.addEventListener('change', async function() {
    if (!this.files || this.files.length === 0) return;
    
    const fileOriginal = this.files[0];
    // Mostrar preview inmediato (con la imagen original, antes de comprimir)
    // Esto da sensación instantánea aunque la compresión tome 1-2 segundos
    ui.imgTag.src = URL.createObjectURL(fileOriginal);
    ui.imgPreview.classList.remove('hidden');
    
  // Indicador visual de compresión en curso
ui.imgTag.style.opacity = '0.5';
const overlay = document.getElementById('compresion-overlay');
if (overlay) overlay.style.opacity = '1';
    
    try {
        const fileComprimido = await NubiraImageCompressor.compress(fileOriginal);
        // Reemplazar el archivo del input por el comprimido
        // (DataTransfer es la única forma cross-browser de modificar el input file)
        const dt = new DataTransfer();
        dt.items.add(fileComprimido);
        this.files = dt.files;
        
        // Actualizar preview con la versión comprimida
        ui.imgTag.src = URL.createObjectURL(fileComprimido);
        ui.imgTag.style.opacity = '1';
const overlayOff = document.getElementById('compresion-overlay');
if (overlayOff) overlayOff.style.opacity = '0';
        
        // Refrescar barra de calidad
        if (typeof calcQuality === 'function') calcQuality();
        
    } catch (err) {
      ui.imgTag.style.opacity = '1';
const overlayOff = document.getElementById('compresion-overlay');
if (overlayOff) overlayOff.style.opacity = '0';
        console.error('[Nubira] Error compresión:', err);
        
        // Mostrar error suave al usuario
        const errBox = document.createElement('div');
        errBox.className = 'mt-2 text-xs text-red-500 font-medium bg-red-50 border border-red-200 px-3 py-2 rounded-lg';
        errBox.textContent = err.message + ' Sube otra imagen.';
        ui.imgPreview.appendChild(errBox);
        setTimeout(() => errBox.remove(), 5000);
        
        // Resetear el input
        this.value = '';
        ui.imgPreview.classList.add('hidden');
    }
});
ui.modalidad?.addEventListener('change', function() {
    if (this.value === 'Presencial' || this.value === 'Híbrido') { ui.ubicacionBox.classList.remove('hidden'); ui.ubicacionInput.required = true; } 
    else { ui.ubicacionBox.classList.add('hidden'); ui.ubicacionInput.required = false; ui.ubicacionInput.value = ''; }
});
ui.desc?.addEventListener('input', function() {
    const isBad = [
        /\d{8,}/,
        /(?:\d[\s\-.]?){7}\d/,
        /\+56/,
        /@/,
        /\b(whatsapp|wsp|wpp|telegram|instagram|insta|tiktok|snapchat|discord|facebook)\b/i
    ].some(p => p.test(this.value));
    ui.warning.classList.toggle('hidden', !isBad);
    ui.warning.classList.toggle('flex', isBad);
    ui.btnSubmit.disabled = isBad;
    if(isBad) ui.btnSubmit.classList.add('opacity-50'); else ui.btnSubmit.classList.remove('opacity-50');
    if(ui.countDesc) ui.countDesc.textContent = this.value.length;
});
ui.titulo?.addEventListener('input', function() { if(ui.countTitulo) ui.countTitulo.textContent = this.value.length; });

// --- CÁLCULO DE CALIDAD (definido aquí para que setupPrecioFormat pueda llamarlo) ---
function calcQuality() {
    let score = 0;
    if (ui.titulo?.value.length >= 10) score += 25;
    if (ui.desc?.value.length >= 50) score += 25;
    if (ui.imgInput?.files.length > 0) score += 30;
    
    const precioVisible = document.getElementById('precio_visible');
    if (precioVisible && precioVisible.value !== '') score += 20;
    
    if(ui.bar) {
        ui.bar.style.width = `${score}%`;
        ui.scoreText.innerText = `${score}%`;
        ui.bar.className = `h-1.5 rounded-full transition-all duration-500 ease-out ${score === 0 ? 'bg-gray-300' : 'bg-[#54A6D8]'}`;
    }
}

// [NUBIRA 2.0] Formato chileno de precio en vivo ($15.000)
// Un solo listener, sin reentradas, con anti-bucle.
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
            
            calcQuality();
        } finally {
            formateando = false;
        }
    });
})();

// Listeners de calidad para los demás campos
['titulo', 'descripcion', 'imagen'].forEach(id => 
    document.getElementById(id)?.addEventListener('input', calcQuality)
);
document.getElementById('imagen')?.addEventListener('change', calcQuality);

// [NUBIRA 2.0] Bloque obsoleto — el nav_bottom ahora se oculta vía CSS en esta vista.
// No necesitamos manipular el padding en focus/blur. El botón sticky tiene su propio espacio.
// Mantenemos un minimal scroll-into-view para que cuando el teclado móvil aparezca,
// el campo enfocado quede visible y no debajo del teclado.
if (window.innerWidth < 768) {
    document.querySelectorAll('input, textarea, select').forEach(el => {
        el.addEventListener('focus', () => {
            // Pequeño delay para que el teclado iOS termine de subir
            setTimeout(() => {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        });
    });
}
</script>

</body>
</html>