// Puerto exacto de generar_slug() en app/helpers/seo.php:104-128.
const MAPA_ACENTOS: Record<string, string> = {
  á: "a",
  à: "a",
  â: "a",
  ä: "a",
  ã: "a",
  é: "e",
  è: "e",
  ê: "e",
  ë: "e",
  í: "i",
  ì: "i",
  î: "i",
  ï: "i",
  ó: "o",
  ò: "o",
  ô: "o",
  ö: "o",
  õ: "o",
  ú: "u",
  ù: "u",
  û: "u",
  ü: "u",
  ñ: "n",
  ç: "c",
};

export function generarSlug(titulo: string): string {
  let texto = titulo
    // Rango de emojis "misceláneos" (equivalente al \x{1F000}-\x{1FFFF} de PHP).
    .replace(/[\u{1F000}-\u{1FFFF}]/gu, "")
    // Símbolos/dingbats (\x{2600}-\x{27BF}).
    .replace(/[\u{2600}-\u{27BF}]/gu, "")
    .toLowerCase();

  texto = texto.replace(/[áàâäãéèêëíìîïóòôöõúùûüñç]/g, (c) => MAPA_ACENTOS[c] ?? c);
  texto = texto.replace(/[^a-z0-9\s-]/gu, "-");
  texto = texto.replace(/[\s-]+/g, "-");
  texto = texto.replace(/^-+|-+$/g, "");

  if (texto.length > 100) {
    texto = texto.slice(0, 100);
    const pos = texto.lastIndexOf("-");
    if (pos > 50) {
      texto = texto.slice(0, pos);
    }
  }

  return texto;
}
