import { NextResponse } from "next/server";
import { cookies } from "next/headers";

const PHP_SITE_URL = process.env.PHP_SITE_URL ?? "http://nubira.local";

// Proxy hacia app/api/geolocalizar_ip.php ("Ciudad, País" bajo cada IP — ver la
// simplificación deliberada en server/src/modules/adminAccesos/adminAccesos.types.ts: se
// omiten los mapas embebidos y el tooltip hover del PHP real, se conserva el dato).
//
// A diferencia de los demás proxies de este panel (que van vía fetchConSesion hacia server/,
// nuestro backend Express), este pega DIRECTO al sitio PHP real — geolocalizar_ip.php valida
// su propio $_SESSION['usuario_id']/rol (sesión nativa de PHP), no la tabla sesiones_api que
// usa nuestro backend. Reenviamos la cookie PHPSESSID tal cual llegó, sin pasar por
// fetchConSesion (que apunta a API_URL, un origen distinto).
export async function POST(req: Request) {
  const cookieStore = await cookies();
  const phpSessId = cookieStore.get("PHPSESSID")?.value;
  if (!phpSessId) return NextResponse.json({ ok: false, error: "no_autenticado" }, { status: 401 });

  const body = await req.text();
  try {
    const res = await fetch(`${PHP_SITE_URL}/app/api/geolocalizar_ip.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${phpSessId}` },
      body,
      cache: "no-store",
    });
    const data = await res.json().catch(() => null);
    return NextResponse.json(data, { status: res.status });
  } catch {
    return NextResponse.json({ ok: false }, { status: 502 });
  }
}
