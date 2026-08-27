import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Puerto de finalizar_servicio.php ("Finalizar y Pagar", comprador). El server ya lo tenía
// listo desde el Checkpoint 2 (Pago) pero sin UI que lo disparara, porque mini_aula.php
// (donde vive el botón real) todavía no estaba portado. El disparador real es AulaShell.tsx.
export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/me/contratos/${id}/finalizar`, { method: "POST" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
