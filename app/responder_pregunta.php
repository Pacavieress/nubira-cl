<?php
session_start();
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

// 🔒 Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión para responder una pregunta.']);
    exit;
}

/* -----------------------------------------
   🧠 Compatibilidad con JSON o FormData
------------------------------------------*/
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
    $id_pregunta = (int)($data['id_pregunta'] ?? 0);
    $respuesta   = trim($data['respuesta'] ?? '');
} else {
    $id_pregunta = (int)($_POST['id_pregunta'] ?? 0);
    $respuesta   = trim($_POST['respuesta'] ?? '');
}

/* -----------------------------------------
   🧩 Validaciones básicas
------------------------------------------*/
if ($id_pregunta <= 0 || $respuesta === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos o la respuesta está vacía.']);
    exit;
}

/* -----------------------------------------
   🚫 Filtro para evitar compartir contactos
------------------------------------------*/
function contiene_contacto($texto) {
    $patrones = [
        '/\b\d{8,}\b/i',                                 // Números largos
        '/@/i',                                          // Arroba
        '/(gmail|hotmail|yahoo|outlook|uc\.cl|aiep\.cl|udla\.cl)/i',
        '/(https?:\/\/|www\.)/i',                        // URLs
        '/(wa\.me|whatsapp|fono|tel[ée]fono)/i',         // Palabras clave
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $texto)) return true;
    }
    return false;
}

if (contiene_contacto($respuesta)) {
    $log = __DIR__ . '/../logs/contacto_bloqueado.log';
    if (!is_dir(dirname($log))) mkdir(dirname($log), 0777, true);
    file_put_contents(
        $log,
        date('Y-m-d H:i:s') . " - Usuario {$_SESSION['usuario_id']} intentó enviar contacto: {$respuesta}\n",
        FILE_APPEND
    );

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '⚠️ No puedes incluir correos, teléfonos o enlaces en tu respuesta.']);
    exit;
}

/* -----------------------------------------
   🔍 Verificar que el usuario es dueño
------------------------------------------*/
$check = $conn->prepare("
    SELECT s.alumno_id, p.id_servicio
    FROM preguntas_servicios p
    JOIN servicios s ON s.id = p.id_servicio
    WHERE p.id = ?
    LIMIT 1
");
$check->bind_param("i", $id_pregunta);
$check->execute();
$datos = $check->get_result()->fetch_assoc();
$check->close();

if (!$datos || $datos['alumno_id'] != $_SESSION['usuario_id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado para responder esta pregunta.']);
    exit;
}

/* -----------------------------------------
   💬 Guardar la respuesta
------------------------------------------*/
$stmt = $conn->prepare("
    UPDATE preguntas_servicios
    SET respuesta = ?, fecha_respuesta = NOW()
    WHERE id = ?
");
$stmt->bind_param("si", $respuesta, $id_pregunta);
$stmt->execute();
$stmt->close();

/* -----------------------------------------
   ✅ Respuesta final JSON
------------------------------------------*/
echo json_encode([
    'ok' => true,
    'id_pregunta' => $id_pregunta,
    'respuesta' => htmlspecialchars($respuesta, ENT_QUOTES, 'UTF-8'),
    'fecha_respuesta' => date('Y-m-d H:i:s')
]);
exit;
