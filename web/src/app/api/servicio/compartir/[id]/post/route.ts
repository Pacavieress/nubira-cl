import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy same-origin hacia server/ — binario (JPEG), no JSON, mismo criterio que
// /api/apunte/compartir/[id]/post y /api/desafio/compartir/[slug]/post: público a
// propósito, sin fetchConSesion.
export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetch(`${API_URL}/api/compartir/servicio/${encodeURIComponent(id)}/post`, { cache: "no-store" });

  if (!res.ok) {
    return NextResponse.json({ error: "not_found" }, { status: res.status });
  }

  const buffer = await res.arrayBuffer();
  return new NextResponse(buffer, {
    status: 200,
    headers: {
      "Content-Type": res.headers.get("content-type") ?? "image/jpeg",
      "Cache-Control": res.headers.get("cache-control") ?? "public, max-age=86400, immutable",
    },
  });
}
