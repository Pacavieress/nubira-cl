import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function GET(req: Request, { params }: { params: Promise<{ servicioId: string }> }) {
  const { servicioId } = await params;
  const { search } = new URL(req.url);
  const res = await fetchConSesion(`/api/me/contratos/checkout/${servicioId}${search}`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
