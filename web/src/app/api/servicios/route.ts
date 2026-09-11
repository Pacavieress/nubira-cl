import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy fino hacia server/'s GET /api/servicios — mismo patrón que el resto de Route
// Handlers del repo (ej. web/src/app/api/admin/accesos/route.ts), pero SIN sesión: el
// listado de servicios es público (mismo criterio que getServicios() en lib/api.ts, que
// tampoco reenvía cookie). Reenvía el querystring completo, incluido page/limit para el
// scroll infinito — GrillaServiciosInfinita.tsx (Client Component) le pega acá en vez de a
// server/ directo porque API_URL es server-only (no NEXT_PUBLIC_*) y server/ no tiene
// CORS_ORIGIN habilitado para el navegador (ver sesion.ts:19-24).
export async function GET(req: Request) {
  const { search } = new URL(req.url);
  const res = await fetch(`${API_URL}/api/servicios${search}`, { cache: "no-store" });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
