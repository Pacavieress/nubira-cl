<?php
/**
 * NUBIRA 2.0 - MOTOR DE VALIDACIÓN DE CUPONES (AJAX)
 * Ubicación: public_html/app/validar_cupon.php
 * Retorno estricto: JSON
 * Estándar: Nubira Shield (Cero Fricción Administrada)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. SEGURIDAD: Inicia verificación de sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'valido' => false, 
        'mensaje' => 'Sesión expirada. Inicia sesión para usar becas.'
    ]);
    exit;
}

// 2. CONEXIÓN ROBUSTA
require_once __DIR__ . '/conexion.php'; 

// 3. CAPTURA Y SANITIZACIÓN
$codigo_raw = $_GET['codigo'] ?? $_GET['codigo_beca'] ?? '';
$codigo = strtoupper(trim(htmlspecialchars(strip_tags($codigo_raw), ENT_QUOTES, 'UTF-8')));
$servicio_id_actual = isset($_GET['servicio_id']) ? (int)$_GET['servicio_id'] : 0;

if (empty($codigo)) {
    echo json_encode(['valido' => false, 'mensaje' => 'Ingresa un código de beca.']);
    exit;
}
if ($servicio_id_actual <= 0) {
    echo json_encode(['valido' => false, 'mensaje' => 'Error de sistema: Contexto de servicio no identificado.']);
    exit;
}

// 4. CONSULTA BLINDADA
$sql = "SELECT id, porcentaje_descuento, usos_actuales, usos_maximos, fecha_expiracion, servicio_id 
        FROM cupones WHERE codigo = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['valido' => false, 'mensaje' => 'Error técnico en la bóveda de datos.']);
    exit;
}

$stmt->bind_param("s", $codigo);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['valido' => false, 'mensaje' => "El código '{$codigo}' no existe."]);
    $stmt->close();
    exit;
}

$cupon = $result->fetch_assoc();
$stmt->close();
        
// 5. MOTOR DE REGLAS NUBIRA 2.0

// A. Control de Stock
$usos_max = (int)$cupon['usos_maximos'];
$usos_act = (int)$cupon['usos_actuales'];
if ($usos_max > 0 && $usos_act >= $usos_max) {
    echo json_encode(['valido' => false, 'mensaje' => 'Este código ya agotó sus cupos disponibles.']);
    exit;
} 

// B. Control de Tiempo
if (!empty($cupon['fecha_expiracion'])) {
    date_default_timezone_set('America/Santiago');
    $hoy = date('Y-m-d');
    
    if ($hoy > $cupon['fecha_expiracion']) {
        $fecha_formateada = date('d/m/Y', strtotime($cupon['fecha_expiracion']));
        echo json_encode(['valido' => false, 'mensaje' => "Esta beca expiró el {$fecha_formateada}."]);
        exit;
    }
} 

// C. LÓGICA DE ALCANCE AUTOMÁTICO
$es_global = is_null($cupon['servicio_id']) || (int)$cupon['servicio_id'] === 0;

if (!$es_global) {
    $id_restringida = (int)$cupon['servicio_id'];
    
    if ($id_restringida !== $servicio_id_actual) {
        // Retornamos al mensaje UX amigable y limpio, protegiendo IDs internos.
        echo json_encode([
            'valido' => false, 
            'mensaje' => "Esta beca es exclusiva para otro servicio o tutor."
        ]);
        exit;
    }
}

// 6. APROBACIÓN EXITOSA
echo json_encode([
    'valido' => true,
    'mensaje' => "¡Beca aplicada! Beneficio del {$cupon['porcentaje_descuento']}% activado.",
    'descuento' => (int)$cupon['porcentaje_descuento'],
    'cupon_id' => (int)$cupon['id']
]);
exit;