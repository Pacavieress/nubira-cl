import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { isIP } from "node:net";

const PHP_SITE_URL = process.env.PHP_SITE_URL ?? "http://nubira.local";

// Proxy hacia app/crear_cuenta_express.php (modal "Cuenta Express" de detalle_servicio.php).
// Existe porque el navegador no puede llamar al PHP directo desde Next (otro origen, sin
// CORS) y porque la sesión que crea ese endpoint es una sesión NATIVA de PHP: hay que
// trasladar su cookie PHPSESSID al navegador (las cookies se scopean por host, no por
// puerto, así que nubira.local:3000 y nubira.local:80 la comparten).
//
// IP del visitante (para el límite de 3 intentos/60 min de PHP, que lee REMOTE_ADDR y solo
// respeta X-Forwarded-For si la conexión directa viene de un proxy de confianza — ver
// crear_cuenta_express.php): un Route Handler de Next no tiene acceso al socket, así que la
// única fuente es la cabecera x-forwarded-for de la request entrante, y esa la controla el
// cliente salvo que haya un proxy inverso PROPIO delante de Next que la complete.
//   - TRUSTED_PROXY_HOPS (default 0): cuántos proxies de confianza hay delante de Next. Cada
//     uno AGREGA al final de la lista la IP de quien le habló, así que con N proxies la IP
//     real del visitante es la N-ésima desde la derecha; lo que esté a su izquierda lo puso
//     el cliente y se ignora.
//   - Con 0 (local, o cualquier despliegue sin proxy inverso) la cabecera entrante se
//     IGNORA por completo y se usa 127.0.0.1: en local hay un solo visitante (el
//     desarrollador); en un despliegue sin proxy sería un límite global, pero fallar así es
//     preferible a dejar que el cliente falsifique su IP.
const HOPS_CONFIABLES = Number(process.env.TRUSTED_PROXY_HOPS ?? 0);
const IP_FALLBACK = "127.0.0.1";

function ipDelVisitante(req: Request): string {
  if (Number.isInteger(HOPS_CONFIABLES) && HOPS_CONFIABLES > 0) {
    const partes = (req.headers.get("x-forwarded-for") ?? "")
      .split(",")
      .map((p) => p.trim())
      .filter(Boolean);
    const candidata = partes[partes.length - HOPS_CONFIABLES];
    if (candidata && isIP(candidata) !== 0) return candidata;
  }
  return IP_FALLBACK;
}

const ERROR_CONEXION = { ok: false, error: "Error de conexión. Intenta de nuevo." };

export async function POST(req: Request) {
  const body = await req.text();

  // Se reenvía solo PHPSESSID (ninguna otra cookie del navegador). Sin esto, PHP no vería la
  // sesión existente del visitante: no podría responder "Ya estás logueado." y, peor, le
  // crearía una sesión nueva que reemplazaría la suya al trasladar el Set-Cookie.
  const cookieStore = await cookies();
  const phpSessId = cookieStore.get("PHPSESSID")?.value;

  let res: Response;
  try {
    res = await fetch(`${PHP_SITE_URL}/app/crear_cuenta_express.php`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Forwarded-For": ipDelVisitante(req),
        ...(phpSessId ? { Cookie: `PHPSESSID=${phpSessId}` } : {}),
      },
      body,
      cache: "no-store",
      redirect: "manual",
      signal: AbortSignal.timeout(10_000),
    });
  } catch {
    return NextResponse.json(ERROR_CONEXION, { status: 502 });
  }

  const data = await res.json().catch(() => null);
  if (data === null) {
    // Respuesta no-JSON (ej. error fatal de PHP): no se reenvía tal cual.
    return NextResponse.json(ERROR_CONEXION, { status: 502 });
  }

  const respuesta = NextResponse.json(data, { status: res.status });

  // Solo la cookie PHPSESSID, con sus atributos tal como PHP los fijó (path, httponly,
  // samesite, y domain/secure solo si PHP los puso). Cualquier otro Set-Cookie se descarta.
  const cookiePhp = res.headers.getSetCookie().find((c) => c.startsWith("PHPSESSID="));
  if (cookiePhp) respuesta.headers.append("Set-Cookie", cookiePhp);

  return respuesta;
}
