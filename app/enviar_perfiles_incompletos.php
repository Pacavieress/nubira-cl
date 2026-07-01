<?php
/**
 * Campaña de perfiles incompletos — tutores con datos sin completar.
 * USO web: logueado como admin → /app/enviar_perfiles_incompletos.php
 * USO CLI: php app/enviar_perfiles_incompletos.php
 */

$es_cli = (php_sapi_name() === 'cli');
if (!$es_cli) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
        http_response_code(403);
        exit('403 - Acceso restringido a administradores.');
    }
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/helpers/campanas.php';

date_default_timezone_set('America/Santiago');
if (!defined('LOG_PATH')) define('LOG_PATH', __DIR__ . '/log_correos.txt');
set_time_limit(300);

// ── Email ─────────────────────────────────────────────────────
function generarHtmlEmailPerfilIncompleto($nombre, array $faltantes, $unsubUrl) {
    $nombre_safe = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $items_html  = '';
    foreach ($faltantes as $item) {
        $items_html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    return "
<p>Hola {$nombre_safe},</p>

<p>Te escribimos desde el equipo de <b>Nubira.cl</b>.</p>

<p>Revisamos tu perfil y notamos que falta completar:</p>

<ul>{$items_html}</ul>

<p>Completar tu perfil y horarios hace que los alumnos te encuentren más rápido,
agenden con confianza y aumenten tus contrataciones.</p>

<p><b>Te toma 2 minutos:</b></p>
<ul>
<li><a href=\"https://nubira.cl/configurar-cuenta\">Editar perfil</a></li>
<li><a href=\"https://nubira.cl/mis-publicaciones\">Editar servicios</a></li>
</ul>

<p>P.D. Si tienes dudas, responde este correo y te ayudamos.</p>

<p>Atentamente,<br>Equipo Nubira.cl</p>

<hr style=\"margin:30px 0;border:none;border-top:1px solid #eee;\">

<p style=\"font-size:11px;color:#888;\">
   Si ya no deseas recibir mensajes de Nubira, puedes
   <a href=\"{$unsubUrl}\" style=\"color:#888;\">darte de baja aquí</a>.
</p>
";
}

// ── Configurable ──────────────────────────────────────────────
$LIMITE       = 20;
$admin_id     = $_SESSION['usuario_id'] ?? 0;
$admin_nombre = 'perfil_incompleto_v1';
$asunto       = 'Completa tu perfil en Nubira 🎓';
// ─────────────────────────────────────────────────────────────

// ── Query tutores con perfil incompleto ───────────────────────
$sql = "SELECT
    a.id, a.nombre, a.correo,
    a.foto_perfil, a.bio, a.tipo,
    COUNT(DISTINCT s.id) AS total_servicios,
    COUNT(DISTINCT CASE WHEN s.horarios_json IS NOT NULL
                         AND s.horarios_json != ''
                         AND s.horarios_json LIKE '% - %'
                         THEN s.id END) AS servicios_con_horario
FROM alumnos a
INNER JOIN servicios s ON s.alumno_id = a.id AND s.estado = 'aprobado'
WHERE a.visible = 1 AND a.confirmado = 1
  AND a.correo NOT IN (SELECT correo FROM unsubscribed)
GROUP BY a.id
HAVING (a.foto_perfil IS NULL OR a.foto_perfil = ''
     OR a.bio IS NULL OR a.bio = ''
     OR a.tipo IS NULL OR a.tipo = ''
     OR servicios_con_horario < total_servicios)
ORDER BY a.nombre ASC
LIMIT ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $LIMITE);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if (!$res || $res->num_rows === 0) {
    echo "<p>No hay tutores con perfil incompleto (o todos están en unsubscribed).</p>";
    $conn->close();
    exit;
}

// ── Log BD ────────────────────────────────────────────────────
$stmt_log = $conn->prepare(
    "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$enviados = 0;
$fallidos = 0;
$detalle  = [];

while ($row = $res->fetch_assoc()) {
    $correo      = strtolower(trim($row['correo']));
    $nombre      = $row['nombre'];
    $total_s     = (int)$row['total_servicios'];
    $con_horario = (int)$row['servicios_con_horario'];

    $faltantes = [];
    if (empty($row['foto_perfil']))             $faltantes[] = 'Foto de perfil';
    if (empty(trim((string)$row['bio'])))       $faltantes[] = 'Bio (descripción personal)';
    if (empty($row['tipo']))                    $faltantes[] = 'Tipo: estudiante / egresado / profesor / particular';
    if ($con_horario < $total_s) {
        $sin         = $total_s - $con_horario;
        $faltantes[] = "Horario de clases en {$sin} de {$total_s} servicio" . ($total_s !== 1 ? 's' : '');
    }

    $unsubUrl = generarUnsubUrl($correo);
    $html     = generarHtmlEmailPerfilIncompleto($nombre, $faltantes, $unsubUrl);

    $exito     = enviarDormidoConUnsubscribe($correo, $asunto, $html, $unsubUrl);
    $exito_int = $exito ? 1 : 0;

    $stmt_log->bind_param('issssi', $admin_id, $admin_nombre, $correo, $asunto, $html, $exito_int);
    $stmt_log->execute();

    if ($exito) {
        $enviados++;
        logCampana('[PERFIL OK] ' . $correo . ' — falta: ' . implode(', ', $faltantes));
    } else {
        $fallidos++;
        logCampana('[PERFIL FAIL] ' . $correo);
    }

    $detalle[] = ['nombre' => $nombre, 'correo' => $correo,
                  'faltantes' => $faltantes, 'exito' => $exito];
    sleep(1);
}

$res->free();
$stmt_log->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Campaña Perfiles Incompletos</title>
<style>
  body  { font-family: sans-serif; padding: 30px; max-width: 960px; margin: auto; }
  table { border-collapse: collapse; width: 100%; margin-top: 16px; }
  th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; vertical-align: top; }
  th    { background: #f5f5f5; }
  ul    { margin: 0; padding-left: 18px; }
  .ok   { color: green; font-weight: bold; }
  .fail { color: red;   font-weight: bold; }
</style>
</head>
<body>
<h2>Campaña PERFILES INCOMPLETOS completada</h2>
<p>
  <b>Total: <?= $enviados + $fallidos ?></b> &nbsp;|&nbsp;
  Enviados: <span class="ok"><?= $enviados ?></span> &nbsp;|&nbsp;
  Fallidos: <span class="fail"><?= $fallidos ?></span>
</p>
<table>
  <thead>
    <tr><th>Nombre</th><th>Correo</th><th>Faltantes</th><th>Estado</th></tr>
  </thead>
  <tbody>
  <?php foreach ($detalle as $d): ?>
    <tr>
      <td><?= htmlspecialchars($d['nombre']) ?></td>
      <td><?= htmlspecialchars($d['correo']) ?></td>
      <td><ul><?php foreach ($d['faltantes'] as $f): ?>
        <li><?= htmlspecialchars($f) ?></li>
      <?php endforeach; ?></ul></td>
      <td class="<?= $d['exito'] ? 'ok' : 'fail' ?>">
        <?= $d['exito'] ? 'Enviado ✓' : 'Fallido ✗' ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
