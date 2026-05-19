<?php
/**
 * CRON: Enviar recordatorios automáticos a usuarios inactivos
 * Ejecutar cada día con el cron del servidor.
 */

// === MODO PRODUCCIÓN: loguear errores ===
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_recordatorios.log');
error_reporting(E_ALL);

date_default_timezone_set('America/Santiago');

// Carga robusta de dependencias
if (file_exists(__DIR__ . '/conexion.php')) {
    require_once __DIR__ . '/conexion.php';
} elseif (file_exists(__DIR__ . '/../app/conexion.php')) {
    require_once __DIR__ . '/../app/conexion.php';
} else {
    die("❌ Error crítico: No se encuentra conexion.php");
}

if (file_exists(__DIR__ . '/correo.php')) {
    require_once __DIR__ . '/correo.php';
} elseif (file_exists(__DIR__ . '/../app/correo.php')) {
    require_once __DIR__ . '/../app/correo.php';
} else {
    die("❌ Error crítico: No se encuentra correo.php");
}

/* ==========================
 * CONFIG / HELPERS
 * ========================== */

$default_img = 'https://nubira.cl/upload/email/email-card-default.jpg';
$cmp_publicar  = 'recordatorio_publicar_auto';
$cmp_explorar  = 'reactivacion_explorar_auto';

function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function trunc($s, $n = 60) {
    $s = (string)($s ?? '');
    return (strlen($s) <= $n) ? $s : substr($s, 0, $n - 2) . '…';
}

function imagen_email_servicio($id) {
    return "https://nubira.cl/upload/email/$id.jpg";
}

function imagen_email_apunte($id) {
    return "https://nubira.cl/upload/email-apuntes/$id.jpg";
}

/* ==========================
 * GENERADOR DE CARDS HTML
 * ========================== */
function generar_explorar_data($cmp_explorar, $conn) {
    $apuntes_cards = [];
    $apuntes_html  = '';
    $cards         = [];
    $cards_html    = '';

    // --- APUNTES (3 al azar) ---
    $resAp = $conn->query("SELECT id, titulo, archivo FROM apuntes WHERE estado = 'aprobado' ORDER BY RAND() LIMIT 3");
    if ($resAp) while ($row = $resAp->fetch_assoc()) $apuntes_cards[] = $row;

    if (!empty($apuntes_cards)) {
        $apuntes_html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:20px;"><tr>';
        foreach ($apuntes_cards as $a) {
            $id = (int)$a['id'];
            $titulo = esc(trunc($a['titulo'], 40));
            $src = imagen_email_apunte($id);
            $link = "https://nubira.cl/ver-apunte?archivo=" . rawurlencode($a['archivo']) . "&src=email&cmp=$cmp_explorar";

            $apuntes_html .= '
            <td width="33%" style="padding:5px; vertical-align:top;">
              <div style="background:#fff; border:1px solid #eee; border-radius:12px; overflow:hidden;">
                <a href="'.$link.'" style="text-decoration:none; display:block;">
                    <img src="'.$src.'" width="180" style="width:100%; height:120px; object-fit:cover; display:block;" alt="Apunte">
                    <div style="padding:10px;">
                        <p style="margin:0; font-family:Helvetica, Arial, sans-serif; font-size:13px; font-weight:bold; color:#333; height:36px; overflow:hidden;">'.$titulo.'</p>
                        <p style="margin:4px 0 0 0; font-size:11px; color:#54A6D8; font-weight:bold;">Ver apunte →</p>
                    </div>
                </a>
              </div>
            </td>';
        }
        $apuntes_html .= '</tr></table>';
    }

    // --- SERVICIOS (3 al azar variados) ---
    $resServ = $conn->query("SELECT id, titulo, institucion FROM servicios WHERE estado='aprobado' ORDER BY RAND() LIMIT 3");
    if ($resServ) while ($r = $resServ->fetch_assoc()) $cards[] = $r;

    if (!empty($cards)) {
        $cards_html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:20px;"><tr>';
        foreach ($cards as $c) {
            $id = (int)$c['id'];
            $titulo = esc(trunc($c['titulo'], 40));
            $inst = esc(strtoupper($c['institucion']));
            $src = imagen_email_servicio($id);
            $link = "https://nubira.cl/detalle-servicio/$id?src=email&cmp=$cmp_explorar";

            $cards_html .= '
            <td width="33%" style="padding:5px; vertical-align:top;">
              <div style="background:#fff; border:1px solid #eee; border-radius:12px; overflow:hidden;">
                <a href="'.$link.'" style="text-decoration:none; display:block;">
                    <img src="'.$src.'" width="180" style="width:100%; height:120px; object-fit:cover; display:block;" alt="Clase">
                    <div style="padding:10px;">
                        <p style="margin:0; font-family:Helvetica, Arial, sans-serif; font-size:13px; font-weight:bold; color:#333; height:36px; overflow:hidden;">'.$titulo.'</p>
                        <p style="margin:2px 0 0 0; font-size:10px; color:#888;">'.$inst.'</p>
                        <p style="margin:4px 0 0 0; font-size:11px; color:#54A6D8; font-weight:bold;">Ver clase →</p>
                    </div>
                </a>
              </div>
            </td>';
        }
        $cards_html .= '</tr></table>';
    }

    return ['cards_html' => $cards_html, 'apuntes_html' => $apuntes_html];
}

// Pre-generar contenido dinámico
$explorarData = generar_explorar_data($cmp_explorar, $conn);

/* ==========================
 * 1. Obtener alumnos inactivos
 * ========================== */

$sql = "
SELECT a.id AS alumno_id, a.nombre, a.correo, v.ultima_publicacion
FROM alumnos a
LEFT JOIN v_ultima_publicacion_por_alumno v ON a.id = v.alumno_id
";
// Tip: Si tienes una columna 'estado' en alumnos, úsala aquí.

$result = $conn->query($sql);
if (!$result) die('Error DB: ' . $conn->error);

$ahora = new DateTime();

while ($row = $result->fetch_assoc()) {
    $alumno_id = (int)$row['alumno_id'];
    $correo    = $row['correo'];
    $nombre    = $row['nombre'];
    $ultima    = $row['ultima_publicacion'];

    $nombre_esc = esc(explode(' ', $nombre)[0]); // Solo primer nombre

    // Calcular días inactivos
    if (empty($ultima) || $ultima === '1900-01-01') {
        $dias = null; // Nunca publicó
    } else {
        try {
            $fecha_pub = new DateTime($ultima);
            $dias = $ahora->diff($fecha_pub)->days;
        } catch (Exception $e) { $dias = null; }
    }

    // Reglas de envío
    $tipos = [
        3  => 'recordatorio_3dias',  // A los 3 días de registro sin publicar
        7  => 'recordatorio_7dias',  // A la semana de última actividad
        14 => 'recordatorio_14dias'  // A las 2 semanas
    ];

    foreach ($tipos as $dias_limite => $tipo) {
        
        // Condición de disparo
        if ($dias === $dias_limite || ($dias === null && $dias_limite === 3)) {

            // Verificar si ya se envió
            $check = $conn->prepare("SELECT COUNT(*) FROM acciones_pendientes WHERE alumno_id = ? AND tipo = ? AND estado = 'enviado'");
            $check->bind_param('is', $alumno_id, $tipo);
            $check->execute();
            $check->bind_result($ya_enviado);
            $check->fetch();
            $check->close();

            if ($ya_enviado > 0) continue;

            // --- Plantilla Base (Header/Footer) ---
            $header = '
            <!DOCTYPE html>
            <html>
            <body style="font-family:Helvetica, Arial, sans-serif; background-color:#f4f4f5; margin:0; padding:40px 0;">
            <div style="max-width:600px; margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.05);">
                <div style="background:#ffffff; padding:20px; text-align:center; border-bottom:1px solid #f0f0f0;">
                    <img src="https://nubira.cl/img/logo.webp" alt="Nubira" height="35" style="display:block; margin:0 auto;">
                </div>
                <div style="padding:40px 30px; color:#333333; line-height:1.6; font-size:16px;">
            ';

            $footer = '
                </div>
                <div style="background:#f9fafb; padding:20px; text-align:center; border-top:1px solid #f0f0f0;">
                    <p style="margin:0 0 10px 0; font-size:12px; color:#999;">Síguenos para más novedades:</p>
                    <a href="https://instagram.com/nubira.cl" style="text-decoration:none; margin:0 5px; font-weight:bold; color:#54A6D8;">Instagram</a> • 
                    <a href="https://nubira.cl" style="text-decoration:none; margin:0 5px; font-weight:bold; color:#54A6D8;">Web</a>
                    <p style="margin:20px 0 0 0; font-size:11px; color:#ccc;">
                        Enviado con 💙 por el equipo de Nubira.<br>
                        Si no quieres recibir estos correos, puedes darte de baja en tu perfil.
                    </p>
                </div>
            </div>
            </body>
            </html>';

            // --- Contenido por Tipo ---
            $body_content = "";
            $asunto = "";

            if ($tipo === 'recordatorio_3dias') {
                $asunto = "👋 $nombre_esc, ¿te animas a publicar?";
                $body_content = "
                    <h2 style='margin-top:0; color:#111; font-size:24px;'>¡Bienvenido a la comunidad!</h2>
                    <p style='font-size:16px; color:#555;'>Vimos que creaste tu cuenta, pero aún no has subido tu primer contenido. 🚀</p>
                    
                    <div style='background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:25px; margin:25px 0; text-align:center;'>
                        <p style='margin:0 0 20px 0; font-weight:bold; color:#0c4a6e; font-size:15px;'>¿Qué te gustaría hacer hoy?</p>
                        
                        <center>
                        <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                            <tr>
                                <td align='center' style='padding-bottom:15px;'>
                                    <a href='https://nubira.cl/formulario-subir-apunte?src=email&cmp=$cmp_publicar' 
                                       style='background:#54A6D8; color:#fff; text-decoration:none; padding:12px 24px; border-radius:50px; font-weight:bold; font-size:14px; display:block; min-width:180px; border:2px solid #54A6D8;'>
                                       📘 Subir un Apunte
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td align='center'>
                                    <a href='https://nubira.cl/publicar-servicio?src=email&cmp=$cmp_publicar' 
                                       style='background:#ffffff; color:#54A6D8; text-decoration:none; padding:12px 24px; border-radius:50px; font-weight:bold; font-size:14px; display:block; min-width:180px; border:2px solid #54A6D8;'>
                                       🧑‍🏫 Ofrecer una Clase
                                    </a>
                                </td>
                            </tr>
                        </table>
                        </center>
                    </div>

                    <p style='font-size:14px; color:#666; text-align:center;'>Publicar es gratis, rápido y ayudas a miles de estudiantes.</p>
                ";
            } elseif ($tipo === 'recordatorio_7dias') {
                $asunto = "👀 Mira lo que están subiendo en tu U";
                $body_content = "
                    <h2 style='margin-top:0; color:#111;'>Descubre nuevo material</h2>
                    <p>Hola <strong>$nombre_esc</strong>, la comunidad ha estado muy activa esta semana. Aquí tienes algunas cosas que te podrían interesar:</p>
                    
                    <p style='font-weight:bold; margin-top:20px; font-size:14px; color:#888; text-transform:uppercase;'>🔥 Clases Destacadas</p>
                    {$explorarData['cards_html']}

                    <p style='font-weight:bold; margin-top:30px; font-size:14px; color:#888; text-transform:uppercase;'>📚 Apuntes Recientes</p>
                    {$explorarData['apuntes_html']}

                    <div style='text-align:center; margin:30px 0;'>
                        <a href='https://nubira.cl/vitrina?src=email' style='background:#1f2937; color:#fff; text-decoration:none; padding:12px 24px; border-radius:50px; font-weight:bold; font-size:14px; display:inline-block;'>Ir a la Vitrina</a>
                    </div>
                ";
            } elseif ($tipo === 'recordatorio_14dias') {
                $asunto = "💡 Tu conocimiento vale mucho";
                $body_content = "
                    <h2 style='margin-top:0; color:#111;'>¿Sabías que puedes monetizar?</h2>
                    <p>Hola <strong>$nombre_esc</strong>, muchos estudiantes buscan tutores o apuntes de calidad. Si te va bien en un ramo, ¡aprovéchalo!</p>
                    <p>Publicar un servicio en Nubira es gratis y seguro. Nosotros gestionamos el pago para que tú solo te preocupes de enseñar.</p>
                    
                    <div style='background:#f0f9ff; padding:20px; border-radius:12px; text-align:center; margin:25px 0; border:1px dashed #54A6D8;'>
                        <p style='margin:0; font-weight:bold; color:#0c4a6e;'>🚀 Publica tu primera clase hoy</p>
                        <p style='margin:5px 0 15px 0; font-size:13px; color:#555;'>Es rápido y sencillo.</p>
                        <a href='https://nubira.cl/publicar-servicio?src=email' style='color:#54A6D8; font-weight:bold; text-decoration:underline;'>Comenzar aquí</a>
                    </div>
                ";
            }

            // Enviar
            $html_final = $header . $body_content . $footer;
            $txt_final  = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body_content));

            $enviado = enviarCorreo($correo, $asunto, $html_final, $txt_final);

            // Registrar evento
            $estado = $enviado ? 'enviado' : 'fallido';
            $log = $conn->prepare("INSERT INTO acciones_pendientes (alumno_id, tipo, etapa, programado_para, enviado_en, estado) VALUES (?, ?, ?, NOW(), NOW(), ?)");
            if ($log) {
                $log->bind_param('isis', $alumno_id, $tipo, $dias_limite, $estado);
                $log->execute();
                $log->close();
            }
            
            echo "📩 [$estado] $tipo para $correo\n";
        }
    }
}

echo "✅ Proceso CRON finalizado.\n";
?>