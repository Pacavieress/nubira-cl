import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const body = await req.text();
  const res = await fetchConSesion(`/api/me/publicar/servicios/${id}/horario`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body,
  });
  if (!res) return NextResponse.json({ ok: false, error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
