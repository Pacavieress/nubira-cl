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
require_once $app_dir . '/helpers/horarios.php';
require_once $app_dir . '/helpers/institucion.php';

// 2. CANDADO ESTRICTO (Visitantes fuera)
if (function_exists('proteger_ruta')) {
    proteger_ruta(); 
} else {
    die("Error de seguridad: No se pudo cargar el control de sesión.");
}

// 3. CONEXIÓN
if (!isset($conn)) require_once $app_dir . '/conexion.php';

// AUTO-MIGRACIÓN: columna es_paes (etiqueta "Prepara para la PAES")
$check_col_paes = $conn->query("SHOW COLUMNS FROM servicios LIKE 'es_paes'");
if ($check_col_paes && $check_col_paes->num_rows === 0) {
    $conn->query("ALTER TABLE servicios ADD COLUMN es_paes TINYINT(1) NOT NULL DEFAULT 0");
}

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

// Fallback: si la sesión no trae institución real (dominio no institucional, cuenta express,
// o el placeholder 'Excepción Gmail' de login.php), usar lo que el propio usuario ya escribió
// en su perfil. Primero alumnos.institucion (la que llena la ruta "comprar" de
// completar_perfil.php y la que usa el resto del sitio, ej. busqueda.php/vitrina.php vía
// COALESCE(dp.institucion, a.institucion)); si está vacía, alumnos.universidad como respaldo
// (tutores con ese campo lleno de antes de este cambio). Normalizado con el mismo diccionario
// de institucion.php — sin HTML-escape (se guarda texto plano en BD, no para mostrar), con 50
// caracteres de tope (igual al ancho real de la columna servicios.institucion).
if ($institucion === '' || $institucion === 'Excepción Gmail') {
    $stmt_univ = $conn->prepare("SELECT COALESCE(NULLIF(institucion, ''), NULLIF(universidad, '')) AS institucion_perfil FROM alumnos WHERE id = ? LIMIT 1");
    $stmt_univ->bind_param("i", $usuario_id);
    $stmt_univ->execute();
    $institucion_perfil = trim((string)($stmt_univ->get_result()->fetch_assoc()['institucion_perfil'] ?? ''));
    $stmt_univ->close();
    if ($institucion_perfil !== '') {
        $institucion = abreviar_institucion($institucion_perfil, 50, false);
    }
}

// [NUBIRA 2.0] CSRF TOKEN — Protección contra Cross-Site Request Forgery
if (empty($_SESSION['csrf_token_publicar'])) {
    $_SESSION['csrf_token_publicar'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token_publicar'];

// Para upload de video post-publicación (el endpoint usa csrf_token_editar)
if (empty($_SESSION['csrf_token_editar'])) {
    $_SESSION['csrf_token_editar'] = bin2hex(random_bytes(32));
}
$csrf_token_editar = $_SESSION['csrf_token_editar'];

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
    } elseif ($precio < 10000) {
        $mensaje = "El precio mínimo es \$10.000.";
    } elseif (contiene_contacto($titulo) || contiene_contacto($descripcion)) {
        $mensaje = "Por seguridad, no incluyas teléfonos ni correos.";
    } else {
        // [BANCO] Imagen de portada asignada automáticamente según la categoría
        // (ya no requiere selección manual del usuario).
        $imagen_banco_id = 0;
        $stmt_b = $conn->prepare("SELECT id FROM banco_imagenes WHERE activa = 1 AND categoria = ? ORDER BY RAND() LIMIT 1");
        $stmt_b->bind_param("s", $categoria);
        $stmt_b->execute();
        $stmt_b->bind_result($imagen_banco_id);
        $stmt_b->fetch();
        $stmt_b->close();

        if ($imagen_banco_id <= 0) {
            $mensaje = "No hay imágenes disponibles para esta categoría. Contacta a soporte.";
        } else {
            // Servicios nuevos: imagen legacy vacía; el resolver prioriza imagen_banco_id.
            $imagen_legacy = '';
            $es_paes = isset($_POST['es_paes']) ? 1 : 0;
            $sql = "INSERT INTO servicios (alumno_id, institucion, titulo, descripcion, nombre_oferente, categoria, modalidad, ubicacion, precio, correo, imagen, imagen_banco_id, estado, fecha_publicacion, es_paes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW(), ?)";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("isssssssdssii", $usuario_id, $institucion, $titulo, $descripcion, $nombre_oferente, $categoria, $modalidad, $ubicacion, $precio, $correo, $imagen_legacy, $imagen_banco_id, $es_paes);

                if ($stmt->execute()) {
                    $nuevo_servicio_id = $stmt->insert_id;
                    actualizar_score_servicio($conn, $nuevo_servicio_id);

                    // Generar slug SEO para la URL amigable del servicio
                    require_once $app_dir . '/helpers/seo.php';
                    $slug_nuevo = generar_slug($titulo);
                    if (!empty($slug_nuevo)) {
                        $stmt_sl = $conn->prepare("UPDATE servicios SET slug = ? WHERE id = ?");
                        $stmt_sl->bind_param("si", $slug_nuevo, $nuevo_servicio_id);
                        $stmt_sl->execute();
                        $stmt_sl->close();
                    }

                    require_once __DIR__ . '/enviar_push_nubira.php';
                    $autor_p  = explode(' ', trim($_SESSION['usuario_nombre'] ?? 'Alguien'))[0];
                    $titulo_p = mb_substr($titulo, 0, 50);
                    enviar_push_nubira(1, '🔔 Servicio nuevo', $autor_p . ' publicó: "' . $titulo_p . '". Revisar.', '/admin/servicios');

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
  <?php require_once __DIR__ . '/componentes/head_common.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
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

<main class="pt-16 md:pt-20 pb-32 md:pb-8 lg:ml-64 px-4 max-w-[1100px] w-full mx-auto md:px-8 min-h-[calc(100vh-80px)] flex flex-col transition-all duration-300">

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
            
            <div class="mb-4 md:mb-6 bg-white border border-gray-100 rounded-xl px-4 py-2.5 shadow-sm flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <?= icon('sparkles', 'w-4 h-4 text-[#54A6D8]') ?>
                    <span class="text-xs font-bold text-gray-700 uppercase tracking-wide">Progreso</span>
                </div>
                <div class="flex items-center gap-3 flex-1 max-w-[200px] justify-end">
                    <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                        <div id="barra-progreso" class="h-1.5 rounded-full transition-all duration-500 ease-out w-0 bg-gray-300"></div>
                    </div>
                    <span id="calidad-score" class="font-bold text-xs text-gray-500 min-w-[32px] text-right">0%</span>
                </div>
            </div>

            <form id="form-servicio" method="POST" class="space-y-6" autocomplete="off">
                
                <!-- [NUBIRA 2.0] CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                
                <div class="bg-white border border-gray-100 rounded-2xl p-4 md:p-8 shadow-sm !mt-0">

                    <?php if ($mensaje): ?>
                        <div id="toast" class="mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm <?= $exito ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                            <?= icon($exito ? 'check-circle' : 'alert', 'w-5 h-5 flex-shrink-0') ?>
                            <span class="text-sm font-bold flex-1"><?= htmlspecialchars($mensaje); ?></span>
                            <?php if(!$exito): ?><button type="button" onclick="document.getElementById('toast').remove()" class="text-sm opacity-70 hover:opacity-100">✕</button><?php endif; ?>
                        </div>
                        <?php if($exito): ?>
                        <input type="hidden" id="nubira-new-svc-id" value="<?= (int)$nuevo_servicio_id ?>">
                        <script>setTimeout(()=>window.location.href='/clases-servicios', 2500);</script>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4 md:mb-6">
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-xs font-bold text-gray-900 uppercase tracking-wide">Categoría</label>
                                <span class="text-[10px] font-semibold text-gray-400 bg-gray-50 border border-gray-200 rounded-full px-2 py-0.5">Modalidad: Online</span>
                            </div>
                            <div class="relative">
                                <select name="categoria" id="categoria" class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 pr-10 transition outline-none appearance-none cursor-pointer">
                                    <option value="">Selecciona una opción...</option>
                                    <?php
                                    $categorias_canonicas = ['Matemáticas','Química','Física','Biología','Programación','Idiomas','Historia','Lenguaje','Economía','Diseño','Derecho','Asesoría','Otros'];
                                    foreach ($categorias_canonicas as $cat_op):
                                    ?>
                                        <option value="<?= htmlspecialchars($cat_op) ?>"><?= htmlspecialchars($cat_op) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                                    <?= icon('chevron-down', 'w-4 h-4 text-gray-400') ?>
                                </div>
                            </div>
                            <input type="hidden" name="modalidad" id="modalidad" value="Online">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">PAES</label>
                            <label title="Márcalo si este servicio ayuda a rendir la prueba de admisión" class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl p-3.5 cursor-pointer select-none h-[50px]">
                                <input type="checkbox" name="es_paes" id="es_paes" value="1" class="w-4 h-4 rounded border-gray-300 text-[#54A6D8] focus:ring-[#54A6D8] shrink-0">
                                <span class="text-sm font-bold text-gray-900">Prepara para la PAES</span>
                            </label>
                        </div>
                    </div>

                    <div class="mb-4 md:mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Título del anuncio</label>
                        <div class="relative">
                            <input type="text" name="titulo" id="titulo" required maxlength="50" placeholder="Ej: Clases de Cálculo / Asesoría de Tesis"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-base md:text-sm rounded-xl focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent block p-3.5 transition outline-none">
                            <div class="text-right text-xs mt-1 absolute right-0 -bottom-5"><span id="titulo-msg" class="mr-2"></span><span id="titulo-count" class="text-gray-500">0</span>/50</div>
                        </div>
                    </div>

                    <div class="mb-4 md:mb-6 mt-4 md:mt-6">
                        <div class="mb-1.5">
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
                    <div class="mb-4 md:mb-6">
                        <label class="block text-xs font-bold text-gray-900 mb-1.5 uppercase tracking-wide">Precio Base (CLP)</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold pointer-events-none">$</span>
                            
                            <!-- Input visible: formato chileno con puntos -->
                            <input type="text"
                                   id="precio_visible"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   placeholder="Mínimo $10.000"
                                   class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-lg font-bold rounded-xl pl-8 p-3.5 focus:ring-2 focus:ring-[#54A6D8] focus:border-transparent transition outline-none">

                            <!-- Input real que viaja al backend (siempre numérico puro) -->
                            <input type="hidden" name="precio" id="precio" value="">
                        </div>
                        <p class="text-xs text-gray-400 mt-1 ml-1">El precio mínimo es $10.000.</p>
                        <p id="precio-error" class="hidden text-xs font-bold text-red-600 mt-1 ml-1">El precio debe ser al menos $10.000.</p>
                    </div>

                </div>

            </form>

            <!-- [NUBIRA 2.0] Horario de disponibilidad — FUERA del <form>, se guarda por AJAX
                 inmediatamente después de crear el servicio (mismo patrón que el video). -->
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm mt-6 overflow-hidden" id="seccion-horario">
                <button type="button" onclick="toggleAcordeonPublicar('horario')" class="w-full flex items-start justify-between gap-3 p-4 md:p-8 text-left">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h2 class="text-base font-bold text-gray-900">Horario de disponibilidad</h2>
                            <span class="text-[10px] font-bold bg-red-50 text-red-600 px-2 py-0.5 rounded-full border border-red-100 uppercase tracking-widest">Requerido</span>
                        </div>
                        <p class="text-xs text-gray-400 leading-relaxed max-w-lg">
                            Define al menos un bloque en el que estés disponible. Es obligatorio para poder aprobar tu servicio.
                        </p>
                    </div>
                    <span id="acordeon-chevron-horario" class="shrink-0 text-gray-400 transition-transform duration-200">
                        <?= icon('chevron-down', 'w-5 h-5') ?>
                    </span>
                </button>

                <div id="acordeon-body-horario" class="hidden px-4 md:px-8 pb-4 md:pb-8 pt-4 border-t border-gray-100">
                    <?php require_once __DIR__ . '/componentes/grilla_horarios.php'; ?>
                </div>
            </div>

            <!-- Video de presentación (se sube DESPUÉS de crear el servicio vía XHR) -->
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm mt-6 overflow-hidden" id="seccion-video">
                <button type="button" onclick="toggleAcordeonPublicar('video')" class="w-full flex items-start justify-between gap-3 p-4 md:p-8 text-left">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <h2 class="text-base font-bold text-gray-900">Video de presentación</h2>
                            <span class="text-[10px] font-bold bg-blue-50 text-[#54A6D8] px-2 py-0.5 rounded-full border border-blue-100 uppercase tracking-widest">Opcional</span>
                        </div>
                        <p class="text-xs text-gray-400 leading-relaxed max-w-lg">
                            Los alumnos eligen primero a los tutores que pueden ver antes de escribirles. Video vertical (9:16), máx. 45 seg.
                        </p>
                    </div>
                    <span id="acordeon-chevron-video" class="shrink-0 text-gray-400 transition-transform duration-200">
                        <?= icon('chevron-down', 'w-5 h-5') ?>
                    </span>
                </button>

                <div id="acordeon-body-video" class="hidden px-4 md:px-8 pb-4 md:pb-8 pt-4 border-t border-gray-100">
                <!-- Reglas del video -->
                <div class="bg-amber-50 border border-amber-100 rounded-xl p-4 flex gap-3 mb-4">
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

                <!-- Guion sugerido -->
                <div class="bg-sky-50 border border-sky-100 rounded-xl p-4 flex gap-3 mb-4">
                    <span class="shrink-0 mt-0.5 text-[#54A6D8]">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" />
                        </svg>
                    </span>
                    <p class="text-xs text-sky-800 leading-relaxed">
                        <span class="font-bold">¿No sabes qué decir?</span> Prueba con esto: "Hola, soy [tu nombre]. Enseño [tu materia] hace [tiempo]. Escríbeme por Nubira si tienes dudas antes de agendar tu clase."
                    </p>
                </div>

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
                                onclick="event.stopPropagation(); quitarVideoPublicar();"
                                class="text-xs font-bold text-red-400 hover:text-red-600 transition-colors flex items-center gap-1">
                            <?= icon('x-circle', 'w-3.5 h-3.5') ?> Quitar
                        </button>
                    </div>
                </div>

                <input type="file" id="video-input"
                       accept=".mp4,.webm,.mov,video/mp4,video/webm,video/quicktime"
                       class="hidden">

                <div id="video-error" class="hidden mt-3 bg-red-50 border border-red-100 rounded-xl px-4 py-3 flex items-start gap-2">
                    <?= icon('exclamation-triangle', 'w-4 h-4 text-red-500 shrink-0 mt-0.5') ?>
                    <span id="video-error-msg" class="text-xs font-bold text-red-700"></span>
                </div>

                <label class="mt-4 flex items-start gap-3 p-4 bg-gray-50 rounded-xl border border-gray-100 cursor-pointer
                              hover:bg-blue-50/30 hover:border-blue-100 transition-all select-none">
                    <input type="checkbox" id="video-consent"
                           class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#54A6D8] focus:ring-[#54A6D8] cursor-pointer shrink-0">
                    <span class="text-xs text-gray-600 leading-relaxed">
                        <span class="font-bold text-gray-800">Autorizo a Nubira</span> a publicar este video en redes sociales (Instagram, TikTok, Facebook) para promocionar mi servicio. El video no será editado y siempre se asociará a mi perfil de tutor.
                    </span>
                </label>

                <div id="video-progress-wrap" class="hidden mt-4 space-y-1.5">
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-bold text-gray-500">Subiendo video...</span>
                        <span id="video-progress-pct" class="text-xs font-bold text-[#54A6D8]">0%</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                        <div id="video-progress-bar" class="h-2 rounded-full transition-all duration-150" style="width:0%; background-color:#54A6D8;"></div>
                    </div>
                </div>

                <p class="mt-4 text-[11px] text-gray-400 text-center">
                    El video se sube automáticamente al publicar. Si no lo subes ahora, puedes hacerlo después desde "Mis Publicaciones".
                </p>
                </div><!-- /acordeon-body-video -->
            </div><!-- /seccion-video -->

            <!-- [NUBIRA 2.0] Wrapper sticky solo en móvil. Movido al final visual (después de
                 Horario y Video); usa form="form-servicio" para seguir disparando el submit
                 del form aunque ya no sea descendiente suyo. -->
            <div class="md:static md:bg-transparent md:p-0 md:border-0
                        fixed bottom-0 left-0 right-0 z-40
                        bg-white/95 backdrop-blur-md
                        px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]
                        border-t border-gray-100 shadow-[0_-4px_12px_rgba(0,0,0,0.04)] mt-6">
                <button id="btn-submit" type="submit" form="form-servicio"
                    class="w-full text-white bg-[#54A6D8] hover:bg-sky-600 font-bold rounded-2xl text-base px-5 py-4 text-center shadow-lg shadow-blue-200 hover:shadow-blue-300 transform active:scale-[0.99] transition-all flex items-center justify-center gap-2 [&>*]:pointer-events-none">
                    <span id="btn-text">Publicar Servicio</span>
                    <?= icon('arrow-right', 'w-5 h-5') ?>
                </button>
            </div>

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
    warning: document.getElementById('security-warning'),
    btnSubmit: document.getElementById('btn-submit'),
    bar: document.getElementById('barra-progreso'),
    scoreText: document.getElementById('calidad-score'),
    countDesc: document.getElementById('descripcion-count'),
    countTitulo: document.getElementById('titulo-count')
};

document.getElementById('categoria')?.addEventListener('change', function() {
    if (typeof calcQuality === 'function') calcQuality();
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

// --- CÁLCULO DE CALIDAD (definido aquí para que setupPrecioFormat pueda llamarlo) ---
function calcQuality() {
    let score = 0;
    if (ui.titulo?.value.length >= 10) score += 25;
    if (ui.desc?.value.length >= 50) score += 25;
    if (document.getElementById('categoria')?.value) score += 30;
    
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

    window.precioEsValido = function() {
        return parseInt(hidden.value || '0', 10) >= 10000;
    };
})();

window.toggleAcordeonPublicar = function(nombre, forzarAbierto) {
    const body = document.getElementById('acordeon-body-' + nombre);
    const chevron = document.getElementById('acordeon-chevron-' + nombre);
    if (!body) return;
    const abrir = forzarAbierto === true ? true : body.classList.contains('hidden');
    if (abrir) {
        body.classList.remove('hidden');
        chevron?.classList.add('rotate-180');
    } else {
        body.classList.add('hidden');
        chevron?.classList.remove('rotate-180');
    }
};

// Listeners de calidad para los demás campos
['titulo', 'descripcion'].forEach(id =>
    document.getElementById(id)?.addEventListener('input', calcQuality)
);

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

// ── VIDEO DE PRESENTACIÓN (flujo A2: sube tras crear el servicio) ─────────────
(function () {
    const CSRF_VIDEO   = <?= json_encode($csrf_token_editar) ?>;
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
    const elForm         = document.getElementById('form-servicio');
    const elBtnSubmit    = document.getElementById('btn-submit');
    const elBtnText      = document.getElementById('btn-text');

    if (!elInput) return; // sección de video no renderizada (estado de error/límite)

    let archivoListo = false;
    let capturaThumbBlob = null;

    elInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        archivoListo = false;
        capturaThumbBlob = null;
        ocultarError();

        const tiposOk = ['video/mp4', 'video/webm', 'video/quicktime'];
        if (!tiposOk.includes(file.type)) {
            mostrarError('Formato no válido. Usa MP4, WebM o MOV.'); this.value = ''; return;
        }
        if (file.size > MAX_BYTES) {
            const pesoMB = (file.size / (1024 * 1024)).toFixed(1);
            mostrarError('Tu video pesa ' + pesoMB + ' MB, el máximo permitido es 30 MB. Comprime el video e intenta de nuevo.'); this.value = ''; return;
        }

        const objURL = URL.createObjectURL(file);
        const tmp    = document.createElement('video');
        tmp.preload  = 'metadata';
        tmp.src      = objURL;

        tmp.addEventListener('loadedmetadata', function () {
            if (this.duration > MAX_SEG) {
                URL.revokeObjectURL(objURL);
                mostrarError('El video dura ' + Math.ceil(this.duration) + ' s. Máximo 45 segundos.');
                elInput.value = ''; return;
            }
            if (this.videoWidth > 0 && this.videoWidth >= this.videoHeight) {
                URL.revokeObjectURL(objURL);
                mostrarError('El video debe ser vertical (9:16). Grábalo con el celular en modo retrato.');
                elInput.value = ''; return;
            }
            archivoListo = true;
            elPreviewVid.src = URL.createObjectURL(file);
            elPreviewInfo.textContent = file.name + ' · ' + (file.size / (1024 * 1024)).toFixed(1) + ' MB · ' + Math.ceil(this.duration) + ' s';
            elDropIcon.classList.add('hidden');
            elPreviewWrap.classList.remove('hidden');
            elPreviewWrap.classList.add('flex');

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
            mostrarError('No se pudo leer el video. Asegúrate de que el archivo no esté dañado.');
            elInput.value = '';
        });
    });

    window.quitarVideoPublicar = function () {
        elInput.value    = '';
        archivoListo     = false;
        capturaThumbBlob = null;
        elPreviewVid.src = '';
        elPreviewWrap.classList.add('hidden');
        elPreviewWrap.classList.remove('flex');
        elDropIcon.classList.remove('hidden');
        ocultarError();
    };

    // Intercepta submit SIEMPRE: precio y horario son obligatorios, con o sin video.
    elForm && elForm.addEventListener('submit', function (e) {
        e.preventDefault();

        function mostrarToastErrorTop(msg) {
            const prev = document.getElementById('toast');
            if (prev) prev.remove();
            const t = document.createElement('div');
            t.id = 'toast';
            t.className = 'mb-6 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm bg-red-50 text-red-700 border border-red-200';
            t.innerHTML = '<span class="text-sm font-bold flex-1">' + msg + '</span><button type="button" onclick="this.parentElement.remove()" class="text-sm opacity-70">✕</button>';
            const card = elForm.querySelector('.bg-white');
            if (card) card.prepend(t);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Si hay video pero falta consentimiento o banco, dejar que esos listeners muestren su propio error.
        const bancoId = document.getElementById('imagen_banco_id')?.value;
        if (archivoListo && (!elConsent.checked || !bancoId)) return;

        // 1. Validar precio y horario ANTES de enviar nada.
        if (!window.precioEsValido || !window.precioEsValido()) {
            document.getElementById('precio-error')?.classList.remove('hidden');
            document.getElementById('precio_visible')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        document.getElementById('precio-error')?.classList.add('hidden');

        const horario = window.serializarHorarioGrilla ? window.serializarHorarioGrilla() : { json: null, error: 'No se pudo leer el horario.' };
        if (horario.error) {
            const errEl = document.getElementById('horario-error');
            if (errEl) { errEl.textContent = horario.error; errEl.classList.remove('hidden'); }
            window.toggleAcordeonPublicar('horario', true);
            document.getElementById('seccion-horario')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        document.getElementById('horario-error')?.classList.add('hidden');

        // 2. Ambas validaciones pasaron → deshabilitar botón y crear el servicio.
        elBtnSubmit.disabled = true;
        if (elBtnText) elBtnText.textContent = 'Publicando...';

        const fd = new FormData(elForm);

        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                const doc   = new DOMParser().parseFromString(html, 'text/html');
                const svcEl = doc.getElementById('nubira-new-svc-id');
                const svcId = svcEl ? parseInt(svcEl.value, 10) : 0;

                if (!svcId) {
                    // Servicio no creado: extraer mensaje de error del server y mostrarlo
                    const toastEl = doc.querySelector('#toast span.flex-1') || doc.querySelector('#toast');
                    const errMsg  = toastEl ? toastEl.textContent.trim() : 'Error al publicar el servicio. Intenta de nuevo.';
                    elBtnSubmit.disabled = false;
                    if (elBtnText) elBtnText.textContent = 'Publicar Servicio';
                    mostrarToastErrorTop(errMsg);
                    return;
                }

                // 3. Servicio creado → guardar horario inmediatamente vía AJAX.
                if (elBtnText) elBtnText.textContent = 'Guardando horario...';

                const hfd = new FormData();
                hfd.append('servicio_id', svcId);
                hfd.append('horarios_json', horario.json);

                return fetch('/app/guardar_horario_servicio.php', { method: 'POST', body: hfd })
                    .then(function (r) { return r.json(); })
                    .then(function (resHorario) {
                        if (resHorario.success) {
                            // 5. Todo salió bien → continuar (video si corresponde, o éxito directo).
                            return continuarTrasHorario(doc, svcId, bancoId);
                        }

                        // 4. Guardado de horario falló → rollback.
                        const rfd = new FormData();
                        rfd.append('servicio_id', svcId);
                        return fetch('/app/eliminar_servicio_incompleto.php', { method: 'POST', body: rfd })
                            .then(function (r) { return r.json(); })
                            .then(function (resRollback) {
                                elBtnSubmit.disabled = false;
                                if (elBtnText) elBtnText.textContent = 'Publicar Servicio';
                                if (resRollback && resRollback.success) {
                                    mostrarToastErrorTop('No se pudo guardar el horario (' + (resHorario.error || 'error desconocido') + '). Tu servicio NO quedó publicado — completa el formulario de nuevo.');
                                } else {
                                    mostrarToastErrorTop('Hubo un problema al publicar tu servicio y no pudimos revertirlo automáticamente. Contacta a soporte con este ID para que lo revisemos: #' + svcId);
                                }
                            })
                            .catch(function () {
                                elBtnSubmit.disabled = false;
                                if (elBtnText) elBtnText.textContent = 'Publicar Servicio';
                                mostrarToastErrorTop('Hubo un problema al publicar tu servicio y no pudimos revertirlo automáticamente. Contacta a soporte con este ID para que lo revisemos: #' + svcId);
                            });
                    });
            })
            .catch(function () {
                elBtnSubmit.disabled = false;
                if (elBtnText) elBtnText.textContent = 'Publicar Servicio';
            });

        function continuarTrasHorario(doc, svcId, bancoId) {
            // Horario guardado. Si no hay video, terminamos con el mensaje de éxito normal.
            if (!archivoListo || !elConsent.checked || !bancoId) {
                setTimeout(function () { window.location.href = '/clases-servicios'; }, 700);
                return;
            }

            // Servicio + horario listos → mostrar el toast verde de éxito del server antes de subir video
            const toastOk = doc.querySelector('#toast');
            if (toastOk) {
                const prev = document.getElementById('toast');
                if (prev) prev.remove();
                const t = document.createElement('div');
                t.id = 'toast';
                t.className = toastOk.className;
                t.innerHTML = toastOk.innerHTML;
                const card = elForm.querySelector('.bg-white');
                if (card) card.prepend(t);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            if (elBtnText) elBtnText.textContent = 'Subiendo video...';
            if (elProgressWrap) elProgressWrap.classList.remove('hidden');

            const vfd = new FormData();
            vfd.append('video',               elInput.files[0]);
            vfd.append('servicio_id',         svcId);
            vfd.append('csrf_token',          CSRF_VIDEO);
            vfd.append('consentimiento_rrss', '1');
            if (capturaThumbBlob) {
                vfd.append('thumb', capturaThumbBlob, 'thumb.jpg');
            }

            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) return;
                const pct = Math.round((ev.loaded / ev.total) * 100);
                if (elProgressBar) elProgressBar.style.width = pct + '%';
                if (elProgressPct) elProgressPct.textContent = pct + '%';
            });

            function redirigir(ok, id) {
                if (ok) {
                    if (elProgressBar) { elProgressBar.style.width = '100%'; elProgressBar.style.background = '#10b981'; }
                    if (elProgressPct) elProgressPct.textContent = '100%';
                    setTimeout(function () { window.location.href = '/clases-servicios'; }, 700);
                } else {
                    // Servicio y horario ya quedaron guardados; solo el video falló → reintentar en editar
                    setTimeout(function () { window.location.href = '/editar-servicio?id=' + id; }, 700);
                }
            }

            xhr.addEventListener('load', function () {
                let res = {};
                try { res = JSON.parse(xhr.responseText); } catch (ex) {}
                redirigir(xhr.status === 200 && res.ok, svcId);
            });
            xhr.addEventListener('error',   function () { redirigir(false, svcId); });
            xhr.addEventListener('timeout', function () { redirigir(false, svcId); });

            xhr.timeout = 120000;
            xhr.open('POST', '/subir-video-servicio');
            xhr.send(vfd);
        }
    });

    function mostrarError(msg) {
        if (!elErrorMsg || !elError) return;
        elErrorMsg.textContent = msg;
        elError.classList.remove('hidden');
        elError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function ocultarError() { if (elError) elError.classList.add('hidden'); }
}());
</script>

</body>
</html>