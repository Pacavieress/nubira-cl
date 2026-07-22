<?php
/**
 * Helper de campañas de email — funciones compartidas.
 * Prerequisitos: correo.php cargado (getSmtpConfig, plantillaMaestra), UNSUB_SECRET definido.
 */

function logCampana($linea) {
    $path = defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../../log_correos.txt';
    file_put_contents($path, date('Y-m-d H:i:s') . ' [CAMPANA] ' . $linea . "\n", FILE_APPEND);
}

function generarUnsubUrl($correo) {
    $token = hash_hmac('sha256', $correo, UNSUB_SECRET);
    return 'https://nubira.cl/unsubscribe?token=' . $token . '&e=' . urlencode($correo);
}

function generarHtmlEmailDormido($nombre, $dias, $unsubUrl) {
    $nombre_safe = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    return "
<p>Hola {$nombre_safe},</p>

<p>Te escribimos desde el equipo de <b>Nubira.cl</b>, la plataforma chilena de tutores universitarios.</p>

<p>Te registraste hace {$dias} días pero no has vuelto.
Mientras tanto, nuestros tutores han ayudado a estudiantes en:</p>

<ul>
<li>Cálculo, Física, Química</li>
<li>Inglés y preparación PAES</li>
<li>Tesis y asesorías universitarias</li>
</ul>

<p>¿Necesitas ayuda con algún ramo este semestre?</p>

<p><a href=\"https://nubira.cl/explorar\"
      style=\"background:#54A6D8;color:white;padding:12px 24px;
             text-decoration:none;border-radius:8px;display:inline-block;\">
   Ver tutores disponibles
</a></p>

<p>Atentamente,<br>Equipo Nubira.cl</p>

<hr style=\"margin:30px 0;border:none;border-top:1px solid #eee;\">

<p style=\"font-size:11px;color:#888;\">
   P.D. Si ya no te interesa Nubira, puedes
   <a href=\"{$unsubUrl}\" style=\"color:#888;\">darte de baja aquí</a>.
</p>
";
}

function generarHtmlEmailRecuperarGmail($unsubUrl) {
    $unsub_safe = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');
    return "
<p>Hola,</p>

<p>Hace un tiempo intentaste registrarte en <strong>Nubira.cl</strong> y no pudimos darte acceso
porque en ese momento solo permitíamos correos institucionales.</p>

<p><strong>Eso cambió. Ahora cualquier persona puede registrarse en Nubira.</strong></p>

<p><strong>Lo que hace distinta a Nubira:</strong></p>

<ul style=\"padding-left:20px; line-height:2.2;\">
  <li>Clases 100% online en la plataforma, sin instalar Meet, Zoom ni Teams.</li>
  <li>Conversa con el tutor sin compartir tu WhatsApp ni contactos de redes sociales.</li>
  <li>Ves los horarios publicados por el tutor antes de escribirle.</li>
  <li>Tu pago queda protegido hasta que confirmes que la clase se realizó.</li>
</ul>

<p style=\"text-align:center; margin:32px 0;\">
  <a href=\"https://nubira.cl/registro\"
     style=\"background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;\">
    Regístrate ahora
  </a>
</p>

<p>Equipo Nubira<br><span style=\"color:#9CA3AF; font-size:14px;\">Nubira.cl</span></p>

<p style=\"text-align:center;margin-top:26px;margin-bottom:6px;font-size:13px;color:#555;\">
  Síguenos en redes sociales:
</p>
<p style=\"text-align:center;margin-bottom:24px;\">
  <a href=\"https://instagram.com/nubira.cl\" target=\"_blank\" style=\"margin:0 8px;display:inline-block;\">
    <img src=\"https://nubira.cl/upload/email/icon-instagram.png\" alt=\"Instagram Nubira\" width=\"26\" style=\"display:inline-block;border:0;\">
  </a>
  <a href=\"https://facebook.com/nubira.cl\" target=\"_blank\" style=\"margin:0 8px;display:inline-block;\">
    <img src=\"https://nubira.cl/upload/email/icon-facebook.png\" alt=\"Facebook Nubira\" width=\"26\" style=\"display:inline-block;border:0;\">
  </a>
</p>

<hr style=\"margin:30px 0;border:none;border-top:1px solid #eee;\">
<p style=\"font-size:11px;color:#888;\">
  Si no quieres recibir más correos de Nubira,
  <a href=\"{$unsub_safe}\" style=\"color:#888;\">puedes darte de baja aquí</a>.
</p>
";
}

function enviarDormidoConUnsubscribe($destinatario, $asunto, $htmlInterno, $unsubUrl, $sender = 'contacto') {
    $cfg  = getSmtpConfig($sender);
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

        $mail->addCustomHeader(
            'List-Unsubscribe',
            '<mailto:' . $cfg['user'] . '?subject=unsubscribe>, <' . $unsubUrl . '>'
        );
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = plantillaMaestra($asunto, $htmlInterno);
        $mail->AltBody = strip_tags($htmlInterno);

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        logCampana('[ERROR] ' . $destinatario . ' :: ' . $mail->ErrorInfo);
        return false;
    }
}

function buildQueryDormidos($segmento, $limite, $universidad, $solo_count = false) {
    $rangos = [
        '0-30'   => "DATEDIFF(NOW(), fecha_registro) BETWEEN 1 AND 30",
        '31-90'  => "DATEDIFF(NOW(), fecha_registro) BETWEEN 31 AND 90",
        '91-180' => "DATEDIFF(NOW(), fecha_registro) BETWEEN 91 AND 180",
        '180+'   => "DATEDIFF(NOW(), fecha_registro) >= 181",
        'todos'  => "DATEDIFF(NOW(), fecha_registro) >= 1",
    ];

    $where = [
        "a.visible = 1",
        "a.confirmado = 1",
        "a.rol = 'alumno'",
        "a.id NOT IN (SELECT DISTINCT alumno_id FROM servicios)",
        "a.id NOT IN (SELECT DISTINCT comprador_id FROM contratos WHERE comprador_id IS NOT NULL)",
        "a.correo NOT IN (SELECT correo FROM unsubscribed)",
    ];

    if (isset($rangos[$segmento])) {
        $where[] = $rangos[$segmento];
    }

    $params = [];
    $tipos  = '';

    if ($universidad !== '') {
        $where[] = "a.correo LIKE ?";
        $params[] = '%' . $universidad . '%';
        $tipos   .= 's';
    }

    if ($solo_count) {
        return [
            'sql'    => "SELECT COUNT(*) AS total FROM alumnos a WHERE " . implode(" AND ", $where),
            'tipos'  => $tipos,
            'params' => $params,
        ];
    }

    $sql = "SELECT a.id, a.nombre, a.correo,
                   DATEDIFF(NOW(), fecha_registro) AS dias_inactivo,
                   COALESCE(a.institucion, '') AS institucion
            FROM alumnos a
            WHERE " . implode(" AND ", $where) . "
            ORDER BY fecha_registro DESC";

    if ($limite !== 'todos' && $limite !== '' && (int)$limite > 0) {
        $sql    .= " LIMIT ?";
        $params[] = (int)$limite;
        $tipos   .= 'i';
    }

    return ['sql' => $sql, 'tipos' => $tipos, 'params' => $params];
}
