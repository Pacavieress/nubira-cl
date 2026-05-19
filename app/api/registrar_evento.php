<?php
// app/api/registrar_evento.php
// RECEPTOR DE TELEMETRÍA NUBIRA 2.0 (MOTOR DE RECOMENDACIÓN)
// Evalúa el interés real del usuario y lo guarda en tracker_intereses

ini_set('display_errors', 0);
error_reporting(0);

session_start();

// 1. BLINDAJE DE CONEXIÓN (Busca el archivo conexion.php automágicamente)
$rutas_conexion = [
    __DIR__ . '/../../conexion.php',        // Subir 2 niveles
    __DIR__ . '/../../config/conexion.php', // Carpeta config
    __DIR__ . '/../conexion.php',           // Subir 1 nivel
    $_SERVER['DOCUMENT_ROOT'] . '/conexion.php' // Raíz del sitio
];

$conn = null;
foreach ($rutas_conexion as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}

if (!isset($conn)) exit;

// 2. CAPTURA DE DATOS (Soporta Beacon nativo)
$datos = !empty($_POST) ? $_POST : json_decode(file_get_contents('php://input'), true);
if (empty($datos)) exit;

// 3. IDENTIFICACIÓN DEL USUARIO (Logueado o Invitado)
$usuario_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
$huella_visitante = null;

if (!$usuario_id) {
    // Si es invitado, creamos una huella única basada en su IP y Navegador
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $huella_visitante = hash('sha256', $ip . $user_agent);
}

// 4. EXTRACCIÓN DE DATOS DEL FRONTEND
$servicio_id = (int)($datos['id'] ?? 0);
if ($servicio_id <= 0) exit; // Sin ID no hay nada que rastrear

$categoria = mb_substr(trim($datos['categoria'] ?? 'General'), 0, 100);
$duracion = (int)($datos['duracion'] ?? 0);
$scroll = (int)($datos['scroll_depth'] ?? 0);
$vio_precio = (int)($datos['vio_precio'] ?? 0);
$clicks_galeria = (int)($datos['clicks_galeria'] ?? 0);
$click_vendedor = (int)($datos['click_vendedor'] ?? 0);
$intencion_compra = (int)($datos['intencion_compra'] ?? 0);
$intencion_contacto = (int)($datos['intencion_contacto'] ?? 0);

// =========================================================================
// 5. EL ALGORITMO DE PUNTUACIÓN (CEREBRO NUBIRA)
// =========================================================================
$score = 1; // Punto base por abrir la URL
$tipo_interaccion = 'view_quick'; // Etiqueta por defecto

// Evaluación de Dwell Time (Tiempo en pantalla)
if ($duracion >= 15) { 
    $score += 2; 
    $tipo_interaccion = 'view_engaged'; 
}
if ($duracion >= 30) { 
    $score += 3; // +5 en total por tiempo
}

// Evaluación de Scroll y Exploración UI
if ($scroll >= 50) $score += 2;
if ($scroll >= 80) $score += 1;
if ($vio_precio > 0) $score += 1;
if ($clicks_galeria > 0) $score += 2;
if ($click_vendedor > 0) $score += 3;

// Evaluación de Intención de Alto Valor (Leads)
if ($intencion_contacto > 0) {
    $score += 15;
    $tipo_interaccion = 'lead_contacto';
}
if ($intencion_compra > 0) {
    $score += 20;
    $tipo_interaccion = 'lead_compra';
}

// Tope máximo de seguridad por sesión
if ($score > 50) $score = 50;

// =========================================================================
// 6. INYECCIÓN EN BASE DE DATOS
// =========================================================================
$sql = "INSERT INTO tracker_intereses 
        (usuario_id, huella_visitante, servicio_id, categoria, tipo_interaccion, peso_score, fecha) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())";

if ($stmt = $conn->prepare($sql)) {
    // i: integer, s: string
    $stmt->bind_param("isissi", 
        $usuario_id, 
        $huella_visitante, 
        $servicio_id, 
        $categoria, 
        $tipo_interaccion, 
        $score
    );
    $stmt->execute();
    $stmt->close();
}
?>