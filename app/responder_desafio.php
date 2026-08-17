<?php
session_start();
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

// Calcula el resultado del "Desafío de hoy" y guarda el intento.
// El único punto de entrada de la UI es la página /desafio, que ya exige
// sesión iniciada antes de renderizar nada — así que en el flujo normal esto
// nunca se llama sin login. Igual se valida acá como barrera de seguridad
// server-side (nunca confiar solo en que el cliente no muestre el botón):
// sin sesión, no se revela ni el puntaje ni la respuesta correcta.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'metodo_invalido']);
    exit;
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_requerido']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$materia = trim($data['materia'] ?? '');
$respuestas = $data['respuestas'] ?? null;

if ($materia === '' || !is_array($respuestas) || count($respuestas) !== 3) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'datos_invalidos']);
    exit;
}

// Normaliza y valida la forma de cada respuesta enviada
$opciones_validas = ['a', 'b', 'c', 'd'];
$pregunta_ids = [];
$elegidas = []; // pregunta_id => opcion elegida

foreach ($respuestas as $r) {
    $pid = (int)($r['pregunta_id'] ?? 0);
    $op  = strtolower(trim($r['opcion'] ?? ''));
    if ($pid <= 0 || !in_array($op, $opciones_validas, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'datos_invalidos']);
        exit;
    }
    $pregunta_ids[] = $pid;
    $elegidas[$pid] = $op;
}

// 3 preguntas distintas, sin duplicados
if (count(array_unique($pregunta_ids)) !== 3) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'datos_invalidos']);
    exit;
}

// Trae la respuesta correcta real desde la BD (nunca se confía en el cliente)
// y exige que las 3 preguntas pertenezcan de verdad a la materia declarada.
$stmt = $conn->prepare(
    "SELECT id, tipo, respuesta_correcta FROM desafio_preguntas
     WHERE id IN (?,?,?) AND materia_slug = ? AND activa = 1 AND revisado_por_admin = 1"
);
$stmt->bind_param('iiis', $pregunta_ids[0], $pregunta_ids[1], $pregunta_ids[2], $materia);
$stmt->execute();
$res = $stmt->get_result();

$correctas = [];
$tipos_preguntas = [];
while ($row = $res->fetch_assoc()) {
    $correctas[(int)$row['id']] = $row['respuesta_correcta'];
    $tipos_preguntas[(int)$row['id']] = $row['tipo'];
}
$stmt->close();

if (count($correctas) !== 3) {
    // Alguna pregunta no existe, no pertenece a esta materia, o ya no está activa/revisada.
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'preguntas_invalidas']);
    exit;
}

// Tipos de opinión (sin respuesta única — diseño aprobado "Opción C: auto-acierto
// neutro"): cuentan siempre como acierto, sin comparar contra respuesta_correcta.
// Nunca perjudican al usuario; a cambio, no miden conocimiento real en esa pregunta
// puntual. respuesta_correcta sigue existiendo en la fila pero se ignora acá.
$tipos_opinion = ['cual_elegirias', 'que_harias_primero'];

$aciertos = 0;
foreach ($elegidas as $pid => $op) {
    if (in_array($tipos_preguntas[$pid] ?? '', $tipos_opinion, true)) {
        $aciertos++;
    } elseif ($correctas[$pid] === $op) {
        $aciertos++;
    }
}

$ins = $conn->prepare("INSERT INTO desafio_intentos (usuario_id, materia_slug, aciertos) VALUES (?, ?, ?)");
$ins->bind_param('isi', $usuario_id, $materia, $aciertos);
$ins->execute();
$ins->close();

// Marca las 3 preguntas como vistas (no repetir hasta agotar el banco — ver
// cargar_desafio.php). ON DUPLICATE es defensivo: si un reset de banco ya
// las había limpiado y se re-sirvieron, no debe romper por la UNIQUE KEY.
$visto = $conn->prepare(
    "INSERT INTO desafio_preguntas_vistas (usuario_id, pregunta_id) VALUES (?,?),(?,?),(?,?)
     ON DUPLICATE KEY UPDATE fecha_visto = NOW()"
);
$visto->bind_param(
    'iiiiii',
    $usuario_id, $pregunta_ids[0],
    $usuario_id, $pregunta_ids[1],
    $usuario_id, $pregunta_ids[2]
);
$visto->execute();
$visto->close();

$resultado = ($aciertos >= 2) ? 'bien' : 'mal';

// Progresión de dificultad ENTRE sesiones (Punto 3): bien sube 1 nivel (tope 3),
// mal baja 1 (piso 1). UPSERT atómico en una sola sentencia — evita el race
// condition de leer nivel_actual y escribirlo en dos pasos separados. Para un
// usuario nuevo en esta materia (INSERT), el valor inicial ya sale del baseline
// 2 (medio) ajustado por este resultado, no un 2 plano seguido de un segundo ajuste.
$delta = ($resultado === 'bien') ? 1 : -1;
$nivel_inicial = max(1, min(3, 2 + $delta));
$prog = $conn->prepare(
    "INSERT INTO desafio_progreso (usuario_id, materia_slug, nivel_actual) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE nivel_actual = LEAST(3, GREATEST(1, nivel_actual + ?))"
);
$prog->bind_param('isii', $usuario_id, $materia, $nivel_inicial, $delta);
$prog->execute();
$prog->close();

$categoria_servicio = null;
if ($resultado === 'mal') {
    $catStmt = $conn->prepare("SELECT categoria_servicio FROM materia_categoria_map WHERE materia_slug = ?");
    $catStmt->bind_param('s', $materia);
    $catStmt->execute();
    $catRow = $catStmt->get_result()->fetch_assoc();
    $catStmt->close();
    $categoria_servicio = $catRow['categoria_servicio'] ?? null;
}

echo json_encode([
    'ok' => true,
    'materia' => $materia,
    'aciertos' => $aciertos,
    'resultado' => $resultado,
    'categoria_servicio' => $categoria_servicio,
]);
