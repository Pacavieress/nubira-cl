<?php
require_once __DIR__ . '/init_sesion.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['rol'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
    exit;
}

$campana_id = (int)($_GET['campana_id'] ?? 0);
if ($campana_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

global $conn;

$stmt = $conn->prepare("
    SELECT a.nombre, a.institucion, av.fecha_leido
    FROM avisos_admin av
    JOIN alumnos a ON a.id = av.destino_id
    WHERE av.campana_id = ? AND av.leido = 1 AND av.fecha_leido IS NOT NULL
    ORDER BY av.fecha_leido DESC
    LIMIT 500
");
$stmt->bind_param("i", $campana_id);
$stmt->execute();
$res = $stmt->get_result();

$lectores = [];
while ($r = $res->fetch_assoc()) {
    $lectores[] = [
        'nombre' => $r['nombre'],
        'institucion' => $r['institucion'],
        'fecha_leido' => date('d/m/Y H:i', strtotime($r['fecha_leido']))
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'lectores' => $lectores]);
?>