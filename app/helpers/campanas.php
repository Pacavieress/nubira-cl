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

function generarHtmlEmailRecuperarGmail($unsubUrl, string $bloqueCuponHtml = '') {
    $unsub_safe = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');
    $utm_base   = 'utm_source=email&amp;utm_medium=reactivacion&amp;utm_campaign=recuperar_gmails';
    return "
<p>En <strong>Nubira</strong> encuentras tutores para lo que estés estudiando.</p>

<p><strong>Elige por dónde partir:</strong></p>

<!-- Card destacada: PAES -->
<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#F0F9FF;border:1px solid #e5e7eb;border-radius:12px;margin:24px 0;overflow:hidden;\">
  <tr>
    <td>
      <img src=\"https://nubira.cl/upload/email/card-paes.png\" alt=\"PAES\" style=\"display:block;width:100%;height:auto;background-color:#DCEBF7;\">
    </td>
  </tr>
  <tr>
    <td style=\"padding:20px;\">
      <h3 style=\"margin:0 0 8px 0;font-size:18px;color:#111827;\">¿Estás preparando la PAES?</h3>
      <p style=\"margin:0 0 16px 0;font-size:14px;color:#374151;line-height:1.5;\">Tutores y material para reforzar la prueba, con clases 100% online.</p>
      <a href=\"https://nubira.cl/clases/paes?{$utm_base}&amp;utm_content=card_paes\"
         style=\"background-color:#54A6D8;color:#ffffff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;display:inline-block;\">
        Ver tutores PAES
      </a>
    </td>
  </tr>
</table>

<!-- Cards compactas: Matemáticas / Lenguaje / Biología / Inglés -->
<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #e5e7eb;border-radius:12px;margin:16px 0;overflow:hidden;\">
  <tr>
    <td width=\"96\" style=\"padding:0;\">
      <img src=\"https://nubira.cl/upload/email/card-matematicas.png\" alt=\"Matemáticas\" width=\"96\" height=\"96\" style=\"display:block;width:96px;height:96px;object-fit:cover;background-color:#DCEBF7;\">
    </td>
    <td style=\"padding:16px;vertical-align:middle;\">
      <h4 style=\"margin:0 0 4px 0;font-size:15px;color:#111827;\">¿Te está costando Matemáticas?</h4>
      <p style=\"margin:0 0 8px 0;font-size:13px;color:#6B7280;line-height:1.4;\">Cálculo, álgebra y más, a tu ritmo con un tutor.</p>
      <a href=\"https://nubira.cl/clases/matematicas?{$utm_base}&amp;utm_content=card_matematicas\"
         style=\"color:#54A6D8;font-size:13px;font-weight:bold;text-decoration:none;\">Ver tutores &rarr;</a>
    </td>
  </tr>
</table>

<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #e5e7eb;border-radius:12px;margin:16px 0;overflow:hidden;\">
  <tr>
    <td width=\"96\" style=\"padding:0;\">
      <img src=\"https://nubira.cl/upload/email/card-lenguaje.png\" alt=\"Lenguaje\" width=\"96\" height=\"96\" style=\"display:block;width:96px;height:96px;object-fit:cover;background-color:#DCEBF7;\">
    </td>
    <td style=\"padding:16px;vertical-align:middle;\">
      <h4 style=\"margin:0 0 4px 0;font-size:15px;color:#111827;\">¿Necesitas mejorar en Lenguaje?</h4>
      <p style=\"margin:0 0 8px 0;font-size:13px;color:#6B7280;line-height:1.4;\">Comprensión lectora, redacción y ensayos con quien sabe.</p>
      <a href=\"https://nubira.cl/clases/lenguaje?{$utm_base}&amp;utm_content=card_lenguaje\"
         style=\"color:#54A6D8;font-size:13px;font-weight:bold;text-decoration:none;\">Ver tutores &rarr;</a>
    </td>
  </tr>
</table>

<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #e5e7eb;border-radius:12px;margin:16px 0;overflow:hidden;\">
  <tr>
    <td width=\"96\" style=\"padding:0;\">
      <img src=\"https://nubira.cl/upload/email/card-biologia.png\" alt=\"Biología\" width=\"96\" height=\"96\" style=\"display:block;width:96px;height:96px;object-fit:cover;background-color:#DCEBF7;\">
    </td>
    <td style=\"padding:16px;vertical-align:middle;\">
      <h4 style=\"margin:0 0 4px 0;font-size:15px;color:#111827;\">¿Examen de Biología o Anatomía?</h4>
      <p style=\"margin:0 0 8px 0;font-size:13px;color:#6B7280;line-height:1.4;\">Tutores para reforzar antes de tu prueba.</p>
      <a href=\"https://nubira.cl/clases/biologia?{$utm_base}&amp;utm_content=card_biologia\"
         style=\"color:#54A6D8;font-size:13px;font-weight:bold;text-decoration:none;\">Ver tutores &rarr;</a>
    </td>
  </tr>
</table>

<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #e5e7eb;border-radius:12px;margin:16px 0;overflow:hidden;\">
  <tr>
    <td width=\"96\" style=\"padding:0;\">
      <img src=\"https://nubira.cl/upload/email/card-ingles.png\" alt=\"Inglés\" width=\"96\" height=\"96\" style=\"display:block;width:96px;height:96px;object-fit:cover;background-color:#DCEBF7;\">
    </td>
    <td style=\"padding:16px;vertical-align:middle;\">
      <h4 style=\"margin:0 0 4px 0;font-size:15px;color:#111827;\">¿Quieres avanzar en Inglés?</h4>
      <p style=\"margin:0 0 8px 0;font-size:13px;color:#6B7280;line-height:1.4;\">Práctica y apoyo para el ramo o para hablarlo mejor.</p>
      <a href=\"https://nubira.cl/clases/ingles?{$utm_base}&amp;utm_content=card_ingles\"
         style=\"color:#54A6D8;font-size:13px;font-weight:bold;text-decoration:none;\">Ver tutores &rarr;</a>
    </td>
  </tr>
</table>

<p style=\"font-size:13px;color:#6B7280;line-height:1.6;margin:24px 0;\">
  Clases 100% online, sin instalar Meet, Zoom ni Teams &middot; conversas con el tutor sin dar tu WhatsApp &middot; ves los horarios antes de escribir &middot; tu pago queda protegido hasta que confirmes la clase.
</p>

<p style=\"text-align:center; margin:32px 0;\">
  <a href=\"https://nubira.cl/registro?{$utm_base}\"
     style=\"background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;\">
    Regístrate gratis
  </a>
</p>

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
{$bloqueCuponHtml}
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

function nb_consultar_cupon_global(mysqli $conn, string $codigo): array {
    $codigo = strtoupper(trim($codigo));
    if ($codigo === '') return ['ok' => false, 'error' => 'Falta el código.'];

    $stmt = $conn->prepare("SELECT porcentaje_descuento, fecha_expiracion, servicio_id FROM cupones WHERE codigo = ? LIMIT 1");
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $cupon = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cupon) return ['ok' => false, 'error' => "El código '$codigo' no existe."];
    if (!empty($cupon['servicio_id'])) return ['ok' => false, 'error' => 'Este código está restringido a un servicio específico.'];

    return ['ok' => true, 'porcentaje' => (int)$cupon['porcentaje_descuento'], 'fecha_expiracion' => $cupon['fecha_expiracion']];
}

function nb_bloque_cupon_html(string $codigo, int $porcentaje, ?string $fecha_expiracion): string {
    $codigo_safe = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');
    $vigencia = $fecha_expiracion
        ? 'Válido hasta el ' . date('d/m/Y', strtotime($fecha_expiracion)) . '.'
        : 'Sin fecha límite.';
    return "
    <div style='background:#F0F9FF; border:1px dashed #54A6D8; border-radius:12px; padding:20px; margin:20px 0; text-align:center;'>
        <p style='margin:0 0 8px 0; font-size:13px; color:#0c4a6e; font-weight:bold;'>Tu código de descuento</p>
        <p style='margin:0; font-size:22px; font-weight:bold; letter-spacing:1px; color:#111;'>{$codigo_safe}</p>
        <p style='margin:8px 0 0 0; font-size:12px; color:#555;'>{$porcentaje}% de descuento en tu próxima clase. {$vigencia}</p>
    </div>";
}

function nb_generar_email_cupon_promocional(string $primer_nombre, string $codigo, int $porcentaje, ?string $fecha_expiracion, string $intro, string $correo): string {
    $nombre_safe = htmlspecialchars($primer_nombre, ENT_QUOTES, 'UTF-8');
    $bloqueCupon = nb_bloque_cupon_html($codigo, $porcentaje, $fecha_expiracion);
    $unsub_safe = htmlspecialchars(generarUnsubUrl($correo), ENT_QUOTES, 'UTF-8');
    return "
<p>Hola <strong>{$nombre_safe}</strong>,</p>
<p>{$intro}</p>
{$bloqueCupon}
<p style=\"text-align:center; margin:32px 0;\">
  <a href=\"https://nubira.cl/explorar?utm_source=email&amp;utm_medium=reactivacion&amp;utm_campaign=despertar_dormidos_cupon\"
     style=\"background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;\">
    Buscar tutor o servicio
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
  Si no quieres seguir recibiendo estos correos, puedes <a href=\"{$unsub_safe}\" style=\"color:#888;\">darte de baja aquí</a>.
</p>
";
}
