<?php
/**
 * Campaña de reactivación — usuarios dormidos (registrados sin actividad).
 * USO web: logueado como admin → /app/enviar_dormidos.php
 * USO CLI: php app/enviar_dormidos.php
 * Primera corrida recomendada: $LIMITE = 5, revisar log_correos.txt.
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

// ── Configurable ──────────────────────────────────────────────
$LIMITE = 5;
// ─────────────────────────────────────────────────────────────

$admin_id     = $_SESSION['usuario_id'] ?? 0;
$admin_nombre = 'campaña_dormidos_v1';
$asunto       = "¿Te ayudamos con tus ramos este semestre?";

// ── Selección dormidos ────────────────────────────────────────
$q    = buildQueryDormidos('31-90', $LIMITE, '');
$stmt = $conn->prepare($q['sql']);
if ($q['tipos']) $stmt->bind_param($q['tipos'], ...$q['params']);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    echo "<p>No hay usuarios dormidos pendientes en el rango 31–90 días.</p>";
    $stmt->close();
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
    $correo = strtolower(trim($row['correo']));
    $nombre = $row['nombre'];
    $dias   = (int)$row['dias_inactivo'];

    $unsubUrl = generarUnsubUrl($correo);
    $html     = generarHtmlEmailDormido($nombre, $dias, $unsubUrl);

    $exito     = enviarDormidoConUnsubscribe($correo, $asunto, $html, $unsubUrl, 'noreply');
    $exito_int = $exito ? 1 : 0;

    $stmt_log->bind_param('issssi', $admin_id, $admin_nombre, $correo, $asunto, $html, $exito_int);
    $stmt_log->execute();

    if ($exito) {
        $enviados++;
        logCampana('[OK] ' . $correo . ' (' . $dias . ' días)');
    } else {
        $fallidos++;
        logCampana('[FAIL] ' . $correo);
    }

    $detalle[] = ['correo' => $correo, 'dias' => $dias, 'exito' => $exito];

    sleep(1);
}

$stmt->close();
$stmt_log->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Campaña Dormidos V1</title>
<style>
  body { font-family: sans-serif; padding: 30px; max-width: 700px; margin: auto; }
  table { border-collapse: collapse; width: 100%; margin-top: 16px; }
  th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
  th { background: #f5f5f5; }
</style>
</head>
<body>
<h2>Campaña DORMIDOS V1 completada</h2>
<p>
  <b>Total: <?= $enviados + $fallidos ?></b> &nbsp;|&nbsp;
  Exitosos: <span style="color:green;font-weight:bold"><?= $enviados ?></span> &nbsp;|&nbsp;
  Fallidos: <span style="color:red;font-weight:bold"><?= $fallidos ?></span>
</p>
<table>
  <thead><tr><th>Correo</th><th>Días inactivo</th><th>Estado</th></tr></thead>
  <tbody>
  <?php foreach ($detalle as $d): ?>
    <tr>
      <td><?= htmlspecialchars($d['correo']) ?></td>
      <td><?= $d['dias'] ?></td>
      <td style="color:<?= $d['exito'] ? 'green' : 'red' ?>;font-weight:bold">
          <?= $d['exito'] ? 'Enviado ✓' : 'Fallido ✗' ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
