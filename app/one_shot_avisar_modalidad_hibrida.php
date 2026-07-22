<?php
/**
 * ONE-SHOT: avisa a los tutores con servicios en modalidad Híbrido sobre el
 * cambio de regla de negocio (Nubira pasa a ser 100% online).
 * Ejecutar UNA SOLA VEZ (es idempotente: si se repite, salta lo ya avisado).
 * No es un cron recurrente — borrar este archivo después de correrlo.
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['cron_secret'])) {
    http_response_code(403);
    die('Forbidden');
}
require_once __DIR__ . '/env_loader.php';
$SECRET = getenv('ONE_SHOT_MODALIDAD_SECRET') ?: '';
if (php_sapi_name() !== 'cli' && ($_GET['cron_secret'] ?? '') !== $SECRET) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/conexion.php';

$admin_id_soporte = 1; // Soporte Nubira / admin@uc.cl

$res = $conn->query("
    SELECT id, alumno_id, titulo
    FROM servicios
    WHERE estado = 'aprobado'
      AND (visible = 1 OR visible IS NULL)
      AND modalidad = 'Híbrido'
      AND aviso_modalidad_enviado_en IS NULL
");

$total = 0;
while ($row = $res->fetch_assoc()) {
    $servicio_id = (int)$row['id'];
    $tutor_id    = (int)$row['alumno_id'];
    $titulo      = $row['titulo'];

    $mensaje = "¡Hola! Te escribimos porque desde ahora Nubira solo permite clases en modalidad Online "
             . "— ya no se ofrecen clases presenciales ni híbridas. Tu servicio \"$titulo\" está marcado "
             . "como Híbrido. Tienes 7 días para editarlo y confirmar la modalidad Online desde "
             . "\"Mis Publicaciones\". Si pasado ese plazo sigue en modalidad Híbrido, se ocultará "
             . "automáticamente hasta que lo actualices (no se elimina ni se rechaza, solo queda oculto "
             . "y vuelve a aparecer apenas lo edites). ¡Gracias por ser parte de Nubira!";

    $stmt = $conn->prepare("INSERT INTO avisos_admin (admin_id, destino_id, mensaje, tipo) VALUES (?, ?, ?, 'importante')");
    $stmt->bind_param("iis", $admin_id_soporte, $tutor_id, $mensaje);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("UPDATE servicios SET aviso_modalidad_enviado_en = NOW() WHERE id = ?");
    $stmt2->bind_param("i", $servicio_id);
    $stmt2->execute();
    $stmt2->close();

    $total++;
}

echo "Avisos enviados: $total\n";
