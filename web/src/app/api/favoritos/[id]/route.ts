import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — mismo patrón que web/src/app/api/soporte/[id]/*.
async function proxy(id: string, method: "PUT" | "DELETE") {
  const res = await fetchConSesion(`/api/me/favoritos/${id}`, { method });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  return new NextResponse(null, { status: res.status });
}

export async function PUT(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return proxy(id, "PUT");
}

export async function DELETE(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return proxy(id, "DELETE");
}
