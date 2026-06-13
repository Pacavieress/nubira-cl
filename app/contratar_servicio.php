<?php
/**
 * VISTA: CONTRATAR SERVICIO (CHECKOUT NUBIRA 2.0)
 * OBJETIVO: Conversión, Seguridad y Claridad
 * 
 * Mejoras aplicadas en esta versión:
 * - Doble input name="monto" eliminado (bug de cobro)
 * - mb_substr + preg_split para nombres UTF-8 safe
 * - die() reemplazados por flash_error + redirect
 * - HTTP_REFERER eliminado del botón volver (XSS-safe)
 * - Bloque inline ?error= eliminado (ahora vía toast/flash)
 * - Emoji 🔥 reemplazado por icon() del sistema Nubira
 * - Botón submit siempre azul Nubira (identidad consistente)
 * - hover:scale-[1.05] estandarizado a 1.01
 * - Container #toast-container duplicado eliminado (lo provee header)
 */
session_start();

// 1. CONFIGURACIÓN Y SEGURIDAD
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Verificación de Sesión
if (!isset($_SESSION['usuario_id'])) { 
    $_SESSION['redirigir_despues_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /login'); exit; 
}

// Sistema de Rutas Robusto
$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    $app_dir = dirname(__DIR__) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';
require_once $app_dir . '/helpers/institucion.php'; // institucion_tutor()

// 2. DATOS DE USUARIO Y SERVICIO
$usuario_id = (int)$_SESSION['usuario_id'];
$servicio_id = (int)($_GET['servicio_id'] ?? 0);

if ($servicio_id <= 0) { header("Location: /vitrina"); exit; }

// Consulta optimizada [INYECCIÓN NUBIRA SUBSIDIOS]
$stmt = $conn->prepare("SELECT s.id, s.titulo, s.alumno_id, s.precio, s.precio_oferta, s.cupos_oferta, s.is_subvencionado, s.modalidad, s.categoria, s.imagen, s.horarios_json,
                        a.nombre as nombre_vendedor, a.institucion 
                        FROM servicios s 
                        JOIN alumnos a ON s.alumno_id = a.id 
                        WHERE s.id = ? AND s.estado = 'aprobado' LIMIT 1");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$serv = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Validaciones de Lógica de Negocio (con flash messages)
if (!$serv) { 
    $_SESSION['flash_error'] = 'Este servicio ya no está disponible.';
    header('Location: /vitrina'); 
    exit; 
}
if ($serv['alumno_id'] == $usuario_id) { 
    $_SESSION['flash_warning'] = 'No puedes contratar tu propio servicio.';
    header('Location: /vitrina'); 
    exit; 
}

// --- PRIVACIDAD DE NOMBRE (UTF-8 safe) ---
$nombre_raw = trim($serv['nombre_vendedor']);
$partes_nombre = preg_split('/\s+/u', $nombre_raw, -1, PREG_SPLIT_NO_EMPTY);
$vendedor_display = htmlspecialchars($partes_nombre[0] ?? 'Tutor Nubira', ENT_QUOTES, 'UTF-8');
if (count($partes_nombre) > 1) {
    $vendedor_display .= ' ' . htmlspecialchars(mb_substr($partes_nombre[1], 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '.';
}

// Lógica de Precio [INYECCIÓN NUBIRA SUBSIDIOS]
$is_oferta = ($serv['is_subvencionado'] == 1 && $serv['cupos_oferta'] > 0);
$precioOriginal = (int)$serv['precio'];

if ($is_oferta) {
    $montoInicial = (int)$serv['precio_oferta'];
} else {
    $montoInicial = $precioOriginal;
}

// --- [INYECCIÓN NUBIRA] MOTOR DE VALIDACIÓN DE BECAS ---
$codigo_beca = isset($_GET['codigo_beca']) ? strtoupper(trim($_GET['codigo_beca'])) : '';
$montoFinal = $montoInicial;
$cupon_id = null;
$mensaje_beca = '';
$descuento_porcentaje = 0;
$error_beca = null;

if (!empty($codigo_beca) && $montoInicial > 0) {
    $stmt_cupon = $conn->prepare("SELECT id, porcentaje_descuento, usos_actuales, usos_maximos, fecha_expiracion, servicio_id FROM cupones WHERE codigo = ? LIMIT 1");
    if ($stmt_cupon) {
        $stmt_cupon->bind_param("s", $codigo_beca);
        $stmt_cupon->execute();
        $res_cupon = $stmt_cupon->get_result();
        
     if ($res_cupon->num_rows > 0) {
            $c = $res_cupon->fetch_assoc();
            $valido = true;
            
            // A. Control Stock
            if ($c['usos_maximos'] > 0 && $c['usos_actuales'] >= $c['usos_maximos']) {
                $valido = false;
                $error_beca = "La beca agotó sus usos.";
            }
            
            // B. Control Expiración
            if ($valido && !empty($c['fecha_expiracion'])) {
                date_default_timezone_set('America/Santiago');
                $hoy = date('Y-m-d');
                if ($hoy > $c['fecha_expiracion']) {
                    $valido = false;
                    $error_beca = "La beca ingresada está caducada.";
                }
            }
            
            // C. Alcance Automático (Nubira Shield Fix)
            if ($valido) {
                $es_global = is_null($c['servicio_id']) || (int)$c['servicio_id'] === 0;
                if (!$es_global && (int)$c['servicio_id'] !== $servicio_id) {
                    $valido = false;
                    $error_beca = "La beca ingresada es exclusiva para otro servicio.";
                }
            }
            
            // Aplicación Matemática
            if ($valido) {
                $cupon_id = $c['id'];
                $descuento_porcentaje = (int)$c['porcentaje_descuento'];
                $montoDescuento = ($montoInicial * $descuento_porcentaje) / 100;
                $montoFinal = max(0, $montoInicial - $montoDescuento); // Protege contra negativos
                $mensaje_beca = "Beca Nubira ($descuento_porcentaje%)";
            }
        } else {
            $error_beca = "El código de beca ingresado no existe.";
        }
        $stmt_cupon->close();
    }
}

// Imagen con fallback corregido
$img_nombre = basename($serv['imagen'] ?? '');
$ruta_img = '/upload/servicios/' . $img_nombre;
$imgSrc = (file_exists($_SERVER['DOCUMENT_ROOT'] . $ruta_img) && !empty($img_nombre)) 
          ? $ruta_img 
          : '/upload/servicios/default_clases.webp'; 

// CSRF Token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

$page_title = "Confirmar Contrato";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contratar | Nubira</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/webp" href="/img/logo2.webp">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
        
        /* [NUBIRA 2.0] Fix para que el input date funcione en todos los móviles */
        input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.5;
            transition: 0.2s;
            width: 20px;
            height: 20px;
        }
        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }
        input[type="date"] {
            min-height: 48px;
        }
    </style>
</head>

<body class="bg-white text-gray-900 antialiased overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
    <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<!-- [NUBIRA 2.0] Ocultar Header global en móvil para modo Checkout -->
<div class="hidden md:block">
    <?php if(file_exists($app_dir . '/componentes/header.php')) require_once $app_dir . '/componentes/header.php'; ?>
</div>
<?php if(file_exists($app_dir . '/componentes/sidebar.php')) require_once $app_dir . '/componentes/sidebar.php'; ?>

<main class="pt-4 md:pt-20 pb-32 md:pb-16 lg:ml-64 px-4 md:px-8">
    <!-- [NUBIRA 2.0] Topbar Nativo Móvil (Checkout Mode) -->
    <div class="md:hidden flex items-center justify-between mb-6 mt-1 max-w-[1000px] mx-auto">
        <button type="button" 
                onclick="window.history.length > 1 ? window.history.back() : window.location.href='/vitrina'"
                class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 hover:bg-gray-100 border border-gray-200/60 shadow-sm active:scale-95 transition-all"
                aria-label="Volver">
            <i class="fa-solid fa-arrow-left text-gray-700 text-[17px]"></i>
        </button>
        <div class="w-10 h-1.5 bg-gray-200 rounded-full"></div>
        <div class="w-10 h-10"></div>
    </div>

    <div class="w-full max-w-[1000px] mx-auto"> 
        
        <div class="mb-8 mt-2 md:mt-0">
            <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                <button type="button" 
                        onclick="window.history.length > 1 ? window.history.back() : window.location.href='/vitrina'"
                        class="hidden md:flex w-10 h-10 rounded-full bg-gray-100 hover:bg-gray-200 items-center justify-center text-gray-600 transition-all hover:scale-[1.01]"
                        aria-label="Volver">
                    <i class="fa-solid fa-chevron-left text-sm"></i>
                </button>
                Solicitar servicio
            </h1>
        </div>
        
        <?php if ($error_beca): ?>
            <div class="mb-6 p-4 rounded-2xl border bg-rose-50 border-rose-100 text-rose-800 text-sm font-bold flex items-center gap-3 animate-fade-in">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error_beca, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form action="/app/crear_contrato.php" method="POST" id="form-contrato">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="servicio_id" value="<?= $servicio_id ?>">
            <input type="hidden" name="vendedor_id" value="<?= (int)$serv['alumno_id'] ?>">
            <input type="hidden" name="precio_original" value="<?= $precioOriginal ?>">
            <input type="hidden" name="monto" value="<?= $montoFinal ?>">
           <?php if($cupon_id && !$error_beca): ?>
                <input type="hidden" name="codigo_beca" value="<?= htmlspecialchars($codigo_beca, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 relative">
                
                <div class="lg:col-span-7 space-y-8">
                    
                    <section>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Detalles del acuerdo</h3>
                        
                        <div class="mb-6">
  <div class="mb-6">
    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">Agenda tu clase</label>
    
    <?php
    // Procesar horarios del tutor (mismo formato que detalle_servicio.php)
    $horarios_tutor = null;
    $tiene_horarios = false;
    $dias_disponibles = [];
    $dia_proximo = null;

    if (!empty($serv['horarios_json'])) {
        $horarios_tutor = json_decode($serv['horarios_json'], true);
        if (is_array($horarios_tutor)) {
            $orden_dias = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
            foreach ($orden_dias as $dia) {
                if (!empty($horarios_tutor[$dia]) && count($horarios_tutor[$dia]) > 0) {
                    $dias_disponibles[$dia] = $horarios_tutor[$dia];
                }
            }
            if (count($dias_disponibles) > 0) {
                $tiene_horarios = true;
                date_default_timezone_set('America/Santiago');
                $hoy_index = (int)date('N') - 1;
                for ($i = 0; $i < 7; $i++) {
                    $check_dia = $orden_dias[($hoy_index + $i) % 7];
                    if (isset($dias_disponibles[$check_dia])) {
                        $dia_proximo = $check_dia;
                        break;
                    }
                }
            }
        }
    }
    ?>

    <?php if (!$tiene_horarios): ?>
        <!-- Sin horarios configurados -->
        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5 text-center">
            <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mx-auto mb-3 border border-amber-100 text-amber-500">
                <i class="fa-regular fa-calendar-xmark text-xl"></i>
            </div>
            <p class="text-sm font-bold text-gray-800 mb-1">Este servicio aún no acepta reservas en línea</p>
            <p class="text-xs text-gray-500 max-w-sm mx-auto mb-4">El tutor está completando su disponibilidad. Puedes contactarlo directamente para coordinar.</p>
            <button type="submit" form="form-chat-sticky"
                    class="inline-flex items-center gap-2 bg-[#54A6D8] hover:bg-blue-600 text-white font-bold text-xs px-5 py-2.5 rounded-full transition-all">
                <i class="fa-regular fa-comments"></i> Contactar al tutor por chat
            </button>
        </div>
    <?php else: ?>
        <!-- Grilla de días (idéntica a detalle_servicio.php) -->
        <div id="agenda-wrapper" data-servicio-id="<?= $servicio_id ?>">
            
            <div class="mb-4 flex items-center gap-2">
                <div class="inline-flex items-center gap-1.5 bg-emerald-50 border border-emerald-100 rounded-full px-3 py-1">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                    <span class="text-[11px] font-bold text-emerald-700">
                        Disponible <?= count($dias_disponibles) ?> día<?= count($dias_disponibles) > 1 ? 's' : '' ?> a la semana
                    </span>
                </div>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <?php foreach ($dias_disponibles as $dia => $bloques):
                    $es_proximo = ($dia === $dia_proximo);
                ?>
                    <button type="button"
                            class="dia-card text-left bg-white border <?= $es_proximo ? 'border-[#54A6D8] ring-2 ring-blue-100' : 'border-blue-100' ?> rounded-xl p-3 hover:border-[#54A6D8] hover:shadow-md transition-all group relative"
                            data-dia="<?= $dia ?>">
                        
                        <?php if ($es_proximo): ?>
                            <span class="absolute -top-2 -right-2 bg-[#54A6D8] text-white text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full shadow-sm">
                                Próximo
                            </span>
                        <?php endif; ?>
                        
                        <p class="text-xs font-extrabold <?= $es_proximo ? 'text-[#54A6D8]' : 'text-gray-800' ?> mb-2 group-hover:text-[#54A6D8] transition-colors">
                            <?= $dia ?>
                        </p>
                        
                        <div class="flex flex-col gap-1.5">
                            <?php foreach ($bloques as $h): ?>
                                <span class="bg-blue-50 text-[#54A6D8] text-[10px] font-bold px-2 py-1 rounded-md text-center border border-blue-100/50 truncate">
                                    <?= htmlspecialchars($h) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Selector de slot (aparece cuando elige un día) -->
            <div id="slots-section" class="hidden mt-6 pt-6 border-t border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">
                    Elige una hora para <span id="slots-dia-label" class="text-[#54A6D8]"></span>
                </p>
                
                <!-- Selector de fecha específica (próximas 4 ocurrencias del día elegido) -->
                <div id="fechas-strip" class="flex gap-2 overflow-x-auto pb-3 no-scrollbar mb-4"></div>
                
                <div id="slots-grid" class="grid grid-cols-3 sm:grid-cols-4 gap-2"></div>
                
                <div id="slots-loading" class="hidden text-center py-6">
                    <div class="inline-block animate-spin h-6 w-6 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
                </div>
                
                <div id="slots-empty" class="hidden bg-gray-50 border border-dashed border-gray-200 rounded-xl p-5 text-center">
                    <p class="text-xs text-gray-500">No hay horarios disponibles para esta fecha.</p>
                </div>
            </div>
            
            <!-- Confirmación slot elegido -->
            <div id="slot-confirmado" class="hidden mt-4 bg-blue-50 border border-blue-100 rounded-xl p-3 flex items-center gap-3">
                <div class="w-8 h-8 bg-[#54A6D8] text-white rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-check text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-bold text-gray-900" id="slot-confirmado-texto"></p>
                    <p class="text-[10px] text-gray-500">Tu clase quedará reservada exclusivamente a esta hora.</p>
                </div>
            </div>
        </div>
        
        <input type="hidden" name="fecha_clase" id="input-fecha-clase" required>
    <?php endif; ?>
</div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Mensaje inicial (Opcional)</label>
                            <textarea name="notas" rows="4" 
                                     class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-[16px] md:text-sm focus:ring-2 focus:ring-[#54A6D8] focus:bg-white transition outline-none resize-none"
                                      placeholder="Hola, me interesa tu servicio para..."></textarea>
                        </div>
                    </section>

                    <hr class="border-gray-100">

                    <section>
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Monto a pagar</h3>
                        
                        <div class="bg-white border-2 border-gray-100 rounded-2xl p-6 hover:border-blue-100 transition-colors shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                                <?= icon('money', 'w-24 h-24 text-[#54A6D8]') ?>
                            </div>
                            
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Precio del servicio (CLP)</label>
                            <div class="flex items-center gap-2">
                                <span class="text-3xl font-extrabold text-gray-400">$</span>
                                <span class="text-4xl font-extrabold text-gray-900"><?= number_format($montoInicial, 0, ',', '.') ?></span>
                            </div>
                            
                            <?php if($is_oferta): ?>
                                <div class="mt-3 inline-flex items-center gap-1.5 bg-gradient-to-r from-orange-50 to-orange-100/50 border border-orange-200 px-3 py-2 rounded-lg">
                                    <span class="text-orange-500"><?= icon('fire', 'w-4 h-4') ?></span>
                                    <span class="text-xs text-orange-700 font-bold">Descuento aplicado.</span>
                                </div>
                            <?php elseif($montoInicial == 0): ?>
                                <p class="text-xs text-emerald-600 font-bold mt-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-gift"></i> ¡Este servicio es totalmente gratuito!
                                </p>
                            <?php else: ?>
                                <p class="text-xs text-gray-400 font-medium mt-2 flex items-center gap-1.5">
                                    <i class="fa-solid fa-lock text-[10px]"></i> Precio fijo establecido por el vendedor
                                </p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div class="lg:col-span-5 relative">
                    <div class="sticky top-24">
                        <div class="bg-white rounded-3xl border border-gray-200 shadow-xl shadow-gray-200/50 overflow-hidden">
                            
                            <div class="p-6 border-b border-gray-50 flex gap-4">
                                <img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>" class="w-20 h-20 rounded-xl object-cover border border-gray-100 bg-gray-50" alt="Servicio">
                                <div>
                                    <p class="text-[10px] font-bold text-[#54A6D8] uppercase tracking-wide mb-1">Contratando a</p>
                                    <h4 class="font-bold text-gray-900 leading-tight mb-1 line-clamp-2"><?= $vendedor_display ?></h4>
                                    <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars(institucion_tutor($serv['institucion'] ?? '', false), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>

                            <div class="p-6 bg-gray-50/50">
                                <h3 class="font-bold text-gray-900 text-sm mb-4"><?= htmlspecialchars($serv['titulo'], ENT_QUOTES, 'UTF-8') ?></h3>
                                
                                <div class="flex justify-between items-center text-sm text-gray-600 mb-2">
                                    <span>Modalidad</span>
                                    <span class="font-medium text-gray-900"><?= ucfirst(htmlspecialchars($serv['modalidad'], ENT_QUOTES, 'UTF-8')) ?></span>
                                </div>
                                <div class="flex justify-between items-center text-sm text-gray-600 mb-2">
                                    <span>Categoría</span>
                                    <span class="font-medium text-gray-900"><?= htmlspecialchars($serv['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                
                                <?php if ($cupon_id): ?>
                                    <div class="space-y-2 mb-6">
                                        <div class="flex justify-between items-center text-sm text-gray-500 mt-4">
                                            <span>Subtotal</span>
                                            <span class="font-bold line-through">$<?= number_format($montoInicial,0,',','.') ?></span>
                                        </div>
                                        <div class="flex justify-between items-center text-sm text-emerald-600 font-bold bg-emerald-50 px-3 py-2 rounded-lg border border-emerald-100">
                                            <span><?= htmlspecialchars($mensaje_beca, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span>-$<?= number_format($montoInicial - $montoFinal, 0, ',', '.') ?></span>
                                        </div>
                                        <div class="border-t border-gray-200 my-3 border-dashed"></div>
                                        <div class="flex justify-between items-center">
                                            <span class="font-bold text-gray-900">Total a Pagar</span>
                                            <span class="font-extrabold text-2xl text-[#54A6D8] tracking-tight">$<?= number_format($montoFinal,0,',','.') ?></span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="border-t border-gray-200 my-4 border-dashed"></div>
                                    <div class="flex justify-between items-center mb-6">
                                        <span class="font-bold text-gray-900">Total (CLP)</span>
                                        <span class="font-extrabold text-2xl text-[#54A6D8]">$<?= number_format($montoFinal,0,',','.') ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- [NUBIRA 2.0] Botón de Acción Principal -->
                                <?php if (!$tiene_horarios): ?>
                                <button type="submit" form="form-chat-sticky"
                                        class="w-full text-white font-bold py-4 rounded-2xl transition-all shadow-lg hover:shadow-blue-200 flex items-center justify-center gap-2 bg-[#54A6D8] hover:bg-blue-600 active:scale-[0.98]">
                                    <i class="fa-regular fa-comments text-sm opacity-80"></i>
                                    <span>Contactar al tutor</span>
                                </button>
                                <p class="text-[10px] text-gray-400 text-center mt-4 leading-relaxed">
                                    El tutor coordinará contigo el horario por chat.
                                </p>
                                <?php else: ?>
                                <button type="submit" id="btn-submit" disabled class="w-full text-white font-bold py-4 rounded-xl transition-all shadow-lg hover:shadow-blue-200 transform active:scale-[0.98] flex items-center justify-center gap-2 bg-[#54A6D8] hover:bg-blue-600 disabled:bg-gray-300 disabled:cursor-not-allowed disabled:shadow-none">
                                    <span><?= $montoFinal == 0 ? 'Canjear Servicio Gratis' : 'Confirmar y Pagar' ?></span>
                                    <i class="fa-solid <?= $montoFinal == 0 ? 'fa-gift' : 'fa-lock' ?> text-sm opacity-80" id="btn-icon"></i>
                                </button>
                                <p class="text-[10px] text-gray-400 text-center mt-4 leading-relaxed">
                                    No se te cobrará nada todavía. Al confirmar, se abrirá un chat seguro. El pago quedará en custodia hasta que recibas el servicio.
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mt-6 flex items-start gap-3 px-4">
                            <div class="text-emerald-500 mt-0.5"><?= icon('shield-check', 'w-5 h-5') ?></div>
                            <div>
                                <p class="text-xs font-bold text-gray-900">Protección al Estudiante Nubira</p>
                                <p class="text-[10px] text-gray-500 leading-tight mt-1">Si el servicio no se entrega o no cumple lo acordado, te devolvemos el dinero íntegramente.</p>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </form>

        <?php if (!$tiene_horarios): ?>
        <form id="form-chat-sticky" class="form-chat-sin-horarios" action="/app/iniciar_chat.php" method="POST">
            <input type="hidden" name="servicio_id" value="<?= $servicio_id ?>">
            <input type="hidden" name="mensaje_inicial" value="">
        </form>
        <?php endif; ?>

    </div>
</main>

<?php 
// [NUBIRA 2.0] nav_bottom eliminado intencionalmente para forzar el "Modo Túnel" en el Checkout Móvil
if(file_exists($app_dir . '/componentes/modal_publicar.php')) require_once $app_dir . '/componentes/modal_publicar.php'; 
if(file_exists($app_dir . '/componentes/modal_explora.php')) require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
    // Loader
    window.onload = () => { 
        const l = document.getElementById('loader'); 
        if(l) { l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } 
    };

    // Modal System
    function setupModal(t,m,c,x){const b=document.getElementById(t),o=document.getElementById(m),r=document.getElementById(c),l=document.getElementById(x);if(!b||!o)return;const open=()=>{o.classList.remove('hidden');requestAnimationFrame(()=>r.classList.remove('translate-y-full','opacity-0'));document.body.style.overflow='hidden'};const shut=()=>{r.classList.add('translate-y-full','opacity-0');setTimeout(()=>{o.classList.add('hidden');document.body.style.overflow=''},300)};b.onclick=(e)=>{e.preventDefault();open()};l.onclick=shut;o.onclick=(e)=>{if(e.target===o)shut()}}
    setupModal('btn-publicar','modal-quick','quick-card','quick-close');
    setupModal('btn-explora','modal-explora','explora-card','explora-close');

    // =========================================================
    // [NUBIRA 2.0] CALENDARIO + SLOTS (estilo Calendly)
    // =========================================================
   // =========================================================
    // [NUBIRA 2.0] AGENDA: Grilla de días + Slots por hora
    // =========================================================
    (function initAgenda() {
        const wrapper = document.getElementById('agenda-wrapper');
        if (!wrapper) return;

        const servicioId = wrapper.dataset.servicioId;
        const slotsSection = document.getElementById('slots-section');
        const slotsDiaLabel = document.getElementById('slots-dia-label');
        const fechasStrip = document.getElementById('fechas-strip');
        const slotsGrid = document.getElementById('slots-grid');
        const slotsLoad = document.getElementById('slots-loading');
        const slotsEmpty = document.getElementById('slots-empty');
        const inputFecha = document.getElementById('input-fecha-clase');
        const btnSubmit = document.getElementById('btn-submit');
        const slotConf = document.getElementById('slot-confirmado');
        const slotConfTxt = document.getElementById('slot-confirmado-texto');

        const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const diasMap = { 'Lunes':1,'Martes':2,'Miércoles':3,'Jueves':4,'Viernes':5,'Sábado':6,'Domingo':0 };

        let diaSeleccionado = null;
        let fechaActiva = null;

        // Click en una card de día → mostrar próximas 4 fechas reales y cargar slots
        document.querySelectorAll('.dia-card').forEach(card => {
            card.addEventListener('click', () => {
                document.querySelectorAll('.dia-card').forEach(c => {
                    c.classList.remove('ring-2','ring-blue-100','border-[#54A6D8]','bg-blue-50');
                    c.classList.add('border-blue-100');
                });
                card.classList.remove('border-blue-100');
                card.classList.add('ring-2','ring-blue-100','border-[#54A6D8]','bg-blue-50');

                diaSeleccionado = card.dataset.dia;
                slotsDiaLabel.textContent = diaSeleccionado.toLowerCase();
                slotsSection.classList.remove('hidden');
                inputFecha.value = '';
                btnSubmit.disabled = true;
                slotConf.classList.add('hidden');

                renderFechasProximas(diasMap[diaSeleccionado]);
            });
        });

        // Genera próximas 4 fechas reales del día elegido (ej: próximos 4 lunes)
        function renderFechasProximas(targetDow) {
            fechasStrip.innerHTML = '';
            const hoy = new Date();
            const fechas = [];
            let cursor = new Date(hoy);

            while (fechas.length < 4) {
                if (cursor.getDay() === targetDow && cursor >= hoy) {
                    fechas.push(new Date(cursor));
                }
                cursor.setDate(cursor.getDate() + 1);
                if (fechas.length >= 4) break;
            }

            fechas.forEach((d, idx) => {
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                const fechaStr = `${yyyy}-${mm}-${dd}`;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'flex-shrink-0 w-20 py-2.5 rounded-xl border border-gray-200 bg-white hover:border-[#54A6D8] transition-all text-center fecha-btn';
                btn.dataset.fecha = fechaStr;
                btn.innerHTML = `
                    <p class="text-[10px] font-bold text-gray-400 uppercase">${idx === 0 ? 'Próximo' : 'En ' + (idx*7) + ' días'}</p>
                    <p class="text-base font-extrabold text-gray-900 leading-tight">${d.getDate()} ${meses[d.getMonth()]}</p>
                `;
                btn.addEventListener('click', () => seleccionarFecha(btn));
                fechasStrip.appendChild(btn);
            });

            // Auto-seleccionar la primera fecha
            const first = fechasStrip.querySelector('.fecha-btn');
            if (first) seleccionarFecha(first);
        }

        function seleccionarFecha(btn) {
            fechasStrip.querySelectorAll('.fecha-btn').forEach(b => {
                b.classList.remove('border-[#54A6D8]','bg-blue-50','ring-2','ring-blue-100');
                b.classList.add('border-gray-200','bg-white');
            });
            btn.classList.remove('border-gray-200','bg-white');
            btn.classList.add('border-[#54A6D8]','bg-blue-50','ring-2','ring-blue-100');

            inputFecha.value = '';
            btnSubmit.disabled = true;
            slotConf.classList.add('hidden');
            fechaActiva = btn.dataset.fecha;
            cargarSlots(fechaActiva);
        }

        async function cargarSlots(fecha) {
            slotsGrid.innerHTML = '';
            slotsEmpty.classList.add('hidden');
            slotsLoad.classList.remove('hidden');

            try {
                const res = await fetch(`/app/api/slots_disponibles.php?servicio_id=${servicioId}&fecha=${fecha}`);
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                slotsLoad.classList.add('hidden');

                if (!data.slots || data.slots.length === 0) {
                    slotsEmpty.classList.remove('hidden');
                    return;
                }
                renderSlots(data.slots);
            } catch (e) {
                console.error('Error cargando slots:', e);
                slotsLoad.classList.add('hidden');
                slotsEmpty.classList.remove('hidden');
            }
        }

        function renderSlots(slots) {
            let html = '';
            slots.forEach(slot => {
                const dis = slot.disponible;
                const cls = dis 
                    ? 'slot-btn bg-white border border-gray-200 text-gray-900 hover:border-[#54A6D8] hover:bg-blue-50 cursor-pointer'
                    : 'bg-gray-50 border border-gray-100 text-gray-300 cursor-not-allowed line-through';
                html += `
                    <button type="button" 
                            class="${cls} py-2.5 rounded-xl text-sm font-bold transition-all"
                            ${dis ? `data-datetime="${slot.datetime}" data-hora="${slot.hora}"` : 'disabled'}>
                        ${slot.hora}
                    </button>
                `;
            });
            slotsGrid.innerHTML = html;

            slotsGrid.querySelectorAll('.slot-btn').forEach(b => {
                b.addEventListener('click', () => seleccionarSlot(b));
            });
        }

        function seleccionarSlot(btn) {
            slotsGrid.querySelectorAll('.slot-btn').forEach(b => {
                b.classList.remove('bg-[#54A6D8]','text-white','border-[#54A6D8]');
                b.classList.add('bg-white','text-gray-900','border-gray-200');
            });
            btn.classList.remove('bg-white','text-gray-900','border-gray-200');
            btn.classList.add('bg-[#54A6D8]','text-white','border-[#54A6D8]');

            inputFecha.value = btn.dataset.datetime;
            btnSubmit.disabled = false;

            const fObj = new Date(btn.dataset.datetime.replace(' ', 'T'));
            const diasL = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            const mesesL = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            const txt = `${diasL[fObj.getDay()]} ${fObj.getDate()} de ${mesesL[fObj.getMonth()]} a las ${btn.dataset.hora}`;
            slotConfTxt.textContent = txt.charAt(0).toUpperCase() + txt.slice(1);
            slotConf.classList.remove('hidden');
        }
    })();

    document.querySelectorAll('.form-chat-sin-horarios').forEach(function(form) {
        form.addEventListener('submit', function() {
            var nota = document.querySelector('textarea[name="notas"]');
            var hidden = form.querySelector('input[name="mensaje_inicial"]');
            if (nota && hidden) hidden.value = nota.value.trim();
        });
    });
</script>

</body>
</html>