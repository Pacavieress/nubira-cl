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

// Feedback "¿Te resultó útil este correo?" (tabla correo_feedback). Mismo secreto que la baja, pero con prefijo
// 'feedback|' y el voto dentro de la firma: un token de baja no sirve para votar y un voto no se puede cambiar editando el enlace.
const CAMPANA_FEEDBACK = 'recuperar_gmails';

function feedbackToken(string $correo, string $campana, string $voto): string {
    return hash_hmac('sha256', 'feedback|' . $campana . '|' . $voto . '|' . $correo, UNSUB_SECRET);
}

function generarFeedbackUrl(string $correo, string $voto, string $campana = CAMPANA_FEEDBACK): string {
    return 'https://nubira.cl/feedback?c=' . rawurlencode($campana) . '&v=' . $voto
         . '&e=' . rawurlencode($correo) . '&token=' . feedbackToken($correo, $campana, $voto);
}

// Tope diario de correos de campaña (Hostinger limita el SMTP por día). Ajustable.
if (!defined('CAMPANA_TOPE_DIARIO')) define('CAMPANA_TOPE_DIARIO', 100);
// Campañas que cuentan contra el tope (valores de correos_admin.admin_nombre). Exactos + prefijos con LIKE
// (admin_campanas.php genera 'campaña_dormidos_YYYYMMDD_HHMM'; enviar_dormidos.php usa 'campaña_dormidos_v1').
const CAMPANA_NOMBRES_TOPE = [
    'recuperar_gmails_jun2026',
    'despertar_dormidos_jun2026',
    'anuncio_video_tutores_jun2026',
    'cupon_alternativas_jul2026',
    'perfil_incompleto_v1',
];
const CAMPANA_PREFIJOS_TOPE = ['campaña_dormidos_%'];
const CAMPANA_REPLY_TO = 'contacto@nubira.cl';

// Cupo que queda hoy: tope menos los envíos exitosos de hoy (hora Chile, la fija conexion.php).
function campanaCupoRestante(mysqli $conn): int {
    $ph      = implode(',', array_fill(0, count(CAMPANA_NOMBRES_TOPE), '?'));
    $likes   = implode(' OR ', array_fill(0, count(CAMPANA_PREFIJOS_TOPE), 'admin_nombre LIKE ?'));
    $params  = array_merge(CAMPANA_NOMBRES_TOPE, CAMPANA_PREFIJOS_TOPE);
    $stmt    = $conn->prepare("SELECT COUNT(*) FROM correos_admin
                                WHERE exito = 1 AND DATE(fecha_envio) = CURDATE()
                                  AND (admin_nombre IN ($ph) OR $likes)");
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $hoy = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return max(0, CAMPANA_TOPE_DIARIO - $hoy);
}

// HTML final -> texto plano: enlaces como "texto (url)", saltos de línea por bloque.
function nb_html_a_texto(string $html): string {
    $t = preg_replace('~<(head|style|script)\b.*?</\1>~is', '', $html);
    $t = preg_replace_callback('~<img\b[^>]*\balt=(["\'])(.*?)\1[^>]*>~is', fn($m) => $m[2], $t);
    $t = preg_replace_callback('~<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)</a>~is', function ($m) {
        $url   = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        $texto = trim(preg_replace('/\s+/', ' ', strip_tags($m[3])));
        if ($url === '' || $url[0] === '#') return $texto;
        return ($texto === '' || $texto === $url) ? $url : "$texto ($url)";
    }, $t);
    $t = preg_replace('~<br\s*/?>|</(p|div|h[1-6]|tr|ul|ol|table)>~i', "\n", $t);
    $t = preg_replace('~<li\b[^>]*>~i', "\n- ", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
    $t = str_replace(["\r\n", "\r"], "\n", $t); // las plantillas heredoc pueden venir con CRLF
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", $t);
    return trim(preg_replace('/\n{3,}/', "\n\n", $t));
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

function generarHtmlEmailRecuperarGmail($unsubUrl, string $bloqueCuponHtml = '', ?string $correo = null) {
    $unsub_safe = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');
    $bloqueFeedback = '';
    if ($correo !== null && $correo !== '') {
        $fb_util   = htmlspecialchars(generarFeedbackUrl($correo, 'util'), ENT_QUOTES, 'UTF-8');
        $fb_noutil = htmlspecialchars(generarFeedbackUrl($correo, 'no_util'), ENT_QUOTES, 'UTF-8');
        $bloqueFeedback = "
<p style=\"text-align:center;margin:24px 0 0 0;font-size:13px;color:#555;\">
  ¿Te resultó útil este correo?
  <a href=\"{$fb_util}\" style=\"color:#54A6D8;font-weight:bold;text-decoration:none;margin:0 6px;\">Útil</a>
  &middot;
  <a href=\"{$fb_noutil}\" style=\"color:#6B7280;font-weight:bold;text-decoration:none;margin:0 6px;\">No es útil</a>
</p>";
    }
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
{$bloqueFeedback}
<hr style=\"margin:30px 0;border:none;border-top:1px solid #eee;\">
<p style=\"font-size:11px;color:#888;\">
  Si no quieres recibir más correos de Nubira,
  <a href=\"{$unsub_safe}\" style=\"color:#888;\">puedes darte de baja aquí</a>.
</p>
";
}

function enviarDormidoConUnsubscribe($destinatario, $asunto, $htmlInterno, $unsubUrl, $sender = 'contacto', ?string $tituloPlantilla = null, bool $usarSenderContacto = false) {
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
        $mail->addReplyTo(CAMPANA_REPLY_TO, 'Equipo Nubira');
        // $usarSenderContacto queda en la firma sin efecto: Hostinger rechaza MAIL FROM distinto al usuario SMTP.
        // if ($usarSenderContacto) {
        //     $mail->Sender = CAMPANA_REPLY_TO; // Return-Path / MAIL FROM
        // }
        $mail->MessageID = sprintf('<%s@nubira.cl>', bin2hex(random_bytes(16)));

        $mail->addCustomHeader(
            'List-Unsubscribe',
            '<mailto:' . $cfg['user'] . '?subject=unsubscribe>, <' . $unsubUrl . '>'
        );
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $htmlFinal     = plantillaMaestra($tituloPlantilla ?? $asunto, $htmlInterno);
        $mail->Body    = $htmlFinal;
        $mail->AltBody = nb_html_a_texto($htmlFinal); // plantilla + pie incluidos

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
