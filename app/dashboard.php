<?php
/**
 * VISTA: DASHBOARD (MI PANEL)
 * UBICACIÓN: public_html/app/dashboard.php
 * ESTADO: PROMEDIO PONDERADO (LEGACY + NUBIRA 2.0)
 */

// 1. CONFIGURACIÓN
ini_set('display_errors', 0); // En producción 0
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$app_dir = __DIR__;
if (!file_exists($app_dir . '/conexion.php')) {
    $app_dir = dirname(__DIR__) . '/app';
}
require_once $app_dir . '/conexion.php';
require_once $app_dir . '/iconos.php';

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /login");
    exit;
}

// 3. DATOS DE SESIÓN
$uid = (int)$_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['usuario_nombre'] ?? 'Estudiante';
$rol            = $_SESSION['rol']            ?? 'alumno';
$es_admin       = ($rol === 'admin');

// ==========================================================================
// 4. LÓGICA DE REPUTACIÓN: FUSIÓN DE DATOS (NUEVO + ANTIGUO)
// ==========================================================================

// Variables base
$vendedor_final_nota = 0.0;
$vendedor_final_qty  = 0;
$comprador_final_nota = 0.0;
$comprador_final_qty  = 0;

// --- A. OBTENER DATOS HISTÓRICOS (LEGACY) ---
// Asumimos que todo el historial antiguo corresponde a méritos de VENDEDOR.
$leg_nota = 0.0; 
$leg_qty  = 0;

$stmt_old = $conn->prepare("SELECT calificacion_promedio, cantidad_votos FROM alumnos WHERE id = ?");
if ($stmt_old) {
    $stmt_old->bind_param("i", $uid);
    $stmt_old->execute();
    $res_old = $stmt_old->get_result()->fetch_assoc();
    $stmt_old->close();
    if ($res_old && $res_old['cantidad_votos'] > 0) {
        $leg_nota = (float)$res_old['calificacion_promedio'];
        $leg_qty  = (int)$res_old['cantidad_votos'];
    }
}

// --- B. OBTENER DATOS NUEVOS (TABLA VALORACIONES) ---
$new_v_nota = 0.0; $new_v_qty = 0; // Nuevos como Vendedor
$new_c_nota = 0.0; $new_c_qty = 0; // Nuevos como Comprador

try {
    // 1. Nuevas notas de Vendedor
    $sql_v = "SELECT AVG(calificacion) as p, COUNT(*) as c FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor'";
    $stmt_v = $conn->prepare($sql_v);
    $stmt_v->bind_param("i", $uid);
    $stmt_v->execute();
    $r_v = $stmt_v->get_result()->fetch_assoc();
    $stmt_v->close();
    if($r_v) { $new_v_nota = (float)$r_v['p']; $new_v_qty = (int)$r_v['c']; }

    // 2. Nuevas notas de Comprador
    $sql_c = "SELECT AVG(calificacion) as p, COUNT(*) as c FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'comprador'";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("i", $uid);
    $stmt_c->execute();
    $r_c = $stmt_c->get_result()->fetch_assoc();
    $stmt_c->close();
    if($r_c) { $new_c_nota = (float)$r_c['p']; $new_c_qty = (int)$r_c['c']; }

} catch (Exception $e) { /* Silencio */ }

// --- C. CALCULO MATEMÁTICO FINAL (PROMEDIO PONDERADO) ---

// 1. Calcular Vendedor Final (Histórico + Nuevo)
$vendedor_final_qty = $leg_qty + $new_v_qty; // Suma de votos

if ($vendedor_final_qty > 0) {
    // (NotaVieja * CantidadVieja) + (NotaNueva * CantidadNueva)
    $suma_puntos = ($leg_nota * $leg_qty) + ($new_v_nota * $new_v_qty);
    // Dividido por el total de votos
    $vendedor_final_nota = $suma_puntos / $vendedor_final_qty;
}

// 2. Calcular Comprador Final (Solo Nuevo)
$comprador_final_nota = $new_c_nota;
$comprador_final_qty  = $new_c_qty;


// --- D. PREPARAR DISPLAY ---
$display_rate_vendedor  = ($vendedor_final_qty > 0)  ? number_format($vendedor_final_nota, 1)  : 'Nuevo';
$display_rate_comprador = ($comprador_final_qty > 0) ? number_format($comprador_final_nota, 1) : 'Nuevo';

// Helper Estrellas
if (!function_exists('render_stars_html')) {
    function render_stars_html($promedio, $num_votos) {
        $html = '';
        if ($num_votos === 0) {
            for($i=1; $i<=5; $i++) $html .= '<i class="fa-regular fa-star text-gray-300 text-xs"></i>';
            return $html;
        }
        $estrellas_pintar = round($promedio);
        for($i=1; $i<=5; $i++) {
            $icon = ($i <= $estrellas_pintar) ? 'fa-solid fa-star text-yellow-400' : 'fa-regular fa-star text-gray-300';
            $html .= '<i class="'.$icon.' text-xs"></i>';
        }
        return $html;
    }
}

// 5. BADGES ADMIN
$pendientes_servicios = 0;
if ($es_admin) {
    $res_pend = @$conn->query("SELECT COUNT(*) AS total FROM servicios WHERE estado = 'pendiente'");
    if ($res_pend) $pendientes_servicios = (int) $res_pend->fetch_assoc()['total'];
}

// 6. HELPER ICONOS
function dash_icon($type) {
    $c = "w-5 h-5"; 
    switch ($type) {
        case 'apuntes':   return icon('publish-doc', $c);
        case 'servicios': return icon('publish-class', $c);
        case 'editar':    return icon('user', $c);
        // Iconos SVG Hardcodeados para asegurar visualización
        case 'compras':   return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>';
        case 'ventas':    return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        case 'contratos': return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>';
        case 'logout':    return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>';
        case 'reclamos':  return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>';
        case 'soporte':   return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>';
        
        // ADMIN
        case 'users':     return icon('user', $c);
        case 'admin-doc': return icon('publish-doc', $c);
        case 'admin-srv': return icon('publish-class', $c);
        case 'money':     return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>';
        case 'credit':    return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>';
        case 'globe':     return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" /></svg>';
        case 'building':  return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" /></svg>';
        case 'bell':      return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>';
        case 'photo':     return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008H12.75V7.5zm-1.5 0h.008v.008H11.25V7.5zm1.5 1.5h.008v.008H12.75V9zm-1.5 0h.008v.008H11.25V9z" /></svg>';
        case 'window':    return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z" /></svg>';
        case 'add-user':  return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" /></svg>';
        case 'shield':    return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" /></svg>';
        case 'chart':     return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>';
        case 'users-tag': return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>';
        case 'tags':      return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" /></svg>';
        case 'eye':       return '<svg class="'.$c.'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>';
        
        default: return icon('home', $c);
    }
}
$page_title = "Mi Panel";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Mi Panel | Nubira</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" type="image/webp" href="/img/logo2.webp">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; background-color: #ffffff; }
    /* Micro-interacción estilo Airbnb */
    .dash-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    .dash-card:hover { transform: translateY(-2px) scale(1.005); box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.08); }
  </style>
</head>

<body class="bg-gray-50 min-h-screen text-gray-800 font-sans overflow-x-hidden">

<div id="loader" class="fixed inset-0 bg-white/95 flex items-center justify-center z-[60] transition-opacity duration-300">
  <div class="animate-spin h-10 w-10 border-4 border-blue-200 border-t-[#54A6D8] rounded-full"></div>
</div>

<?php 
require_once $app_dir . '/componentes/header.php'; 
require_once $app_dir . '/componentes/sidebar.php'; 
?>

<main class="pt-20 pb-28 md:pb-16 md:ml-64 px-4 md:px-8 w-auto">
  <div class="w-full max-w-[1600px] mx-auto"> 

    <div class="mb-6">
        <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-tight">Mi Panel</h1>
        <p class="text-gray-500 text-sm mt-1">Gestiona tus publicaciones, compras y ventas.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        
        <div class="bg-white border border-gray-100 rounded-2xl p-4 md:p-5 shadow-sm hover:shadow-md transition-all flex items-center justify-between group">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-blue-50 text-[#54A6D8] flex items-center justify-center shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z" /></svg>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-0.5">Como Vendedor</h3>
                    <div class="flex items-center gap-2">
                        <span class="text-2xl font-bold text-gray-900"><?= $display_rate_vendedor ?></span>
                        <div class="flex flex-col justify-center">
                            <div class="flex leading-none mb-0.5"><?= render_stars_html($vendedor_final_nota, $vendedor_final_qty) ?></div>
                            <span class="text-[10px] text-gray-400 font-medium"><?= $vendedor_final_qty ?> votos</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white border border-gray-100 rounded-2xl p-4 md:p-5 shadow-sm hover:shadow-md transition-all flex items-center justify-between group">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-orange-50 text-orange-500 flex items-center justify-center shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-0.5">Como Comprador</h3>
                    <div class="flex items-center gap-2">
                        <span class="text-2xl font-bold text-gray-900"><?= $display_rate_comprador ?></span>
                        <div class="flex flex-col justify-center">
                            <div class="flex leading-none mb-0.5"><?= render_stars_html($comprador_final_nota, $comprador_final_qty) ?></div>
                            <span class="text-[10px] text-gray-400 font-medium"><?= $comprador_final_qty ?> votos</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-10">

      <a href="/mis_apuntes_publicados" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start hover:border-blue-200">
        <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-500 flex items-center justify-center mb-3">
             <?= dash_icon('apuntes') ?>
        </div>
        <h3 class="font-bold text-gray-900">Mis Apuntes</h3>
        <p class="text-xs text-gray-500 mt-1">Administra lo que publicaste</p>
      </a>

      <a href="/mis_servicios_publicados" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start hover:border-yellow-200">
        <div class="w-12 h-12 rounded-full bg-yellow-50 text-yellow-600 flex items-center justify-center mb-3">
             <?= dash_icon('servicios') ?>
        </div>
        <h3 class="font-bold text-gray-900">Mis Servicios</h3>
        <p class="text-xs text-gray-500 mt-1">Gestiona tus clases ofrecidas</p>
      </a>

      <a href="/mis_compras" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start hover:border-green-200">
        <div class="w-12 h-12 rounded-full bg-green-50 text-green-600 flex items-center justify-center mb-3">
             <?= dash_icon('compras') ?>
        </div>
        <h3 class="font-bold text-gray-900">Mis Compras</h3>
        <p class="text-xs text-gray-500 mt-1">Historial de adquisiciones</p>
      </a>

      <a href="/mis_ventas" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start relative hover:border-purple-200">
        <div class="flex w-full justify-between items-start">
            <div class="w-12 h-12 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mb-3">
                 <?= dash_icon('ventas') ?>
            </div>
            <span id="badge-mis-ventas" class="hidden bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full animate-pulse"></span>
        </div>
        <h3 class="font-bold text-gray-900">Mis Ventas</h3>
        <p class="text-xs text-gray-500 mt-1">Resumen de tus ingresos</p>
      </a>
      
      <a href="/mis_servicios_contratados" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start hover:border-indigo-200">
        <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3">
             <?= dash_icon('contratos') ?>
        </div>
        <h3 class="font-bold text-gray-900">Mis Contratos</h3>
        <p class="text-xs text-gray-500 mt-1">Servicios activos y finalizados</p>
      </a>
      
        <a href="/mis_evaluaciones" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start hover:border-indigo-200">
        <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3">
             <?= dash_icon('contratos') ?>
        </div>
        <h3 class="font-bold text-gray-900">Mis Evaluaciones</h3>
        <p class="text-xs text-gray-500 mt-1">Servicios activos y finalizados</p>
      </a>
     
   

      <a href="/editar_mis_datos" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start hover:border-gray-300">
        <div class="w-12 h-12 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center mb-3">
             <?= dash_icon('editar') ?>
        </div>
        <h3 class="font-bold text-gray-900">Editar Datos</h3>
        <p class="text-xs text-gray-500 mt-1">Actualiza tu información</p>
      </a>
      
      <a href="/reclamos_sugerencias" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start relative hover:border-orange-200">
        <div class="flex w-full justify-between items-start">
            <div class="w-12 h-12 rounded-full bg-orange-50 text-orange-600 flex items-center justify-center mb-3">
                 <?= dash_icon('reclamos') ?>
            </div>
            <span id="badge-reclamos-user" class="hidden bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"></span>
        </div>
        <h3 class="font-bold text-gray-900">Reclamos</h3>
        <p class="text-xs text-gray-500 mt-1">Envíanos tus comentarios</p>
      </a>

      <a href="/soporte" class="dash-card bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col items-start relative hover:border-teal-200">
        <div class="flex w-full justify-between items-start">
            <div class="w-12 h-12 rounded-full bg-teal-50 text-teal-600 flex items-center justify-center mb-3">
                 <?= dash_icon('soporte') ?>
            </div>
            <span id="badge-soporte-user" class="hidden bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"></span>
        </div>
        <h3 class="font-bold text-gray-900">Soporte</h3>
        <p class="text-xs text-gray-500 mt-1">¿Necesitas ayuda?</p>
      </a>

      <a href="/logout" class="dash-card bg-red-50 p-6 rounded-2xl shadow-sm border border-red-100 flex flex-col items-start hover:bg-red-100 transition">
        <div class="w-12 h-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mb-3">
             <?= dash_icon('logout') ?>
        </div>
        <h3 class="font-bold text-red-700">Cerrar Sesión</h3>
        <p class="text-xs text-red-500 mt-1">Salir de tu cuenta</p>
      </a>

    </div>

    <?php if ($es_admin): ?>
      <div class="mt-12 mb-8 border-t border-gray-200 pt-8 bg-gradient-to-br from-purple-50/50 to-white rounded-3xl p-6 border border-purple-100">
          <h2 class="text-xl font-bold text-purple-800 mb-6 flex items-center gap-2">
               <?= dash_icon('shield') ?> Panel de Administración
          </h2>

          <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
              
              <a href="/admin/usuarios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                  <div class="flex justify-between items-start mb-2">
                      <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('users') ?></div>
                      <span id="badge-usuarios" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                  </div>
                  <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Usuarios</h3>
              </a>

              <a href="/admin/servicios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                  <div class="flex justify-between items-start mb-2">
                      <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('admin-srv') ?></div>
                      <span id="badge-servicios" class="<?= $pendientes_servicios > 0 ? '' : 'hidden' ?> bg-red-600 text-white text-[10px] font-bold px-1.5 rounded-full animate-pulse">
                          <?= $pendientes_servicios ?>
                      </span>
                  </div>
                  <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Servicios</h3>
              </a>

              <a href="/admin/apuntes" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                  <div class="flex justify-between items-start mb-2">
                      <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('admin-doc') ?></div>
                      <span id="badge-apuntes" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                  </div>
                  <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Apuntes</h3>
              </a>

              <a href="/admin/retiros" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                  <div class="flex justify-between items-start mb-2">
                      <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('money') ?></div>
                      <span id="badge-retiros" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                  </div>
                  <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Retiros</h3>
              </a>

              <a href="/admin/retiros_contratos" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                  <div class="flex justify-between items-start mb-2">
                      <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('money') ?></div>
                  </div>
                  <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Retiros (C)</h3>
              </a>

              <a href="/admin/pagos_servicios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('credit') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Pagos Serv.</h3>
              </a>

              <a href="/admin/contratos" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('contratos') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Contratos</h3>
              </a>

              <a href="/admin/dominios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('globe') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Dominios</h3>
              </a>

              <a href="/admin/instituciones" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('building') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Instituciones</h3>
              </a>
              
              <a href="/admin/recordatorios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('bell') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Recordatorios</h3>
              </a>

              <a href="/admin/banners" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('photo') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Banners</h3>
              </a>

              <a href="/admin/popup" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('window') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Popup Home</h3>
              </a>

              <a href="/admin/soporte" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                   <div class="flex justify-between items-start mb-2">
                       <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('support') ?></div>
                       <span id="badge-soporte" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                   </div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Soporte</h3>
              </a>

              <a href="/admin/reclamos" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                   <div class="flex justify-between items-start mb-2">
                       <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('alert') ?></div>
                       <span id="badge-reclamos" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                   </div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Reclamos</h3>
              </a>
              
              <a href="/admin/solicitudes" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                   <div class="flex justify-between items-start mb-2">
                       <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('add-user') ?></div>
                       <span id="badge-solicitudes" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                   </div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Solicitudes</h3>
              </a>

              <a href="/admin/login-fallos" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                   <div class="flex justify-between items-start mb-2">
                       <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('shield') ?></div>
                       <span id="badge-login-fallos" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                   </div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Login Fallos</h3>
              </a>

              <a href="/admin/reporte-servicios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('chart') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Reportes</h3>
              </a>

              <a href="/admin/autores_servicios" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('users-tag') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Autores</h3>
              </a>

              <a href="/app/admin_config_precios.php" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 hover:border-purple-300">
                   <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm mb-2"><?= dash_icon('tags') ?></div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Precios</h3>
              </a>
              
              <a href="/app/admin_accesos_vitrina.php" class="dash-card bg-white p-4 rounded-2xl border border-purple-100 relative hover:border-purple-300">
                   <div class="flex justify-between items-start mb-2">
                       <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-sm"><?= dash_icon('eye') ?></div>
                       <span id="badge-accesos-vitrina" class="hidden bg-red-500 text-white text-[10px] font-bold px-1.5 rounded-full"></span>
                   </div>
                   <h3 class="font-bold text-gray-800 text-xs uppercase tracking-wide">Accesos</h3>
              </a>

          </div>
      </div>
    <?php endif; ?>

  </div>
</main>

<?php 
require_once $app_dir . '/componentes/nav_bottom.php'; 
require_once $app_dir . '/componentes/modal_publicar.php'; 
require_once $app_dir . '/componentes/modal_explora.php'; 
?>

<script>
window.onload = () => { const l = document.getElementById('loader'); if(l){ l.classList.add('opacity-0'); setTimeout(()=>l.classList.add('hidden'),300); } };

function setupModal(triggerId, modalId, cardId, closeId) {
    const btn = document.getElementById(triggerId), modal = document.getElementById(modalId), card = document.getElementById(cardId), close = document.getElementById(closeId);
    if(!btn || !modal) return;
    const open = () => { modal.classList.remove('hidden'); requestAnimationFrame(() => card.classList.remove('translate-y-full', 'opacity-0')); document.body.style.overflow = 'hidden'; };
    const shut = () => { card.classList.add('translate-y-full', 'opacity-0'); setTimeout(() => { modal.classList.add('hidden'); document.body.style.overflow = ''; }, 300); };
    btn.onclick = (e) => { e.preventDefault(); open(); }; close.onclick = shut; modal.onclick = (e) => { if(e.target === modal) shut(); };
}
setupModal('btn-publicar', 'modal-quick', 'quick-card', 'quick-close');
setupModal('btn-explora', 'modal-explora', 'explora-card', 'explora-close');

async function actualizarBadgeChats() {
    try {
        const res = await fetch('/app/contar_mensajes_nuevos.php');
        const data = await res.json();
        const total = parseInt(data.total || 0);
        ['badge-chats-sidebar', 'badge-chats-bottom'].forEach(id => {
            const el = document.getElementById(id);
            if(el) { el.innerText = total; total > 0 ? el.classList.remove('hidden') : el.classList.add('hidden'); }
        });
    } catch {}
}
function abrirMisChats() { window.open("/app/mis_chats.php", "mis_chats", "width=440,height=640,resizable=yes,scrollbars=yes"); }
document.addEventListener('DOMContentLoaded', ()=>{ actualizarBadgeChats(); setInterval(actualizarBadgeChats, 10000); });

async function actualizarContadoresAdmin() {
    try {
        const res = await fetch('/app/ajax_contadores_admin.php');
        const data = await res.json();

        const updateBadge = (id, val) => {
            const el = document.getElementById(id);
            if(el) {
                if(val > 0) { el.textContent = val; el.classList.remove('hidden'); }
                else el.classList.add('hidden');
            }
        };

        updateBadge('badge-usuarios', data.usuarios);
        updateBadge('badge-apuntes', data.apuntes);
        updateBadge('badge-servicios', data.servicios);
        updateBadge('badge-retiros', data.retiros);
        updateBadge('badge-soporte', data.soporte);
        updateBadge('badge-reclamos', data.reclamos);
        updateBadge('badge-solicitudes', data.solicitudes);
        updateBadge('badge-login-fallos', data.login_fallos);
        updateBadge('badge-accesos-vitrina', data.accesos_vitrina);

        updateBadge('badge-mis-ventas', data.mis_ventas);
        updateBadge('badge-reclamos-user', data.reclamos_user);
        updateBadge('badge-soporte-user', data.soporte_user);
    } catch (e) {}
}
setInterval(actualizarContadoresAdmin, 20000);
document.addEventListener('DOMContentLoaded', actualizarContadoresAdmin);
</script>

</body>
</html>