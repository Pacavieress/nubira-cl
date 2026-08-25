import { fetchConSesion } from "@/lib/sesion";

// Puerto de la exportación CSV (admin_accesos_vitrina.php:66-119). A diferencia del resto de
// los proxies de este panel, no envuelve la respuesta en NextResponse.json — reenvía el CSV
// tal cual (Content-Type/Content-Disposition incluidos) para que el navegador dispare la
// descarga del archivo igual que con el PHP real.
export async function GET(req: Request) {
  const { search } = new URL(req.url);
  const res = await fetchConSesion(`/api/admin/accesos/exportar${search}`);
  if (!res) return new Response(JSON.stringify({ error: "no_autenticado" }), { status: 401, headers: { "Content-Type": "application/json" } });

  return new Response(res.body, {
    status: res.status,
    headers: {
      "Content-Type": res.headers.get("Content-Type") ?? "text/csv; charset=utf-8",
      "Content-Disposition": res.headers.get("Content-Disposition") ?? "attachment; filename=export.csv",
    },
  });
}
