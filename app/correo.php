<?php
/**
 * NUBIRA 2.0 - MOTOR DE CORREO CENTRALIZADO (BACKEND)
 * Este archivo NO es una vista. Es una librería de funciones.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. CARGA ROBUSTA DE DEPENDENCIAS (Soluciona el error de "no envía")
// Probamos rutas comunes hasta encontrar el autoload
$paths = [
    __DIR__ . '/vendor/autoload.php',       // Vendor en raíz
    __DIR__ . '/../vendor/autoload.php',    // Vendor arriba
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php' // Ruta absoluta servidor
];

$loaded = false;
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    // Log de emergencia si falla Composer
    error_log("CRITICAL NUBIRA: No se encuentra vendor/autoload.php. Los correos no funcionarán.");
    exit(); // Detener ejecución para no exponer errores fatales
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CONFIGURACIÓN CONSTANTE
define('LOG_FILE', __DIR__ . '/log_correos.txt');
define('ADMIN_MAIL', 'soporte@nubira.cl');

require_once __DIR__ . '/config.php';

// CONFIGURACIÓN SMTP (Hostinger)
function getSmtpConfig($tipo = 'noreply') {
    $config = [
        'noreply' => [
            'user' => 'no-reply@nubira.cl',
            'pass' => SMTP_PASS_NOREPLY,
            'name' => 'Nubira'
        ],
        'contacto' => [
            'user' => 'contacto@nubira.cl',
            'pass' => SMTP_PASS_CONTACTO,
            'name' => 'Equipo Nubira'
        ]
    ];
    return $config[$tipo] ?? $config['noreply'];
}

/* ==========================================================
   FUNCIÓN CORE: El motor de envío (Privada)
   ========================================================== */
function _enviarEmailBase($destinatario, $asunto, $htmlBody, $altText = '', $usarContacto = false) {
    $mail = new PHPMailer(true);
    $smtpKey = $usarContacto ? 'contacto' : 'noreply';
    $credenciales = getSmtpConfig($smtpKey);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $credenciales['user'];
        $mail->Password   = $credenciales['pass'];
        
        // --- CAMBIO PARA MÁXIMA COMPATIBILIDAD ---
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Usa SSL directo
        $mail->Port       = 465;                        // Puerto 465 es más robusto en Hostinger
        // -----------------------------------------
        
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($credenciales['user'], $credenciales['name']);
        $mail->addAddress($destinatario);
        
        // El Reply-To debe ser el mismo que el From para evitar alertas de SPAM
        $mail->addReplyTo($credenciales['user'], $credenciales['name']);

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altText ?: strip_tags($htmlBody);

        $mail->send();
        file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " [OK] Enviado a $destinatario | Asunto: $asunto\n", FILE_APPEND);
        return true;

    } catch (Exception $e) {
        file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " [ERROR] " . $mail->ErrorInfo . "\n", FILE_APPEND);
        return false;
    }
}
/* ==========================================================
   FUNCIÓN DE UI: Plantilla Maestra (ESCALABILIDAD)
   Aquí defines el diseño UNA SOLA VEZ para todos los correos.
   ========================================================== */
function plantillaMaestra($titulo, $contenido, $botonTexto = null, $botonLink = null) {
    $botonHtml = '';
    if ($botonTexto && $botonLink) {
        $botonHtml = "
            <div style='text-align:center; margin: 30px 0;'>
                <a href='$botonLink' style='background-color:#54A6D8; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:8px; font-weight:bold; font-size:16px; display:inline-block;'>
                    $botonTexto
                </a>
            </div>
        ";
    }

    return "
    <!DOCTYPE html>
    <html>
    <body style='margin:0; padding:0; background-color:#F3F4F6; font-family: Helvetica, Arial, sans-serif;'>
        <table width='100%' border='0' cellspacing='0' cellpadding='0'>
            <tr>
                <td align='center' style='padding: 40px 0;'>
                    <table width='600' border='0' cellspacing='0' cellpadding='0' style='background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                        <tr>
                            <td align='center' style='padding:30px; border-bottom:1px solid #E5E7EB;'>
                                <span style='color:#54A6D8; font-size:24px; font-weight:bold; letter-spacing:-1px;'>nubira.cl</span>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding:40px 30px; color:#374151; font-size:16px; line-height:1.6;'>
                                <h2 style='color:#111827; margin-top:0; font-size:20px; text-align:center;'>$titulo</h2>
                                <br>
                                $contenido
                                $botonHtml
                            </td>
                        </tr>
                        <tr>
                            <td style='background-color:#F9FAFB; padding:20px; text-align:center; color:#9CA3AF; font-size:12px;'>
                                <p>&copy; " . date('Y') . " Nubira. Todos los derechos reservados.</p>
                                <p>Este correo fue enviado automáticamente, por favor no respondas directamente.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
}

/* ==========================================================
   FUNCIONES PÚBLICAS (API que usan tus otros archivos)
   Mantenemos los nombres EXACTOS para no romper nada.
   ========================================================== */

function enviarCorreoConfirmacion($correo, $nombre, $token) {
    $link = "https://nubira.cl/confirmar.php?token=$token&e=" . urlencode($correo);
    $html = "<p>Hola <strong>$nombre</strong>,</p>
             <p>Gracias por unirte a la comunidad estudiantil. Para comenzar a publicar y conectar, por favor confirma tu cuenta.</p>";
    
    $cuerpo = plantillaMaestra("Confirma tu cuenta", $html, "Verificar Email", $link);
    return _enviarEmailBase($correo, "Bienvenido a Nubira", $cuerpo);
}

function enviarCorreoRecuperacion($correo, $nombre, $token) {
    $link = "https://nubira.cl/nueva_contrasena.php?token=$token";
    $html = "<p>Hola <strong>$nombre</strong>,</p>
             <p>Hemos recibido una solicitud para restablecer tu contraseña. Si no fuiste tú, ignora este mensaje.</p>";
    
    $cuerpo = plantillaMaestra("Recuperar Contraseña", $html, "Cambiar Contraseña", $link);
    return _enviarEmailBase($correo, "Recupera tu acceso - Nubira", $cuerpo);
}

function enviarCorreoSolicitudInstitucion($correo, $nombre, $institucion, $estado = 'aprobada') {
    $esAprobado = ($estado === 'aprobada');
    $color = $esAprobado ? '#10B981' : '#EF4444'; // Verde o Rojo
    $titulo = $esAprobado ? "¡Solicitud Aprobada!" : "Solicitud Rechazada";
    
    $html = "<p>Hola <strong>$nombre</strong>,</p>
             <p>Tu solicitud para la institución <strong>$institucion</strong> ha sido procesada.</p>
             <p style='font-size:18px; font-weight:bold; color:$color; text-align:center;'>Estado: " . strtoupper($estado) . "</p>";
    
    if(!$esAprobado) {
        $html .= "<p>El correo ingresado no corresponde a un dominio institucional válido.</p>";
    }

    $cuerpo = plantillaMaestra($titulo, $html, "Ir a Nubira", "https://nubira.cl");
    return _enviarEmailBase($correo, "Estado Solicitud: $institucion", $cuerpo);
}

// ... Puedes agregar el resto de funciones (Pagos, Contratos) siguiendo este patrón:
// 1. Defines el HTML simple.
// 2. Llamas a plantillaMaestra.
// 3. Llamas a _enviarEmailBase.

// MANTENIENDO COMPATIBILIDAD CON ALIAS VIEJOS
function enviarCorreo($correo, $asunto, $bodyHtml, $altBody = '') {
    // Si algún script viejo usa esta función genérica, la envolvemos en el nuevo diseño
    $cuerpo = plantillaMaestra($asunto, $bodyHtml);
    return _enviarEmailBase($correo, $asunto, $cuerpo, $altBody);
}

// ==========================================================
// NUEVAS FUNCIONES PARA CONTRATOS (Usando Plantilla Maestra)
// ==========================================================

function enviarCorreoNuevaVenta($correoVendedor, $nombreVendedor, $nombreComprador, $tituloServicio, $monto, $chatId) {
$linkChat = "https://nubira.cl/app/mini_aula.php?id=$chatId";
    
    $html = "
        <p>Hola <strong>$nombreVendedor</strong>,</p>
        <p>¡Buenas noticias! El estudiante <strong>$nombreComprador</strong> quiere contratar tu servicio:</p>
        
        <div style='background-color:#F0F9FF; border-left: 4px solid #54A6D8; padding: 15px; margin: 20px 0;'>
            <p style='margin:0; font-size:14px; color:#6B7280;'>Servicio</p>
            <p style='margin:0 0 10px 0; font-weight:bold; font-size:16px;'>$tituloServicio</p>
            
            <p style='margin:0; font-size:14px; color:#6B7280;'>Oferta Recibida</p>
            <p style='margin:0; font-weight:bold; font-size:18px; color:#10B981;'>$" . number_format($monto, 0, ',', '.') . " CLP</p>
        </div>
        
        <p>Ingresa ahora para responderle y coordinar los detalles.</p>
    ";

    $cuerpo = plantillaMaestra("¡Nueva Solicitud de Venta! 🎓", $html, "Ir al Chat", $linkChat);
    return _enviarEmailBase($correoVendedor, "¡Tienes un nuevo alumno! - $tituloServicio", $cuerpo);
}

function enviarCorreoConfirmacionCompra($correoComprador, $nombreComprador, $nombreVendedor, $tituloServicio, $chatId) {
  $linkChat = "https://nubira.cl/app/mini_aula.php?id=$chatId";
    
    $html = "
        <p>Hola <strong>$nombreComprador</strong>,</p>
        <p>Hemos notificado a <strong>$nombreVendedor</strong> sobre tu interés en contratar:</p>
        
        <div style='background-color:#F0F9FF; border-left: 4px solid #54A6D8; padding: 15px; margin: 20px 0;'>
            <p style='margin:0; font-weight:bold; font-size:16px;'>$tituloServicio</p>
        </div>
        
        <p>El vendedor recibirá una alerta inmediata.</p>
        <p style='font-size:12px; color:#6B7280; margin-top:20px;'>
            <strong>Tu seguridad es primero:</strong> No liberes pagos hasta estar seguro de recibir el servicio. Todo queda registrado en el chat.
        </p>
    ";

    $cuerpo = plantillaMaestra("Solicitud Enviada 🚀", $html, "Ver Mensajes", $linkChat);
    return _enviarEmailBase($correoComprador, "Solicitud enviada: $tituloServicio", $cuerpo);
}
// ==========================================================
// NUEVA FUNCIÓN: RECUPERACIÓN DE DEMANDA (MARKETING)
// ==========================================================

function enviarCorreoRecuperacionRegistro($correo) {
    // Truco UX: Enviamos el correo como parámetro para que el campo se llene solo
    $linkRegistro = "https://nubira.cl/registro.php?email=" . urlencode($correo);
    
    // Obtenemos el dominio para personalizar el mensaje (ej: duoc.cl)
    $partes = explode('@', $correo);
    $dominio = end($partes);

    $html = "
        <p>Hola,</p>
        <p>Hace un tiempo mostraste interés en unirte a la comunidad de <strong>Nubira</strong> con tu correo institucional <strong>@$dominio</strong>.</p>
        
        <div style='background-color:#F0F9FF; border-left: 4px solid #54A6D8; padding: 15px; margin: 20px 0;'>
            <p style='margin:0; font-size:16px; color:#374151;'>
                <strong>¡Tu cupo sigue disponible!</strong> 🎓
            </p>
            <p style='margin:5px 0 0 0; font-size:14px; color:#6B7280;'>
                Ya estamos aceptando estudiantes de tu institución. Conecta, comparte apuntes y encuentra servicios académicos hoy mismo.
            </p>
        </div>
        
        <p>Solo te falta un paso para activar tu perfil oficial.</p>
    ";

    // Usamos la plantilla maestra existente (mantiene estilo Nubira)
    $cuerpo = plantillaMaestra("¡Termina tu registro en Nubira!", $html, "Continuar Registro", $linkRegistro);
    
    // Enviamos usando el motor base existente
    return _enviarEmailBase($correo, "Tu cuenta en Nubira te espera", $cuerpo);
}

// ==========================================================
// NUEVA FUNCIÓN: NOTIFICACIÓN DE CHAT (NUBIRA 2.0)
// ==========================================================

function enviarCorreoNuevoMensaje($correoDestino, $nombreDestino, $nombreEmisor, $tituloServicio, $mensaje, $chatId) {
    // URL a la bandeja de chats (ajusta si tu ruta es distinta, ej: /app/chats.php)
    $linkChat = "https://nubira.cl/app/chat_previo_contrato.php?id=" . $chatId;
    
    // Truncar el mensaje si es muy largo para no saturar el correo
    $mensajePreview = htmlspecialchars(mb_substr($mensaje, 0, 150, 'UTF-8')) . (mb_strlen($mensaje, 'UTF-8') > 150 ? '...' : '');

    $html = "
        <p>Hola <strong>$nombreDestino</strong>,</p>
        <p><strong>$nombreEmisor</strong> te ha escrito sobre <strong>$tituloServicio</strong>:</p>
        
        <div style='background-color:#F9FAFB; border-left: 4px solid #54A6D8; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0;'>
            <p style='margin:0; font-style:italic; color:#4B5563; font-size:15px;'>
                \"$mensajePreview\"
            </p>
        </div>
        
        <p style='font-size:14px; color:#6B7280; margin-top:20px;'>
            Recuerda mantener todas las conversaciones dentro de Nubira por tu seguridad.
        </p>
    ";

    // Usamos tu plantilla maestra para que tenga el logo, el footer y el botón oficial
    $cuerpo = plantillaMaestra("Nuevo mensaje de $nombreEmisor 💬", $html, "Responder ahora", $linkChat);
    
    // Enviamos usando el motor base
    return _enviarEmailBase($correoDestino, "Tienes un nuevo mensaje de $nombreEmisor", $cuerpo);
}

// ==========================================================
// NUEVA FUNCIÓN: RECORDATORIO DE CLASE AGENDADA (NUBIRA 2.0 AGENDA)
// ==========================================================

/**
 * Envía recordatorio de clase agendada al alumno o tutor
 * 
 * @param string $correoDestino  Email del destinatario
 * @param string $nombreDestino  Nombre del destinatario
 * @param string $nombreOtro     Nombre de la otra persona (tutor o alumno según el caso)
 * @param string $tituloServicio Título del servicio/clase
 * @param string $fechaClase     Fecha y hora de la clase (YYYY-MM-DD HH:MM:SS)
 * @param int    $contratoId     ID del contrato (para link directo al aula)
 * @param string $tipo           '24h' o '1h' — define el tono y urgencia del mensaje
 * @param string $rolDestino     'alumno' o 'tutor' — define el texto del mensaje
 */
function enviarCorreoRecordatorioClase($correoDestino, $nombreDestino, $nombreOtro, $tituloServicio, $fechaClase, $contratoId, $tipo = '24h', $rolDestino = 'alumno') {
    $linkAula = "https://nubira.cl/app/mini_aula.php?id=" . (int)$contratoId;
    
    // Formatear fecha amigable en español
    $ts = strtotime($fechaClase);
    $dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $fechaAmigable = ucfirst($dias[date('w', $ts)]) . ' ' . date('j', $ts) . ' de ' . $meses[date('n', $ts)-1];
    $hora = date('H:i', $ts);
    
    // Configuración según tipo y rol
    if ($tipo === '1h') {
        $emoji = '⏰';
        $urgencia = 'En 1 hora';
        $colorAcento = '#F59E0B'; // Ámbar
        $tituloEmail = "Tu clase comienza en 1 hora $emoji";
        $asunto = "⏰ Tu clase con $nombreOtro empieza en 1 hora";
        
        $mensajeCustom = $rolDestino === 'tutor'
            ? "Tu alumno <strong>$nombreOtro</strong> te espera en 1 hora. Asegúrate de tener todo listo (cámara, materiales, conexión)."
            : "Tu clase con <strong>$nombreOtro</strong> comienza en 1 hora. Prepárate y entra al aula 5 minutos antes.";
    } else {
        $emoji = '📅';
        $urgencia = 'Mañana';
        $colorAcento = '#54A6D8'; // Azul Nubira
        $tituloEmail = "Recordatorio: tu clase es mañana $emoji";
        $asunto = "📅 Tu clase con $nombreOtro es mañana";
        
        $mensajeCustom = $rolDestino === 'tutor'
            ? "No olvides que tienes una clase agendada con tu alumno <strong>$nombreOtro</strong> mañana."
            : "Recuerda que tienes una clase agendada con <strong>$nombreOtro</strong> mañana.";
    }

    $html = "
        <p>Hola <strong>$nombreDestino</strong>,</p>
        <p>$mensajeCustom</p>
        
        <div style='background-color:#F0F9FF; border-left: 4px solid $colorAcento; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0;'>
            <p style='margin:0; font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:1px; font-weight:bold;'>$urgencia</p>
            <p style='margin:8px 0 0 0; font-weight:bold; font-size:18px; color:#111827;'>$tituloServicio</p>
            <p style='margin:8px 0 0 0; font-size:15px; color:#374151;'>
                <strong>$fechaAmigable</strong> a las <strong>$hora</strong>
            </p>
        </div>
        
        <p style='font-size:14px; color:#6B7280; margin-top:20px;'>
            Podrás entrar al aula virtual <strong>5 minutos antes</strong> del inicio. Te recomendamos revisar tu cámara y micrófono con anticipación.
        </p>
    ";

    $cuerpo = plantillaMaestra($tituloEmail, $html, "Ir al Aula", $linkAula);
    return _enviarEmailBase($correoDestino, $asunto, $cuerpo);
}
?>