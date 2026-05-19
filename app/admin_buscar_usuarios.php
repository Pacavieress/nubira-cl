<?php
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'usuarios' => []]);
    exit;
}

global $conn;

$like = '%' . $q . '%';
$stmt = $conn->prepare("
    SELECT id, nombre, correo, institucion 
    FROM alumnos 
    WHERE (nombre LIKE ? OR correo LIKE ?) 
    AND rol != 'admin' AND visible = 1 AND bloqueado = 0
    ORDER BY nombre ASC
    LIMIT 10
");
$stmt->bind_param("ss", $like, $like);
$stmt->execute();
$res = $stmt->get_result();

$usuarios = [];
while ($r = $res->fetch_assoc()) {
    $usuarios[] = [
        'id' => (int)$r['id'],
        'nombre' => $r['nombre'],
        'correo' => $r['correo'],
        'institucion' => $r['institucion'] ?: 'Sin institución'
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'usuarios' => $usuarios]);
?>