import { NextResponse } from "next/server";
import { fetchConSesion } from "@/lib/sesion";

// Proxy same-origin hacia server/ — multipart/form-data, no JSON, a diferencia de los
// demás proxies de esta pieza. `req.formData()` + reenviar el mismo FormData como body deja
// que fetch() arme el Content-Type con el boundary correcto solo — nunca hay que fijarlo a
// mano (fijarlo mal rompe el parseo de multer del lado server/).
export async function POST(req: Request) {
  const formData = await req.formData();
  const res = await fetchConSesion("/api/me/publicar/apuntes", { method: "POST", body: formData });
  if (!res) return NextResponse.json({ error: "no_autenticado" }, { status: 401 });
  const data = await res.json().catch(() => null);
  return NextResponse.json(data, { status: res.status });
}
