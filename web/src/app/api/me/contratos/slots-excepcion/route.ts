import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Puerto de generar_slot_excepcion.php — el server ya lo tenía listo desde el Checkpoint 1
// (Grupo de Contratación) pero sin UI que lo disparara, porque el chat todavía no estaba
// portado. El disparador real es el modal "Generar Reserva" de ChatWindow.tsx.
export async function POST(req: Request) {
  const body = await req.text();
  const res = await fetchConSesion("/api/me/contratos/slots-excepcion", { method: "POST", headers: { "Content-Type": "application/json" }, body });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
