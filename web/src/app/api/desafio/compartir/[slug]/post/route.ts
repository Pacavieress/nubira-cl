import { NextResponse } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:4000";

// Proxy same-origin hacia server/ — binario (JPEG), no JSON, a diferencia del resto de los
// proxies de web/. Público a propósito (mismo criterio que el endpoint real de server/):
// sin fetchConSesion, cualquier visitante (o crawler de redes sociales) debe poder cargar
// esta imagen.
export async function GET(_req: Request, { params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const res = await fetch(`${API_URL}/api/compartir/desafio/${encodeURIComponent(slug)}/post`, { cache: "no-store" });

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
