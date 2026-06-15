<?php
/**
 * Campaña de re-invitación a prospectos @gmail.com que intentaron registrarse
 * antes de la apertura a gmail.com y nunca fueron contactados.
 *
 * USO RECOMENDADO: primera corrida con $LIMITE = 5, revisar log_correos.txt,
 * y luego subir el límite para la corrida completa (~98).
 *
 * Ejecutable por:
 *   - Web: logueado como admin → /app/enviar_invitaciones_fallos.php
 *   - CLI: php app/enviar_invitaciones_fallos.php   (sin auth, solo terminal)
 */

// ============================================================
// FIX 3 — AUTH (antes de cargar correo.php/config.php para no
// arrastrar el output de las 2 líneas en blanco de config.php)
// ============================================================
$es_cli = (php_sapi_name() === 'cli');
if (!$es_cli) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
        http_response_code(403);
        exit('403 - Acceso restringido a administradores.');
    }
}

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php'; // carga PHPMailer + getSmtpConfig() + plantillaMaestra()

date_default_timezone_set('America/Santiago');

// FIX 1 — LOG_PATH no estaba definido (era error fatal en PHP 8)
if (!defined('LOG_PATH')) {
    define('LOG_PATH', __DIR__ . '/log_correos.txt');
}

// Tanda: 30 por corrida para control. Quedan ~93 prospectos.
$LIMITE = 30;

function logCampania($linea) {
    file_put_contents(LOG_PATH, date('Y-m-d H:i:s') . ' - ' . $linea . "\n", FILE_APPEND);
}

/**
 * Envío self-contained con header List-Unsubscribe (FIX 4).
 * No usa enviarCorreo() porque ese wrapper no permite headers SMTP custom.
 * Reusa getSmtpConfig() y plantillaMaestra() de correo.php.
 */
function enviarInvitacionConUnsubscribe($destinatario, $asunto, $htmlInterno, $unsubUrl) {
    $cfg  = getSmtpConfig('contacto'); // mejor reputación que no-reply para marketing
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['user'];
        $mail->Password   = $cfg['pass'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['user'], $cfg['name']);
        $mail->addAddress($destinatario);
        $mail->addReplyTo($cfg['user'], $cfg['name']);

        // FIX 4 — headers de baja (Ley 19.628). El endpoint https /unsubscribe
        // ya está en producción; el mailto es el fallback.
        $mail->addCustomHeader(
            'List-Unsubscribe',
            '<mailto:contacto@nubira.cl?subject=unsubscribe>, <' . $unsubUrl . '>'
        );
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = plantillaMaestra($asunto, $htmlInterno);
        $mail->AltBody = strip_tags($htmlInterno);

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        logCampania('[ERROR PHPMailer] ' . $destinatario . ' :: ' . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
// FIX 2 — Query con targeting correcto (UNION + DISTINCT)
// ============================================================
$sql = "
    SELECT DISTINCT email FROM (
        SELECT LOWER(TRIM(correo)) AS email
          FROM interesados_registro
         WHERE invitado = 0 AND correo IS NOT NULL AND correo <> ''
        UNION
        SELECT LOWER(TRIM(correo)) AS email
          FROM login_fallos
         WHERE correo IS NOT NULL AND correo <> ''
    ) AS prospectos
    WHERE email LIKE '%@gmail.com'          -- solo gmail.com (ancla al final)
      AND email NOT LIKE '%gmail.com.cl%'   -- excluye typo gmail.com.cl
      AND email NOT LIKE '%@nubira.cl'      -- excluye internos
      AND email NOT IN (                     -- excluye dados de baja (List-Unsubscribe)
            SELECT LOWER(TRIM(correo)) FROM unsubscribed
      )
      AND email NOT IN (                     -- excluye ya-enviados (cubre login_fallos sin columna invitado)
            SELECT LOWER(TRIM(correo)) FROM interesados_registro WHERE invitado = 1
      )
      AND NOT EXISTS (                       -- excluye ya-registrados (case-insensitive)
            SELECT 1 FROM alumnos a
             WHERE LOWER(TRIM(a.correo)) = prospectos.email
      )
    ORDER BY email
    LIMIT ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $LIMITE);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    echo "No hay prospectos gmail pendientes.\n";
    $stmt->close();
    $conn->close();
    exit;
}

$enviados = 0;
$fallidos = 0;

while ($row = $res->fetch_assoc()) {
    $correo = strtolower(trim($row['email']));

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        logCampania('[SKIP] correo invalido: ' . $correo);
        continue;
    }

    // Token de baja (FIX 4) — determinístico por email
    $token    = hash_hmac('sha256', $correo, UNSUB_SECRET);
    $unsubUrl = 'https://nubira.cl/unsubscribe?token=' . $token . '&e=' . urlencode($correo);

    $asunto = "Ya puedes crear tu cuenta en Nubira";
    $html = "
<p>Hola 👋</p>

<p>En su momento intentaste registrarte en <b>Nubira.cl</b>, pero solo aceptábamos correos universitarios (.uc.cl, .usach.cl, etc).</p>

<p><b>Buenas noticias:</b> abrimos el registro a Gmail y otros correos. Ya puedes crear tu cuenta y aprovechar la plataforma.</p>

<p>En Nubira encontrarás:</p>
<ul>
  <li>🎓 <b>Tutores verificados</b> por sus universidades</li>
  <li>💰 <b>Pago protegido</b> con Garantía Nubira</li>
  <li>📚 Apuntes y clases particulares</li>
  <li>🤝 Comunicación directa con tutores</li>
</ul>

<p style='text-align:center;margin:24px 0;'>
  <a href='https://nubira.cl/register?email=" . urlencode($correo) . "'
     style='background:#54A6D8;color:white;padding:14px 28px;border-radius:8px;
     text-decoration:none;font-weight:bold;display:inline-block;font-size:16px'>
     Crear mi cuenta gratis
  </a>
</p>

<p style='color:#6B7280;font-size:14px;text-align:center;'>
  ¿Te interesa ofrecer tus servicios como tutor? También puedes registrarte como vendedor.
</p>

<p style='font-size:12px;color:#9CA3AF;margin-top:32px;text-align:center;border-top:1px solid #E5E7EB;padding-top:16px;'>
  Recibes este correo porque intentaste registrarte en Nubira.cl.<br>
  Si no quieres recibir más correos de Nubira,
  <a href='" . $unsubUrl . "' style='color:#9CA3AF;'>haz clic aquí</a>.
</p>
    ";

    logCampania('Enviando invitacion a ' . $correo);
    $ok = enviarInvitacionConUnsubscribe($correo, $asunto, $html, $unsubUrl);

    if ($ok) {
        // FIX 5 — marcar invitado + fecha_envio_correo por correo (UNION pierde el id)
        $upd = $conn->prepare(
            "UPDATE interesados_registro
                SET invitado = 1, fecha_envio_correo = NOW()
              WHERE LOWER(TRIM(correo)) = ?"
        );
        $upd->bind_param('s', $correo);
        $upd->execute();
        $afectadas = $upd->affected_rows;
        $upd->close();

        // Si el email solo estaba en login_fallos, registrarlo para dedupe futuro
        if ($afectadas === 0) {
            $ins = $conn->prepare(
                "INSERT IGNORE INTO interesados_registro (correo, ip, invitado, fecha, fecha_envio_correo)
                 VALUES (?, NULL, 1, NOW(), NOW())"
            );
            $ins->bind_param('s', $correo);
            $ins->execute();
            $ins->close();
        }

        $enviados++;
        echo "OK  -> $correo\n";
        logCampania('[OK] ' . $correo);
    } else {
        $fallidos++;
        echo "FAIL -> $correo\n";
        logCampania('[FALLO] ' . $correo);
    }

    sleep(1); // throttling SMTP
}

$stmt->close();
$conn->close();
echo "Proceso finalizado. Enviados: $enviados | Fallidos: $fallidos\n";
