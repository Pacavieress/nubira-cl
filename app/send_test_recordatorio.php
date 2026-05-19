<?php
/****************************************************
 * Nubira.cl – TEST correos recordatorio
 * Modos:
 *  - tipo=publicar  → empujar a publicar (primer correo)
 *  - tipo=explorar  → mini-cards servicios + apuntes
 ****************************************************/

ini_set('display_errors', 1); // pon 0 en producción
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/send_test_recordatorio_error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/correo.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

/* ====== CONFIG TEST ====== */
$correo      = 'pacavieress@uc.cl';
$nombre      = 'Pablo';
$nombre_esc  = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); // para heredoc
$default_img = 'https://nubira.cl/upload/email/email-card-default.jpg';

/* Modo: publicar / explorar */
$tipo = $_GET['tipo'] ?? 'explorar';
$tipo = in_array($tipo, ['publicar', 'explorar']) ? $tipo : 'explorar';

/* ====== HELPERS ====== */
function esc($s){
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function trunc($s, $n = 60){
    $s = (string)($s ?? '');
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $n, '…', 'UTF-8');
    }
    return (strlen($s) <= $n) ? $s : substr($s, 0, $n - 2) . '…';
}

/* Imagen del servicio para email (upload/email/{id}.jpg) */
function imagen_email_servicio($id_servicio) {
    global $default_img;
    $path = __DIR__ . "/../upload/email/$id_servicio.jpg";
    $url  = "https://nubira.cl/upload/email/$id_servicio.jpg";
    if (file_exists($path)) return $url;
    return $default_img;
}

/* Imagen del apunte para email (upload/email-apuntes/{id}.jpg) */
function imagen_email_apunte($id_apunte) {
    global $default_img;
    $path = __DIR__ . "/../upload/email-apuntes/$id_apunte.jpg";
    $url  = "https://nubira.cl/upload/email-apuntes/$id_apunte.jpg";
    if (file_exists($path)) return $url;
    return $default_img;
}

/* =================================================================
 *  MODO 1: PUBLICAR (primer correo)
 * ================================================================= */
if ($tipo === 'publicar') {

    $cmp    = 'recordatorio_publicar_test';
    $asunto = "📘 Publica tu primer apunte o servicio en Nubira.cl";

    $html = <<<HTML
<html>
<body style="font-family:Poppins,Arial,sans-serif;background:#f8fafc;padding:30px;">
<table style="max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 3px 10px rgba(0,0,0,0.05);">

  <!-- Encabezado -->
  <tr>
    <td style="background:#54A6D8;color:white;text-align:center;padding:20px 0;">
      <h2 style="margin:0;">Nubira.cl</h2>
      <p style="margin:0;font-size:14px;">Tu vitrina universitaria</p>
    </td>
  </tr>

  <!-- Contenido principal -->
  <tr>
    <td style="padding:30px 40px;color:#333;font-size:15px;line-height:1.6;">

      <p>Hola <strong>{$nombre_esc}</strong>,</p>
      <p>
        Ya tienes tu cuenta creada en Nubira.cl, pero aún no has publicado tu primer contenido 💙
      </p>
      <p>
        En Nubira puedes:
      </p>
      <ul style="margin:15px 0 25px 20px;">
        <li>📘 Compartir tus apuntes con otros estudiantes.</li>
        <li>🧑‍🏫 Ofrecer clases o apoyo académico.</li>
        <li>💰 Recibir pagos protegidos dentro de la plataforma.</li>
      </ul>
      <p>
        Publicar toma menos de un minuto y puedes editar o bajar tu publicación cuando quieras.
      </p>

      <p style="text-align:center;margin-top:30px;">
        <a href="https://nubira.cl/app/cargar_apuntes.php?src=email&cmp={$cmp}"
           style="background:#54A6D8;color:white;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;display:inline-block;margin:4px;">
           📘 Publicar mi primer apunte
        </a><br>
        <a href="https://nubira.cl/app/publicar_servicio.php?src=email&cmp={$cmp}"
           style="background:#000;color:white;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:bold;display:inline-block;margin:4px;font-size:14px;">
           🧑‍🏫 Ofrecer un servicio
        </a>
      </p>

      <!-- Síguenos redes -->
      <p style="text-align:center;margin-top:26px;margin-bottom:6px;font-size:13px;color:#555;">
        Síguenos en redes sociales:
      </p>
      <p style="text-align:center;margin-bottom:24px;">
        <a href="https://www.instagram.com/nubira.cl/" target="_blank" style="margin:0 8px;display:inline-block;">
          <img src="https://nubira.cl/upload/email/icon-instagram.png" alt="Instagram Nubira" width="26" style="display:inline-block;border:0;">
        </a>
        <a href="https://www.facebook.com/profile.php?id=61578820987125&mibextid=wwXIfr&rdid=lwHRGJ9zUBU4VstA&share_url=https%3A%2F%2Fwww.facebook.com%2Fshare%2F19pPFgiXbN%2F%3Fmibextid%3DwwXIfr#" target="_blank" style="margin:0 8px;display:inline-block;">
          <img src="https://nubira.cl/upload/email/icon-facebook.png" alt="Facebook Nubira" width="26" style="display:inline-block;border:0;">
        </a>
      </p>

      <p style="color:#777;font-size:13px;text-align:center;margin-top:10px;">
        Este correo se envió automáticamente desde Nubira.cl.<br>
        Si ya estás usando la plataforma, puedes ignorarlo sin problema 😊
      </p>

    </td>
  </tr>

</table>
</body>
</html>
HTML;

    $txt  = "Hola $nombre,\n\n";
    $txt .= "Ya tienes tu cuenta creada en Nubira.cl, pero aún no has publicado tu primer contenido.\n\n";
    $txt .= "En Nubira puedes:\n";
    $txt .= "- Compartir tus apuntes con otros estudiantes.\n";
    $txt .= "- Ofrecer clases o apoyo académico.\n";
    $txt .= "- Recibir pagos protegidos dentro de la plataforma.\n\n";
    $txt .= "Publicar toma menos de un minuto.\n\n";
    $txt .= "📘 Publicar apunte: https://nubira.cl/app/cargar_apuntes.php?src=email&cmp=$cmp\n";
    $txt .= "🧑‍🏫 Ofrecer servicio: https://nubira.cl/app/publicar_servicio.php?src=email&cmp=$cmp\n\n";
    $txt .= "Este correo se envió automáticamente desde Nubira.cl.\n";
    $txt .= "Si ya estás usando la plataforma, puedes ignorarlo sin problema.\n";
    $txt .= "\nSíguenos en redes:\n";
    $txt .= "- Instagram: https://www.instagram.com/nubira.cl/\n";
    $txt .= "- Facebook: https://www.facebook.com/profile.php?id=61578820987125&mibextid=wwXIfr&rdid=lwHRGJ9zUBU4VstA&share_url=https%3A%2F%2Fwww.facebook.com%2Fshare%2F19pPFgiXbN%2F%3Fmibextid%3DwwXIfr#\n";

}
/* =================================================================
 *  MODO 2: EXPLORAR (cards servicios + apuntes)
 * ================================================================= */
else {

    $cmp = 'reactivacion_explorar_test';

    /* --- APUNTES (3 al azar) --- */
    $apuntes_cards = [];
    $apuntes_html  = '';

    $sqlAp = "
        SELECT id, titulo, archivo
        FROM apuntes
        WHERE estado = 'aprobado'
        ORDER BY RAND()
        LIMIT 3
    ";
    $resAp = $conn->query($sqlAp);
    if ($resAp) {
        while ($row = $resAp->fetch_assoc()) {
            $apuntes_cards[] = $row;
        }
    } else {
        error_log('[send_test_recordatorio] SQL apuntes: ' . $conn->error);
    }

    if (!empty($apuntes_cards)) {
        $apuntes_html = '
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;">
  <tr>
';
        foreach ($apuntes_cards as $a) {
            $id      = (int)$a['id'];
            $titulo  = esc(trunc($a['titulo'], 70));
            $archivo = $a['archivo'] ?? '';
            $src     = imagen_email_apunte($id);
            $link_apunte = 'https://nubira.cl/ver-apunte?archivo=' . rawurlencode($archivo) . '&src=email&cmp=' . $cmp;

            $apuntes_html .= '
    <td align="center" valign="top" width="33%" style="padding:5px;">
      <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background:#fff;border:1px solid #eee;border-radius:10px;">
        <tr>
          <td style="padding:0;">
            <img src="'.$src.'" alt="'.$titulo.'" width="180" style="width:100%;height:auto;display:block;">
          </td>
        </tr>
        <tr>
          <td style="padding:10px;font-size:14px;color:#333;">
            <strong style="font-size:14px;">'.$titulo.'</strong><br>
            <span style="color:#777;font-size:12px;">Apunte</span><br><br>
            <a href="'.$link_apunte.'"
               style="color:#54A6D8;font-weight:bold;font-size:12px;text-decoration:none;">
               Ver más →
            </a>
          </td>
        </tr>
      </table>
    </td>
';
        }
        $apuntes_html .= '
  </tr>
</table>';
    } else {
        $apuntes_html = '<p style="color:#777;">Pronto te mostraremos apuntes recomendados según tu carrera 📚</p>';
    }

    /* --- SERVICIOS (3 mini-cards) --- */
    $cards      = [];
    $cards_html = '';

    $insts    = [];
    $sqlInsts = "
      SELECT DISTINCT TRIM(institucion) AS inst
      FROM servicios
      WHERE estado='aprobado' AND TRIM(institucion) <> ''
      ORDER BY RAND()
      LIMIT 10
    ";
    $resInst = $conn->query($sqlInsts);
    if ($resInst) {
        while ($r = $resInst->fetch_assoc()) {
            $insts[] = $r['inst'];
        }
    } else {
        error_log('[send_test_recordatorio] SQL insts: '.$conn->error);
    }
    shuffle($insts);
    $insts = array_slice($insts, 0, 3);

    foreach ($insts as $inst) {
        $inst_esc = $conn->real_escape_string($inst);
        $q = $conn->query("
            SELECT id, titulo, institucion
            FROM servicios
            WHERE estado='aprobado' AND TRIM(institucion) = '$inst_esc'
            ORDER BY RAND()
            LIMIT 1
        ");
        if ($q && $q->num_rows === 1) {
            $cards[] = $q->fetch_assoc();
        }
    }

    if (count($cards) < 3) {
        $faltan = 3 - count($cards);
        $idsYa  = array_column($cards, 'id');
        $idsSql = $idsYa ? implode(',', array_map('intval', $idsYa)) : '0';
        $qFill = $conn->query("
            SELECT id, titulo, institucion
            FROM servicios
            WHERE estado='aprobado' AND id NOT IN ($idsSql)
            ORDER BY RAND()
            LIMIT $faltan
        ");
        if ($qFill) {
            while ($r = $qFill->fetch_assoc()) {
                $cards[] = $r;
            }
        } else {
            error_log('[send_test_recordatorio] SQL fill servicios: '.$conn->error);
        }
    }

    if (!empty($cards)) {
        $cards_html = '
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;">
  <tr>
';
        foreach ($cards as $c) {
            $id     = (int)$c['id'];
            $titulo = esc(trunc($c['titulo']));
            $inst   = strtoupper($c['institucion']);
            $src    = imagen_email_servicio($id);
            $link_serv = 'https://nubira.cl/detalle-servicio/'.$id.'?src=email&cmp='.$cmp;

            $cards_html .= '
    <td align="center" valign="top" width="33%" style="padding:5px;">
      <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background:#fff;border:1px solid #eee;border-radius:10px;">
        <tr>
          <td style="padding:0;">
            <img src="'.$src.'" alt="'.$titulo.'" width="180" style="width:100%;height:auto;display:block;">
          </td>
        </tr>
        <tr>
          <td style="padding:10px;font-size:14px;color:#333;">
            <strong style="font-size:14px;">'.$titulo.'</strong><br>
            <span style="color:#777;font-size:12px;">'.$inst.'</span><br><br>
            <a href="'.$link_serv.'"
               style="color:#54A6D8;font-weight:bold;font-size:12px;text-decoration:none;">
               Ver más →
            </a>
          </td>
        </tr>
      </table>
    </td>
';
        }
        $cards_html .= '
  </tr>
</table>';
    } else {
        $cards_html = '<p style="color:#777;">Aún no hay servicios para mostrar. ¡Sé de los primeros en publicar! 🙌</p>';
    }

    /* --- HTML explorar --- */
    $asunto = "🔎 Explora lo que ya publicaron tus compañeros en Nubira.cl";

    $html = '
<html>
<body style="font-family:Poppins,Arial,sans-serif;background:#f8fafc;padding:30px;">
<table style="max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 3px 10px rgba(0,0,0,0.05);">

  <!-- Encabezado -->
  <tr>
    <td style="background:#54A6D8;color:white;text-align:center;padding:20px 0;">
      <h2 style="margin:0;">Nubira.cl</h2>
      <p style="margin:0;font-size:14px;">Tu vitrina universitaria</p>
    </td>
  </tr>

  <!-- Contenido principal -->
  <tr>
    <td style="padding:30px 40px;color:#333;font-size:15px;line-height:1.6;">

      <p>Hola <strong>'.esc($nombre).'</strong>,</p>
      <p>Ya llevas algunos días con tu cuenta Nubira y queremos mostrarte lo que otros estudiantes ya están publicando 👇</p>

      <p style="margin-top:18px;font-weight:bold;">Clases y servicios destacados</p>
      '.$cards_html.'

      <p style="margin-top:32px;font-weight:bold;">Apuntes recomendados para ti</p>
      '.$apuntes_html.'

      <p style="text-align:center;margin-top:28px;">
        <a href="https://nubira.cl/vitrina-apuntes?src=email&cmp='.$cmp.'"
           style="background:#54A6D8;color:white;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold;display:inline-block;margin:4px;">
           Ver apuntes
        </a>

        <a href="https://nubira.cl/clases-servicios?src=email&cmp='.$cmp.'"
           style="background:#000;color:white;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold;display:inline-block;margin:4px;">
           Ver clases/servicios
        </a>
      </p>

      <!-- Síguenos redes -->
      <p style="text-align:center;margin-top:26px;margin-bottom:6px;font-size:13px;color:#555;">
        Síguenos en redes sociales:
      </p>
      <p style="text-align:center;margin-bottom:24px;">
        <a href="https://www.instagram.com/nubira.cl/" target="_blank" style="margin:0 8px;display:inline-block;">
          <img src="https://nubira.cl/upload/email/icon-instagram.png" alt="Instagram Nubira" width="26" style="display:inline-block;border:0;">
        </a>
        <a href="https://www.facebook.com/profile.php?id=61578820987125&mibextid=wwXIfr&rdid=lwHRGJ9zUBU4VstA&share_url=https%3A%2F%2Fwww.facebook.com%2Fshare%2F19pPFgiXbN%2F%3Fmibextid%3DwwXIfr#" target="_blank" style="margin:0 8px;display:inline-block;">
          <img src="https://nubira.cl/upload/email/icon-facebook.png" alt="Facebook Nubira" width="26" style="display:inline-block;border:0;">
        </a>
      </p>

      <p style="color:#777;font-size:13px;text-align:center;margin-top:10px;">
        Este correo se envió automáticamente desde Nubira.cl.<br>
        Si ya estás usando la plataforma, puedes ignorarlo sin problema 😊
      </p>

    </td>
  </tr>

</table>
</body>
</html>';

    /* Texto plano explorar */
    $txt  = "Hola $nombre,\n\nAquí tienes algunas publicaciones destacadas en Nubira.cl:\n\n";
    $txt .= "SERVICIOS:\n";
    if (!empty($cards)) {
        foreach ($cards as $c) {
            $txt .= "- ".$c['titulo']." (".$c['institucion'].")\n";
            $txt .= "  https://nubira.cl/detalle-servicio/".$c['id']."?src=email&cmp=".$cmp."\n";
        }
    } else {
        $txt .= "- Aún no hay servicios para mostrar.\n";
    }
    $txt .= "\nAPUNTES:\n";
    if (!empty($apuntes_cards)) {
        foreach ($apuntes_cards as $a) {
            $txt .= "- ".$a['titulo']."\n";
            $txt .= "  https://nubira.cl/ver-apunte?archivo=".rawurlencode($a['archivo'])."&src=email&cmp=".$cmp."\n";
        }
    } else {
        $txt .= "- Pronto te mostraremos apuntes recomendados.\n";
    }
    $txt .= "\nExplora más en https://nubira.cl\n";
    $txt .= "\nSíguenos en redes:\n";
    $txt .= "- Instagram: https://www.instagram.com/nubira.cl/\n";
    $txt .= "- Facebook: https://www.facebook.com/profile.php?id=61578820987125&mibextid=wwXIfr&rdid=lwHRGJ9zUBU4VstA&share_url=https%3A%2F%2Fwww.facebook.com%2Fshare%2F19pPFgiXbN%2F%3Fmibextid%3DwwXIfr#\n";
}

/* ====== ENVIAR CORREO ====== */

error_log("[send_test_recordatorio] tipo=$tipo Enviando a $correo con asunto '$asunto'");

$resultado = enviarCorreo($correo, $asunto, $html, $txt);

if ($resultado === true) {
    echo "OK ($tipo) - correo enviado (revisa también SPAM)";
} else {
    echo "ERROR AL ENVIAR ($tipo)\n";
    var_dump($resultado);
}

exit;
