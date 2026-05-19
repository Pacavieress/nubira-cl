<?php
/**
 * NUBIRA 2.0 - UPLOAD SYSTEM (AJAX EDITION)
 * 
 * [V3-1] Subida AJAX con progreso real (XMLHttpRequest.upload)
 * [V3-2] Compresión de imágenes en navegador antes de subir
 * [V3-3] Validación en tiempo real de campos
 * [V3-4] Retry automático si falla la red (hasta 2 reintentos)
 * [V3-5] Seguridad: Prepared statement en UPDATE, no htmlspecialchars al guardar
 * [V3-6] Rendimiento: Scripts defer, fonts preload
 */

session_start();

if (!isset($_SESSION['usuario_id'])) {
    // Si es AJAX, responder JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        http_response_code(401);
        echo json_encode(['error' => 'No autenticado']);
        exit;
    }
    header("Location: /login.php");
    exit;
}

ignore_user_abort(true); 
set_time_limit(300); 
ini_set('memory_limit', '512M'); 

$app_dir = file_exists(__DIR__ . '/init_sesion.php') ? __DIR__ : __DIR__ . '/app';
require_once $app_dir . '/iconos.php';
require_once $app_dir . '/conexion.php';

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

$usuario_id = (int)$_SESSION['usuario_id'];
$anio_default = date('Y');
$semestre_default = (date('n') <= 7) ? 1 : 2;

// =============================================
// MODO AJAX: Procesar y responder JSON
// =============================================
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax) {
    header('Content-Type: application/json');
    
    try {
        $root = $_SERVER['DOCUMENT_ROOT']; 
        $dir_apuntes = $root . '/upload/apuntes/';
        $dir_preview = $root . '/upload/preview/';

        if (!file_exists($dir_apuntes)) { @mkdir($dir_apuntes, 0755, true); }
        if (!file_exists($dir_preview)) { @mkdir($dir_preview, 0755, true); }

        $titulo      = trim($_POST['titulo'] ?? '');
        $semestre    = intval($_POST['semestre'] ?? $semestre_default);
        $anio        = intval($_POST['anio'] ?? date('Y'));
        $descripcion = trim($_POST['descripcion'] ?? '');
        $precio      = intval($_POST['precio'] ?? 0);
        $inst        = $_SESSION['institucion'] ?? '';
        $asignatura  = trim($_POST['asignatura'] ?? 'General');
        
        $ia_version  = 'Nubira-AI-2.0-Fast'; 
        $ia_used     = intval($_POST['ia_used'] ?? 0);
        $ia_accepted = intval($_POST['ia_accepted'] ?? 0);
        $ia_keywords = trim($_POST['ia_keywords'] ?? '');
        
        // [NUBIRA 2.0] Categorización
$materia         = trim($_POST['materia'] ?? '');
$nivel_academico = trim($_POST['nivel_academico'] ?? 'universitario');
$subtema         = mb_substr(trim($_POST['subtema'] ?? ''), 0, 80);

// Validación
$materias_validas = ['calculo','fisica','algebra','programacion','quimica','biologia','contabilidad','economia','derecho','psicologia','idiomas','redaccion'];
$niveles_validos = ['universitario','paes','escolar'];

if (!in_array($materia, $materias_validas, true)) $materia = null;
if (!in_array($nivel_academico, $niveles_validos, true)) $nivel_academico = 'universitario';
if ($subtema === '') $subtema = null;

        // Validaciones server-side
        if (empty($titulo)) { echo json_encode(['error' => 'El título es obligatorio']); exit; }
        if (strlen($titulo) > 80) { echo json_encode(['error' => 'El título no puede superar 80 caracteres']); exit; }
        if (empty($descripcion)) { echo json_encode(['error' => 'La descripción es obligatoria']); exit; }
        if ($precio < 0) { echo json_encode(['error' => 'El precio no puede ser negativo']); exit; }

        if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'Archivo supera el límite del servidor',
                UPLOAD_ERR_FORM_SIZE => 'Archivo supera el límite del formulario',
                UPLOAD_ERR_PARTIAL => 'Archivo subido parcialmente — intenta de nuevo',
                UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
            ];
            $code = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
            echo json_encode(['error' => $upload_errors[$code] ?? 'Error al recibir archivo']); 
            exit;
        }
        
        if ($_FILES['archivo']['size'] > 40*1024*1024) { 
            echo json_encode(['error' => 'Archivo muy pesado (Máx 40MB)']); exit;
        }

        $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
        $imgs = ['jpg','jpeg','png','webp','bmp','gif'];
        $docs = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt'];
        
        if (!in_array($ext, array_merge($imgs, $docs))) {
            echo json_encode(['error' => 'Formato no permitido: .' . $ext]); exit;
        }

        $filename_original = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $ruta_final_apunte = $dir_apuntes . $filename_original;

        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $ruta_final_apunte)) {
            echo json_encode(['error' => 'Error crítico al guardar archivo']); exit;
        }
        @chmod($ruta_final_apunte, 0644);

        $sql = "INSERT INTO apuntes (titulo, semestre, anio, descripcion, archivo, id_alumno, institucion, publico, precio, fecha_subida, ia_version, ia_used, ia_accepted, ia_keywords, asignatura, materia, subtema, nivel_academico) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['error' => 'Error de base de datos']); exit;
}

$stmt->bind_param("siissisisiisssss", $titulo, $semestre, $anio, $descripcion, $filename_original, $usuario_id, $inst, $precio, $ia_version, $ia_used, $ia_accepted, $ia_keywords, $asignatura, $materia, $subtema, $nivel_academico);
        
        if (!$stmt) {
    echo json_encode(['error' => 'Prepare falló: ' . $conn->error]); exit;
}
        if (!$stmt->execute()) {
            echo json_encode(['error' => 'Error BD: ' . $stmt->error]); exit;
        }

        $id_apunte = $stmt->insert_id;
        $stmt->close();
        
        $filename_preview = $id_apunte . '.webp';
        $ruta_final_preview = $dir_preview . $filename_preview;
        $se_genero_preview = false;

        // Preview para imágenes
        if (in_array($ext, $imgs)) {
            try {
                $image = null;
                if ($ext == 'jpeg' || $ext == 'jpg') $image = imagecreatefromjpeg($ruta_final_apunte);
                elseif ($ext == 'png') $image = imagecreatefrompng($ruta_final_apunte);
                elseif ($ext == 'webp') $image = imagecreatefromwebp($ruta_final_apunte);
                
                if ($image) {
                    if (imagesx($image) > 1200) $image = imagescale($image, 1200);
                    if ($ext == 'png' || $ext == 'webp') { 
                        imagepalettetotruecolor($image); 
                        imagealphablending($image, true); 
                        imagesavealpha($image, true); 
                    }
                    imagewebp($image, $ruta_final_preview, 70); 
                    imagedestroy($image);
                    $se_genero_preview = true;
                }
            } catch (Throwable $e) { 
                if($ext === 'webp') @copy($ruta_final_apunte, $ruta_final_preview); 
            }
        } 
        // Preview para PDFs con Imagick
        elseif ($ext === 'pdf' && extension_loaded('imagick') && class_exists('Imagick')) {
            try {
                $pagina_elegida = intval($_POST['pagina_portada'] ?? 1);
                $indice_portada = max(0, $pagina_elegida - 1); 
                
                $dir_paginas = $_SERVER['DOCUMENT_ROOT'] . '/upload/preview_paginas/';
                if (!file_exists($dir_paginas)) { @mkdir($dir_paginas, 0755, true); }

                $ping = new Imagick();
                $ping->pingImage($ruta_final_apunte);
                $num_pages = $ping->getNumberImages();
                $ping->clear(); $ping->destroy();

                if ($indice_portada >= $num_pages) $indice_portada = 0;
                $pages_to_extract = min(3, $num_pages);

                for ($i = 0; $i < $pages_to_extract; $i++) {
                    $im = new Imagick();
                    $im->setResolution(100, 100); 
                    $im->setBackgroundColor('white');
                    $im->readImage($ruta_final_apunte . '[' . $i . ']');
                    $im->setImageFormat('webp');
                    $im->setImageCompressionQuality(80);
                    $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                    $im->thumbnailImage(900, 0); 
                    
                    $ruta_pagina = $dir_paginas . $id_apunte . '_' . ($i + 1) . '.webp';
                    $im->writeImage($ruta_pagina);
                    
                    if ($i === $indice_portada) {
                        @copy($ruta_pagina, $ruta_final_preview);
                        $se_genero_preview = true;
                    }
                    $im->clear(); $im->destroy();
                }
            } catch (Throwable $e) {
                error_log("Nubira Imagick Error: " . $e->getMessage());
                if (!file_exists($ruta_final_preview)) {
                    try {
                        $fallback = new Imagick();
                        $fallback->setResolution(60, 60);
                        $fallback->readImage($ruta_final_apunte . '[0]');
                        $fallback->setImageFormat('webp');
                        $fallback->thumbnailImage(800, 0);
                        $fallback->writeImage($ruta_final_preview);
                        $fallback->destroy();
                        $se_genero_preview = true;
                    } catch(Throwable $e2) {}
                }
            }
        }

        if ($se_genero_preview && file_exists($ruta_final_preview)) {
            @chmod($ruta_final_preview, 0644);
            $stmtUpd = $conn->prepare("UPDATE apuntes SET preview = ?, portada = ? WHERE id = ?");
            if ($stmtUpd) {
                $stmtUpd->bind_param("ssi", $filename_preview, $filename_preview, $id_apunte);
                $stmtUpd->execute();
                $stmtUpd->close();
            }
        }

        // Logger
        if (file_exists($app_dir . '/logger.php')) {
            require_once $app_dir . '/logger.php';
            registrar_actividad($conn, $usuario_id, 'PUBLICAR_APUNTE', "ID: $id_apunte | $titulo");
        }

      echo json_encode([
    'success' => true, 
    'mensaje' => '✅ Tu publicación está siendo revisada',
    'id' => $id_apunte,
    'redirect' => '/vitrina-apuntes'
]);
        exit;

    } catch (Throwable $e) {
    error_log("Nubira Fatal Error: " . $e->getMessage());
    echo json_encode(['error' => 'Error inesperado del servidor']);
    exit;
}
}

// =============================================
// MODO NORMAL: Renderizar formulario HTML
// =============================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Publicar Apunte | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js" defer></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfAZ9hjQ.woff2" as="font" type="font/woff2" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .drag-over { border-color: #54A6D8 !important; background-color: rgba(84, 166, 216, 0.05) !important; transform: scale(1.01); }
        
      @keyframes breathe {
    0%, 100% { transform: scale(0.95); filter: drop-shadow(0 0 6px rgba(84, 166, 216, 0.15)); }
    50% { transform: scale(1.05); filter: drop-shadow(0 0 20px rgba(84, 166, 216, 0.45)); }
}
@keyframes breatheFast {
    0%, 100% { transform: scale(0.96); filter: drop-shadow(0 0 8px rgba(84, 166, 216, 0.3)); }
    50% { transform: scale(1.1); filter: drop-shadow(0 0 30px rgba(84, 166, 216, 0.7)) brightness(1.08); }
}
.brain-idle { animation: breathe 3.5s ease-in-out infinite; transform-origin: center center; }
.brain-thinking { animation: breatheFast 0.6s ease-in-out infinite; transform-origin: center center; }
        
        .scan-layer { display: none; }
        .scanning .scan-layer { display: block; animation: fadeIn 0.3s ease; }
        @keyframes laser-move { 0% { top: 0%; opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
        .scan-laser-beam { 
            position: absolute; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent, #54A6D8, #60A5FA, #ffffff, #60A5FA, #54A6D8, transparent); 
            box-shadow: 0 0 20px 5px rgba(84, 166, 216, 0.5); 
            animation: laser-move 2s ease-in-out infinite; z-index: 20; pointer-events: none;
        }

        @property --angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
        .ai-border-spin {
            position: relative; background: white !important; color: #111827;
            border-radius: 9999px; z-index: 1; display: inline-flex; align-items: center; justify-content: center;
            border: 2px solid transparent; background-clip: padding-box !important;
        }
        .ai-border-spin::before {
            content: ""; position: absolute; inset: -2px; border-radius: inherit; padding: 2px;
            background: conic-gradient(from var(--angle), #54A6D8, #a855f7, #ec4899, #54A6D8);
            animation: spin-border 2s linear infinite;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; z-index: -1;
        }
        @keyframes spin-border { to { --angle: 360deg; } }

        /* [V3-1] Barra de progreso real */
        .upload-progress-track { height: 6px; border-radius: 999px; overflow: hidden; }
        .upload-progress-bar { height: 100%; border-radius: 999px; transition: width 0.3s ease; }
        
        /* [V3-3] Validación en tiempo real */
        .field-error { border-color: #ef4444 !important; }
        .field-ok { border-color: #22c55e !important; }
        /* [NUBIRA 2.0] Sistema de badges "Nuevo" reutilizable */
.feature-badge {
    position: absolute;
    top: -6px;
    right: -10px;
    background: linear-gradient(135deg, #54A6D8 0%, #4092c4 100%);
    color: white;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.3px;
    padding: 2px 6px;
    border-radius: 999px;
    box-shadow: 0 2px 6px rgba(84, 166, 216, 0.45);
    border: 1.5px solid white;
    line-height: 1;
    text-transform: uppercase;
    pointer-events: none;
    white-space: nowrap;
    transition: opacity 0.3s ease, transform 0.3s ease;
    z-index: 5;
}
.feature-badge.is-hidden {
    opacity: 0;
    transform: scale(0.5);
}
.feature-host {
    position: relative;
}
    </style>
</head>

<body class="bg-gray-50 text-gray-900 antialiased overflow-x-hidden overscroll-y-none selection:bg-sky-100 selection:text-sky-900">

<div id="loader" class="fixed inset-0 bg-white/90 backdrop-blur-sm flex items-center justify-center z-[60] transition-opacity duration-500">
    <div class="animate-spin h-10 w-10 border-4 border-gray-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
if (file_exists($app_dir . '/componentes/header.php')) require_once $app_dir . '/componentes/header.php'; 
if (file_exists($app_dir . '/componentes/sidebar.php')) require_once $app_dir . '/componentes/sidebar.php'; 
if (file_exists($app_dir . '/componentes/nav_bottom.php')) require_once $app_dir . '/componentes/nav_bottom.php'; 
?>

<main class="pt-16 md:pt-20 pb-20 md:pb-8 md:ml-64 px-4 max-w-[1100px] w-full mx-auto md:px-8 min-h-[calc(100vh-80px)] flex flex-col transition-all duration-300">

    <div class="mb-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight leading-tight">Publicar Apunte</h1>
            <p class="text-gray-500 text-xs md:text-sm mt-0.5">Comparte y monetiza tu conocimiento.</p>
        </div>
        <div class="flex items-center gap-3 bg-white px-3 py-1.5 rounded-full border border-gray-100 shadow-sm hidden md:flex">
            <span class="text-[10px] uppercase tracking-widest font-bold text-gray-400">Calidad</span>
            <div class="w-20 bg-gray-100 rounded-full h-1.5 overflow-hidden">
                <div id="barra-progreso" class="h-1.5 rounded-full transition-all duration-700 ease-out w-0 bg-gradient-to-r from-sky-400 to-[#54A6D8]"></div>
            </div>
            <span id="calidad-score" class="font-bold text-xs text-[#54A6D8]">0%</span>
        </div>
    </div>

    <!-- [V3-1] Toast dinámico para respuestas AJAX -->
    <div id="toast" class="mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm hidden transition-all duration-300">
        <span id="toast-icon"></span>
        <span id="toast-msg" class="text-sm font-bold flex-1"></span>
        <button type="button" onclick="document.getElementById('toast').classList.add('hidden')" class="text-sm opacity-60 hover:opacity-100">✕</button>
    </div>

    <form id="form-apunte" class="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 shadow-sm flex-grow mb-6">
     <input type="hidden" name="ia_keywords" id="ia_keywords" value="">
<input type="hidden" name="ia_used" id="ia_used" value="0">
<input type="hidden" name="ia_accepted" id="ia_accepted" value="0">
<input type="hidden" name="materia" id="materia" value="">
<input type="hidden" name="nivel_academico" id="nivel_academico" value="universitario">
<input type="hidden" name="subtema" id="subtema" value="">

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
            
            <div class="flex flex-col gap-4">

                <div id="mobile-buttons" class="md:hidden grid grid-cols-2 gap-3 mb-2">
                    <button type="button" onclick="abrirModalEscaner()" class="p-4 bg-sky-50 border border-sky-100 rounded-2xl text-[#54A6D8] hover:bg-sky-100 hover:scale-[1.02] transition-all font-bold text-xs flex flex-col items-center gap-2 shadow-sm">
                        <?= icon('camera', 'w-6 h-6') ?> Escanear
                    </button>
                    <button type="button" onclick="document.getElementById('archivo').click()" class="p-4 bg-gray-50 border border-gray-200 rounded-2xl text-gray-600 font-bold text-xs flex flex-col items-center gap-2">
                        <?= icon('upload', 'w-6 h-6') ?> Subir
                    </button>
                </div>

               <label for="archivo" id="drop-zone" class="relative overflow-hidden transform-gpu border-2 border-dashed border-gray-200 rounded-2xl h-48 md:h-64 flex flex-col justify-center items-center text-center cursor-pointer transition-all duration-300 hidden md:flex bg-gray-50/50 hover:bg-white z-0">
    <div class="scan-layer absolute inset-0 z-10 bg-white/80 backdrop-blur-[2px]">
        <div class="scan-laser-beam absolute left-0 right-0 top-0"></div> 
        <div class="absolute bottom-6 w-full text-center">
            <span id="scan-text" class="text-xs font-bold text-[#54A6D8] tracking-widest animate-pulse">ANALIZANDO CON IA...</span>
        </div>
    </div>
    
    <div id="original-upload-content" class="relative z-0 flex flex-col items-center justify-center pointer-events-none p-4">
        <div id="brain-icon" class="w-24 h-24 md:w-32 md:h-32 -mb-2 brain-idle transition-all duration-500">
            <img src="/img/apunteicono.webp" alt="IA Nubira" class="w-full h-full object-contain select-none pointer-events-none" draggable="false">
        </div>
        
        <p class="text-sm font-bold text-gray-800 leading-none mt-2">Sube tu apunte</p>
        <p class="text-[11px] text-gray-400 mt-1">PDF, Word o Fotos</p>
    </div>
</label>

                <!-- [V3-1] Preview + barra de progreso real -->
                <div id="preview" class="mt-2 hidden bg-gray-50 rounded-xl p-3 border border-gray-100 animate-[fadeIn_0.3s]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center text-[#54A6D8] border border-gray-200 shadow-sm">
                            <?= icon('file-text', 'w-5 h-5') ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p id="previewContent" class="text-xs font-bold text-gray-900 truncate">archivo.pdf</p>
                            <p id="previewStatus" class="text-[10px] text-green-600 font-bold">Listo para procesar</p>
                        </div>
                        <button type="button" onclick="resetForm()" class="p-2 hover:bg-red-50 text-gray-400 hover:text-red-500 rounded-full transition"><?= icon('trash', 'w-4 h-4') ?></button>
                    </div>
                    <!-- Barra de progreso de subida (oculta hasta el submit) -->
                    <div id="upload-progress-wrapper" class="hidden mt-3">
                        <div class="upload-progress-track bg-gray-200">
                            <div id="upload-progress-bar" class="upload-progress-bar bg-gradient-to-r from-sky-400 to-[#54A6D8]" style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between mt-1">
                            <span id="upload-progress-text" class="text-[10px] font-bold text-gray-500">Subiendo... 0%</span>
                            <span id="upload-speed" class="text-[10px] font-medium text-gray-400"></span>
                        </div>
                    </div>
                </div>

                <input type="file" name="archivo" id="archivo" class="hidden" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
                <input type="file" id="cameraInput" class="hidden" accept="image/*" capture="environment">
                
                <div id="selector-portada-container" class="mt-4 hidden animate-[fadeIn_0.3s]">
                    <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">✨ Elige tu Portada</label>
                    <div class="flex gap-3 overflow-x-auto pb-2 scrollbar-hide" id="thumbnails-container"></div>
                    <input type="hidden" name="pagina_portada" id="pagina_portada" value="1">
                </div>
            </div>

            <div class="flex flex-col gap-5">
                <div>
                    <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Título <span class="text-red-400">*</span></label>
                    <input type="text" name="titulo" id="titulo" placeholder="Ej: Resumen Completo Anatomía" required maxlength="80" 
                           class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-[#54A6D8] block p-3.5 transition-all outline-none font-medium">
                    <p id="titulo-error" class="text-[10px] text-red-500 font-bold mt-1 hidden"></p>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase">Año</label>
                        <input type="number" name="anio" value="<?= $anio_default ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase">Semestre</label>
                        <select name="semestre" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                            <option value="1">1º Semestre</option>
                            <option value="2">2º Semestre</option>
                        </select>
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase">Precio</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold">$</span>
                            <input type="number" name="precio" id="precio" value="0" min="0" class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-6 p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                        </div>
                    </div>
                </div>

               <div class="mb-2">
    <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Asignatura</label>
    <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[#54A6D8]"><?= icon('book-open', 'w-5 h-5') ?></span>
        <input type="text" name="asignatura" id="asignatura" placeholder="Ej: Biología Celular" 
               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl pl-10 p-3.5 transition-all outline-none font-bold focus:ring-2 focus:ring-[#54A6D8]">
    </div>
</div>

<!-- [NUBIRA 2.0] Categorización (sugerido por IA, confirmado por usuario) -->
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
            Materia <span class="text-red-400">*</span>
            <span id="badge-ia-materia" class="ml-1 text-[9px] font-bold text-indigo-500 bg-indigo-50 px-1.5 py-0.5 rounded hidden">✨ IA</span>
        </label>
        <select id="select-materia" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
            <option value="">Selecciona...</option>
            <option value="calculo">Cálculo</option>
            <option value="fisica">Física</option>
            <option value="algebra">Álgebra</option>
            <option value="programacion">Programación</option>
            <option value="quimica">Química</option>
            <option value="biologia">Biología y Anatomía</option>
            <option value="contabilidad">Contabilidad y Finanzas</option>
            <option value="economia">Economía</option>
            <option value="derecho">Derecho</option>
            <option value="psicologia">Psicología y Estadística</option>
            <option value="idiomas">Idiomas</option>
            <option value="redaccion">Redacción y Tesis</option>
        </select>
    </div>
    <div>
        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
            Nivel <span class="text-red-400">*</span>
            <span id="badge-ia-nivel" class="ml-1 text-[9px] font-bold text-indigo-500 bg-indigo-50 px-1.5 py-0.5 rounded hidden">✨ IA</span>
        </label>
        <select id="select-nivel" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
            <option value="universitario">🎓 Universitario</option>
            <option value="paes">📘 PAES</option>
            <option value="escolar">📒 Escolar</option>
        </select>
    </div>
</div>

<div>
    <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
        Subtema <span class="text-gray-400 normal-case font-medium">(opcional)</span>
        <span id="badge-ia-subtema" class="ml-1 text-[9px] font-bold text-indigo-500 bg-indigo-50 px-1.5 py-0.5 rounded hidden">✨ IA</span>
    </label>
    <input type="text" id="input-subtema" maxlength="60" placeholder="Ej: Derivadas, PEP1, Examen final"
           class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl p-3.5 transition-all outline-none font-medium focus:ring-2 focus:ring-[#54A6D8]">
</div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-0">Descripción <span class="text-red-400">*</span></label>
                       <span id="badge-categoria" class="text-[10px] font-bold text-gray-400 bg-gray-100 px-2 py-1 rounded-md uppercase hidden">General</span>
                    </div>
                    
                  <!-- [NUBIRA 2.0] 3 botones de tono IA -->
<div class="flex flex-wrap gap-2 mb-2">
    <button type="button" data-tono="default" class="btn-tono ai-border-spin px-3 py-1 bg-white hover:bg-gray-50 transition-all active:scale-95 shadow-sm cursor-pointer">
        <div class="flex items-center gap-1.5">
            <?= icon('sparkles', 'w-3 h-3 text-indigo-500') ?>
            <span class="text-[10px] font-bold text-indigo-600">Redactar con IA</span>
        </div>
    </button>
   <div class="feature-host">
    <button type="button" data-tono="academico" class="btn-tono ai-border-spin px-3 py-1 bg-white hover:bg-gray-50 transition-all active:scale-95 shadow-sm cursor-pointer">
        <div class="flex items-center gap-1.5">
            <?= icon('sparkles', 'w-3 h-3 text-indigo-500') ?>
            <span class="text-[10px] font-bold text-indigo-600">Redactar con IA Académico</span>
        </div>
    </button>
    <span class="feature-badge" data-feature-key="redactar_academico" data-feature-launch="2026-05-04">Nuevo</span>
</div>
<div class="feature-host">
    <button type="button" data-tono="vendedor" class="btn-tono ai-border-spin px-3 py-1 bg-white hover:bg-gray-50 transition-all active:scale-95 shadow-sm cursor-pointer">
        <div class="flex items-center gap-1.5">
            <?= icon('sparkles', 'w-3 h-3 text-indigo-500') ?>
            <span class="text-[10px] font-bold text-indigo-600">Redactar con IA Vendedor</span>
        </div>
    </button>
    <span class="feature-badge" data-feature-key="redactar_vendedor" data-feature-launch="2026-05-04">Nuevo</span>
</div>
</div>
                    <textarea name="descripcion" id="descripcion"
                              class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-[#54A6D8] block p-4 resize-none transition-all outline-none h-32 leading-relaxed" 
                              maxlength="1500" placeholder="Sube el archivo y la IA de Nubira redactará por ti..." required></textarea>
                    <p id="descripcion-error" class="text-[10px] text-red-500 font-bold mt-1 hidden"></p>
                </div>

                <div class="pt-2">
                    <button id="btn-submit" type="submit" disabled class="w-full text-white bg-gray-300 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2 cursor-not-allowed">
                        <span id="btn-text">Completa los campos</span>
                    </button>
                </div>
            </div>
        </div>
    </form>
    
    <!-- Modal Escáner -->
    <div id="modal-escaner" class="fixed inset-0 z-[100] hidden items-end md:items-center justify-center">
        <div id="modal-escaner-bg" class="absolute inset-0 bg-gray-900/40 backdrop-blur-sm transition-opacity duration-300 opacity-0 cursor-pointer"></div>
        <div id="modal-escaner-card" class="bg-white w-full md:w-[420px] rounded-t-3xl md:rounded-3xl p-6 relative z-10 transform translate-y-full md:translate-y-10 opacity-0 transition-all duration-300 shadow-2xl flex flex-col">
            <div class="w-12 h-1.5 bg-gray-200 rounded-full mx-auto mb-6 md:hidden"></div>
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 bg-sky-50 rounded-2xl flex items-center justify-center text-[#54A6D8] border border-sky-100 shrink-0"><?= icon('sparkles', 'w-6 h-6') ?></div>
                <div>
                    <h3 class="text-xl md:text-2xl font-bold tracking-tight text-gray-900">Mejora tu escaneo</h3>
                    <p class="text-xs text-gray-500">Ayuda a la IA a leer tu apunte sin errores.</p>
                </div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-4 mb-6 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1 h-full bg-[#54A6D8]"></div>
                <p class="text-sm font-bold text-gray-900 mb-1">💡 Truco de Experto</p>
                <p class="text-xs text-gray-600 leading-relaxed">Tu celular ya tiene un escáner perfecto (App <span class="font-bold text-gray-800">Notas</span> en iPhone o <span class="font-bold text-gray-800">Drive</span> en Android). Úsalo para crear un PDF, guárdalo y luego súbelo a Nubira usando el botón <strong>"Subir"</strong>.</p>
            </div>
            <div class="flex items-center gap-4 mb-6">
                <div class="h-px bg-gray-200 flex-1"></div>
                <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">o usa la cámara rápida</span>
                <div class="h-px bg-gray-200 flex-1"></div>
            </div>
            <button type="button" onclick="lanzarCamaraNubira()" class="w-full text-gray-700 bg-white border-2 border-gray-200 hover:border-[#54A6D8] hover:text-[#54A6D8] font-bold rounded-xl text-sm px-5 py-3.5 transition-all flex items-center justify-center gap-2">
                <?= icon('camera', 'w-5 h-5') ?> Tomar foto normal (JPG)
            </button>
        </div>
    </div>
</main>

<?php 
$rutas_footer = [$app_dir . '/includes/footer.php', $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php'];
foreach ($rutas_footer as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }
?>

<script>
/**
 * NUBIRA 2.0 - UPLOAD ENGINE (AJAX + COMPRESSION + REALTIME VALIDATION)
 */

let currentFile = null;       // Archivo original seleccionado
let processedFile = null;     // Archivo después de compresión (o el mismo si no aplica)
let isUploading = false;

// --- MODALES NUBIRA ---
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
    if(!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
    const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
    btn.onclick = (e) => { e.preventDefault(); open(); }; 
    if(close) close.onclick = shut; 
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}

// --- LOADER ---
window.addEventListener('load', () => { 
    const l = document.getElementById('loader'); 
    if(l) { l.classList.add('opacity-0'); setTimeout(() => l.remove(), 500); }
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
});
window.addEventListener('DOMContentLoaded', () => {
    setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
    setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');
});

function resetForm() { window.location.reload(); }
function setStatus(msg) { const t=document.getElementById('scan-text'); if(t) t.innerText=msg; }
function triggerProgress(val) { 
    const b = document.getElementById('barra-progreso'), s = document.getElementById('calidad-score');
    if(b) b.style.width=val+'%'; if(s) s.innerText=val+'%'; 
}

function showToast(msg, success) {
    const t = document.getElementById('toast'), m = document.getElementById('toast-msg'), ic = document.getElementById('toast-icon');
    t.className = `mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm transition-all duration-300 ${success ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'}`;
    m.innerText = msg;
    ic.innerHTML = success ? '✅' : '❌';
    t.classList.remove('hidden');
    if (success) setTimeout(() => t.classList.add('hidden'), 5000);
}

// =============================================
// [V3-3] VALIDACIÓN EN TIEMPO REAL
// =============================================
const fields = {
    titulo: { el: () => document.getElementById('titulo'), required: true, min: 3, max: 80 },
    descripcion: { el: () => document.getElementById('descripcion'), required: true, min: 10 },
    archivo: { el: () => document.getElementById('archivo'), required: true, isFile: true }
};

function validateField(name) {
    const cfg = fields[name];
    if (!cfg) return true;
    const el = cfg.el();
    if (!el) return true;
    const errEl = document.getElementById(name + '-error');
    
    let val = cfg.isFile ? (processedFile || currentFile) : el.value.trim();
    let error = '';

    if (cfg.required && !val) error = 'Este campo es obligatorio';
    else if (!cfg.isFile && cfg.min && val.length < cfg.min) error = `Mínimo ${cfg.min} caracteres`;
    else if (!cfg.isFile && cfg.max && val.length > cfg.max) error = `Máximo ${cfg.max} caracteres`;

    if (error) {
        if (!cfg.isFile) { el.classList.add('field-error'); el.classList.remove('field-ok'); }
        if (errEl) { errEl.innerText = error; errEl.classList.remove('hidden'); }
        return false;
    } else {
        if (!cfg.isFile) { el.classList.remove('field-error'); el.classList.add('field-ok'); }
        if (errEl) errEl.classList.add('hidden');
        return true;
    }
}

function validateAll() {
    let valid = true;
    for (const name in fields) { if (!validateField(name)) valid = false; }
    
    const btn = document.getElementById('btn-submit');
    if (valid && !isUploading) {
        btn.disabled = false;
        btn.className = 'w-full text-white bg-[#54A6D8] hover:bg-sky-500 font-bold rounded-xl text-base px-5 py-4 shadow-sm hover:shadow-md transform hover:scale-[1.01] active:scale-[0.99] transition-all flex items-center justify-center gap-2 cursor-pointer';
        btn.querySelector('#btn-text').innerText = 'Publicar Apunte';
    } else if (!isUploading) {
        btn.disabled = true;
        btn.className = 'w-full text-white bg-gray-300 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2 cursor-not-allowed';
        btn.querySelector('#btn-text').innerText = 'Completa los campos';
    }
    return valid;
}

// Listeners de validación
['titulo', 'descripcion'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', () => { validateField(id); validateAll(); });
        el.addEventListener('blur', () => validateField(id));
    }
});

// =============================================
// [V3-2] COMPRESIÓN DE IMÁGENES EN NAVEGADOR
// =============================================
async function compressImage(file, maxWidth = 2000, quality = 0.8) {
    return new Promise((resolve) => {
        // Si es menor a 500KB, no comprimir
        if (file.size < 500 * 1024) { resolve(file); return; }
        
        const img = new Image();
        const url = URL.createObjectURL(file);
        
        img.onload = () => {
            URL.revokeObjectURL(url);
            const canvas = document.createElement('canvas');
            let w = img.width, h = img.height;
            
            if (w > maxWidth) {
                h = Math.round((h * maxWidth) / w);
                w = maxWidth;
            }
            
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, w, h);
            
            canvas.toBlob((blob) => {
                if (blob && blob.size < file.size) {
                    // Solo usar la versión comprimida si realmente es más chica
                    const compressed = new File([blob], file.name.replace(/\.\w+$/, '.webp'), { type: 'image/webp' });
                    const saved = ((file.size - compressed.size) / file.size * 100).toFixed(0);
                    document.getElementById('previewStatus').innerHTML = `<span class="text-green-600">Comprimido: -${saved}% (${(compressed.size/1024/1024).toFixed(1)}MB)</span>`;
                    resolve(compressed);
                } else {
                    resolve(file);
                }
            }, 'image/webp', quality);
        };
        
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

// =============================================
// MOTOR DE ANÁLISIS IA (igual que antes)
// =============================================
async function analizarArchivo(file, tono = 'default') {
    const isFile = file instanceof File;
    if(isFile) currentFile = file;
    window.tonoActual = tono;

    // Comprimir si es imagen
    const ext = file.name.split('.').pop().toLowerCase();
    if (['jpg','jpeg','png','webp','bmp'].includes(ext)) {
        document.getElementById('previewStatus').innerText = 'Comprimiendo imagen...';
        processedFile = await compressImage(file);
    } else {
        processedFile = file;
    }

    document.getElementById('mobile-buttons').classList.add('hidden');
    const dz = document.getElementById('drop-zone');
    if(dz) {
        dz.classList.remove('hidden', 'md:flex'); dz.classList.add('flex', 'scanning');
        document.getElementById('brain-icon').classList.replace('brain-idle','brain-thinking');
    }
    document.getElementById('preview').classList.remove('hidden');
    if(isFile) document.getElementById('previewContent').innerText = file.name;
    
    const btnText = document.getElementById('btn-ia-text');
    if(btnText) { btnText.innerText = "Analizando..."; btnText.parentElement.classList.add('animate-pulse'); }
    
    triggerProgress(10);
    setStatus("DETECTANDO FORMATO...");

    try {
    let payload = { filename: isFile ? file.name : "Archivo Manual", text: "", image: null, tono: tono };

        if (isFile) {
            if (['jpg', 'jpeg', 'png', 'webp', 'bmp'].includes(ext)) {
                setStatus("ESCANEANDO IMAGEN...");
                payload.image = await fileToBase64(file);
            } else if (ext === 'pdf') {
                setStatus("LEYENDO PDF...");
                payload.text = await extractPdfText(file);
            } else if (ext === 'docx') {
                setStatus("LEYENDO WORD...");
                payload.text = await extractDocxText(file);
            } else if (ext === 'txt') {
                payload.text = await file.text();
            }
        }

        setStatus("IA NUBIRA PENSANDO...");
        triggerProgress(60);

        const response = await fetch('/app/datos/ia_nubira.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if(!response.ok) throw new Error("Error Servidor");
        const data = await response.json();
        
document.getElementById('titulo').value = data.titulo || payload.filename.substring(0, 80);
document.getElementById('ia_keywords').value = data.keywords || "";
document.getElementById('ia_used').value = "1";
document.getElementById('ia_accepted').value = "1";
if(document.getElementById('asignatura')) document.getElementById('asignatura').value = data.asignatura || "General";

// [NUBIRA 2.0] Aplicar materia/nivel/subtema desde IA
if (data.materia) {
    document.getElementById('select-materia').value = data.materia;
    document.getElementById('materia').value = data.materia;
    document.getElementById('badge-ia-materia').classList.remove('hidden');
}
if (data.nivel_academico) {
    document.getElementById('select-nivel').value = data.nivel_academico;
    document.getElementById('nivel_academico').value = data.nivel_academico;
    document.getElementById('badge-ia-nivel').classList.remove('hidden');
}
if (data.subtema) {
    document.getElementById('input-subtema').value = data.subtema;
    document.getElementById('subtema').value = data.subtema;
    document.getElementById('badge-ia-subtema').classList.remove('hidden');
}
        
        const badge = document.getElementById('badge-categoria');
        badge.innerText = data.categoria || "General";
        badge.classList.remove('hidden');
        
        triggerProgress(85);
        setStatus("REDACTANDO...");
        typeWriter(data.descripcion || "", document.getElementById('descripcion'));

    } catch (e) {
        console.error("Error Nubira IA:", e);
        document.getElementById('titulo').value = (currentFile?.name || "").split('.')[0].substring(0, 80);
        document.getElementById('descripcion').value = "⚡ Apunte esencial para tu estudio. Organiza tu semestre con este material clave.";
        setStatus("MODO MANUAL");
        triggerProgress(0);
    } finally {
        if(dz) dz.classList.remove('scanning');
        document.getElementById('brain-icon').classList.replace('brain-thinking','brain-idle');
        if(btnText) { btnText.innerText = "Regenerar IA"; btnText.parentElement.classList.remove('animate-pulse'); }
        // Revalidar después de que la IA llenó los campos
        setTimeout(() => validateAll(), 500);
    }
}

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result.toString().replace(/^data:(.*,)?/, ''));
        reader.onerror = reject;
    });
}

async function extractPdfText(file) {
    if (typeof pdfjsLib === 'undefined') { console.warn('PDF.js aún no cargó'); return ""; }
    try {
        const ab = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument(ab).promise;
        let text = "";
        const maxPages = Math.min(pdf.numPages, 3); 
        const thumbContainer = document.getElementById('thumbnails-container');
        const selectorContainer = document.getElementById('selector-portada-container');
        thumbContainer.innerHTML = '';
        if (maxPages > 0) selectorContainer.classList.remove('hidden');

        for(let i=1; i<=maxPages; i++){
            const p = await pdf.getPage(i);
            const c = await p.getTextContent();
            text += c.items.map(s=>s.str).join(' ') + " ";
            
            const viewport = p.getViewport({scale: 0.3});
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = viewport.width; canvas.height = viewport.height;
            await p.render({canvasContext: ctx, viewport: viewport}).promise;
            
            const imgUrl = canvas.toDataURL('image/jpeg', 0.8);
            const div = document.createElement('div');
            div.className = `shrink-0 w-20 h-28 rounded-xl overflow-hidden border-2 cursor-pointer transition-all relative ${i === 1 ? 'border-[#54A6D8] shadow-md scale-105' : 'border-transparent opacity-60 hover:opacity-100'}`;
            div.innerHTML = `<img src="${imgUrl}" class="w-full h-full object-cover"><div class="absolute bottom-1 right-1 bg-black/60 backdrop-blur-sm text-white text-[10px] px-1.5 rounded font-bold">${i}</div>`;
            div.onclick = () => {
                document.querySelectorAll('#thumbnails-container > div').forEach(el => {
                    el.className = 'shrink-0 w-20 h-28 rounded-xl overflow-hidden border-2 border-transparent opacity-60 cursor-pointer transition-all hover:opacity-100 relative';
                });
                div.className = 'shrink-0 w-20 h-28 rounded-xl overflow-hidden border-2 border-[#54A6D8] shadow-md scale-105 cursor-pointer transition-all relative';
                document.getElementById('pagina_portada').value = i;
            };
            thumbContainer.appendChild(div);
        }
        return text;
    } catch(e) { console.error("Error PDF:", e); return ""; }
}

async function extractDocxText(file) {
    if(typeof mammoth === 'undefined') return "";
    try { const ab = await file.arrayBuffer(); return (await mammoth.extractRawText({arrayBuffer: ab})).value; }
    catch(e) { return ""; }
}

function typeWriter(txt, el, i=0) {
    if(i===0) el.value="";
    if(i<txt.length) {
        el.value += txt.charAt(i); el.scrollTop=el.scrollHeight;
        setTimeout(()=>typeWriter(txt,el,i+1), 10);
    } else {
        triggerProgress(100);
        setStatus("¡LISTO!");
    }
}

// =============================================
// [V3-1] SUBIDA AJAX CON PROGRESO REAL + RETRY
// =============================================
function uploadWithProgress(formData, retries = 2) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const startTime = Date.now();
        
        // Progreso real del upload
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                const bar = document.getElementById('upload-progress-bar');
                const txt = document.getElementById('upload-progress-text');
                const spd = document.getElementById('upload-speed');
                
                if (bar) bar.style.width = pct + '%';
                if (txt) txt.innerText = pct < 100 ? `Subiendo... ${pct}%` : 'Procesando en servidor...';
                
                // Calcular velocidad
                const elapsed = (Date.now() - startTime) / 1000;
                if (elapsed > 0.5 && spd) {
                    const speed = e.loaded / elapsed;
                    if (speed > 1024*1024) spd.innerText = (speed/1024/1024).toFixed(1) + ' MB/s';
                    else spd.innerText = (speed/1024).toFixed(0) + ' KB/s';
                }
            }
        });
        
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try { resolve(JSON.parse(xhr.responseText)); }
                catch(e) { reject(new Error('Respuesta inválida del servidor')); }
            } else if (xhr.status === 401) {
                reject(new Error('Sesión expirada. Recarga la página.'));
            } else {
                reject(new Error('Error del servidor: ' + xhr.status));
            }
        });
        
        xhr.addEventListener('error', () => {
            if (retries > 0) {
                console.warn(`Upload falló, reintentando... (${retries} restantes)`);
                document.getElementById('upload-progress-text').innerText = `Reconectando... (intento ${3-retries}/2)`;
                document.getElementById('upload-progress-bar').style.width = '0%';
                setTimeout(() => {
                    uploadWithProgress(formData, retries - 1).then(resolve).catch(reject);
                }, 1500);
            } else {
                reject(new Error('Error de red. Verifica tu conexión e intenta de nuevo.'));
            }
        });
        
        xhr.addEventListener('timeout', () => {
            if (retries > 0) {
                setTimeout(() => uploadWithProgress(formData, retries - 1).then(resolve).catch(reject), 1500);
            } else {
                reject(new Error('Tiempo de espera agotado.'));
            }
        });
        
        xhr.open('POST', window.location.pathname);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 120000; // 2 minutos
        xhr.send(formData);
    });
}

// =============================================
// FORM SUBMIT (AJAX)
// =============================================
document.getElementById('form-apunte').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (isUploading) return;
    if (!validateAll()) return;
    
    const fileToUpload = processedFile || currentFile;
    if (!fileToUpload) { showToast('Selecciona un archivo primero', false); return; }
    
    // Validación de tamaño frontend
    if (fileToUpload.size > 40 * 1024 * 1024) {
        showToast('El archivo supera los 40MB permitidos', false); return;
    }
    
    isUploading = true;
    const btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.className = 'w-full text-white bg-gray-400 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2 cursor-not-allowed';
    btn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> <span id="btn-text">Subiendo...</span>';
    
    // Mostrar barra de progreso
    document.getElementById('upload-progress-wrapper').classList.remove('hidden');
    
    // Construir FormData
    const fd = new FormData(this);
    // Reemplazar el archivo por el comprimido si aplica
    fd.delete('archivo');
    fd.append('archivo', fileToUpload, fileToUpload.name);
    
    try {
        const result = await uploadWithProgress(fd);
        
        if (result.error) {
            showToast(result.error, false);
            isUploading = false;
            validateAll();
            document.getElementById('upload-progress-wrapper').classList.add('hidden');
        } else if (result.success) {
            document.getElementById('upload-progress-bar').style.width = '100%';
            document.getElementById('upload-progress-text').innerText = '¡Publicado!';
            showToast(result.mensaje || '✅ Publicado correctamente', true);
            
       btn.innerHTML = '<span id="btn-text">✅ En revisión</span>';
            btn.className = 'w-full text-white bg-green-500 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2';
            
            setTimeout(() => {
                window.location.href = result.redirect || '/vitrina-apuntes';
            }, 3000);
        }
    } catch (err) {
        showToast(err.message, false);
        isUploading = false;
        validateAll();
        document.getElementById('upload-progress-wrapper').classList.add('hidden');
    }
});

// =============================================
// LISTENERS
// =============================================
const dz = document.getElementById('drop-zone');
if(dz) {
    ['dragover','dragenter'].forEach(e=>dz.addEventListener(e, ev=>{ev.preventDefault();dz.classList.add('drag-over')}));
    ['dragleave','drop'].forEach(e=>dz.addEventListener(e, ev=>{ev.preventDefault();dz.classList.remove('drag-over')}));
    dz.addEventListener('drop', e=>{ if(e.dataTransfer.files[0]) analizarArchivo(e.dataTransfer.files[0]); });
}
document.getElementById('archivo').onchange = function(){ 
    if(this.files[0]) {
        // Validación inmediata de tamaño
        if (this.files[0].size > 40 * 1024 * 1024) {
            showToast('El archivo supera los 40MB permitidos. Usa uno más liviano.', false);
            this.value = '';
            return;
        }
        analizarArchivo(this.files[0]); 
        validateAll();
    }
};
document.getElementById('cameraInput').onchange = function(){ if(this.files[0]) { analizarArchivo(this.files[0]); validateAll(); } };
// [NUBIRA 2.0] 3 botones de tono IA
document.querySelectorAll('.btn-tono').forEach(btn => {
    btn.onclick = function() {
        const tono = this.dataset.tono;
        if (currentFile) {
            analizarArchivo(currentFile, tono);
        } else {
            document.getElementById('archivo').click();
        }
    };
});

// --- UX MÓVIL: Ocultar nav al escribir ---
const navBottom = document.querySelector('.fixed.bottom-0');
const mainContent = document.querySelector('main');
if (navBottom && mainContent) {
    document.querySelectorAll('input, textarea').forEach(el => {
        el.addEventListener('focus', () => { if (window.innerWidth < 768) { navBottom.classList.add('hidden'); mainContent.style.paddingBottom = '20px'; } });
        el.addEventListener('blur', () => {
            if (window.innerWidth < 768) {
                setTimeout(() => { if (!['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) { navBottom.classList.remove('hidden'); mainContent.style.paddingBottom = ''; } }, 150);
            }
        });
    });
}

// --- MODAL ESCÁNER ---
function abrirModalEscaner() {
    const modal = document.getElementById('modal-escaner'), bg = document.getElementById('modal-escaner-bg'), card = document.getElementById('modal-escaner-card');
    modal.classList.remove('hidden'); modal.classList.add('flex');
    setTimeout(() => { bg.classList.remove('opacity-0'); bg.classList.add('opacity-100'); card.classList.remove('translate-y-full', 'md:translate-y-10', 'opacity-0'); card.classList.add('translate-y-0', 'opacity-100'); }, 10);
}
function cerrarModalEscaner() {
    const modal = document.getElementById('modal-escaner'), bg = document.getElementById('modal-escaner-bg'), card = document.getElementById('modal-escaner-card');
    bg.classList.remove('opacity-100'); bg.classList.add('opacity-0');
    card.classList.remove('translate-y-0', 'opacity-100'); card.classList.add('translate-y-full', 'md:translate-y-10', 'opacity-0');
    setTimeout(() => { modal.classList.remove('flex'); modal.classList.add('hidden'); }, 300);
}
function lanzarCamaraNubira() { cerrarModalEscaner(); setTimeout(() => document.getElementById('cameraInput').click(), 300); }
document.getElementById('modal-escaner-bg').addEventListener('click', cerrarModalEscaner);

// [NUBIRA 2.0] Sincronizar selects con hidden inputs
document.getElementById('select-materia').addEventListener('change', function() {
    document.getElementById('materia').value = this.value;
    document.getElementById('badge-ia-materia').classList.add('hidden');
});
document.getElementById('select-nivel').addEventListener('change', function() {
    document.getElementById('nivel_academico').value = this.value;
    document.getElementById('badge-ia-nivel').classList.add('hidden');
});
document.getElementById('input-subtema').addEventListener('input', function() {
    document.getElementById('subtema').value = this.value;
    document.getElementById('badge-ia-subtema').classList.add('hidden');
});
// [NUBIRA 2.0] Sistema de badges "Nuevo" reutilizable
(function inicializarFeatureBadges() {
    const DIAS_VIDA_BADGE = 14;
    const STORAGE_KEY = 'nubira_features_vistas';

    function leerVistas() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); }
        catch (e) { return {}; }
    }
    function marcarVista(key) {
        try {
            const v = leerVistas();
            v[key] = Date.now();
            localStorage.setItem(STORAGE_KEY, JSON.stringify(v));
        } catch (e) {}
    }
    function ocultarBadge(badge) {
        if (!badge || badge.classList.contains('is-hidden')) return;
        badge.classList.add('is-hidden');
        setTimeout(() => badge.remove(), 350);
    }

    const badges = document.querySelectorAll('.feature-badge[data-feature-key]');
    const vistas = leerVistas();
    const ahora = Date.now();

    badges.forEach(badge => {
        const key = badge.dataset.featureKey;
        const launch = badge.dataset.featureLaunch;

        if (vistas[key]) { ocultarBadge(badge); return; }

        if (launch) {
            const launchTime = new Date(launch + 'T00:00:00').getTime();
            const dias = (ahora - launchTime) / (1000 * 60 * 60 * 24);
            if (dias > DIAS_VIDA_BADGE) { ocultarBadge(badge); return; }
        }

        const host = badge.closest('.feature-host');
        if (!host) return;
        const trigger = host.querySelector('button, a');
        if (!trigger) return;

        trigger.addEventListener('click', () => {
            marcarVista(key);
            ocultarBadge(badge);
        }, { once: true });
    });
})();
</script>
</body>
</html>