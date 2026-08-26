import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — mismo patrón que web/src/app/api/configurar-cuenta/.
// Sin body: el monto NUNCA lo elige el cliente, server/ siempre retira el saldo
// disponible completo recién calculado (ver miBilletera.controller.ts::postSolicitarRetiro).
export async function POST() {
  const res = await fetchConSesion("/api/me/mi-billetera/solicitar-retiro", { method: "POST" });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
