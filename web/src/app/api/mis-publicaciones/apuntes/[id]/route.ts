import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function DELETE(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/me/mis-publicaciones/apuntes/${id}`, { method: "DELETE" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  return new NextResponse(null, { status: res.status });
}
