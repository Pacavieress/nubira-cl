<?php
/**
 * NUBIRA 2.0 - ENDPOINT API: GEOLOCALIZACIÓN DE IPs
 * 
 * Filosofía API-First: Reutilizable desde Web (admin_accesos_vitrina.php)
 * y futura App Nativa Flutter (iOS/Android, 2027).
 * 
 * Método: POST
 * Content-Type: application/json
 * Body: { "ips": ["1.2.3.4", "5.6.7.8", ...] }
 * 
 * Respuesta: { "ok": true, "data": { "1.2.3.4": {...}, ... } }
 */

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 1. SEGURIDAD: Solo admin autenticado (por ahora; Flutter usará tokens en el futuro)
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// 2. CONEXIÓN BD
$rutas_conexion = [
    __DIR__ . '/../conexion.php',
    __DIR__ . '/../../conexion.php',
    $_SERVER['DOCUMENT_ROOT'] . '/app/conexion.php'
];
$conn_found = false;
foreach ($rutas_conexion as $rc) {
    if (file_exists($rc)) { require_once $rc; $conn_found = true; break; }
}
if (!$conn_found || !isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexión']);
    exit;
}

// 3. INPUT: solo POST con JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['ips']) || !is_array($input['ips'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato inválido. Esperado: { "ips": [...] }']);
    exit;
}

// 4. SANITIZACIÓN ESTRICTA: solo IPs válidas, máximo 100 por request
$ips_validas = [];
foreach ($input['ips'] as $ip) {
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'])) {
        $ips_validas[] = $ip;
    }
}
$ips_validas = array_unique(array_slice($ips_validas, 0, 100));

if (empty($ips_validas)) {
    echo json_encode(['ok' => true, 'data' => new stdClass()]);
    exit;
}

// 5. CACHE LOOKUP: ¿qué IPs ya tenemos en BD y siguen frescas (<30 días)?
$resultados = [];
$ips_a_consultar = [];
$cache_ttl_dias = 30;

$placeholders = implode(',', array_fill(0, count($ips_validas), '?'));
$types = str_repeat('s', count($ips_validas));

$sql = "SELECT ip, pais, pais_codigo, region, ciudad, lat, lon, isp, es_hosting, es_proxy, zona_horaria, fecha_actualizacion
        FROM ip_geolocalizacion
        WHERE ip IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$ips_validas);
$stmt->execute();
$res = $stmt->get_result();

$ips_cacheadas = [];
while ($row = $res->fetch_assoc()) {
    $vencida = (time() - strtotime($row['fecha_actualizacion'])) > ($cache_ttl_dias * 86400);
    if ($vencida) {
        $ips_a_consultar[] = $row['ip'];
    } else {
        $ips_cacheadas[] = $row['ip'];
        $resultados[$row['ip']] = [
            'ip'           => $row['ip'],
            'pais'         => $row['pais'],
            'pais_codigo'  => $row['pais_codigo'],
            'region'       => $row['region'],
            'ciudad'       => $row['ciudad'],
            'lat'          => $row['lat'] !== null ? (float)$row['lat'] : null,
            'lon'          => $row['lon'] !== null ? (float)$row['lon'] : null,
            'isp'          => $row['isp'],
            'es_hosting'   => (bool)$row['es_hosting'],
            'es_proxy'     => (bool)$row['es_proxy'],
            'zona_horaria' => $row['zona_horaria'],
            'fuente'       => 'cache'
        ];
    }
}
$stmt->close();

// IPs que faltan en cache + IPs vencidas
foreach ($ips_validas as $ip) {
    if (!in_array($ip, $ips_cacheadas) && !in_array($ip, $ips_a_consultar)) {
        $ips_a_consultar[] = $ip;
    }
}

// 6. CONSULTA EXTERNA: ip-api.com batch (server-side, HTTPS no es problema)
if (!empty($ips_a_consultar)) {
    $batch = [];
    foreach ($ips_a_consultar as $ip) {
        $batch[] = [
            'query'  => $ip,
            'fields' => 'status,country,countryCode,regionName,city,lat,lon,timezone,isp,hosting,proxy,query'
        ];
    }

    $ch = curl_init('http://ip-api.com/batch');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($batch),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            // Prepared statement de UPSERT (insertar o actualizar)
            $sql_up = "INSERT INTO ip_geolocalizacion 
                       (ip, pais, pais_codigo, region, ciudad, lat, lon, isp, es_hosting, es_proxy, zona_horaria)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                         pais=VALUES(pais), pais_codigo=VALUES(pais_codigo),
                         region=VALUES(region), ciudad=VALUES(ciudad),
                         lat=VALUES(lat), lon=VALUES(lon),
                         isp=VALUES(isp), es_hosting=VALUES(es_hosting),
                         es_proxy=VALUES(es_proxy), zona_horaria=VALUES(zona_horaria),
                         consultas = consultas + 1,
                         fecha_actualizacion = CURRENT_TIMESTAMP";
            $stmt_up = $conn->prepare($sql_up);

            foreach ($data as $d) {
                if (($d['status'] ?? '') !== 'success') continue;

                $ip          = $d['query'] ?? null;
                $pais        = $d['country'] ?? null;
                $pais_cod    = $d['countryCode'] ?? null;
                $region      = $d['regionName'] ?? null;
                $ciudad      = $d['city'] ?? null;
                $lat         = isset($d['lat']) ? (float)$d['lat'] : null;
                $lon         = isset($d['lon']) ? (float)$d['lon'] : null;
                $isp         = $d['isp'] ?? null;
                $es_hosting  = !empty($d['hosting']) ? 1 : 0;
                $es_proxy    = !empty($d['proxy']) ? 1 : 0;
                $tz          = $d['timezone'] ?? null;

                if (!$ip) continue;

                $stmt_up->bind_param(
                    "sssssddsiis",
                    $ip, $pais, $pais_cod, $region, $ciudad,
                    $lat, $lon, $isp, $es_hosting, $es_proxy, $tz
                );
                $stmt_up->execute();

                $resultados[$ip] = [
                    'ip'           => $ip,
                    'pais'         => $pais,
                    'pais_codigo'  => $pais_cod,
                    'region'       => $region,
                    'ciudad'       => $ciudad,
                    'lat'          => $lat,
                    'lon'          => $lon,
                    'isp'          => $isp,
                    'es_hosting'   => (bool)$es_hosting,
                    'es_proxy'     => (bool)$es_proxy,
                    'zona_horaria' => $tz,
                    'fuente'       => 'api'
                ];
            }
            $stmt_up->close();
        }
    }
}

// 7. RESPUESTA UNIFORME
echo json_encode([
    'ok'         => true,
    'total'      => count($resultados),
    'desde_cache'=> count($ips_cacheadas),
    'consultados'=> count($ips_a_consultar),
    'data'       => $resultados
]);
$conn->close();