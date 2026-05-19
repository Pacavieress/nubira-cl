<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']); exit;
}

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado']); exit;
}

require_once __DIR__ . '/../conexion.php';

$apunte_id = filter_input(INPUT_POST, 'apunte_id', FILTER_VALIDATE_INT);
$materia_slug = trim($_POST['materia_slug'] ?? '');
$subtema = trim($_POST['subtema'] ?? '');
$nivel = trim($_POST['nivel'] ?? 'universitario');

if (!$apunte_id || $apunte_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID inválido']); exit;
}

if (!in_array($nivel, ['universitario','paes','escolar'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nivel inválido']); exit;
}

$materia_final = null;
if ($materia_slug !== '') {
    $stmt_check = $conn->prepare("SELECT slug FROM materias WHERE slug = ? AND activa = 1 LIMIT 1");
    $stmt_check->bind_param("s", $materia_slug);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Materia no válida']); exit;
    }
    $stmt_check->close();
    $materia_final = $materia_slug;
}

$subtema_final = ($subtema !== '') ? mb_substr($subtema, 0, 80) : null;

try {
    $stmt = $conn->prepare("UPDATE apuntes SET materia = ?, subtema = ?, nivel_academico = ? WHERE id = ?");
    $stmt->bind_param("sssi", $materia_final, $subtema_final, $nivel, $apunte_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true, 'materia' => $materia_final, 'nivel' => $nivel]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error servidor']);
}