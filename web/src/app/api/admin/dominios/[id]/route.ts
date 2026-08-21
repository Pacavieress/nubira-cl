import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function PUT(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const body = await req.text();
  const res = await fetchConSesion(`/api/admin/dominios/${id}`, { method: "PUT", headers: { "Content-Type": "application/json" }, body });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}

export async function DELETE(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/admin/dominios/${id}`, { method: "DELETE" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
