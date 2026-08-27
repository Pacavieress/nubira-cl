import crypto from "node:crypto";
import { env } from "../../config/env.js";

function escapeHtml(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Puerto exacto de generarUnsubUrl() (app/helpers/campanas.php:12-15) — mismo HMAC-SHA256
// sobre UNSUB_SECRET, misma URL absoluta de producción (el endpoint /unsubscribe real sigue
// viviendo en el sitio PHP, no se porta acá).
export function generarUnsubUrl(correo: string): string {
  const token = crypto.createHmac("sha256", env.unsubSecret).update(correo).digest("hex");
  return `https://nubira.cl/unsubscribe?token=${token}&e=${encodeURIComponent(correo)}`;
}

// Puerto exacto del header custom que enviarDormidoConUnsubscribe() agrega (campanas.php:119-123).
export function headersUnsubscribe(cfgUser: string, unsubUrl: string): Record<string, string> {
  return {
    "List-Unsubscribe": `<mailto:${cfgUser}?subject=unsubscribe>, <${unsubUrl}>`,
    "List-Unsubscribe-Post": "List-Unsubscribe=One-Click",
  };
}

const FOOTER_REDES = `
<p style="text-align:center;margin-top:26px;margin-bottom:6px;font-size:13px;color:#555;">
  Síguenos en redes sociales:
</p>
<p style="text-align:center;margin-bottom:24px;">
  <a href="https://instagram.com/nubira.cl" target="_blank" style="margin:0 8px;display:inline-block;">
    <img src="https://nubira.cl/upload/email/icon-instagram.png" alt="Instagram Nubira" width="26" style="display:inline-block;border:0;">
  </a>
  <a href="https://facebook.com/nubira.cl" target="_blank" style="margin:0 8px;display:inline-block;">
    <img src="https://nubira.cl/upload/email/icon-facebook.png" alt="Facebook Nubira" width="26" style="display:inline-block;border:0;">
  </a>
</p>`;

// Puerto de generarHtmlEmailDespertarDormidos() (enviar_despertar_dormidos.php:11-53), CON
// el pie de baja agregado (unsubUrl ahora es un parámetro requerido en vez de no existir) —
// ver la nota de corrección deliberada en adminDespertarDormidos.types.ts.
export function generarHtmlEmailDespertarDormidos(primerNombre: string, unsubUrl: string): string {
  const nombreSafe = escapeHtml(primerNombre);
  const unsubSafe = escapeHtml(unsubUrl);
  return `
<p>Hola <strong>${nombreSafe}</strong>,</p>

<p>Hace un tiempo te registraste en Nubira buscando ayuda académica.</p>

<p>Si este semestre todavía necesitas apoyo con un ramo, preparar una prueba, avanzar en tu tesis o encontrar otro servicio académico, tu cuenta sigue activa.</p>

<p><strong>Lo que hace distinta a Nubira:</strong></p>

<ul style="padding-left:20px; line-height:2.2;">
  <li>Tu dinero queda protegido en la plataforma hasta que confirmes que recibiste lo contratado. Si algo sale mal, no lo pierdes.</li>
  <li>Puedes conversar con los tutores sin compartir tu WhatsApp ni contactos de redes sociales.</li>
  <li>Las clases se hacen dentro de Nubira, sin instalar Zoom ni Meet.</li>
</ul>

<p>Hoy hay estudiantes y tutores activos en la plataforma resolviendo dudas y agendando clases particulares.</p>

<p style="text-align:center; margin:32px 0;">
  <a href="https://nubira.cl/explorar"
     style="background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;">
    Buscar tutor o servicio
  </a>
</p>

<p>Equipo Nubira<br><span style="color:#9CA3AF; font-size:14px;">Nubira.cl</span></p>
${FOOTER_REDES}
<hr style="margin:30px 0;border:none;border-top:1px solid #eee;">
<p style="font-size:11px;color:#888;">
  Si no quieres recibir más correos de Nubira,
  <a href="${unsubSafe}" style="color:#888;">puedes darte de baja aquí</a>.
</p>
`;
}

function bloqueCuponHtml(codigo: string, porcentaje: number, fechaExpiracion: string | null): string {
  const codigoSafe = escapeHtml(codigo);
  const vigencia = fechaExpiracion ? `Válido hasta el ${new Date(fechaExpiracion).toLocaleDateString("es-CL")}.` : "Sin fecha límite.";
  return `
    <div style="background:#F0F9FF; border:1px dashed #54A6D8; border-radius:12px; padding:20px; margin:20px 0; text-align:center;">
        <p style="margin:0 0 8px 0; font-size:13px; color:#0c4a6e; font-weight:bold;">Tu código de descuento</p>
        <p style="margin:0; font-size:22px; font-weight:bold; letter-spacing:1px; color:#111;">${codigoSafe}</p>
        <p style="margin:8px 0 0 0; font-size:12px; color:#555;">${porcentaje}% de descuento en tu próxima clase. ${vigencia}</p>
    </div>`;
}

// Puerto exacto de nb_generar_email_cupon_promocional() (campanas.php:222-250), con el
// mismo pie de baja agregado que generarHtmlEmailDespertarDormidos.
export function generarHtmlEmailCuponPromocional(
  primerNombre: string,
  codigo: string,
  porcentaje: number,
  fechaExpiracion: string | null,
  intro: string,
  unsubUrl: string,
): string {
  const nombreSafe = escapeHtml(primerNombre);
  const unsubSafe = escapeHtml(unsubUrl);
  return `
<p>Hola <strong>${nombreSafe}</strong>,</p>
<p>${escapeHtml(intro)}</p>
${bloqueCuponHtml(codigo, porcentaje, fechaExpiracion)}
<p style="text-align:center; margin:32px 0;">
  <a href="https://nubira.cl/explorar"
     style="background:#54A6D8;color:white;padding:13px 28px;
            text-decoration:none;border-radius:8px;font-weight:bold;
            font-size:16px;display:inline-block;">
    Buscar tutor o servicio
  </a>
</p>
<p>Equipo Nubira<br><span style="color:#9CA3AF; font-size:14px;">Nubira.cl</span></p>
${FOOTER_REDES}
<hr style="margin:30px 0;border:none;border-top:1px solid #eee;">
<p style="font-size:11px;color:#888;">
  Si no quieres recibir más correos de Nubira,
  <a href="${unsubSafe}" style="color:#888;">puedes darte de baja aquí</a>.
</p>
`;
}
