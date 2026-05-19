<?php
session_start();
header('Content-Type: application/json');
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id'])) {
  echo json_encode(['ok' => false, 'msg' => 'Debes iniciar sesión.']);
  exit;
}

$usuario_id  = (int)$_SESSION['usuario_id'];
$servicio_id = (int)($_POST['servicio_id'] ?? 0);
$rating      = (int)($_POST['rating'] ?? 0);
$comentario  = trim($_POST['comentario'] ?? '');

if ($servicio_id <= 0 || $rating <= 0 || $rating > 5 || $comentario === '') {
  echo json_encode(['ok' => false, 'msg' => 'Datos incompletos o inválidos.']);
  exit;
}

// 🔒 Evitar que el autor se autoevalúe
$stmt = $conn->prepare("SELECT alumno_id FROM servicios WHERE id = ?");
$stmt->bind_param("i", $servicio_id);
$stmt->execute();
$stmt->bind_result($autor_id);
$stmt->fetch();
$stmt->close();

if ($autor_id == $usuario_id) {
  echo json_encode(['ok' => false, 'msg' => 'No puedes valorar ni comentar tu propio servicio.']);
  exit;
}

// 🚫 Bloquear datos de contacto, números escritos e insultos
$bloqueados = [
  '/\b\d{7,}\b/', '/@/',
  '/whatsapp|wsp|contacto|tel[eé]fono|fono/',
  '/mail|correo|gmail|hotmail|outlook/',
  '/instagram|insta|ig|facebook|fb/',
  '/direcci[oó]n|ubicaci[oó]n|calle|avenida/',
  '/cero|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieci|veinti|veinte|treinta|cuarenta|cincuenta|sesenta|setenta|ochenta|noventa|cien|mil/i',
  // 🧨 Lenguaje ofensivo común
  '/we[oó]|wea|culia|ctm|mierda|idiota|imb[eé]cil|estupido|tonto|maric|puta|pendej|perkin|sapo|huev/i'
];

foreach ($bloqueados as $patron) {
  if (preg_match($patron, strtolower($comentario))) {
    echo json_encode(['ok' => false, 'msg' => '🚫 Tu comentario contiene palabras o información no permitida.']);
    exit;
  }
}

// ✅ Guardar comentario
$stmt = $conn->prepare("
  INSERT INTO servicio_comentarios (servicio_id, usuario_id, rating, comentario, fecha)
  VALUES (?, ?, ?, ?, NOW())
");
$stmt->bind_param("iiis", $servicio_id, $usuario_id, $rating, $comentario);

if ($stmt->execute()) {
  echo json_encode(['ok' => true]);
} else {
  echo json_encode(['ok' => false, 'msg' => 'Error al guardar el comentario.']);
}

$stmt->close();
