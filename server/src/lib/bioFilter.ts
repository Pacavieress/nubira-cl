// Puerto exacto de las validaciones de app/actualizar_bio.php:54-125 — mismo orden
// (vacía -> largo máx -> largo mín -> lenguaje ofensivo -> patrones DLP), mismos mensajes
// (para no generarle al usuario un mensaje distinto al que ya conoce del sitio real).

const PALABRAS_PROHIBIDAS = ["weon", "ctm", "puta", "culiao", "ql", "perra", "maricon", "odio", "muerte", "pendejo", "estafa"];

const NUCLEO_DIGITOS_TEL = "(?:\\d[\\s\\-.]*){7,}";

interface PatronBloqueo {
  regex: RegExp;
  mensaje: string;
}

// Orden EXACTO del PHP real (email, telefono, redes, urls) — un mensaje de error distinto
// por categoría, no un genérico "contenido no permitido".
const PATRONES_BLOQUEO: PatronBloqueo[] = [
  {
    regex: /[a-z0-9._%+-]+(?:@|\s+arroba\s+|\[arroba\]|\(arroba\))[a-z0-9.-]+(?:\.|\s+punto\s+|\[punto\])[a-z]{2,}/i,
    mensaje: "Tu biografía no puede incluir un correo electrónico. Bórralo para poder guardar.",
  },
  {
    regex: new RegExp(`(?:\\+?56\\s*9|9)?[\\s\\-.]*${NUCLEO_DIGITOS_TEL}`),
    mensaje: "Tu biografía no puede incluir un número de teléfono. Bórralo para poder guardar.",
  },
  {
    regex: /\b(wh?a[ts]+s?[aá]pp?|wasap|watsap|whsatap|guatsap|wsp|wa\.me|instagram|insta|ig|face|fb|tiktok|tk|telegram|tg|t\.me|discord|dc|linktree|x\.com|twitter|tw|linkedin|in)\b/i,
    mensaje: "Tu biografía no puede mencionar redes sociales o apps de mensajería. Bórralo para poder guardar.",
  },
  {
    regex: /(http|https|www\.)/i,
    mensaje: "Tu biografía no puede incluir enlaces. Bórralo para poder guardar.",
  },
];

export type ValidacionBio = { ok: true } | { ok: false; mensaje: string };

// bio ya se recibe trim()-eada por el caller (mismo orden que trim($_POST['bio']) del PHP,
// aplicado ANTES de estas validaciones).
export function validarBio(bio: string): ValidacionBio {
  if (bio === "") {
    return { ok: false, mensaje: "LA BIO NO PUEDE ESTAR VACÍA" };
  }
  // strlen() de PHP cuenta BYTES utf-8, no caracteres — Buffer.byteLength replica eso
  // exacto (un carácter acentuado ocupa 2 bytes, igual que en el límite real de 500).
  if (Buffer.byteLength(bio, "utf8") > 500) {
    return { ok: false, mensaje: "MÁXIMO 500 CARACTERES PERMITIDOS" };
  }
  if ([...bio].length < 60) {
    return { ok: false, mensaje: "PARA DESTACAR TU PERFIL, TU BIO DEBE TENER AL MENOS 60 CARACTERES" };
  }
  const bioMinuscula = bio.toLowerCase();
  for (const palabra of PALABRAS_PROHIBIDAS) {
    if (bioMinuscula.includes(palabra)) {
      return { ok: false, mensaje: "LENGUAJE NO PERMITIDO" };
    }
  }
  for (const { regex, mensaje } of PATRONES_BLOQUEO) {
    if (regex.test(bio)) {
      return { ok: false, mensaje };
    }
  }
  return { ok: true };
}
