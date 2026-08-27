import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy binario — primer caso de este tipo en web/ (los demás proxies de esta migración son
// JSON). Reenvía el body (stream) y los headers relevantes tal cual, en vez de parsear JSON.
export async function GET(req: Request, { params }: { params: Promise<{ mensajeId: string }> }) {
  const { mensajeId } = await params;
  const { search } = new URL(req.url);
  const res = await fetchConSesion(`/api/me/chat/archivo/${mensajeId}${search}`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });

  const headers = new Headers();
  for (const nombre of ["content-type", "content-disposition", "cache-control", "x-content-type-options", "content-security-policy"]) {
    const valor = res.headers.get(nombre);
    if (valor) headers.set(nombre, valor);
  }

  return new Response(res.body, { status: res.status, headers });
}
