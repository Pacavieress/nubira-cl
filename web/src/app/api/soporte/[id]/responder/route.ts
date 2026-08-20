import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const body = await req.text();
  const res = await fetchConSesion(`/api/me/soporte/${id}/responder`, { method: "POST", headers: { "Content-Type": "application/json" }, body });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  return new NextResponse(null, { status: res.status });
}
