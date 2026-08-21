import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — mismo patrón que web/src/app/api/soporte/route.ts.
export async function POST(req: Request) {
  const body = await req.text();
  const res = await fetchConSesion("/api/me/publicar/servicios", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body,
  });
  if (!res) return NextResponse.json({ ok: false, error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
