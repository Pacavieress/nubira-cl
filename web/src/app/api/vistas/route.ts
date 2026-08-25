import { NextResponse } from "next/server";
import { cookies } from "next/headers";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy same-origin hacia server/ — mismo criterio que /api/servicio/compartir/track, con
// el agregado de reenviar la cookie PHPSESSID (a diferencia de ese, este endpoint SÍ
// enriquece con usuario_id cuando hay sesión, igual que track_vista.php con
// $_SESSION['usuario_id']). navigator.sendBeacon (VistaTracker.tsx) no permite headers
// custom, así que el body llega como texto plano tal cual — se reenvía igual, sin parsear.
export async function POST(req: Request) {
  const body = await req.text();
  const ipEntrante = req.headers.get("x-forwarded-for");
  const cookieStore = await cookies();
  const phpSessId = cookieStore.get("PHPSESSID")?.value;

  const res = await fetch(`${API_URL}/api/vistas`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(ipEntrante ? { "X-Forwarded-For": ipEntrante } : {}),
      ...(phpSessId ? { Cookie: `PHPSESSID=${phpSessId}` } : {}),
    },
    body,
  });

  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
