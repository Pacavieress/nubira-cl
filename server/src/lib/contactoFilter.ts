// Puerto exacto de contiene_contacto() en app/publicar_servicio.php:107-132 — mismos
// patrones, mismo orden. Solo se usa en el flujo de servicios (el PHP real de apuntes,
// formulario_subir_apunte.php, no llama esta función en ningún punto — se replica esa
// misma asimetría, no es un descuido).
const PATRONES_CONTACTO: RegExp[] = [
  /\b\d{8,}\b/,
  /\b(?:\d[\s\-.]?){7}\d/,
  /\+56/,
  /@/,
  /\barroba\b/iu,
  /\b(gmail|hotmail|yahoo|outlook|protonmail|live|icloud)\b/i,
  /(https?:\/\/|www\.)/i,
  /wa\.me|t\.me/i,
  /\b(whatsapp|wsp|wpp|telegram|instagram|insta|tiktok|snapchat|discord|facebook)\b/i,
  /\b(contact[aá]me|escrí?beme|mi\s+n[uú]mero|fuera\s+de\s+la\s+plataforma)\b/iu,
];

export function contieneContacto(texto: string): boolean {
  return PATRONES_CONTACTO.some((patron) => patron.test(texto));
}
