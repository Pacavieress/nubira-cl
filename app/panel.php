<?php
// 1. INICIO DE SESIÓN Y SEGURIDAD
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php"); // Ajusta si tu login está en otra ruta
    exit;
}

$user_id = $_SESSION['usuario_id'];

// ---------------------------------------------------------
// 2. CONEXIÓN A BASE DE DATOS (CRÍTICO: MODIFICA ESTO)
// ---------------------------------------------------------

// OPCIÓN A: Si tienes un archivo de conexión, descomenta y ajusta la ruta:
// require_once 'conexion.php'; 

// OPCIÓN B: Conexión directa (Úsalo si no encuentras tu archivo de conexión):
$servidor = "localhost";
$usuario_db = "root";       // Tu usuario de BD local
$password_db = "";          // Tu password de BD local
$nombre_db = "nubira_db";   // EL NOMBRE EXACTO DE TU BASE DE DATOS

$conexion = new mysqli($servidor, $usuario_db, $password_db, $nombre_db);

if ($conexion->connect_error) {
    die("Error crítico de conexión: " . $conexion->connect_error);
}
$conexion->set_charset("utf8");

// ---------------------------------------------------------
// 3. LÓGICA DE CALIFICACIONES (SEPARADA Y SEGURA)
// ---------------------------------------------------------

// A) ESTADÍSTICAS COMO VENDEDOR (Mis ventas)
// "Busca en valoraciones donde yo soy el VENDEDOR"
$sql_vendedor = "SELECT 
                    COUNT(*) as total_votos, 
                    COALESCE(AVG(calificacion), 0) as promedio 
                 FROM valoraciones 
                 WHERE id_vendedor = ?";

$stmt_v = $conexion->prepare($sql_vendedor);
if ($stmt_v) {
    $stmt_v->bind_param("i", $user_id);
    $stmt_v->execute();
    $res_v = $stmt_v->get_result();
    $stats_vendedor = $res_v->fetch_assoc();
    $stmt_v->close();
} else {
    // Si falla la consulta (ej: tabla no existe), devolvemos valores en 0 para no romper el front
    $stats_vendedor = ['total_votos' => 0, 'promedio' => 0];
}

// B) ESTADÍSTICAS COMO COMPRADOR (Mis compras)
// "Busca en valoraciones donde yo soy el USUARIO que compró"
// NOTA: Si tu tabla usa 'id_comprador' en vez de 'id_usuario', cambia el nombre del campo abajo.
$sql_comprador = "SELECT 
                    COUNT(*) as total_votos, 
                    COALESCE(AVG(calificacion), 0) as promedio 
                  FROM valoraciones 
                  WHERE id_usuario = ?"; 

$stmt_c = $conexion->prepare($sql_comprador);
if ($stmt_c) {
    $stmt_c->bind_param("i", $user_id);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    $stats_comprador = $res_c->fetch_assoc();
    $stmt_c->close();
} else {
    $stats_comprador = ['total_votos' => 0, 'promedio' => 0];
}

// Helper para estrellas (Integrado aquí para no depender de includes externos)
function renderStars($promedio) {
    $html = '<div class="flex items-center space-x-0.5">';
    for ($i = 1; $i <= 5; $i++) {
        $colorClass = ($i <= round($promedio)) ? "text-yellow-400" : "text-gray-200";
        $html .= '<svg class="w-4 h-4 '.$colorClass.'" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>';
    }
    $html .= '</div>';
    return $html;
}
?>