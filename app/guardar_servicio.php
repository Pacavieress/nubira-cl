
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$usuario_id      = $_SESSION['usuario_id'];
$institucion     = $_SESSION['institucion'] ?? '';
$nombre_oferente = $_SESSION['nombre'] ?? '';
$correo_institucional = $_SESSION['correo'] ?? $_SESSION['email'] ?? ''; // Siempre desde la sesión

// --- Capturar datos del formulario ---
$titulo      = trim($_POST['titulo'] ?? '');
$preview     = trim($_POST['preview'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$categoria   = trim($_POST['categoria'] ?? '');
$area        = trim($_POST['area'] ?? '');
$modalidad   = trim($_POST['modalidad'] ?? '');
$ubicacion   = trim($_POST['ubicacion'] ?? '');
$precio      = $_POST['precio'] ?? null;
$whatsapp    = trim($_POST['whatsapp'] ?? '');

// --- Generar preview si viene vacío ---
if ($preview === '') {
    $preview = mb_substr(preg_replace('/\s+/', ' ', $descripcion), 0, 80);
}

// --- Validación básica ---
if (
    empty($titulo) ||
    empty($descripcion) ||
    empty($categoria) ||
    empty($modalidad) ||
    empty($whatsapp)
) {
    header("Location: /publicar-servicio?error=Faltan campos obligatorios.");
    exit;
}

// --- Validación: solo 1 publicación por día ---
$stmt = $conn->prepare("SELECT COUNT(*) FROM servicios WHERE alumno_id=? AND DATE(fecha_publicacion)=CURDATE()");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$stmt->bind_result($yaPublicoHoy);
$stmt->fetch();
$stmt->close();

if ($yaPublicoHoy > 0) {
    header("Location: /publicar-servicio?error=Ya publicaste un servicio hoy. Vuelve a intentarlo mañana.");
    exit;
}

// --- Validación de datos prohibidos en descripción ---
$descripcion_min = mb_strtolower($descripcion);
$prohibidos = [
    '/@/',
    '/\b(gmail|hotmail|outlook|yahoo|uc\.cl|duoc|aiep|ipchile|mail)\b/',
    '/\d{8,}/',
    '/(fono|tel[ée]fono|whatsapp|ws|contacto|celular|dirección|address|wsp)/',
    '/\+56/'
];
foreach ($prohibidos as $regex) {
    if (preg_match($regex, $descripcion_min)) {
        header("Location: /publicar-servicio?error=No puedes ingresar datos de contacto en la descripción.");
        exit;
    }
}

// --- Validar WhatsApp: debe tener 9 dígitos (sin +56), anteponer +56 ---
if (!preg_match('/^[1-9][0-9]{8}$/', $whatsapp)) {
    header("Location: /publicar-servicio?error=El número WhatsApp debe tener 9 dígitos (sin +56).");
    exit;
}
$whatsapp = '+56' . $whatsapp;

// --- Guardar servicio en estado pendiente ---
$estado = 'pendiente';
$campos = "alumno_id, institucion, titulo, preview, descripcion, nombre_oferente, categoria, area, modalidad, ubicacion, precio, whatsapp, correo, estado, fecha_publicacion";
$insert_sql = "INSERT INTO servicios ($campos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($insert_sql);
if (!$stmt) {
    exit("Error al preparar SQL: " . $conn->error);
}

// precio puede ser null → pasarlo como double (d) o null
$precio = ($precio === '' || $precio === null) ? null : floatval($precio);

$stmt->bind_param(
    "isssssssssdsss",
    $usuario_id,
    $institucion,
    $titulo,
    $preview,
    $descripcion,
    $nombre_oferente,
    $categoria,
    $area,
    $modalidad,
    $ubicacion,
    $precio,
    $whatsapp,
    $correo_institucional,
    $estado
);


if (!$stmt->execute()) {
    exit("Error al ejecutar SQL: " . $stmt->error);
}

// [NUBIRA 2.0] Atrapamos el ID del servicio recién creado
$nuevo_servicio_id = $stmt->insert_id;
$stmt->close();

// [NUBIRA 2.0] Ejecutamos el examen de gamificación pasiva al instante
require_once __DIR__ . '/helpers/usuario_helper.php';
actualizar_score_servicio($conn, $nuevo_servicio_id);

// Slug SEO
require_once __DIR__ . '/helpers/seo.php';
$slug_nuevo = generar_slug($titulo);
if (!empty($slug_nuevo)) {
    $stmt_slug = $conn->prepare("UPDATE servicios SET slug = ? WHERE id = ?");
    $stmt_slug->bind_param("si", $slug_nuevo, $nuevo_servicio_id);
    $stmt_slug->execute();
    $stmt_slug->close();
}

$conn->close();

header("Location: /publicar-servicio?ok=1");
exit;