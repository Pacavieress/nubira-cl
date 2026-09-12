import crypto from "node:crypto";
import { env } from "../config/env.js";

// Puerto exacto de enlaceDescargaApunte() (app/helpers/comprador_invitado.php:144-149).
// String firmado, orden y separador idénticos al PHP: "$idApunte|$compradorId|$archivo|$exp".
// hash_hmac('sha256', ..., $secret) en PHP devuelve hex por defecto (raw_output=false,
// omitido ahí) — createHmac(...).digest("hex") acá replica exactamente ese encoding, mismo
// patrón ya usado en adminDespertarDormidos.templates.ts para UNSUB_SECRET.
//
// A diferencia del PHP real (que cae a 'NUBIRA_SECRET_TEMP_CAMBIAR' si la env var falta),
// acá NUNCA hay fallback: sin env.nubiraHmacSecret, throw ruidoso. Firmar con un secreto
// hardcodeado y conocido en el repo sería peor que no firmar nada.
export function enlaceDescargaApunte(idApunte: number, archivo: string, compradorId: number, ttlSegundos = 30 * 24 * 3600): string {
  if (!env.nubiraHmacSecret) {
    throw new Error(
      "NUBIRA_HMAC_SECRET no está configurado en server/.env — no se puede firmar el link de descarga (sin fallback a propósito, ver env.ts).",
    );
  }

  const exp = Math.floor(Date.now() / 1000) + ttlSegundos;
  const sig = crypto.createHmac("sha256", env.nubiraHmacSecret).update(`${idApunte}|${compradorId}|${archivo}|${exp}`).digest("hex");

  const params = new URLSearchParams({
    id: String(idApunte),
    archivo,
    comprador_id: String(compradorId),
    exp: String(exp),
    sig,
  });

  return `${env.assetsBaseUrl}/app/descargar_apunte.php?${params.toString()}`;
}
