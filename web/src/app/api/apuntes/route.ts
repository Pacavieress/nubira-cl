import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy fino hacia server/'s GET /api/apuntes — mismo patrón y mismas razones que
// web/src/app/api/servicios/route.ts (ver ese archivo).
export async function GET(req: Request) {
  const { search } = new URL(req.url);
  const res = await fetch(`${API_URL}/api/apuntes${search}`, { cache: "no-store" });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
