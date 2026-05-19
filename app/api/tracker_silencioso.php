<?php
/**
 * ENDPOINT: TRACKER SILENCIOSO (HUELLA DE DISPOSITIVO)
 * ESTADO: Nubira 2.0 (Seguro, Rápido, Sin Output)
 */

// 1. OPTIMIZACIÓN DE RENDIMIENTO Y CABECERAS
ini_set('display_errors', 0);
error_reporting(0);

// Le decimos al navegador: "Recibido, no hay nada que mostrar, cierra la conexión"
header("HTTP/1.1 204 No Content");
header("Connection: close");

// Solo aceptamos POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

$device_id = $_POST['device_id'] ?? '';
$ruta_actual = $_POST['ruta_actual'] ?? '/';
$ip_actual = $_SERVER['REMOTE_ADDR'] ?? '';

// 2. SEGURIDAD BÁSICA
if (empty($device_id) || strlen($device_id) > 64) exit;
// Si estamos en entorno de desarrollo local (localhost), evitamos saturar la API
if ($ip_actual === '127.0.0.1' || $ip_actual === '::1') exit;

require_once dirname(__DIR__) . '/conexion.php';

// 3. IDENTIFICACIÓN DE RED (ISP)
$isp_org = null;
$institucion_limpia = null;
$es_universidad = false;

// Consultamos la API. Usamos @ para suprimir errores si la API falla.
$json = @file_get_contents("http://ip-api.com/json/{$ip_actual}?fields=isp,org,status");

if ($json) {
    $data = json_decode($json, true);
    if (isset($data['status']) && $data['status'] === 'success') {
        $cadena_busqueda = strtolower(($data['isp'] ?? '') . ' ' . ($data['org'] ?? ''));
        
        // [Criterio Nubira] Agregamos keywords de instituciones latinas comunes
        if (preg_match('/universidad|instituto|academ|uc |usm|udec|duoc|inacap|aiep|ipchile/i', $cadena_busqueda)) {
            $es_universidad = true;
            // Guardamos el nombre original con mayúsculas para mostrarlo bonito después
            $institucion_limpia = ucwords(trim($data['org'] ?? $data['isp'])); 
        }
    }
}

// 4. ALMACENAMIENTO (Upsert)
// Solo guardamos a los que son de universidades para mantener la base de datos ultra ligera.
if ($es_universidad && $institucion_limpia && isset($conn)) {
    $stmt = $conn->prepare("
        INSERT INTO visitantes_anonimos (device_id, ultima_ip, posible_institucion, visitas_totales, ultima_ruta) 
        VALUES (?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE 
        posible_institucion = VALUES(posible_institucion), 
        visitas_totales = visitas_totales + 1,
        ultima_ruta = VALUES(ultima_ruta),
        ultima_ip = VALUES(ultima_ip),
        fecha_ultima_actividad = NOW()
    ");
    
    if ($stmt) {
        $stmt->bind_param("ssss", $device_id, $ip_actual, $institucion_limpia, $ruta_actual);
        $stmt->execute();
        $stmt->close();
    }
}
?>