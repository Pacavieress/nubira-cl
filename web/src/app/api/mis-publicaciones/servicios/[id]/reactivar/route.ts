import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/me/mis-publicaciones/servicios/${id}/reactivar`, { method: "POST" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  return new NextResponse(null, { status: res.status });
}
