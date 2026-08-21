import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy same-origin hacia server/ — mismo criterio que /api/desafio/compartir/track
// (público, reenvía X-Forwarded-For real cuando la request entrante ya lo trae).
export async function POST(req: Request) {
  const body = await req.text();
  const ipEntrante = req.headers.get("x-forwarded-for");

  const res = await fetch(`${API_URL}/api/compartir/apunte/track`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      ...(ipEntrante ? { "X-Forwarded-For": ipEntrante } : {}),
    },
    body,
  });

  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
