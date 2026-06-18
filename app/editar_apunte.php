<?php
/**
 * NUBIRA 2.0 - EDIT SYSTEM (AJAX EDITION)
 * 
 * [V3-1] Edición AJAX con progreso real (XMLHttpRequest.upload)
 * [V3-2] Compresión de imagen de portada en navegador antes de subir
 * [V3-3] Validación en tiempo real de campos
 * [V3-4] Seguridad: Prepared statements, Anti-bot middleware
 * [V3-5] Consistencia UX: UI idéntica al formulario_subir_apunte.php
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

// 2. RUTAS BLINDADAS Y SEGURIDAD
$app_dir = file_exists(__DIR__ . '/init_sesion.php') ? __DIR__ : __DIR__ . '/app';
if (!file_exists($app_dir . '/conexion.php')) $app_dir = dirname(__DIR__) . '/app';

require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['usuario_id'])) {
    if ($is_ajax) {
        http_response_code(401);
        echo json_encode(['error' => 'No autenticado']);
        exit;
    }
    header("Location: /login");
    exit;
}

// =========================================================================
// 🛡️ [NUBIRA SHIELD] MIDDLEWARE ANTI-BOT
// =========================================================================
if (isset($conn)) {
    $antibot_path = $app_dir . '/middleware/antibot.php';
    if (file_exists($antibot_path)) {
        require_once $antibot_path;
        if (function_exists('check_nubira_shield')) check_nubira_shield($conn);
    }
}

$usuario_id = (int)$_SESSION['usuario_id'];
$rol        = $_SESSION['rol'] ?? 'alumno';
$es_admin   = ($rol === 'admin');
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';

// 3. OBTENER ID APUNTE
$id_apunte = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id_apunte <= 0 && !$is_ajax) { header("Location: /dashboard"); exit; }

// 4. CARGAR DATOS DEL APUNTE
$stmt = $conn->prepare("SELECT * FROM apuntes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_apunte);
$stmt->execute();
$apunte = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$apunte) { 
    if ($is_ajax) { echo json_encode(['error' => 'Apunte no encontrado.']); exit; }
    die("Apunte no encontrado."); 
}

if (!$es_admin && (int)$apunte['id_alumno'] !== $usuario_id) {
    if ($is_ajax) { echo json_encode(['error' => 'No tienes permiso.']); exit; }
    die("No tienes permiso para editar este apunte.");
}

// =============================================
// MODO AJAX: Procesar Actualización
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax) {
    header('Content-Type: application/json');
    
    try {
        $titulo      = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $asignatura  = trim($_POST['asignatura'] ?? 'General');
        $anio        = (int)($_POST['anio'] ?? date('Y'));
        $semestre    = (int)($_POST['semestre'] ?? 1);
        $precio      = (float)($_POST['precio'] ?? 0);
        
        $materia_raw         = trim($_POST['materia'] ?? '');
        $nivel_academico_raw = trim($_POST['nivel_academico'] ?? 'universitario');
        $subtema             = mb_substr(trim($_POST['subtema'] ?? ''), 0, 80);
        
        $materias_validas = ['calculo','fisica','algebra','programacion','quimica','biologia','contabilidad','economia','derecho','psicologia','idiomas','redaccion'];
        $niveles_validos  = ['universitario','paes','escolar'];
        
        $materia         = in_array($materia_raw, $materias_validas, true) ? $materia_raw : null;
        $nivel_academico = in_array($nivel_academico_raw, $niveles_validos, true) ? $nivel_academico_raw : 'universitario';
        if ($subtema === '') $subtema = null;

        if (empty($titulo)) { echo json_encode(['error' => 'El título es obligatorio']); exit; }
        if (empty($descripcion) || mb_strlen($descripcion) < 50) { echo json_encode(['error' => 'Descripción muy corta (mínimo 50 caracteres)']); exit; }
        
        // Anti-Contacto
        $patrones = ['/\b\d{8,}\b/i', '/@/i', '/(gmail|hotmail|yahoo|outlook|uc\.cl|aiep\.cl)/i', '/(https?:\/\/|www\.)/i', '/(whatsapp|wsp|wa\.me)/i'];
        foreach ($patrones as $p) { 
            if (preg_match($p, $titulo) || preg_match($p, $descripcion)) {
                echo json_encode(['error' => '🚫 No incluyas teléfonos ni correos por seguridad.']); exit;
            }
        }

        $nombreArchivo = $apunte['portada'];

        // Procesar nueva portada si se subió
        if (isset($_FILES['portada']) && $_FILES['portada']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['portada']['size'] > 10 * 1024 * 1024) { echo json_encode(['error' => 'Imagen muy pesada (Máx 10MB)']); exit; }
            
            $file_tmp = $_FILES['portada']['tmp_name'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $file_type = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);

            $exts = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];

            if (array_key_exists($file_type, $exts)) {
                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/upload/portadas/';
                if (!file_exists($upload_dir)) @mkdir($upload_dir, 0755, true);

                $ext_final = extension_loaded('gd') ? '.webp' : $exts[$file_type];
                $file_name = uniqid('apt_') . $ext_final;
                $upload_path = $upload_dir . $file_name;

                if (extension_loaded('gd')) {
                    $img = null;
                    if ($file_type === 'image/jpeg') $img = @imagecreatefromjpeg($file_tmp);
                    elseif ($file_type === 'image/png') $img = @imagecreatefrompng($file_tmp);
                    elseif ($file_type === 'image/webp') $img = @imagecreatefromwebp($file_tmp);

                    if ($img) {
                        $w = imagesx($img); $h = imagesy($img);
                        if ($w > 1200) {
                            $new_w = 1200; $new_h = ($h / $w) * 1200;
                            $resized = imagescale($img, $new_w, $new_h);
                            imagewebp($resized, $upload_path, 80);
                            imagedestroy($resized);
                        } else {
                            imagewebp($img, $upload_path, 80);
                        }
                        imagedestroy($img);
                        $nombreArchivo = $file_name;
                    }
                } else {
                    move_uploaded_file($file_tmp, $upload_path);
                    $nombreArchivo = $file_name;
                }
            } else {
                echo json_encode(['error' => 'Formato de imagen no permitido.']); exit;
            }
        }

        $sql = "UPDATE apuntes SET 
                titulo=?, descripcion=?, asignatura=?, anio=?, semestre=?, 
                precio=?, portada=?, materia=?, nivel_academico=?, subtema=?, 
                estado='pendiente' 
                WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiidssssi", $titulo, $descripcion, $asignatura, $anio, $semestre, $precio, $nombreArchivo, $materia, $nivel_academico, $subtema, $id_apunte);
        
        if ($stmt->execute()) {
            
            // Logger
            if (file_exists($app_dir . '/logger.php')) {
                require_once $app_dir . '/logger.php';
                registrar_actividad($conn, $usuario_id, 'EDITAR_APUNTE', "ID: $id_apunte | $titulo");
            }

            $hash_link = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($id_apunte) : $id_apunte;
            echo json_encode([
                'success' => true, 
                'mensaje' => '✅ Cambios guardados y en revisión.',
                'redirect' => '/apunte/' . $hash_link
            ]);
        } else {
            echo json_encode(['error' => 'Error al guardar en base de datos.']);
        }
        $stmt->close();
        exit;

    } catch (Throwable $e) {
        error_log("Nubira Edit Error: " . $e->getMessage());
        echo json_encode(['error' => 'Error inesperado del servidor']);
        exit;
    }
}

// =============================================
// MODO NORMAL: Renderizar HTML
// =============================================

// Datos bancarios
$tiene_datos = false;
try {
    $stmt = $conn->prepare("SELECT id FROM datos_pago_usuario WHERE usuario_id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $stmt->store_result();
        $tiene_datos = $stmt->num_rows > 0;
        $stmt->close();
    }
} catch (Exception $e) {}

$portada_actual = $apunte['portada'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Apunte | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover" />
    <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfAZ9hjQ.woff2" as="font" type="font/woff2" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .drag-over { border-color: #54A6D8 !important; background-color: rgba(84, 166, 216, 0.05) !important; transform: scale(1.01); }
        
        /* --- ANIMACIONES CEREBRO Y ESCÁNER --- */
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

        /* --- EFECTO GEMINI RING --- */
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
            position: absolute; top: -6px; right: -10px;
            background: linear-gradient(135deg, #54A6D8 0%, #4092c4 100%);
            color: white; font-size: 8px; font-weight: 700; letter-spacing: 0.3px;
            padding: 2px 6px; border-radius: 999px; box-shadow: 0 2px 6px rgba(84, 166, 216, 0.45);
            border: 1.5px solid white; line-height: 1; text-transform: uppercase;
            pointer-events: none; white-space: nowrap; transition: opacity 0.3s ease, transform 0.3s ease; z-index: 5;
        }
        .feature-badge.is-hidden { opacity: 0; transform: scale(0.5); }
        .feature-host { position: relative; }
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

<main class="pt-16 md:pt-20 pb-20 md:pb-8 lg:ml-64 px-4 max-w-[1100px] w-full mx-auto md:px-8 min-h-[calc(100vh-80px)] flex flex-col transition-all duration-300">

    <div class="mb-4 flex flex-col md:flex-row md:items-end justify-between gap-3">
        <div>
            <span class="inline-block py-1 px-3 rounded-full bg-blue-50 text-blue-600 text-[10px] md:text-xs font-bold mb-2 border border-blue-100">
                ✏️ Modo Edición
            </span>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight leading-tight">Editar Apunte</h1>
            <p class="text-gray-500 text-xs md:text-sm mt-0.5">Actualiza la información de tu material.</p>
        </div>
        
        <?php $hash_link = function_exists('nubira_encriptar_id') ? nubira_encriptar_id($id_apunte) : $id_apunte; ?>
        <a href="/apunte/<?= $hash_link ?>" target="_blank" class="text-sm font-bold text-[#54A6D8] hover:underline flex items-center gap-1 bg-white px-3 py-1.5 rounded-full border border-gray-100 shadow-sm hidden md:flex">
            <?= icon('eye', 'w-4 h-4') ?> Ver actual
        </a>
    </div>

    <div class="flex flex-col">
        
        <?php if (!$tiene_datos && (float)$apunte['precio'] > 0): ?>
        <div class="mb-5 bg-orange-50 border border-orange-100 rounded-xl p-4 flex items-center gap-3 shadow-sm">
            <div class="text-orange-500 flex-shrink-0"><?= icon('triangle-exclamation', 'w-5 h-5') ?></div> 
            <div class="flex-1">
                <p class="text-sm text-orange-900 font-bold">Faltan tus datos de pago</p>
                <p class="text-xs text-gray-600">Necesarios para que los alumnos te transfieran.</p>
            </div>
            <a href="/editar-datos-bancarios" class="text-xs font-bold text-orange-600 hover:text-orange-800 underline whitespace-nowrap">Configurar ahora</a>
        </div>
        <?php endif; ?>

        <!-- [V3-1] Toast dinámico para respuestas AJAX -->
        <div id="toast" class="mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm hidden transition-all duration-300">
            <span id="toast-icon"></span>
            <span id="toast-msg" class="text-sm font-bold flex-1"></span>
            <button type="button" onclick="document.getElementById('toast').classList.add('hidden')" class="text-sm opacity-60 hover:opacity-100">✕</button>
        </div>

        <div class="mb-6 bg-white border border-gray-100 rounded-xl px-4 py-3 shadow-sm sticky top-16 md:top-20 z-20 flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <?= icon('sparkles', 'w-4 h-4 text-[#54A6D8]') ?>
                <span class="text-[10px] md:text-xs font-bold text-gray-700 uppercase tracking-widest">Calidad</span>
            </div>
            <div class="flex items-center gap-3 flex-1 max-w-xs justify-end">
                <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden hidden md:block">
                    <div id="barra-progreso" class="h-1.5 rounded-full transition-all duration-700 ease-out w-0 bg-gradient-to-r from-sky-400 to-[#54A6D8]"></div>
                </div>
                <span id="calidad-score" class="font-bold text-xs text-[#54A6D8] min-w-[24px] text-right">0%</span>
            </div>
        </div>

        <form id="form-apunte" enctype="multipart/form-data" class="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 shadow-sm flex-grow mb-6">
            <input type="hidden" name="id" value="<?= $id_apunte ?>">
            <input type="hidden" name="materia" id="materia" value="<?= htmlspecialchars($apunte['materia'] ?? '') ?>">
            <input type="hidden" name="nivel_academico" id="nivel_academico" value="<?= htmlspecialchars($apunte['nivel_academico'] ?? 'universitario') ?>">
            <input type="hidden" name="subtema" id="subtema" value="<?= htmlspecialchars($apunte['subtema'] ?? '') ?>">

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                
                <!-- Columna Izquierda: Portada y Archivo -->
                <div class="flex flex-col gap-4">
                    <label class="block text-xs font-bold text-gray-900 mb-0 uppercase tracking-wide">Foto de portada</label>
                    
                    <label for="portada" id="drop-zone" class="relative overflow-hidden transform-gpu border-2 border-dashed border-gray-200 rounded-2xl h-48 md:h-64 flex flex-col justify-center items-center text-center cursor-pointer transition-all duration-300 bg-gray-50/50 hover:bg-white hover:border-[#54A6D8] group z-0">
                        
                        <?php if(!empty($portada_actual)): ?>
                            <img id="previewImgActual" src="/upload/portadas/<?= htmlspecialchars($portada_actual) ?>?t=<?= time() ?>" class="absolute inset-0 w-full h-full object-cover z-0 opacity-40 group-hover:opacity-20 transition-opacity">
                        <?php endif; ?>

                        <input type="file" name="portada" id="portada" class="hidden" accept="image/jpeg,image/png,image/webp">
                        
                        <div class="relative z-10 flex flex-col items-center justify-center pointer-events-none p-4">
                            <div id="brain-icon" class="w-24 h-24 md:w-32 md:h-32 -mb-2 brain-idle transition-all duration-500">
                                <img src="/img/apunteicono.webp" alt="IA Nubira" class="w-full h-full object-contain select-none pointer-events-none" draggable="false">
                            </div>
                            <div class="group-hover:-translate-y-1 transition-transform bg-white/80 backdrop-blur-sm px-4 py-1.5 rounded-xl border border-gray-100 shadow-sm mt-2">
                                <p class="text-sm font-bold text-gray-800">Cambiar Portada</p>
                                <p class="text-[10px] text-gray-500 mt-0.5 uppercase tracking-widest">JPG, PNG o WebP</p>
                            </div>
                        </div>
                    </label>

                    <!-- Preview nueva portada + progreso real -->
                    <div id="preview" class="mt-2 hidden bg-gray-50 rounded-xl p-3 border border-gray-100 animate-[fadeIn_0.3s]">
                        <div class="flex items-center gap-3">
                            <img id="previewImg" src="" class="w-10 h-10 object-cover rounded-lg border border-gray-200 shadow-sm">
                            <div class="flex-1 min-w-0">
                                <p id="previewContent" class="text-xs font-bold text-gray-900 truncate">Nueva portada seleccionada</p>
                                <p id="previewStatus" class="text-[10px] text-green-600 font-bold">Lista para guardar</p>
                            </div>
                            <button type="button" onclick="document.getElementById('portada').value=''; document.getElementById('preview').classList.add('hidden'); document.getElementById('previewImgActual')?.classList.remove('opacity-20');" class="p-2 hover:bg-red-50 text-gray-400 hover:text-red-500 rounded-full transition"><?= icon('trash', 'w-4 h-4') ?></button>
                        </div>
                        
                        <div id="upload-progress-wrapper" class="hidden mt-3">
                            <div class="upload-progress-track bg-gray-200">
                                <div id="upload-progress-bar" class="upload-progress-bar bg-gradient-to-r from-sky-400 to-[#54A6D8]" style="width: 0%"></div>
                            </div>
                            <div class="flex justify-between mt-1">
                                <span id="upload-progress-text" class="text-[10px] font-bold text-gray-500">Guardando... 0%</span>
                                <span id="upload-speed" class="text-[10px] font-medium text-gray-400"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha: Metadatos -->
                <div class="flex flex-col gap-5">
                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Título <span class="text-red-400">*</span></label>
                        <input type="text" name="titulo" id="titulo" placeholder="Ej: Resumen Completo Anatomía" required maxlength="80" value="<?= htmlspecialchars($apunte['titulo']) ?>"
                               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-[#54A6D8] block p-3.5 transition-all outline-none font-medium">
                        <p id="titulo-error" class="text-[10px] text-red-500 font-bold mt-1 hidden"></p>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-2 uppercase">Año</label>
                            <input type="number" name="anio" value="<?= (int)$apunte['anio'] ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-2 uppercase">Semestre</label>
                            <select name="semestre" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                                <option value="1" <?= (int)$apunte['semestre'] === 1 ? 'selected' : '' ?>>1º Semestre</option>
                                <option value="2" <?= (int)$apunte['semestre'] === 2 ? 'selected' : '' ?>>2º Semestre</option>
                            </select>
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-900 mb-2 uppercase">Precio</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold">$</span>
                                <input type="number" name="precio" id="precio" value="<?= (float)$apunte['precio'] ?>" min="0" step="500" class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-6 p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Asignatura</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[#54A6D8]"><?= icon('book-open', 'w-5 h-5') ?></span>
                            <input type="text" name="asignatura" id="asignatura" placeholder="Ej: Biología Celular" value="<?= htmlspecialchars($apunte['asignatura'] ?? '') ?>"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl pl-10 p-3.5 transition-all outline-none font-bold focus:ring-2 focus:ring-[#54A6D8]">
                        </div>
                    </div>

                    <!-- [NUBIRA 2.0] Categorización -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
                                Materia <span class="text-red-400">*</span>
                            </label>
                            <select id="select-materia" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                                <option value="">Selecciona...</option>
                                <?php
                                $materias = ['calculo'=>'Cálculo', 'fisica'=>'Física', 'algebra'=>'Álgebra', 'programacion'=>'Programación', 'quimica'=>'Química', 'biologia'=>'Biología y Anatomía', 'contabilidad'=>'Contabilidad y Finanzas', 'economia'=>'Economía', 'derecho'=>'Derecho', 'psicologia'=>'Psicología y Estadística', 'idiomas'=>'Idiomas', 'redaccion'=>'Redacción y Tesis'];
                                foreach ($materias as $k => $v) {
                                    $sel = ($apunte['materia'] === $k) ? 'selected' : '';
                                    echo "<option value='$k' $sel>$v</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
                                Nivel <span class="text-red-400">*</span>
                            </label>
                            <select id="select-nivel" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3.5 text-sm font-bold outline-none focus:ring-2 focus:ring-[#54A6D8]">
                                <option value="universitario" <?= ($apunte['nivel_academico'] === 'universitario') ? 'selected' : '' ?>>🎓 Universitario</option>
                                <option value="paes" <?= ($apunte['nivel_academico'] === 'paes') ? 'selected' : '' ?>>📘 PAES</option>
                                <option value="escolar" <?= ($apunte['nivel_academico'] === 'escolar') ? 'selected' : '' ?>>📒 Escolar</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">
                            Subtema <span class="text-gray-400 normal-case font-medium">(opcional)</span>
                        </label>
                        <input type="text" id="input-subtema" maxlength="60" placeholder="Ej: Derivadas, PEP1, Examen final" value="<?= htmlspecialchars($apunte['subtema'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl p-3.5 transition-all outline-none font-medium focus:ring-2 focus:ring-[#54A6D8]">
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide mb-0">Descripción <span class="text-red-400">*</span></label>
                        </div>
                        
                        <!-- [NUBIRA 2.0] 3 botones de tono IA -->
                        <div class="flex flex-wrap gap-2 mb-3">
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
                                <span class="feature-badge" data-feature-key="redactar_academico_edit" data-feature-launch="2026-05-04">Nuevo</span>
                            </div>
                            <div class="feature-host">
                                <button type="button" data-tono="vendedor" class="btn-tono ai-border-spin px-3 py-1 bg-white hover:bg-gray-50 transition-all active:scale-95 shadow-sm cursor-pointer">
                                    <div class="flex items-center gap-1.5">
                                        <?= icon('sparkles', 'w-3 h-3 text-indigo-500') ?>
                                        <span class="text-[10px] font-bold text-indigo-600">Redactar con IA Vendedor</span>
                                    </div>
                                </button>
                                <span class="feature-badge" data-feature-key="redactar_vendedor_edit" data-feature-launch="2026-05-04">Nuevo</span>
                            </div>
                        </div>
                        
                        <textarea name="descripcion" id="descripcion" 
                                  class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-[#54A6D8] block p-4 resize-none transition-all outline-none h-32 leading-relaxed" 
                                  maxlength="1500" required><?= htmlspecialchars($apunte['descripcion']) ?></textarea>
                        
                        <div class="flex justify-between mt-2">
                            <div id="security-warning" class="hidden text-[10px] text-red-500 font-bold items-center gap-1 bg-red-50 px-2 py-1 rounded-lg">
                                <?= icon('triangle-exclamation', 'w-3 h-3') ?> No incluyas teléfonos ni correos.
                            </div>
                            <div class="text-xs text-gray-400 ml-auto"><span id="descripcion-count">0</span>/1500</div>
                        </div>
                    </div>

                    <div class="pt-2">
                        <button id="btn-submit" type="submit" class="w-full text-white bg-[#54A6D8] hover:bg-sky-500 font-bold rounded-xl text-base px-5 py-4 shadow-sm hover:shadow-md transform hover:scale-[1.01] active:scale-[0.99] transition-all flex items-center justify-center gap-2">
                            <span id="btn-text">Guardar Cambios</span>
                            <?= icon('arrow-right', 'w-4 h-4') ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</main>

<?php 
$rutas_footer = [$app_dir . '/includes/footer.php', $_SERVER['DOCUMENT_ROOT'] . '/app/includes/footer.php'];
foreach ($rutas_footer as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }
?>

<script>
/**
 * NUBIRA 2.0 - EDIT ENGINE (AJAX + COMPRESSION + REALTIME VALIDATION + LOCAL AI)
 */

const USER_CONTEXT = { nombre: "<?= htmlspecialchars($nombre_usuario) ?>" };
let currentFile = null;
let processedFile = null;
let isUploading = false;

// --- LOADER & MODALS ---
window.addEventListener('load', () => { 
    const l = document.getElementById('loader'); 
    if(l) { l.classList.add('opacity-0'); setTimeout(() => l.remove(), 500); } 
});
function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
    if(!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
    const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
    btn.onclick = (e) => { e.preventDefault(); open(); }; 
    if(close) close.onclick = shut; 
    modal.onclick = (e) => { if(e.target === modal) shut(); };
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

function showToast(msg, success) {
    const t = document.getElementById('toast'), m = document.getElementById('toast-msg'), ic = document.getElementById('toast-icon');
    t.className = `mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm transition-all duration-300 ${success ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'}`;
    m.innerText = msg; ic.innerHTML = success ? '✅' : '❌';
    t.classList.remove('hidden');
    if (success) setTimeout(() => t.classList.add('hidden'), 5000);
}

// =============================================
// [V3-3] VALIDACIÓN EN TIEMPO REAL & CALIDAD
// =============================================
const fields = {
    titulo: { el: () => document.getElementById('titulo'), required: true, min: 3, max: 80 },
    descripcion: { el: () => document.getElementById('descripcion'), required: true, min: 50 },
    materia: { el: () => document.getElementById('select-materia'), required: true }
};

function validateField(name) {
    const cfg = fields[name];
    if (!cfg) return true;
    const el = cfg.el();
    if (!el) return true;
    const errEl = document.getElementById(name + '-error');
    
    let val = el.value.trim();
    let error = '';

    if (cfg.required && !val) error = 'Este campo es obligatorio';
    else if (cfg.min && val.length < cfg.min) error = `Mínimo ${cfg.min} caracteres`;
    else if (cfg.max && val.length > cfg.max) error = `Máximo ${cfg.max} caracteres`;

    if (error) {
        el.classList.add('field-error'); el.classList.remove('field-ok');
        if (errEl) { errEl.innerText = error; errEl.classList.remove('hidden'); }
        return false;
    } else {
        el.classList.remove('field-error'); el.classList.add('field-ok');
        if (errEl) errEl.classList.add('hidden');
        return true;
    }
}

function calcQuality() {
    let score = 0;
    if (document.getElementById('titulo')?.value.length >= 10) score += 20;
    if (document.getElementById('descripcion')?.value.length >= 50) score += 20;
    const tienePortada = currentFile !== null || <?= !empty($portada_actual) ? 'true' : 'false' ?>;
    if (tienePortada) score += 25;
    if (document.getElementById('precio')?.value !== '') score += 15;
    if (document.getElementById('select-materia')?.value !== '') score += 10;
    if (document.getElementById('select-nivel')?.value !== '') score += 10;
    
    const b = document.getElementById('barra-progreso'), s = document.getElementById('calidad-score');
    if(b) b.style.width = `${score}%`; if(s) s.innerText = `${score}%`;
}

function validateAll() {
    let valid = true;
    for (const name in fields) { if (!validateField(name)) valid = false; }
    calcQuality();
    
    const btn = document.getElementById('btn-submit');
    const isBad = [/\d{8,}/, /@/, /\.cl/].some(p => p.test(document.getElementById('descripcion').value));
    const warning = document.getElementById('security-warning');
    
    if (isBad) {
        valid = false;
        warning.classList.remove('hidden'); warning.classList.add('flex');
    } else {
        warning.classList.add('hidden'); warning.classList.remove('flex');
    }

    if (valid && !isUploading) {
        btn.disabled = false;
        btn.className = 'w-full text-white bg-[#54A6D8] hover:bg-sky-500 font-bold rounded-xl text-base px-5 py-4 shadow-sm hover:shadow-md transform hover:scale-[1.01] active:scale-[0.99] transition-all flex items-center justify-center gap-2 cursor-pointer';
        btn.querySelector('#btn-text').innerText = 'Guardar Cambios';
    } else if (!isUploading) {
        btn.disabled = true;
        btn.className = 'w-full text-white bg-gray-300 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2 cursor-not-allowed';
        btn.querySelector('#btn-text').innerText = isBad ? 'Corrije las advertencias' : 'Completa los campos';
    }
    return valid;
}

// Listener unificado para todos los campos (incluye contador de descripción)
['titulo', 'descripcion', 'precio', 'asignatura', 'select-materia', 'select-nivel', 'input-subtema'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => { 
        validateField(id); 
        validateAll(); 
        if (id === 'descripcion') {
            document.getElementById('descripcion-count').textContent = el.value.length;
        }
    });
});

// =============================================
// [V3-2] COMPRESIÓN DE IMÁGENES (PORTADA)
// =============================================
async function compressImage(file, maxWidth = 1200, quality = 0.8) {
    return new Promise((resolve) => {
        if (file.size < 300 * 1024) { resolve(file); return; }
        const img = new Image(); const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            const canvas = document.createElement('canvas');
            let w = img.width, h = img.height;
            if (w > maxWidth) { h = Math.round((h * maxWidth) / w); w = maxWidth; }
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0, w, h);
            canvas.toBlob((blob) => {
                if (blob && blob.size < file.size) {
                    const compressed = new File([blob], file.name.replace(/\.\w+$/, '.webp'), { type: 'image/webp' });
                    const saved = ((file.size - compressed.size) / file.size * 100).toFixed(0);
                    document.getElementById('previewStatus').innerHTML = `<span class="text-green-600">Optimizada: -${saved}%</span>`;
                    resolve(compressed);
                } else { resolve(file); }
            }, 'image/webp', quality);
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

document.getElementById('portada').addEventListener('change', async function() {
    if (this.files && this.files.length > 0) { 
        currentFile = this.files[0];
        document.getElementById('previewStatus').innerText = 'Optimizando...';
        document.getElementById('previewImg').src = URL.createObjectURL(currentFile); 
        document.getElementById('preview').classList.remove('hidden'); 
        
        const act = document.getElementById('previewImgActual');
        if(act) act.classList.add('opacity-20');

        processedFile = await compressImage(currentFile);
        validateAll();
    }
});

// =============================================
// IA NUBIRA LOCAL (Específico para Edición sin archivo nuevo)
// =============================================
const SERVICE_BRAIN = <?php 
    $archivo_brain = $app_dir . '/datos/service_brain.php';
    echo file_exists($archivo_brain) ? json_encode(require $archivo_brain, JSON_UNESCAPED_UNICODE) : "{}";
?>;

const NubiraAI = {
    typingTimer: null,
    detectArchetype: (text) => {
        const t = text.toLowerCase(); const keywords = SERVICE_BRAIN.keywords || {};
        for (const [arq, words] of Object.entries(keywords)) { if (words.some(w => t.includes(w))) return arq; }
        return 'General';
    },
    getRandom: (arr) => (!arr || arr.length === 0) ? "" : arr[Math.floor(Math.random() * arr.length)],
    replacer: (txt, tema, asignatura, nombre) => (txt || "").replace(/{TEMA}/g, tema).replace(/{ASIGNATURA}/g, asignatura || 'esta materia').replace(/{NOMBRE}/g, nombre).replace(/{SALUDO}/g, "¡Hola!"),
    typeWriter: (text, element, index = 0) => {
        if (index === 0) { element.value = ""; clearTimeout(NubiraAI.typingTimer); element.style.height = 'auto'; }
        if (index < text.length) {
            element.value += text.charAt(index); element.style.height = (element.scrollHeight) + 'px'; 
            NubiraAI.typingTimer = setTimeout(() => NubiraAI.typeWriter(text, element, index + 1), 10);
        } else {
            document.querySelectorAll('.btn-tono').forEach(b => b.classList.remove('opacity-50', 'pointer-events-none'));
            element.dispatchEvent(new Event('input')); 
        }
    },
    generate: (tono = 'default') => {
        const rawTitle = document.getElementById('titulo').value.trim();
        const rawAsig = document.getElementById('asignatura').value.trim();
        if (rawTitle.length < 4) { alert("⚠️ Escribe un Título primero para que la IA tenga contexto."); document.getElementById('titulo').focus(); return; }
        document.querySelectorAll('.btn-tono').forEach(b => b.classList.add('opacity-50', 'pointer-events-none'));

        const arquetipo = NubiraAI.detectArchetype(rawTitle + " " + rawAsig);
        const brain = SERVICE_BRAIN[arquetipo] || SERVICE_BRAIN['General'];
        const tema = rawTitle; const asignatura = rawAsig; const nombre = USER_CONTEXT.nombre.split(' ')[0];

        if(!brain || !brain.hooks) { 
            let fallback = `📚 Apunte de ${asignatura || 'la materia'}: "${tema}".\n\n⚡ Contiene información precisa para repasar rápido y preparar evaluaciones sin estrés.`;
            if (tono === 'vendedor') fallback = `🔥 LO ÚNICO que necesitas para aprobar ${asignatura || 'esta materia'}: "${tema}".\n\n⚡ Descárgalo AHORA.`;
            else if (tono === 'academico') fallback = `Documento de estudio enfocado en ${asignatura || 'la materia'}: "${tema}". Material estructurado para consolidar contenidos.`;
            setTimeout(() => { NubiraAI.typeWriter(fallback, document.getElementById('descripcion')); }, 400);
            return;
        }

        let hookPool = brain.hooks, bodyPool = brain.solutions || brain.problems, ctaPool = brain.cta;
        if (tono === 'academico' && brain.hooks_academico) { hookPool = brain.hooks_academico; bodyPool = brain.solutions_academico || bodyPool; ctaPool = brain.cta_academico || ctaPool; }
        else if (tono === 'vendedor' && brain.hooks_vendedor) { hookPool = brain.hooks_vendedor; bodyPool = brain.solutions_vendedor || bodyPool; ctaPool = brain.cta_vendedor || ctaPool; }

        const hook = NubiraAI.replacer(NubiraAI.getRandom(hookPool), tema, asignatura, nombre);
        const body = NubiraAI.replacer(NubiraAI.getRandom(bodyPool), tema, asignatura, nombre);
        const cta  = NubiraAI.replacer(NubiraAI.getRandom(ctaPool), tema, asignatura, nombre);

        setTimeout(() => { NubiraAI.typeWriter(`${hook}\n\n${body}\n\n${cta}`, document.getElementById('descripcion')); }, 400);
    }
};

document.querySelectorAll('.btn-tono').forEach(btn => {
    btn.onclick = function() { NubiraAI.generate(this.dataset.tono || 'default'); };
});

// Sincronizar hidden inputs con selects/inputs visibles
document.getElementById('select-materia').addEventListener('change', function() { document.getElementById('materia').value = this.value; });
document.getElementById('select-nivel').addEventListener('change', function() { document.getElementById('nivel_academico').value = this.value; });
document.getElementById('input-subtema').addEventListener('input', function() { document.getElementById('subtema').value = this.value; });

// =============================================
// [V3-1] SUBMISSION AJAX CON PROGRESO
// =============================================
function uploadWithProgress(formData) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const startTime = Date.now();
        
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                const bar = document.getElementById('upload-progress-bar'), txt = document.getElementById('upload-progress-text'), spd = document.getElementById('upload-speed');
                if (bar) bar.style.width = pct + '%';
                if (txt) txt.innerText = pct < 100 ? `Guardando... ${pct}%` : 'Procesando en servidor...';
                
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
            } else if (xhr.status === 401) { reject(new Error('Sesión expirada. Recarga la página.')); } 
            else { reject(new Error('Error del servidor: ' + xhr.status)); }
        });
        
        xhr.addEventListener('error', () => reject(new Error('Error de red. Verifica tu conexión.')));
        xhr.open('POST', window.location.pathname);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(formData);
    });
}

document.getElementById('form-apunte').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (isUploading || !validateAll()) return;
    
    isUploading = true;
    const btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.className = 'w-full text-white bg-gray-400 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2 cursor-not-allowed';
    btn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> <span id="btn-text">Guardando...</span>';
    
    document.getElementById('upload-progress-wrapper').classList.remove('hidden');
    
    const fd = new FormData(this);
    if (processedFile) {
        fd.delete('portada');
        fd.append('portada', processedFile, processedFile.name);
    }
    
    try {
        const result = await uploadWithProgress(fd);
        
        if (result.error) {
            showToast(result.error, false);
            isUploading = false; validateAll();
            document.getElementById('upload-progress-wrapper').classList.add('hidden');
        } else if (result.success) {
            document.getElementById('upload-progress-bar').style.width = '100%';
            document.getElementById('upload-progress-text').innerText = '¡Guardado!';
            showToast(result.mensaje, true);
            
            btn.innerHTML = '<span id="btn-text">✅ Cambios Guardados</span>';
            btn.className = 'w-full text-white bg-green-500 font-bold rounded-xl text-base px-5 py-4 shadow-sm transition-all flex items-center justify-center gap-2';
            
            setTimeout(() => { window.location.href = result.redirect || '/dashboard'; }, 2000);
        }
    } catch (err) {
        showToast(err.message, false);
        isUploading = false; validateAll();
        document.getElementById('upload-progress-wrapper').classList.add('hidden');
    }
});

// Inicialización de Badges "Nuevo"
(function inicializarFeatureBadges() {
    const DIAS_VIDA_BADGE = 14; const STORAGE_KEY = 'nubira_features_vistas';
    function leerVistas() { try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { return {}; } }
    function marcarVista(key) { try { const v = leerVistas(); v[key] = Date.now(); localStorage.setItem(STORAGE_KEY, JSON.stringify(v)); } catch (e) {} }
    const badges = document.querySelectorAll('.feature-badge[data-feature-key]');
    const vistas = leerVistas(); const ahora = Date.now();
    badges.forEach(badge => {
        const key = badge.dataset.featureKey, launch = badge.dataset.featureLaunch;
        if (vistas[key]) { badge.classList.add('is-hidden'); return; }
        if (launch) { if ((ahora - new Date(launch + 'T00:00:00').getTime()) / (1000 * 60 * 60 * 24) > DIAS_VIDA_BADGE) { badge.classList.add('is-hidden'); return; } }
        const trigger = badge.closest('.feature-host')?.querySelector('button, a');
        if (trigger) trigger.addEventListener('click', () => { marcarVista(key); badge.classList.add('is-hidden'); }, { once: true });
    });
})();

// =============================================
// INIT — Sincronización inicial y validación
// =============================================
// Forzar sincronización inicial de hidden inputs con selects (evita desfase)
document.getElementById('materia').value         = document.getElementById('select-materia').value;
document.getElementById('nivel_academico').value = document.getElementById('select-nivel').value;
document.getElementById('subtema').value         = document.getElementById('input-subtema').value;

// Contador inicial de descripción
document.getElementById('descripcion-count').textContent = document.getElementById('descripcion').value.length;

// Validación + cálculo de calidad iniciales
validateAll();
</script>
</body>
</html>