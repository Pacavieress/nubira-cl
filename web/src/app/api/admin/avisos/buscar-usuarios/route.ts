import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — búsqueda de usuario para el segmento "usuario
// específico" del formulario de nueva campaña (AdminAvisosPanel.tsx).
export async function GET(req: Request) {
  const { searchParams } = new URL(req.url);
  const q = searchParams.get("q") ?? "";
  const res = await fetchConSesion(`/api/admin/avisos/buscar-usuarios?q=${encodeURIComponent(q)}`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
