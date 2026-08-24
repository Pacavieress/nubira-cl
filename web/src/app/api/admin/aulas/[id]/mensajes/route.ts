import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — el detalle de mensajes se carga al seleccionar un aula
// y se refresca con polling cada 5s (mismo intervalo que admin_chats_aula.php:465), por eso
// es un fetch de Client Component en vez de resolverse en el server component de la página.
export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/admin/aulas/${id}/mensajes`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
