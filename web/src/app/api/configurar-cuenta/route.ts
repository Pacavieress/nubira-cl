import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — mismo criterio que web/src/app/api/mis-publicaciones/
// (el formulario de "Información Básica" se envía desde un Client Component, así que pasa
// por acá en vez de un fetch directo del navegador a server/, evitando abrir CORS_ORIGIN
// solo para esto).
export async function PUT(req: Request) {
  const body = await req.text();
  const res = await fetchConSesion("/api/me/configurar-cuenta", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body,
  });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });

  if (res.status === 204) return new NextResponse(null, { status: 204 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
