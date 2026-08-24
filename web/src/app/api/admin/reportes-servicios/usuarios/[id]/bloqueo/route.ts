import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function PUT(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const body = await req.text();
  const res = await fetchConSesion(`/api/admin/reportes-servicios/usuarios/${id}/bloqueo`, {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body,
  });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
