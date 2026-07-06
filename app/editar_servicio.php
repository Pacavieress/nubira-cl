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
require_once $app_dir . '/helpers/seo.php';

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
$precio          = $servicio['precio'];
$imagen_actual   = $servicio['imagen'];
$institucion     = $servicio['institucion'];
$correo          = $servicio['correo'];
$nombre_oferente = $servicio['nombre_oferente'];

// [NUBIRA 2.0] CSRF TOKEN — Protección contra Cross-Site Request Forgery
if (empty($_SESSION['csrf_token_editar'])) {
    $_SESSION['csrf_token_editar'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token_editar'];

// [BANCO] Imágenes del banco (todas activas) para el carrusel; el JS filtra por categoría.
$banco_imagenes = [];
$res_banco = $conn->query("SELECT id, categoria, archivo, descripcion FROM banco_imagenes WHERE activa = 1 ORDER BY categoria, id");
if ($res_banco) { while ($b = $res_banco->fetch_assoc()) $banco_imagenes[] = $b; }

// [BANCO] Preselección de imagen al abrir:
//   1) Si el servicio ya tiene imagen_banco_id → esa.
//   2) Si es legacy (imagen sin banco) → primera activa de su categoría.
//   3) Si no tiene nada → sin preselección (igual que publicar).
$imagen_banco_id_preseleccionado = (int)($servicio['imagen_banco_id'] ?? 0);
if ($imagen_banco_id_preseleccionado <= 0 && !empty($imagen_actual)) {
    $stmt_pre = $conn->prepare("SELECT id FROM banco_imagenes WHERE activa = 1 AND categoria = ? ORDER BY id LIMIT 1");
    $stmt_pre->bind_param("s", $categoria);
    $stmt_pre->execute();
    $stmt_pre->bind_result($pre_id);
    if ($stmt_pre->fetch()) $imagen_banco_id_preseleccionado = (int)$pre_id;
    $stmt_pre->close();
}

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
        // [BANCO] Validación estricta: imagen_banco_id debe ser un id ACTIVO del banco
        // Y pertenecer a la categoría seleccionada (cierra manipulación del formulario).
        $imagen_banco_id = (int)($_POST['imagen_banco_id'] ?? 0);
        $banco_valido = false;
        if ($imagen_banco_id > 0) {
            $stmt_b = $conn->prepare("SELECT id FROM banco_imagenes WHERE id = ? AND activa = 1 AND categoria = ? LIMIT 1");
            $stmt_b->bind_param("is", $imagen_banco_id, $categoria);
            $stmt_b->execute();
            $stmt_b->store_result();
            $banco_valido = ($stmt_b->num_rows === 1);
            $stmt_b->close();
        }

        if (!$banco_valido) {
            $mensaje = "Elige una imagen del banco para tu categoría antes de guardar.";
        } else {
            // Se mantiene la columna imagen (legacy) SIN tocar; el resolver prioriza imagen_banco_id.
            $sql = "UPDATE servicios SET
                    titulo=?, preview=?, descripcion=?, categoria=?, modalidad=?,
                    precio=?, imagen_banco_id=?,
                    estado='pendiente', fecha_revision=NOW()
                    WHERE id=?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssdii", $titulo, $preview, $descripcion, $categoria, $modalidad, $precio, $imagen_banco_id, $id_servicio);

            if ($stmt->execute()) {
                $mensaje = "Cambios guardados. Pendiente de revisión.";
                $exito = true;

                // [MODALIDAD] Si estaba oculto por la regla de solo-online, restaurar visibilidad al guardar
                $stmt_restore = $conn->prepare("UPDATE servicios SET visible = 1, oculto_por_modalidad = 0 WHERE id = ? AND oculto_por_modalidad = 1");
                $stmt_restore->bind_param("i", $id_servicio);
                $stmt_restore->execute();
                $stmt_restore->close();

                // Regenerar slug si el título cambió
                require_once $app_dir . '/helpers/seo.php';
                $slug_nuevo = generar_slug($titulo);
                if (!empty($slug_nuevo) && $slug_nuevo !== ($servicio['slug'] ?? '')) {
                    $stmt_sl = $conn->prepare("UPDATE servicios SET slug = ? WHERE id = ?");
                    $stmt_sl->bind_param("si", $slug_nuevo, $id_servicio);
                    $stmt_sl->execute();
                    $stmt_sl->close();
                }
            } else {
                error_log("Nubira Error - Update Servicio: " . $stmt->error);
                $mensaje = "Error al guardar.";
            }
            $stmt->close();
        }
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
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
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
    
   

    /* [BANCO] Carrusel horizontal estilo iOS: scroll suave, sin barra visible */
    .banco-scroll { -ms-overflow-style: none; scrollbar-width: none; scroll-behavior: smooth; }
    .banco-scroll::-webkit-scrollbar { display: none; }
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

  <main class="pt-16 md:pt-20 pb-32 md:pb-12 lg:ml-64 px-4 max-w-[1100px] mx-auto md:px-8">

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
        
        <a href="<?= url_servicio($id_servicio, $servicio['slug'] ?? null) ?>" target="_blank" class="hidden md:flex text-sm font-bold text-[#54A6D8] hover:underline items-center gap-1 flex-shrink-0">
            <?= icon('eye', 'w-4 h-4') ?> Ver publicación
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        <div class="lg:col-span-10 lg:col-start-2">
            




            <form id="form-servicio" method="POST" class="space-y-6" autocomplete="off">
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
                                    $cats = ['Matemáticas','Química','Física','Biología','Programación','Idiomas','Historia','Lenguaje','Economía','Diseño','Derecho','Asesoría','Otros'];
                                    // Si la categoría actual no está entre las canónicas (caso raro), la agregamos para no perderla.
                                    if ($categoria !== '' && !in_array($categoria, $cats, true)) $cats[] = $categoria;
                                    foreach($cats as $c) {
                                        $sel = ($categoria === $c) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($c) . "' $sel>" . htmlspecialchars($c) . "</option>";
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
                            <div class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl block p-3.5">Online</div>
                            <input type="hidden" name="modalidad" id="modalidad" value="Online">
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Título del anuncio</label>
                        <div class="relative">
                            <input type="text" name="titulo" id="titulo" required maxlength="50" value="<?= htmlspecialchars($titulo) ?>" placeholder="Ej: Clases de Cálculo / Asesoría de Tesis"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
                            <div class="text-right text-xs mt-1 absolute right-0 -bottom-5"><span id="titulo-msg" class="mr-2"></span><span id="titulo-count" class="text-gray-500">0</span>/50</div>
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
                        <label class="block text-xs font-bold text-gray-900 mb-2 uppercase tracking-wide">Imagen de portada</label>
                        <p class="text-xs text-gray-400 mb-3">Elige una imagen profesional de nuestro banco. Se filtran según la categoría que selecciones.</p>

                        <!-- [BANCO] id elegido (preseleccionado en servidor; validado contra la categoría) -->
                        <input type="hidden" name="imagen_banco_id" id="imagen_banco_id" value="<?= $imagen_banco_id_preseleccionado ?: '' ?>">

                        <!-- Estado sin categoría (no debería verse al editar; queda por seguridad) -->
                        <div id="banco-empty" class="hidden bg-gray-50 border border-dashed border-gray-300 rounded-2xl py-10 text-center">
                            <div class="text-gray-300 mb-2 flex justify-center">
                                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                            </div>
                            <p class="text-sm font-medium text-gray-500">Elige una categoría primero</p>
                        </div>

                        <!-- Carrusel del banco (PHP renderiza TODAS; el JS filtra por categoría) -->
                        <div id="banco-carrusel">
                            <div class="flex gap-3 py-1 overflow-x-auto banco-scroll -mx-1 px-1">
                                <?php foreach ($banco_imagenes as $bi):
                                    $es_pre = ((int)$bi['id'] === $imagen_banco_id_preseleccionado);
                                ?>
                                    <button type="button"
                                            class="banco-card group relative flex-shrink-0 w-[140px] h-[100px] rounded-xl overflow-hidden border-[3px] <?= $es_pre ? 'border-[#54A6D8]' : 'border-transparent' ?> transition-all focus:outline-none focus:ring-2 focus:ring-[#54A6D8]"
                                            data-id="<?= (int)$bi['id'] ?>"
                                            data-categoria="<?= htmlspecialchars($bi['categoria']) ?>"
                                            title="<?= htmlspecialchars($bi['descripcion'] ?? '') ?>">
                                        <img src="/upload/banco/<?= htmlspecialchars($bi['archivo']) ?>" alt="<?= htmlspecialchars($bi['descripcion'] ?? $bi['categoria']) ?>" class="w-full h-full object-cover" loading="lazy">
                                        <span class="banco-check absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-[#54A6D8] text-white <?= $es_pre ? 'flex' : 'hidden' ?> items-center justify-center shadow">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        </span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div id="banco-sin-imagenes" class="hidden py-8 text-center text-sm text-gray-400">Selecciona una imagen del banco (esta categoría aún no tiene imágenes).</div>
                        </div>

                        <p id="banco-error" class="hidden mt-2 text-xs text-red-500 font-bold">Debes elegir una imagen del banco para tu categoría.</p>
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

            <?php
            /* ── SECCIÓN VIDEO ─────────────────────────────────────────────── */
            $video_estado  = $servicio['video_estado']         ?? 'sin_video';
            $video_path    = $servicio['video_path']            ?? null;
            $video_motivo  = $servicio['video_motivo_rechazo']  ?? null;
            $mostrar_form_upload = in_array($video_estado, ['sin_video', 'rechazado']);
            ?>

            <div class="bg-white border border-gray-100 rounded-2xl p-6 md:p-8 shadow-sm mt-6" id="seccion-video">

                <!-- Cabecera -->
                <div class="flex items-start justify-between gap-3 mb-5">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h2 class="text-base font-bold text-gray-900">Video de presentación</h2>
                            <span class="text-[10px] font-bold bg-blue-50 text-[#54A6D8] px-2 py-0.5 rounded-full border border-blue-100 uppercase tracking-widest">Opcional</span>
                        </div>
                        <p class="text-xs text-gray-400 leading-relaxed max-w-lg">
                            Video vertical (9:16) de máx. 45 seg. Aumenta la confianza del comprador y Nubira puede usarlo en Instagram y TikTok para promover tu servicio.
                        </p>
                    </div>

                    <?php if ($video_estado === 'aprobado'): ?>
                        <span class="shrink-0 flex items-center gap-1.5 text-[11px] font-bold text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-full border border-emerald-100 whitespace-nowrap">
                            <?= icon('check-circle', 'w-3.5 h-3.5') ?> Publicado
                        </span>
                    <?php elseif ($video_estado === 'pendiente'): ?>
                        <span class="shrink-0 flex items-center gap-1.5 text-[11px] font-bold text-amber-600 bg-amber-50 px-3 py-1.5 rounded-full border border-amber-100 whitespace-nowrap">
                            <?= icon('clock', 'w-3.5 h-3.5') ?> En revisión
                        </span>
                    <?php elseif ($video_estado === 'rechazado'): ?>
                        <span class="shrink-0 flex items-center gap-1.5 text-[11px] font-bold text-red-600 bg-red-50 px-3 py-1.5 rounded-full border border-red-100 whitespace-nowrap">
                            <?= icon('exclamation-triangle', 'w-3.5 h-3.5') ?> Rechazado
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Estado APROBADO: player + opción reemplazar -->
                <?php if ($video_estado === 'aprobado' && $video_path): ?>
                    <div id="video-aprobado" class="mb-5">
                        <div class="relative rounded-2xl overflow-hidden bg-black aspect-[9/16] max-w-[180px]">
                            <video
                                src="/upload/videos_servicios/<?= htmlspecialchars($video_path) ?>"
                                controls
                                preload="metadata"
                                controlsList="nodownload"
                                disablePictureInPicture
                                playsinline
                                class="w-full h-full object-contain"
                            ></video>
                        </div>
                        <button type="button"
                                onclick="document.getElementById('video-upload-form').classList.remove('hidden'); document.getElementById('video-aprobado').classList.add('hidden');"
                                class="mt-3 flex items-center gap-1.5 text-xs font-bold text-gray-400 hover:text-gray-600 transition-colors">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            Reemplazar video
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Estado PENDIENTE: aviso + opción reemplazar -->
                <?php if ($video_estado === 'pendiente'): ?>
                    <div id="video-pendiente" class="mb-5 bg-amber-50 border border-amber-100 rounded-xl p-4 flex gap-3 items-start">
                        <span class="text-amber-400 mt-0.5 shrink-0"><?= icon('clock', 'w-5 h-5') ?></span>
                        <div>
                            <p class="text-sm font-bold text-amber-800">Tu video está siendo revisado</p>
                            <p class="text-xs text-amber-600 mt-0.5 leading-relaxed">Recibirás una notificación cuando sea aprobado. Mientras tanto no aparece en tu publicación.</p>
                            <button type="button"
                                    onclick="document.getElementById('video-pendiente').classList.add('hidden'); document.getElementById('video-upload-form').classList.remove('hidden');"
                                    class="mt-2 flex items-center gap-1 text-xs font-bold text-amber-700 hover:underline">
                                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                Subir otro video (reemplaza el actual)
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Estado RECHAZADO: motivo -->
                <?php if ($video_estado === 'rechazado'): ?>
                    <div class="mb-5 bg-red-50 border border-red-100 rounded-xl p-4 flex gap-3 items-start">
                        <span class="text-red-400 mt-0.5 shrink-0"><?= icon('exclamation-triangle', 'w-5 h-5') ?></span>
                        <div>
                            <p class="text-sm font-bold text-red-800">Video rechazado</p>
                            <?php if ($video_motivo): ?>
                                <p class="text-xs text-red-600 mt-1 leading-relaxed"><?= htmlspecialchars($video_motivo) ?></p>
                            <?php endif; ?>
                            <p class="text-xs text-red-500 mt-1.5 font-medium">Sube un nuevo video corrigiendo lo indicado.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Formulario de upload -->
                <div id="video-upload-form" class="<?= $mostrar_form_upload ? '' : 'hidden' ?> space-y-4">

                    <!-- Reglas del video -->
                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-4 flex gap-3">
                        <span class="shrink-0 mt-0.5 text-amber-500">
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-xs font-bold text-amber-800 mb-2">Reglas importantes para tu video</p>
                            <ul class="space-y-1 text-xs text-amber-700 leading-relaxed">
                                <li>· Solo puedes mencionar tu primer nombre (ej: "Hola, soy Juan"). No menciones tu apellido.</li>
                                <li>· No menciones números de teléfono, WhatsApp, correos electrónicos, ni redes sociales.</li>
                                <li>· No muestres en pantalla ningún dato de contacto (carteles, papel, fondo con tu Instagram, etc.).</li>
                                <li>· Habla solo de tu servicio: qué enseñas, cómo lo haces, qué pueden esperar.</li>
                                <li>· Si rompes estas reglas tu video será rechazado y deberás subir uno nuevo.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Zona de selección -->
                    <div id="video-drop-zone"
                         onclick="document.getElementById('video-input').click()"
                         class="border-2 border-dashed border-gray-200 rounded-2xl p-8 text-center cursor-pointer
                                hover:border-[#54A6D8] hover:bg-blue-50/20 transition-all">

                        <div id="video-drop-icon" class="flex flex-col items-center gap-2.5">
                            <div class="w-14 h-14 rounded-2xl bg-gray-50 flex items-center justify-center text-gray-300">
                                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-700">Toca para seleccionar tu video</p>
                                <p class="text-xs text-gray-400 mt-0.5">MP4 · WebM · MOV &nbsp;·&nbsp; Máx. 30 MB &nbsp;·&nbsp; Máx. 45 seg. &nbsp;·&nbsp; Vertical 9:16</p>
                            </div>
                        </div>

                        <div id="video-preview-wrap" class="hidden flex-col items-center gap-2">
                            <div class="relative rounded-xl overflow-hidden bg-black aspect-[9/16] w-[140px]">
                                <video id="video-preview-local" class="w-full h-full object-contain" muted playsinline></video>
                            </div>
                            <p id="video-preview-info" class="text-xs text-gray-500 truncate max-w-[220px]"></p>
                            <button type="button"
                                    onclick="event.stopPropagation(); quitarVideo();"
                                    class="text-xs font-bold text-red-400 hover:text-red-600 transition-colors flex items-center gap-1">
                                <?= icon('x-circle', 'w-3.5 h-3.5') ?> Quitar
                            </button>
                        </div>
                    </div>

                    <input type="file" id="video-input"
                           accept=".mp4,.webm,.mov,video/mp4,video/webm,video/quicktime"
                           class="hidden">

                    <!-- Error de validación -->
                    <div id="video-error" class="hidden bg-red-50 border border-red-100 rounded-xl px-4 py-3 flex items-start gap-2">
                        <?= icon('exclamation-triangle', 'w-4 h-4 text-red-500 shrink-0 mt-0.5') ?>
                        <span id="video-error-msg" class="text-xs font-bold text-red-700"></span>
                    </div>

                    <!-- Consentimiento RRSS -->
                    <label class="flex items-start gap-3 p-4 bg-gray-50 rounded-xl border border-gray-100 cursor-pointer
                                  hover:bg-blue-50/30 hover:border-blue-100 transition-all select-none">
                        <input type="checkbox" id="video-consent"
                               class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#54A6D8] focus:ring-[#54A6D8] cursor-pointer shrink-0">
                        <span class="text-xs text-gray-600 leading-relaxed">
                            <span class="font-bold text-gray-800">Autorizo a Nubira</span> a publicar este video en redes sociales (Instagram, TikTok, Facebook) para promocionar mi servicio. El video no será editado y siempre se asociará a mi perfil de tutor.
                        </span>
                    </label>

                    <!-- Barra de progreso -->
                    <div id="video-progress-wrap" class="hidden space-y-1.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-gray-500">Subiendo video...</span>
                            <span id="video-progress-pct" class="text-xs font-bold text-[#54A6D8]">0%</span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div id="video-progress-bar"
                                 class="h-2 rounded-full transition-all duration-150"
                                 style="width:0%; background-color:#54A6D8;"></div>
                        </div>
                    </div>

                    <!-- Botón subir -->
                    <button type="button" id="btn-subir-video" disabled
                            onclick="subirVideo()"
                            class="w-full py-4 rounded-2xl font-bold text-sm transition-all flex items-center justify-center gap-2
                                   bg-[#54A6D8] text-white shadow-sm
                                   disabled:opacity-40 disabled:cursor-not-allowed
                                   enabled:hover:bg-sky-500 enabled:active:scale-[0.99]">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        <span id="btn-subir-texto">Subir video</span>
                    </button>

                </div><!-- /video-upload-form -->

            </div><!-- /seccion-video -->

            <script>
            (function () {
                const SERVICIO_ID  = <?= (int)$id_servicio ?>;
                const CSRF_TOKEN   = <?= json_encode($csrf_token) ?>;
                const MAX_BYTES    = 30 * 1024 * 1024;
                const MAX_SEG      = 45;

                const elInput        = document.getElementById('video-input');
                const elDropIcon     = document.getElementById('video-drop-icon');
                const elPreviewWrap  = document.getElementById('video-preview-wrap');
                const elPreviewVid   = document.getElementById('video-preview-local');
                const elPreviewInfo  = document.getElementById('video-preview-info');
                const elError        = document.getElementById('video-error');
                const elErrorMsg     = document.getElementById('video-error-msg');
                const elConsent      = document.getElementById('video-consent');
                const elProgressWrap = document.getElementById('video-progress-wrap');
                const elProgressBar  = document.getElementById('video-progress-bar');
                const elProgressPct  = document.getElementById('video-progress-pct');
                const elBtn          = document.getElementById('btn-subir-video');
                const elBtnTxt       = document.getElementById('btn-subir-texto');

                let archivoListo = false;
                let capturaThumbBlob = null;

                elInput.addEventListener('change', function () {
                    const file = this.files[0];
                    if (!file) return;
                    archivoListo = false;
                    capturaThumbBlob = null;
                    ocultarError();
                    actualizarBtn();

                    const tiposOk = ['video/mp4', 'video/webm', 'video/quicktime'];
                    if (!tiposOk.includes(file.type)) {
                        error('Formato no válido. Usa MP4, WebM o MOV.'); this.value = ''; return;
                    }
                    if (file.size > MAX_BYTES) {
                        const pesoMB = (file.size / (1024 * 1024)).toFixed(1);
                        error('Tu video pesa ' + pesoMB + ' MB, el máximo permitido es 30 MB. Comprime el video e intenta de nuevo.'); this.value = ''; return;
                    }

                    const objURL = URL.createObjectURL(file);
                    const tmp    = document.createElement('video');
                    tmp.preload  = 'metadata';
                    tmp.src      = objURL;

                    tmp.addEventListener('loadedmetadata', function () {
                        if (this.duration > MAX_SEG) {
                            URL.revokeObjectURL(objURL);
                            error('El video dura ' + Math.ceil(this.duration) + ' s. El máximo es 45 segundos.');
                            elInput.value = ''; return;
                        }
                        if (this.videoWidth > 0 && this.videoWidth >= this.videoHeight) {
                            URL.revokeObjectURL(objURL);
                            error('El video debe ser vertical (9:16). Grábalo con el celular en modo retrato.');
                            elInput.value = ''; return;
                        }

                        archivoListo = true;
                        elPreviewVid.src = URL.createObjectURL(file);
                        elPreviewInfo.textContent =
                            file.name + ' · ' + (file.size / (1024 * 1024)).toFixed(1) + ' MB · ' +
                            Math.ceil(this.duration) + ' s';
                        elDropIcon.classList.add('hidden');
                        elPreviewWrap.classList.remove('hidden');
                        elPreviewWrap.classList.add('flex');
                        actualizarBtn();

                        // --- Captura de miniatura real (mejor esfuerzo; si falla, el poster usa la portada del servicio) ---
                        const finalizarCaptura = (function () {
                            let hecho = false;
                            return function () {
                                if (hecho) return;
                                hecho = true;
                                URL.revokeObjectURL(objURL);
                            };
                        })();
                        const timeoutCaptura = setTimeout(finalizarCaptura, 3000);

                        try {
                            tmp.addEventListener('seeked', function onSeeked() {
                                tmp.removeEventListener('seeked', onSeeked);
                                try {
                                    const w = tmp.videoWidth, h = tmp.videoHeight;
                                    const escala = Math.min(1, 480 / Math.max(w, h));
                                    const canvas = document.createElement('canvas');
                                    canvas.width  = Math.round(w * escala);
                                    canvas.height = Math.round(h * escala);
                                    canvas.getContext('2d').drawImage(tmp, 0, 0, canvas.width, canvas.height);
                                    canvas.toBlob(function (blob) {
                                        capturaThumbBlob = blob;
                                        clearTimeout(timeoutCaptura);
                                        finalizarCaptura();
                                    }, 'image/jpeg', 0.82);
                                } catch (e) {
                                    clearTimeout(timeoutCaptura);
                                    finalizarCaptura();
                                }
                            });
                            tmp.currentTime = Math.max(0, Math.min(15, this.duration - 0.5));
                        } catch (e) {
                            clearTimeout(timeoutCaptura);
                            finalizarCaptura();
                        }
                    });

                    tmp.addEventListener('error', function () {
                        URL.revokeObjectURL(objURL);
                        error('No se pudo leer el video. Asegúrate de que el archivo no esté dañado.');
                        elInput.value = '';
                    });
                });

                elConsent.addEventListener('change', actualizarBtn);

                window.quitarVideo = function () {
                    elInput.value    = '';
                    archivoListo     = false;
                    capturaThumbBlob = null;
                    elPreviewVid.src = '';
                    elPreviewWrap.classList.add('hidden');
                    elPreviewWrap.classList.remove('flex');
                    elDropIcon.classList.remove('hidden');
                    ocultarError();
                    actualizarBtn();
                };

                window.subirVideo = function () {
                    if (!archivoListo || !elConsent.checked) return;
                    const file = elInput.files[0];
                    if (!file) return;

                    const fd = new FormData();
                    fd.append('video',               file);
                    fd.append('servicio_id',         SERVICIO_ID);
                    fd.append('csrf_token',          CSRF_TOKEN);
                    fd.append('consentimiento_rrss', '1');
                    if (capturaThumbBlob) {
                        fd.append('thumb', capturaThumbBlob, 'thumb.jpg');
                    }

                    elBtn.disabled       = true;
                    elBtnTxt.textContent = 'Subiendo...';
                    elProgressWrap.classList.remove('hidden');
                    ocultarError();

                    const xhr = new XMLHttpRequest();

                    xhr.upload.addEventListener('progress', function (e) {
                        if (!e.lengthComputable) return;
                        const pct = Math.round((e.loaded / e.total) * 100);
                        elProgressBar.style.width = pct + '%';
                        elProgressPct.textContent = pct + '%';
                    });

                    xhr.addEventListener('load', function () {
                        let res = {};
                        try { res = JSON.parse(xhr.responseText); } catch (e) {}

                        if (xhr.status === 200 && res.ok) {
                            elProgressBar.style.width      = '100%';
                            elProgressBar.style.background = '#10b981';
                            elProgressPct.textContent      = '100%';
                            elBtnTxt.textContent           = '¡Listo!';
                            setTimeout(() => location.reload(), 1200);
                        } else {
                            error(res.error || 'Error al subir el video. Intenta de nuevo.');
                            resetUI();
                        }
                    });

                    xhr.addEventListener('error',   function () { error('Error de conexión. Verifica tu internet.'); resetUI(); });
                    xhr.addEventListener('timeout', function () { error('El upload tardó demasiado. Intenta con mejor conexión.'); resetUI(); });

                    xhr.timeout = 120000;
                    xhr.open('POST', '/subir-video-servicio');
                    xhr.send(fd);
                };

                function actualizarBtn() { elBtn.disabled = !(archivoListo && elConsent.checked); }
                function error(msg) {
                    elErrorMsg.textContent = msg;
                    elError.classList.remove('hidden');
                    elError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                function ocultarError() { elError.classList.add('hidden'); }
                function resetUI() {
                    elBtn.disabled             = false;
                    elBtnTxt.textContent       = 'Subir video';
                    elProgressWrap.classList.add('hidden');
                    elProgressBar.style.width      = '0%';
                    elProgressBar.style.background = '#54A6D8';
                    elProgressPct.textContent      = '0%';
                }
            }());
            </script>

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
    warning: document.getElementById('security-warning'),
    btnSubmit: document.getElementById('btn-submit'),

    countDesc: document.getElementById('descripcion-count'),
    countTitulo: document.getElementById('titulo-count')
};





// --- EVENTOS UI ---
ui.desc?.addEventListener('input', function() {
    const isBad = [/\d{8,}/, /@/, /\.cl/].some(p => p.test(this.value));
    ui.warning.classList.toggle('hidden', !isBad);
    ui.warning.classList.toggle('flex', isBad);
    ui.btnSubmit.disabled = isBad;
    if(isBad) ui.btnSubmit.classList.add('opacity-50'); else ui.btnSubmit.classList.remove('opacity-50');
    if(ui.countDesc) ui.countDesc.textContent = this.value.length;
});
ui.titulo?.addEventListener('input', function() {
    var len = this.value.length;
    var msg = document.getElementById('titulo-msg');
    if (ui.countTitulo) {
        ui.countTitulo.textContent = len;
        ui.countTitulo.classList.remove('text-green-600','text-amber-600','text-gray-500');
        if (len === 0) {
            ui.countTitulo.classList.add('text-gray-500');
            if (msg) { msg.textContent = ''; msg.className = 'mr-2'; }
        } else if (len <= 40) {
            ui.countTitulo.classList.add('text-green-600');
            if (msg) { msg.textContent = '✓ Se verá completo'; msg.className = 'mr-2 text-green-600'; }
        } else {
            ui.countTitulo.classList.add('text-amber-600');
            if (msg) { msg.textContent = 'Puede recortarse en móvil'; msg.className = 'mr-2 text-amber-600'; }
        }
    }
});
ui.titulo?.dispatchEvent(new Event('input'));

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

// [BANCO] Carrusel de imágenes del banco (con preselección al editar).
// PHP ya renderizó TODAS las tarjetas y marcó la preseleccionada; aquí filtramos por categoría.
(function setupBancoCarrusel() {
    const sel      = document.getElementById('categoria');
    const carrusel = document.getElementById('banco-carrusel');
    const empty    = document.getElementById('banco-empty');
    const sinImgs  = document.getElementById('banco-sin-imagenes');
    const errorMsg = document.getElementById('banco-error');
    const hidden   = document.getElementById('imagen_banco_id');
    const cards    = Array.from(document.querySelectorAll('.banco-card'));
    if (!sel || !carrusel || !hidden) return;

    function limpiar(card) {
        card.classList.remove('border-[#54A6D8]');
        card.classList.add('border-transparent');
        const chk = card.querySelector('.banco-check');
        chk.classList.add('hidden');
        chk.classList.remove('flex');
    }

    function seleccionar(card) {
        cards.forEach(limpiar);
        card.classList.add('border-[#54A6D8]');
        card.classList.remove('border-transparent');
        const chk = card.querySelector('.banco-check');
        chk.classList.remove('hidden');
        chk.classList.add('flex');
        hidden.value = card.dataset.id;
        if (errorMsg) errorMsg.classList.add('hidden');
    }

    cards.forEach(card => card.addEventListener('click', () => seleccionar(card)));

    // reset=true al cambiar de categoría (limpia selección); reset=false en el init (respeta preselección).
    function filtrar(reset) {
        const cat = sel.value;
        if (reset) hidden.value = '';
        if (!cat) {
            carrusel.classList.add('hidden');
            empty.classList.remove('hidden');
            return;
        }
        empty.classList.add('hidden');
        carrusel.classList.remove('hidden');
        let visibles = 0;
        cards.forEach(card => {
            const match = (card.dataset.categoria === cat);
            card.classList.toggle('hidden', !match);
            if (reset) limpiar(card);
            if (match) visibles++;
        });
        if (sinImgs) sinImgs.classList.toggle('hidden', visibles > 0);
    }

    sel.addEventListener('change', () => filtrar(true));
    filtrar(false); // init: respeta la preselección renderizada por PHP

    // Validación en cliente: no permitir guardar sin imagen del banco
    document.getElementById('form-servicio')?.addEventListener('submit', function(e) {
        if (!hidden.value) {
            e.preventDefault();
            if (errorMsg) errorMsg.classList.remove('hidden');
            (empty.classList.contains('hidden') ? carrusel : empty)
                .scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();

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