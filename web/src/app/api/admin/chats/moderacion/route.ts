import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function GET() {
  const res = await fetchConSesion("/api/admin/chats/moderacion");
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
