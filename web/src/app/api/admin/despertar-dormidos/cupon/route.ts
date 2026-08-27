import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — validación de cupón global opcional (AdminDespertarDormidosPanel.tsx).
export async function GET(req: Request) {
  const { searchParams } = new URL(req.url);
  const codigo = searchParams.get("codigo") ?? "";
  const res = await fetchConSesion(`/api/admin/despertar-dormidos/cupon?codigo=${encodeURIComponent(codigo)}`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
