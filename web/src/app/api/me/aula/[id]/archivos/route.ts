import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await fetchConSesion(`/api/me/aula/${id}/archivos`);
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}

// Passthrough multipart — reenvía el body (stream) y el Content-Type original (incluye el
// boundary del multipart) tal cual, sin parsearlo acá. multer del lado server hace el
// parseo real.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const contentType = req.headers.get("content-type") ?? "";
  const res = await fetchConSesion(`/api/me/aula/${id}/archivos`, {
    method: "POST",
    headers: { "Content-Type": contentType },
    body: req.body,
    // @ts-expect-error -- duplex es requerido por fetch cuando body es un stream, todavía sin tipos oficiales en este runtime.
    duplex: "half",
  });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
