<?php
// app/recibir_mensajes_chat_mini_aula.php
declare(strict_types=1);

session_start();

// 1. SEGURIDAD
if (!isset($_SESSION['usuario_id']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'mensaje' => 'Acceso denegado']));
}

require_once __DIR__ . '/db_conexion.php';

// 2. VALIDACIÓN DE ENTRADA
$mini_aula_id = filter_input(INPUT_GET, 'mini_aula_id', FILTER_VALIDATE_INT);
$ultimo_id    = filter_input(INPUT_GET, 'ultimo_id', FILTER_VALIDATE_INT);

if (!$mini_aula_id || $ultimo_id === null) {
    exit(json_encode(['status' => 'empty'])); // No hay datos para procesar
}

// 3. CONSULTA OPTIMIZADA (Solo mensajes NUEVOS)
// Buscamos mensajes con ID mayor al último que tiene el cliente
$nuevos_mensajes = [];
$usuario_actual = $_SESSION['usuario_id'];

$sql = "SELECT m.id, m.contenido, m.fecha_envio, m.usuario_id, 
        u.nombre, u.apellido, u.foto_perfil 
        FROM mensajes_mini_aula m 
        JOIN usuarios u ON m.usuario_id = u.id 
        WHERE m.mini_aula_id = ? AND m.id > ? 
        ORDER BY m.fecha_envio ASC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("ii", $mini_aula_id, $ultimo_id);
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($row = $resultado->fetch_assoc()) {
        // Preparamos datos listos para renderizar en JS
        $nuevos_mensajes[] = [
            'id' => $row['id'],
            'contenido' => nl2br(htmlspecialchars($row['contenido'])),
            'es_mio' => ($row['usuario_id'] == $usuario_actual),
            'nombre' => htmlspecialchars($row['nombre']),
            'foto' => !empty($row['foto_perfil']) ? htmlspecialchars($row['foto_perfil']) : '/img/default-avatar.png',
            'hora' => date('H:i', strtotime($row['fecha_envio']))
        ];
    }
    $stmt->close();
}

// 4. RESPUESTA JSON
echo json_encode([
    'status' => 'success',
    'mensajes' => $nuevos_mensajes
]);
?>