<?php
session_start();

// === BLOQUE ANTI-CACHE (mismo patrón que cargar_apuntes.php/cargar_servicios.php) ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

// Sirve 3 preguntas al azar de una materia para el "Desafío de hoy".
// La página /desafio (único punto de entrada de la UI) ya exige sesión
// iniciada antes de renderizar nada, así que este endpoint replica el mismo
// gate como barrera de seguridad server-side (no depender solo de que la
// página no muestre el link).

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_requerido']);
    exit;
}

$materia = trim($_GET['materia'] ?? '');

if ($materia === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'materia_requerida']);
    exit;
}

// Validamos contra la tabla materias (no un array hardcodeado): es la fuente
// de verdad real de las 12 materias, ya normalizada (ver diagnóstico previo).
$chkMat = $conn->prepare("SELECT 1 FROM materias WHERE slug = ? AND activa = 1 LIMIT 1");
$chkMat->bind_param('s', $materia);
$chkMat->execute();
$materiaValida = $chkMat->get_result()->num_rows > 0;
$chkMat->close();

if (!$materiaValida) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'materia_invalida']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

// No repetir pregunta al mismo usuario hasta agotar el banco de esa materia
// (desafio_preguntas_vistas se llena en responder_desafio.php, al terminar una
// sesión). Si no hay 3 sin ver, reset silencioso: se limpia lo visto de esa
// materia para este usuario y se reintenta desde el pool completo — nunca se
// deja al usuario sin poder jugar por haber agotado el banco.
function nb_desafio_preguntas_no_vistas(mysqli $conn, string $materia, int $usuario_id): array {
    $stmt = $conn->prepare(
        "SELECT id, tipo, enunciado, opcion_a, opcion_b, opcion_c, opcion_d
         FROM desafio_preguntas
         WHERE materia_slug = ? AND activa = 1 AND revisado_por_admin = 1
           AND id NOT IN (SELECT pregunta_id FROM desafio_preguntas_vistas WHERE usuario_id = ?)
         ORDER BY RAND() LIMIT 3"
    );
    $stmt->bind_param('si', $materia, $usuario_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

$rows = nb_desafio_preguntas_no_vistas($conn, $materia, $usuario_id);

if (count($rows) < 3) {
    $del = $conn->prepare(
        "DELETE dpv FROM desafio_preguntas_vistas dpv
         INNER JOIN desafio_preguntas dp ON dp.id = dpv.pregunta_id
         WHERE dpv.usuario_id = ? AND dp.materia_slug = ?"
    );
    $del->bind_param('is', $usuario_id, $materia);
    $del->execute();
    $del->close();

    $rows = nb_desafio_preguntas_no_vistas($conn, $materia, $usuario_id);
}

// Las opciones nulas (preguntas tipo 'vf', con solo opcion_a/opcion_b) se omiten
// del JSON en vez de mandarse como null — el front-end renderiza las que reciba,
// sin necesitar saber de antemano si son 2 o 4.
$preguntas = [];
foreach ($rows as $row) {
    $opciones = [];
    foreach (['a' => 'opcion_a', 'b' => 'opcion_b', 'c' => 'opcion_c', 'd' => 'opcion_d'] as $letra => $col) {
        if ($row[$col] !== null && $row[$col] !== '') $opciones[$letra] = $row[$col];
    }
    $preguntas[] = [
        'id' => (int)$row['id'],
        'tipo' => $row['tipo'],
        'enunciado' => $row['enunciado'],
        'opciones' => $opciones,
    ];
}

if (count($preguntas) < 3) {
    // Banco insuficiente para esta materia (revisado_por_admin=1 filtra preguntas
    // aún no aprobadas) — no es un error del cliente, es falta de contenido.
    echo json_encode(['ok' => false, 'error' => 'contenido_insuficiente', 'disponibles' => count($preguntas)]);
    exit;
}

echo json_encode(['ok' => true, 'materia' => $materia, 'preguntas' => $preguntas]);
