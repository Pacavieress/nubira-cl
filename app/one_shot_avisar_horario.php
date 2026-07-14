<?php
/**
 * ONE-SHOT: avisa a los tutores con servicios aprobados sin horario de
 * disponibilidad cargado sobre el nuevo plazo de 30 días.
 * Ejecutar UNA SOLA VEZ (es idempotente: si se repite, salta lo ya avisado).
 * No es un cron recurrente — borrar este archivo después de correrlo.
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}
require_once __DIR__ . '/env_loader.php';
$SECRET = getenv('ONE_SHOT_HORARIO_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers/horarios.php';

$admin_id_soporte = 1; // Soporte Nubira / admin@uc.cl

$res = $conn->query("
    SELECT id, alumno_id, titulo, horarios_json
    FROM servicios
    WHERE estado = 'aprobado'
      AND (visible = 1 OR visible IS NULL)
      AND aviso_horario_enviado_en IS NULL
");

$total = 0;
while ($row = $res->fetch_assoc()) {
    if (parsear_horarios_servicio($row['horarios_json'])['tiene_horarios']) {
        continue;
    }

    $servicio_id = (int)$row['id'];
    $tutor_id    = (int)$row['alumno_id'];
    $titulo      = $row['titulo'];

    $mensaje = "¡Hola! Te escribimos porque tu servicio \"$titulo\" no tiene horario de disponibilidad "
             . "cargado. A partir de ahora el horario es obligatorio para que los estudiantes puedan "
             . "contratarte. Tienes 30 días para configurarlo desde \"Mis Publicaciones\" → editar horario. "
             . "Si pasado ese plazo sigue sin horario, se ocultará automáticamente hasta que lo agregues "
             . "(no se elimina ni se rechaza, solo queda oculto y vuelve a aparecer apenas lo completes). "
             . "¡Gracias por ser parte de Nubira!";

    $stmt = $conn->prepare("INSERT INTO avisos_admin (admin_id, destino_id, mensaje, tipo) VALUES (?, ?, ?, 'importante')");
    $stmt->bind_param("iis", $admin_id_soporte, $tutor_id, $mensaje);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("UPDATE servicios SET aviso_horario_enviado_en = NOW() WHERE id = ?");
    $stmt2->bind_param("i", $servicio_id);
    $stmt2->execute();
    $stmt2->close();

    $total++;
}

echo "Avisos enviados: $total\n";
