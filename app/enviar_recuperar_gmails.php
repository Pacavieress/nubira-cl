<?php
/**
 * Campaña de recuperación — Gmails bloqueados por política institucional anterior.
 *
 * USO web:  logueado como admin → /app/enviar_recuperar_gmails.php
 *           ?limite=5   → test con 5 correos (default seguro)
 *           ?limite=0   → corrida completa sin límite
 * USO CLI:  php app/enviar_recuperar_gmails.php [limite]
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
set_time_limit(600);

// ── Límite configurable ───────────────────────────────────────
// Web: ?limite=N — CLI: primer argumento. Default 5 (seguro).
$raw_limite = $es_cli ? ($argv[1] ?? 5) : ($_GET['limite'] ?? 5);
$LIMITE     = (int)$raw_limite; // 0 = sin límite

// ── Constantes de campaña ─────────────────────────────────────
$admin_id     = $_SESSION['usuario_id'] ?? 0;
$admin_nombre = 'recuperar_gmails_jun2026';
$asunto       = 'Ya puedes registrarte en Nubira con cualquier email';

// ── HTML del email ────────────────────────────────────────────
function generarHtmlEmailRecuperarGmail($unsubUrl) {
    $unsub_safe = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');
    return "
<p>Hola,</p>

<p>Hace un tiempo intentaste registrarte en <strong>Nubira.cl</strong> y no pudimos darte acceso
porque en ese momento solo permitíamos correos institucionales.</p>

<p><strong>Eso cambió. Ahora cualquier persona puede registrarse en Nubira.</strong></p>

<p>Por si no conoces la plataforma, esto es lo que vas a encontrar:</p>

<ul style=\"padding-left:20px; line-height:2;\">
  <li><strong>Clase 100% online en Nubira</strong> — aula virtual integrada,
      sin Meet, Zoom ni Teams.</li>
  <li><strong>Chat anónimo antes de contratar</strong> — conversa con el tutor
      sin compartir tu WhatsApp ni redes sociales.</li>
  <li><strong>Horarios publicados por el tutor</strong> — sabes cuándo está
      disponible antes de escribirle.</li>
  <li><strong>Garantía Nubira</strong> — tu pago está protegido hasta que
      confirmes que la clase se realizó.</li>
</ul>

<p style=\"text-align:center; margin:32px 0;\">
  <a href=\"https://nubira.cl/registro\"
     style=\"background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;\">
    Regístrate ahora
  </a>
</p>

<p>Gracias por la paciencia. Esperamos verte pronto.</p>

<p>Atentamente,<br>Equipo Nubira.cl</p>

<hr style=\"margin:30px 0;border:none;border-top:1px solid #eee;\">
<p style=\"font-size:11px;color:#888;\">
  Si no quieres recibir más correos de Nubira,
  <a href=\"{$unsub_safe}\" style=\"color:#888;\">puedes darte de baja aquí</a>.
</p>
";
}

// ── Query de destinatarios ────────────────────────────────────
$sql_base = "
    SELECT DISTINCT LOWER(TRIM(ir.correo)) AS correo
    FROM interesados_registro ir
    WHERE ir.correo LIKE '%@gmail.com'
      AND ir.correo NOT LIKE '%gmail.com.cl%'
      AND LOWER(TRIM(ir.correo)) NOT IN (
          SELECT LOWER(TRIM(correo)) FROM unsubscribed
      )
      AND LOWER(TRIM(ir.correo)) NOT IN (
          SELECT LOWER(TRIM(correo)) FROM alumnos WHERE visible = 1
      )
      AND LOWER(TRIM(ir.correo)) NOT IN (
          SELECT LOWER(TRIM(destinatario)) FROM correos_admin
           WHERE admin_nombre = 'recuperar_gmails_jun2026'
             AND exito = 1
      )
    ORDER BY ir.fecha ASC
";

if ($LIMITE > 0) {
    $stmt = $conn->prepare($sql_base . " LIMIT ?");
    $stmt->bind_param('i', $LIMITE);
} else {
    $stmt = $conn->prepare($sql_base);
}
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

if (!$res || $res->num_rows === 0) {
    $msg = "Sin destinatarios pendientes para {$admin_nombre}.";
    logCampana($msg);
    echo $es_cli ? $msg . "\n" : "<p style='font-family:sans-serif;padding:30px;'>{$msg}</p>";
    $conn->close();
    exit;
}

// ── Preparar log BD ───────────────────────────────────────────
$stmt_log = $conn->prepare(
    "INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$enviados = 0;
$fallidos = 0;
$detalle  = [];

// ── Loop de envío ─────────────────────────────────────────────
while ($row = $res->fetch_assoc()) {
    $correo = strtolower(trim($row['correo']));

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        logCampana('[RECUPERAR SKIP] correo inválido: ' . $correo);
        continue;
    }

    $unsubUrl  = generarUnsubUrl($correo);
    $html      = generarHtmlEmailRecuperarGmail($unsubUrl);
    $exito     = enviarDormidoConUnsubscribe($correo, $asunto, $html, $unsubUrl);
    $exito_int = $exito ? 1 : 0;

    $stmt_log->bind_param('issssi', $admin_id, $admin_nombre, $correo, $asunto, $html, $exito_int);
    $stmt_log->execute();

    if ($exito) {
        $enviados++;
        logCampana('[RECUPERAR OK] ' . $correo);
    } else {
        $fallidos++;
        logCampana('[RECUPERAR FAIL] ' . $correo);
    }

    $detalle[] = ['correo' => $correo, 'exito' => $exito];

    sleep(2);
}

$res->free();
$stmt_log->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Campaña Recuperar Gmails</title>
  <style>
    body  { font-family: sans-serif; padding: 30px; max-width: 700px; margin: auto; }
    table { border-collapse: collapse; width: 100%; margin-top: 16px; }
    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
    th    { background: #f5f5f5; }
    .ok   { color: green; font-weight: bold; }
    .fail { color: red;   font-weight: bold; }
  </style>
</head>
<body>
<h2>Campaña RECUPERAR GMAILS completada</h2>
<p>
  <b>Procesados: <?= $enviados + $fallidos ?></b> &nbsp;|&nbsp;
  Enviados: <span class="ok"><?= $enviados ?></span> &nbsp;|&nbsp;
  Fallidos: <span class="fail"><?= $fallidos ?></span> &nbsp;|&nbsp;
  Límite aplicado: <b><?= $LIMITE === 0 ? 'Sin límite' : $LIMITE ?></b>
</p>
<table>
  <thead><tr><th>Correo</th><th>Estado</th></tr></thead>
  <tbody>
  <?php foreach ($detalle as $d): ?>
    <tr>
      <td><?= htmlspecialchars($d['correo']) ?></td>
      <td class="<?= $d['exito'] ? 'ok' : 'fail' ?>">
        <?= $d['exito'] ? 'Enviado ✓' : 'Fallido ✗' ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
