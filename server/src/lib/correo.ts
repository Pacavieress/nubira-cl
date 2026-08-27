import nodemailer from "nodemailer";
import type { Transporter } from "nodemailer";
import { env } from "../config/env.js";

// Puerto mínimo de app/correo.php (_enviarEmailBase + plantillaMaestra) — mismo host/puerto/
// credenciales SMTP reales de Hostinger que usa el sitio PHP, para que un correo se vea
// idéntico sin importar si lo mandó PHP o Node durante la transición. Primer consumidor:
// Admin Retiros (correo de aprobado/rechazado al tutor) — pensado para ser reutilizable por
// futuras piezas sin duplicar esta configuración, no acoplado a retiros.
//
// A diferencia del PHP real (que registra éxito/error en app/log_correos.txt y nunca
// propaga el resultado a quien llama), acá enviarCorreo() devuelve un boolean: cada caller
// decide qué hacer si falla (loggear, avisarle al admin en la respuesta HTTP, etc.) — no se
// asume "seguir en silencio" como único comportamiento posible.

type CuentaRemitente = "noreply" | "contacto";

const CUENTAS: Record<CuentaRemitente, { user: string; name: string; pass: string }> = {
  noreply: { user: "no-reply@nubira.cl", name: "Nubira", pass: env.smtp.passNoreply },
  contacto: { user: "contacto@nubira.cl", name: "Equipo Nubira", pass: env.smtp.passContacto },
};

const transportersCache: Partial<Record<CuentaRemitente, Transporter>> = {};

function getTransporter(cuenta: CuentaRemitente): Transporter {
  const existente = transportersCache[cuenta];
  if (existente) return existente;
  const credenciales = CUENTAS[cuenta];
  // Puerto de correo.php:70-77 — SMTPS directo en el puerto 465 (más robusto en Hostinger
  // que STARTTLS en el 587, según el propio comentario del PHP real).
  const transporter = nodemailer.createTransport({
    host: "smtp.hostinger.com",
    port: 465,
    secure: true,
    auth: { user: credenciales.user, pass: credenciales.pass },
  });
  transportersCache[cuenta] = transporter;
  return transporter;
}

// Puerto exacto de plantillaMaestra() — mismo layout/colores/copy que el PHP real.
function plantillaMaestra(titulo: string, contenidoHtml: string): string {
  return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nubira</title>
</head>
<body style="margin:0; padding:0; background-color:#F3F4F6; font-family: Helvetica, Arial, sans-serif;">
    <table width="100%" border="0" cellspacing="0" cellpadding="0">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <tr>
                        <td align="center" style="padding:30px; border-bottom:1px solid #E5E7EB;">
                            <span style="color:#54A6D8; font-size:24px; font-weight:bold; letter-spacing:-1px;">Nubira.cl</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px 30px; color:#374151; font-size:16px; line-height:1.6;">
                            <h2 style="color:#111827; margin-top:0; font-size:20px; text-align:center;">${titulo}</h2>
                            <br>
                            ${contenidoHtml}
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#F9FAFB; padding:20px; text-align:center; color:#9CA3AF; font-size:12px;">
                            <p>&copy; ${new Date().getFullYear()} Nubira. Todos los derechos reservados.</p>
                            <p>Este correo fue enviado automáticamente, por favor no respondas directamente.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>`;
}

// Puerto de enviarCorreo() (la función genérica que admin_retiros.php realmente usa, no las
// plantillas específicas por caso de uso). Nunca lanza — devuelve false ante cualquier falla
// (credencial faltante, SMTP caído, destinatario inválido) para que el caller decida.
export async function enviarCorreo(destinatario: string, asunto: string, contenidoHtml: string, cuenta: CuentaRemitente = "noreply"): Promise<boolean> {
  const credenciales = CUENTAS[cuenta];
  if (!credenciales.pass) {
    console.error(`[correo] Falta SMTP_PASS_${cuenta.toUpperCase()} en el entorno — no se pudo enviar "${asunto}" a ${destinatario}`);
    return false;
  }
  try {
    await getTransporter(cuenta).sendMail({
      from: `"${credenciales.name}" <${credenciales.user}>`,
      to: destinatario,
      replyTo: credenciales.user,
      subject: asunto,
      html: plantillaMaestra(asunto, contenidoHtml),
    });
    return true;
  } catch (err) {
    console.error(`[correo] Error enviando "${asunto}" a ${destinatario}:`, err);
    return false;
  }
}
