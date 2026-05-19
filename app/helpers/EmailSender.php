<?php
/**
 * NUBIRA 2.0 - MOTOR DE ENVÍO DE CORREOS
 * Función: Centralizar el envío de emails transaccionales (Registro, Avisos, Claves)
 * Retorna: true si se envió, false si falló.
 */

function enviarEmailOficial($destinatario, $asunto, $titulo, $cuerpo_html) {
    
    // 1. Cabeceras Obligatorias para evitar SPAM
    // Usamos noreply@nubira.cl (asegúrate de que este correo exista en tu cPanel)
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Nubira.cl <noreply@nubira.cl>" . "\r\n";
    $headers .= "Reply-To: contacto@nubira.cl" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // 2. Plantilla HTML Oficial Nubira (Estilo Airbnb/Clean)
    // Inyectamos el contenido dentro de una estructura bonita
    $mensaje = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; background-color: #f7f7f7; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; }
            .header { background-color: #ffffff; padding: 30px 40px; border-bottom: 1px solid #f3f4f6; text-align: center; }
            .logo { color: #54A6D8; font-size: 24px; font-weight: bold; letter-spacing: -1px; text-decoration: none; }
            .content { padding: 40px; color: #484848; line-height: 1.6; }
            .btn { display: inline-block; background-color: #54A6D8; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; }
            .footer { background-color: #f9fafb; padding: 20px; text-align: center; font-size: 12px; color: #9ca3af; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <a href="https://nubira.cl" class="logo">nubira.cl</a>
            </div>
            <div class="content">
                <h2 style="color: #111827; margin-top: 0;">' . $titulo . '</h2>
                <div style="font-size: 16px;">
                    ' . $cuerpo_html . '
                </div>
            </div>
            <div class="footer">
                &copy; ' . date('Y') . ' Nubira.cl - Todos los derechos reservados.<br>
                Este es un mensaje automático, por favor no responder.
            </div>
        </div>
    </body>
    </html>
    ';

    // 3. Envío seguro (Manejo de errores básico)
    try {
        // mail() nativo de PHP. Si usas PHPMailer en el futuro, cámbialo aquí.
        if(mail($destinatario, $asunto, $mensaje, $headers)) {
            return true;
        } else {
            error_log("Error enviando email a: $destinatario"); // Guardar en log del servidor
            return false;
        }
    } catch (Exception $e) {
        error_log("Excepción email: " . $e->getMessage());
        return false;
    }
}
?>