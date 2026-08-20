import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — necesario porque esta acción se dispara desde un
// Client Component (botón "Eliminar" en la lista de Mis Publicaciones), no desde un
// Server Component. Un fetch directo del navegador a server/ (otro puerto = otro origin)
// necesitaría CORS_ORIGIN habilitado en server/.env; con este proxy, el navegador solo
// habla con web/ mismo (same-origin, sin preflight) y es Next.js quien reenvía la cookie
// server-to-server vía fetchConSesion, igual que ya hace cada página de esta migración.
export async function DELETE(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/me/mis-publicaciones/servicios/${id}`, { method: "DELETE" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  return new NextResponse(null, { status: res.status });
}
