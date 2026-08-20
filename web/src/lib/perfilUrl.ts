// Puerto exacto de nubira_encriptar_id() (app/seguridad_url.php:10-17) — el salt
// 'nubira_secreto' es ofuscación de IDs contra scraping/enumeración, no un secreto de
// seguridad real (ya documentado como tal en app/seguridad_url.php mismo), así que
// hardcodearlo acá replica el comportamiento real, no introduce un riesgo nuevo.
const NUBIRA_SALT = "nubira_secreto";

export function nubiraEncriptarId(id: number): string {
  const base64 = Buffer.from(`${id}-${NUBIRA_SALT}`, "utf-8").toString("base64");
  return base64.replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}
