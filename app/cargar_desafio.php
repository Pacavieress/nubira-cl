<?php
session_start();

// === BLOQUE ANTI-CACHE (mismo patrón que cargar_apuntes.php/cargar_servicios.php) ===
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

// Sirve 3 preguntas al azar de una materia para el "Desafío de hoy".
// Público (no requiere login) — el login se exige recién en responder_desafio.php,
// al momento de calcular y guardar el resultado.

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

$stmt = $conn->prepare(
    "SELECT id, enunciado, opcion_a, opcion_b, opcion_c, opcion_d
     FROM desafio_preguntas
     WHERE materia_slug = ? AND activa = 1 AND revisado_por_admin = 1
     ORDER BY RAND() LIMIT 3"
);
$stmt->bind_param('s', $materia);
$stmt->execute();
$res = $stmt->get_result();

$preguntas = [];
while ($row = $res->fetch_assoc()) {
    $preguntas[] = [
        'id' => (int)$row['id'],
        'enunciado' => $row['enunciado'],
        'opciones' => [
            'a' => $row['opcion_a'],
            'b' => $row['opcion_b'],
            'c' => $row['opcion_c'],
            'd' => $row['opcion_d'],
        ],
    ];
}
$stmt->close();

if (count($preguntas) < 3) {
    // Banco insuficiente para esta materia (revisado_por_admin=1 filtra preguntas
    // aún no aprobadas) — no es un error del cliente, es falta de contenido.
    echo json_encode(['ok' => false, 'error' => 'contenido_insuficiente', 'disponibles' => count($preguntas)]);
    exit;
}

echo json_encode(['ok' => true, 'materia' => $materia, 'preguntas' => $preguntas]);
