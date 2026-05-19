<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$usuario_id = (int)$_SESSION['usuario_id'];

$sql = "
SELECT 
    s.id AS id_servicio,
    s.titulo AS titulo_servicio,
    u.id AS id_preguntador,
    u.nombre AS nombre_preguntador,
    p.id AS id_pregunta,
    p.pregunta,
    p.respuesta,
    p.fecha_pregunta
FROM preguntas_servicios p
JOIN servicios s ON s.id = p.id_servicio
JOIN alumnos u ON u.id = p.id_preguntador
WHERE s.alumno_id = ? AND p.archivado = 0
ORDER BY s.id, u.id, p.fecha_pregunta ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

$conversaciones = [];

while ($fila = $result->fetch_assoc()) {
    $key = $fila['id_servicio'] . '-' . $fila['id_preguntador'];

    if (!isset($conversaciones[$key])) {
        $conversaciones[$key] = [
            'servicio' => [
                'id' => (int)$fila['id_servicio'],
                'titulo' => htmlspecialchars($fila['titulo_servicio'], ENT_QUOTES, 'UTF-8')
            ],
            'usuario' => [
                'id' => (int)$fila['id_preguntador'],
                'nombre' => htmlspecialchars($fila['nombre_preguntador'], ENT_QUOTES, 'UTF-8')
            ],
            'mensajes' => []
        ];
    }

    $conversaciones[$key]['mensajes'][] = [
        'id_pregunta' => (int)$fila['id_pregunta'],
        'pregunta'    => htmlspecialchars($fila['pregunta'], ENT_QUOTES, 'UTF-8'),
        'respuesta'   => $fila['respuesta'] ? htmlspecialchars($fila['respuesta'], ENT_QUOTES, 'UTF-8') : null,
        'fecha'       => $fila['fecha_pregunta']
    ];
}

$stmt->close();
$conn->close();

// ✅ Limpieza extra: reindexar arrays y eliminar claves vacías
foreach ($conversaciones as &$conv) {
    $conv['mensajes'] = array_values($conv['mensajes']);
}
unset($conv);

// Enviar JSON bonito (útil si se inspecciona en red)
echo json_encode($conversaciones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
