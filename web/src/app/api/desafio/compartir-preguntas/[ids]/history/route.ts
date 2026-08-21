import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy same-origin hacia server/ — mismo patrón binario que
// web/src/app/api/desafio/compartir/[slug]/post/route.ts. `ids` viaja tal cual (formato
// "10-11-12", ya armado por el cliente) — server/ es quien valida que sean 3 ids reales.
export async function GET(_req: Request, { params }: { params: Promise<{ ids: string }> }) {
  const { ids } = await params;
  const res = await fetch(`${API_URL}/api/compartir/desafio-preguntas/${encodeURIComponent(ids)}/history`, { cache: "no-store" });

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
