import { cookies } from "next/headers";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// nombre/iniciales/fotoPerfil/mostrarBotonesPublicar/perfilIncompleto [26/08/2026] — lo
// que Header.tsx necesita para su propio espejo de header.php (avatar real, botones de
// publicar, punto de alerta). Ver server/src/modules/auth/auth.routes.ts para el cálculo.
export interface Sesion {
  usuarioId: number;
  rol: string | null;
  esAdmin: boolean;
  nombre: string | null;
  iniciales: string;
  fotoPerfil: string | null;
  mostrarBotonesPublicar: boolean;
  perfilIncompleto: boolean;
}

// Next.js corre esto en un Server Component — la llamada de acá a server/ es
// server-to-server, así que NO pasa por el navegador ni por CORS (CORS es una regla que
// el navegador aplica a fetch/XHR desde JS de página; no aplica a fetch() de Node ni a
// navegaciones top-level). Por eso NO hace falta tocar CORS_ORIGIN en server/.env para
// este flujo — solo sería necesario el día que algo del lado del navegador (ej. un botón
// "cerrar sesión" en un Client Component) llame directo a la API.
//
// La cookie PHPSESSID de la request entrante a web/ NO llega sola a este fetch — Node no
// hereda cookies del navegador como haría el navegador mismo entre pestañas del mismo
// origen. Hay que leerla explícitamente de next/headers::cookies() y reenviarla a mano
// como header Cookie. Para que el navegador siquiera TENGA esa cookie en la request a
// web/, hace falta visitar web/ por el mismo host que sirve PHPSESSID (nubira.local),
// aunque sea en otro puerto — las cookies se scopean por (dominio, path), no por
// puerto/esquema, así que nubira.local:80 y nubira.local:3000 comparten cookie, pero
// localhost:3000 nunca la va a recibir aunque sea "el mismo mismo server" en la práctica.
//
// Única fuente de verdad para el reenvío de cookie — cualquier endpoint autenticado de
// server/ (getSesion, getMisCompras en api.ts, y los que vengan después) pasa por acá en
// vez de repetir la lectura de cookies()/armado del header Cookie en cada archivo. Sin
// sesión, devuelve null ANTES de tocar la red (mismo corte temprano que ya tenía
// getSesion) — el caso común (visitante anónimo) no paga el roundtrip a server/.
export async function fetchConSesion(path: string, init?: RequestInit): Promise<Response | null> {
  const cookieStore = await cookies();
  const phpSessId = cookieStore.get("PHPSESSID")?.value;
  if (!phpSessId) return null;

  return fetch(`${API_URL}${path}`, {
    ...init,
    cache: "no-store",
    headers: { ...(init?.headers ?? {}), Cookie: `PHPSESSID=${phpSessId}` },
  });
}

export async function getSesion(): Promise<Sesion | null> {
  const res = await fetchConSesion("/api/me");
  if (!res || !res.ok) return null;
  return res.json();
}
