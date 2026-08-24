import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — cambio de tab/página es un fetch de Client Component.
export async function GET(req: Request) {
  const { search } = new URL(req.url);
  const res = await fetchConSesion(`/api/admin/login-fallos${search}`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
