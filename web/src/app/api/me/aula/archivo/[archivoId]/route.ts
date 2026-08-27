import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function GET(_req: Request, { params }: { params: Promise<{ archivoId: string }> }) {
  const { archivoId } = await params;
  const res = await fetchConSesion(`/api/me/aula/archivo/${archivoId}`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });

  const headers = new Headers();
  for (const nombre of ["content-type", "content-disposition", "x-content-type-options", "content-security-policy"]) {
    const valor = res.headers.get(nombre);
    if (valor) headers.set(nombre, valor);
  }
  return new Response(res.body, { status: res.status, headers });
}
