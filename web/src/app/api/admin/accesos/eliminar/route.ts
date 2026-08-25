import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function POST(req: Request) {
  const body = await req.text();
  const res = await fetchConSesion("/api/admin/accesos/eliminar", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body,
  });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
