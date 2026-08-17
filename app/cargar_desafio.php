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
// sesión), Y priorizar la dificultad acorde al nivel actual del usuario en esa
// materia (Punto 3: progresión entre sesiones — desafio_progreso.nivel_actual
// se ajusta en responder_desafio.php, acá solo se lee).
//
// Cascada de selección (siempre respetando "no vistas" primero):
//   1. dificultad = nivel_actual exacto
//   2. dificultad en [nivel_actual-1, nivel_actual, nivel_actual+1]
//   3. cualquier dificultad
//   4. si ni con eso hay 3 (banco agotado para este usuario en esta materia):
//      reset silencioso de vistas para esa materia, reintentar toda la cascada
function nb_desafio_preguntas_candidatas(mysqli $conn, string $materia, int $usuario_id, ?array $dificultades = null): array {
    $sql = "SELECT id, tipo, enunciado, desarrollo, opcion_a, opcion_b, opcion_c, opcion_d,
                   tiempo_limite_segundos, nivel_paes
            FROM desafio_preguntas
            WHERE materia_slug = ? AND activa = 1 AND revisado_por_admin = 1
              AND id NOT IN (SELECT pregunta_id FROM desafio_preguntas_vistas WHERE usuario_id = ?)";
    $tipos = 'si';
    $params = [$materia, $usuario_id];

    if ($dificultades !== null && count($dificultades) > 0) {
        $marcadores = implode(',', array_fill(0, count($dificultades), '?'));
        $sql .= " AND dificultad IN ($marcadores)";
        foreach ($dificultades as $d) { $tipos .= 'i'; $params[] = $d; }
    }
    $sql .= " ORDER BY RAND() LIMIT 3";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function nb_desafio_seleccionar(mysqli $conn, string $materia, int $usuario_id, int $nivel_actual): array {
    $rows = nb_desafio_preguntas_candidatas($conn, $materia, $usuario_id, [$nivel_actual]);
    if (count($rows) >= 3) return $rows;

    $vecinos = array_values(array_unique(array_filter(
        [$nivel_actual - 1, $nivel_actual, $nivel_actual + 1],
        fn($n) => $n >= 1 && $n <= 3
    )));
    $rows = nb_desafio_preguntas_candidatas($conn, $materia, $usuario_id, $vecinos);
    if (count($rows) >= 3) return $rows;

    return nb_desafio_preguntas_candidatas($conn, $materia, $usuario_id, null);
}

$nivelStmt = $conn->prepare("SELECT nivel_actual FROM desafio_progreso WHERE usuario_id = ? AND materia_slug = ?");
$nivelStmt->bind_param('is', $usuario_id, $materia);
$nivelStmt->execute();
$nivelRow = $nivelStmt->get_result()->fetch_assoc();
$nivelStmt->close();
$nivel_actual = $nivelRow ? (int)$nivelRow['nivel_actual'] : 2; // 2=medio, default para quien nunca jugó esta materia

$rows = nb_desafio_seleccionar($conn, $materia, $usuario_id, $nivel_actual);

if (count($rows) < 3) {
    $del = $conn->prepare(
        "DELETE dpv FROM desafio_preguntas_vistas dpv
         INNER JOIN desafio_preguntas dp ON dp.id = dpv.pregunta_id
         WHERE dpv.usuario_id = ? AND dp.materia_slug = ?"
    );
    $del->bind_param('is', $usuario_id, $materia);
    $del->execute();
    $del->close();

    $rows = nb_desafio_seleccionar($conn, $materia, $usuario_id, $nivel_actual);
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
        'desarrollo' => $row['desarrollo'], // solo 'encuentra_error'; null en el resto
        'opciones' => $opciones,
        'tiempo_limite_segundos' => $row['tiempo_limite_segundos'] !== null ? (int)$row['tiempo_limite_segundos'] : null,
        'nivel_paes' => (bool)$row['nivel_paes'],
    ];
}

if (count($preguntas) < 3) {
    // Banco insuficiente para esta materia (revisado_por_admin=1 filtra preguntas
    // aún no aprobadas) — no es un error del cliente, es falta de contenido.
    echo json_encode(['ok' => false, 'error' => 'contenido_insuficiente', 'disponibles' => count($preguntas)]);
    exit;
}

echo json_encode(['ok' => true, 'materia' => $materia, 'preguntas' => $preguntas]);
