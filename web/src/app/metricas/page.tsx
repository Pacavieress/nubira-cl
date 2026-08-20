import { redirect } from "next/navigation";
import { getMisMetricas } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";

// Puerto de app/metricas.php — mismo gate (línea 7: sin sesión -> /login), misma lista
// (servicios + apuntes aprobados/visibles, mezclados por fecha DESC) con visitas de 30
// días + flecha de tendencia. Sin la página de detalle por publicación (/metricas/:tipo/:id,
// app/metricas_detalle.php — 582 líneas con gráficos) — cada fila enlaza al PHP real para
// eso, fuera de alcance de esta pieza.
export default async function MetricasPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const publicaciones = (await getMisMetricas()) ?? [];

  return (
    <>
      <Header titulo="Métricas" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[720px] mx-auto">
        <header className="mb-4">
          <h1 className="text-xl font-medium text-[#222222] tracking-[-0.01em]">Métricas</h1>
          <p className="text-xs text-gray-400 mt-0.5">Últimos 30 días</p>
        </header>

        {publicaciones.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-center bg-white rounded-2xl border border-dashed border-gray-200 mt-4">
            <p className="font-semibold text-gray-700 text-sm">Aún no tienes publicaciones aprobadas</p>
            <p className="text-xs text-gray-400 mt-1">Cuando publiques una tutoría o apunte aparecerá aquí.</p>
          </div>
        ) : (
          <div className="space-y-2">
            {publicaciones.map((pub) => (
              <a
                key={`${pub.tipo}-${pub.id}`}
                href={`${phpSiteUrl}/metricas/${pub.tipo}/${pub.id}`}
                className="flex items-center gap-4 bg-white rounded-2xl border border-gray-100 px-4 py-3 hover:border-gray-200 hover:shadow-sm transition-all"
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={pub.imagenUrl} alt="" className="w-14 h-14 rounded-xl object-cover shrink-0 bg-gray-100" loading="lazy" />

                <div className="flex-1 min-w-0">
                  <span className="inline-block text-[9px] font-bold tracking-widest px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-500 mb-1">
                    {pub.tipo === "servicio" ? "TUTORÍA" : "APUNTE"}
                  </span>
                  <p className="font-semibold text-gray-900 text-sm leading-snug truncate">{pub.titulo}</p>
                  <p className="text-xs text-gray-400 mt-0.5">
                    {pub.precio ? <>CLP {pub.precio.toLocaleString("es-CL")} &nbsp;·&nbsp; </> : null}
                    {pub.visitas30d} visitas · últimos 30 días
                    {pub.tendencia === "up" && <span className="text-green-600 font-bold"> ↑</span>}
                    {pub.tendencia === "down" && <span className="text-red-500 font-bold"> ↓</span>}
                  </p>
                </div>

                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-gray-300 shrink-0">
                  <path strokeLinecap="round" strokeLinejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
              </a>
            ))}
          </div>
        )}
      </main>
    </>
  );
}
