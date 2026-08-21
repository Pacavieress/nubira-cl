import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy same-origin hacia server/ — público (mismo criterio que el endpoint real), sin
// fetchConSesion. Reenvía la IP real del visitante vía X-Forwarded-For cuando la request
// entrante ya la trae (detrás de un proxy/CDN) — sin esto, server/ registraría la IP del
// propio contenedor de Next.js en vez de la del visitante real (campo solo analítico, no
// crítico, pero vale la pena no degradarlo silenciosamente si el dato está disponible).
export async function POST(req: Request) {
  const body = await req.text();
  const ipEntrante = req.headers.get("x-forwarded-for");

  const res = await fetch(`${API_URL}/api/compartir/desafio/track`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(ipEntrante ? { "X-Forwarded-For": ipEntrante } : {}),
    },
    body,
  });

  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
