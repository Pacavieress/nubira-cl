import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Puerto de finalizar_servicio_tutor.php ("Confirmar Cierre", vendedor). Ver nota en
// finalizar/route.ts — mismo caso, mismo disparador real (AulaShell.tsx).
export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/me/contratos/${id}/confirmar-cierre`, { method: "POST" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
