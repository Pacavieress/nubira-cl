import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function DELETE(req: Request) {
  const body = await req.text();
  const res = await fetchConSesion("/api/admin/login-fallos/fallos", {
    method: "DELETE",
    headers: { "Content-Type": "application/json" },
    body,
  });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
