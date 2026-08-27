// Puerto exacto de la capa DLP de app/enviar_mensaje.php:80-209 — 7 categorías de regex +
// 3 reglas contextuales (celular, juntémonos+plataforma, teléfono fraccionado en varios
// mensajes). Factorizado acá (no en el módulo de chat) porque chat_mini_aula.php usa el
// MISMO bloque casi idéntico — se porta una sola vez para no duplicar 130 líneas de regex
// cuando llegue esa pieza (Grupo Mini Aula).
//
// Nota de puerto: PHP usa mb_strtolower/mb_stripos/mb_substr con 'UTF-8' para no desalinear
// ventanas de caracteres con tildes/ñ. JS no lo necesita — los strings ya son UTF-16 y
// indexOf/slice/toLowerCase operan correctamente char a char sobre BMP (incluye todo el
// español) sin equivalente mb_ requerido.

export type CategoriaDlp = "email" | "telefono" | "redes" | "banco" | "intencion_contacto" | "identidad" | "urls";

export interface ResultadoDlp {
  bloqueado: boolean;
  categoria?: CategoriaDlp;
  patronDescripcion?: string;
  mensajeUsuario?: string;
}

const NUCLEO_DIGITOS_TEL = String.raw`(?:\d[\s\-.]*){7,}`;

const PATRONES: Record<CategoriaDlp, RegExp> = {
  email: /[a-z0-9._%+-]+(?:@|\s+arroba\s+|\[arroba\]|\(arroba\))[a-z0-9.-]+(?:\.|\s+punto\s+|\[punto\])[a-z]{2,}/i,
  telefono: new RegExp(String.raw`(?:\+?56\s*9|9)?[\s\-.]*${NUCLEO_DIGITOS_TEL}`),
  redes:
    /\b(wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|instagram|insta|ig|face|fb|tiktok|tk|telegram|tg|t\.me|discord|dc|linktree|x\.com|twitter|tw|linkedin|in)\b/i,
  banco:
    /\b(transferencia|transferir|cuenta rut|cta rut|banco|santander|bci|estado|scotiabank|itau|tenpo|mach|mercadopago|mp|pago rut|datos de mi cuenta|mi rut|rut:)\b/i,
  intencion_contacto: /\b(contacto|fono|tel[eé]fono|ll[aá]mame|llamada|mi n[uú]mero|direcci[oó]n|calle|pasaje|vives en|vivo en|mi casa|zoom|meet|teams|skype)\b/i,
  identidad: /\b(mi nombre es|me llamo|mi apellido|me dicen|puedes decirme|b[úu]scame|encontrarme|encontrame|soy el de|mi perfil|mi cuenta)\b/i,
  urls: /(http|https|www\.)/i,
};

const MENSAJES_DLP: Record<CategoriaDlp, string> = {
  email:
    "⚠️ Detectamos que intentaste compartir un correo electrónico. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
  telefono:
    "⚠️ Detectamos que intentaste compartir un número de teléfono. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
  redes:
    "⚠️ Detectamos que intentaste compartir una red social o app de mensajería externa. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
  banco:
    "⚠️ Detectamos que intentaste compartir datos bancarios o coordinar un pago fuera de Nubira. Los pagos solo deben hacerse a través de la plataforma para mantener tu Garantía Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
  intencion_contacto:
    "⚠️ Detectamos que intentaste coordinar contacto o encuentros fuera de Nubira. Por tu seguridad y la garantía de pago protegido, todo debe quedar dentro de la plataforma. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
  identidad:
    "⚠️ Detectamos que intentaste compartir datos que permitirían identificarte o ser encontrado fuera de Nubira. Por tu seguridad, mantén la conversación dentro de la plataforma. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
  urls: "⚠️ Detectamos que intentaste compartir un enlace externo. Por tu seguridad y la garantía de pago protegido, todo contacto debe quedar dentro de Nubira. Los intentos repetidos pueden derivar en la suspensión de tu cuenta.",
};

const FRASE_CELULAR = /\b(mi|tu|su)\s+celular\b|\bn[uú]mero\s+celular\b/i;
const NUCLEO_DIGITOS_REGEX = new RegExp(NUCLEO_DIGITOS_TEL);
const FRASE_JUNTEMONOS = /\b(junt[eé]monos|reun[aá]monos)\b/i;
const PATRON_PLATAFORMAS = /\b(zoom|meet|teams|skype|wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|telegram|tg|t\.me|discord)\b/i;

function bloqueo(categoria: CategoriaDlp, patronDescripcion: string): ResultadoDlp {
  return { bloqueado: true, categoria, patronDescripcion, mensajeUsuario: MENSAJES_DLP[categoria] };
}

// mensajesPreviosMismoRemitente: hasta 5 mensajes anteriores del MISMO remitente en los
// últimos 5 minutos, en orden cronológico — puerto de enviar_mensaje.php:183-209 (teléfono
// fraccionado en varios mensajes consecutivos).
export function verificarDlp(mensajeOriginal: string, mensajesPreviosMismoRemitente: string[] = []): ResultadoDlp {
  const mensajeLower = mensajeOriginal.toLowerCase();

  for (const categoria of Object.keys(PATRONES) as CategoriaDlp[]) {
    if (PATRONES[categoria].test(mensajeLower)) {
      return bloqueo(categoria, categoria);
    }
  }

  // 5b. "celular" con contexto — evita el falso positivo de "biología celular".
  const posCelular = mensajeLower.indexOf("celular");
  if (posCelular !== -1) {
    const inicioVentana = Math.max(0, posCelular - 25);
    const ventana = mensajeLower.slice(inicioVentana, posCelular + "celular".length + 25);
    if (FRASE_CELULAR.test(ventana) || NUCLEO_DIGITOS_REGEX.test(ventana)) {
      return bloqueo("intencion_contacto", "celular (con contexto)");
    }
  }

  // 5c. "juntémonos"/"reunámonos" solo si además menciona una plataforma externa.
  if (FRASE_JUNTEMONOS.test(mensajeLower) && PATRON_PLATAFORMAS.test(mensajeLower)) {
    return bloqueo("intencion_contacto", "juntemonos/reunamonos + plataforma externa");
  }

  // 5d. Teléfono fraccionado en varios mensajes consecutivos.
  if (/\d/.test(mensajeLower) && mensajesPreviosMismoRemitente.length > 0) {
    const combinado = mensajesPreviosMismoRemitente.join(" ").toLowerCase() + " " + mensajeLower;
    if (PATRONES.telefono.test(combinado)) {
      return bloqueo("telefono", "telefono (fraccionado en varios mensajes)");
    }
  }

  return { bloqueado: false };
}
