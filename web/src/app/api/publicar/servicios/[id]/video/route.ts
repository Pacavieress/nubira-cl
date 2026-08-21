import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — multipart/form-data, mismo patrón que
// web/src/app/api/publicar/apuntes/route.ts.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const formData = await req.formData();
  const res = await fetchConSesion(`/api/me/publicar/servicios/${id}/video`, { method: "POST", body: formData });
  if (!res) return NextResponse.json({ ok: false, error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
