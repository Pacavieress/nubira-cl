import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — mismo patrón que web/src/app/api/soporte/route.ts
// (evita abrir CORS_ORIGIN solo para esta llamada disparada desde el Client Component al
// elegir un ramo).
export async function GET(req: Request) {
  const { searchParams } = new URL(req.url);
  const materia = searchParams.get("materia") ?? "";
  const res = await fetchConSesion(`/api/desafio/preguntas?materia=${encodeURIComponent(materia)}`);
  if (!res) return NextResponse.json({ ok: false, error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
