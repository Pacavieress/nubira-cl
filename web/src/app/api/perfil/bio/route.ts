import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin autenticado — mismo patrón que /api/soporte/[id]/responder.
export async function PUT(req: Request) {
  const body = await req.text();
  const res = await fetchConSesion("/api/me/perfil/bio", { method: "PUT", headers: { "Content-Type": "application/json" }, body });
  if (!res) return NextResponse.json({ ok: false, mensaje: "SESIÓN EXPIRADA" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
