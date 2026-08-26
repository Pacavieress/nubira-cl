import { notFound, redirect } from "next/navigation";
import Link from "next/link";
import { getMiMetricaDetalle } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { DeltaBadge } from "@/components/DeltaBadge";
import { MetricaSparkline } from "@/components/MetricaSparkline";
import { formatoTiempo } from "@/lib/formato";

interface DetalleProps {
  params: Promise<{ tipo: string; id: string }>;
}

const LABEL_DISPOSITIVO: Record<"movil" | "tablet" | "desktop", string> = {
  movil: "Móvil",
  tablet: "Tablet",
  desktop: "Escritorio",
};

// Puerto de app/metricas_detalle.php (582 líneas) — completa /metricas (resumen, ya
// portado) con el detalle por publicación: funnel de conversión, gráfico de 30 días,
// dispositivos, orígenes y ubicación. Mismo gate que el resto de "gestión" (sin sesión ->
// /login) y mismo criterio de ownership del PHP real (si la publicación no es tuya, no
// existe para vos — acá 404 en vez del redirect silencioso a /metricas que hace el PHP,
// más correcto para una URL con parámetros inválidos).
//
// Sin loader/spinner ni el botón "volver con fallback a /metricas" (JS puro de UX,
// cosmético) — el resto de la página SÍ está completo.
export default async function MetricaDetallePage({ params }: DetalleProps) {
  const { tipo, id } = await params;
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }
  if (tipo !== "servicio" && tipo !== "apunte") {
    notFound();
  }

  const detalle = await getMiMetricaDetalle(tipo, id);
  if (!detalle) {
    notFound();
  }

  const { publicacion } = detalle;
  const badgeLbl = publicacion.tipo === "servicio" ? "TUTORÍA" : "APUNTE";
  // new Date() (constructor), no Date.now(): react-hooks/purity marca Date.now() como
  // impuro (mismo patrón ya usado en Footer.tsx/admin/ofertas/page.tsx con new Date(),
  // que sí pasa lint limpio).
  const hace29Dias = new Date();
  hace29Dias.setDate(hace29Dias.getDate() - 29);
  const inicioVentana = hace29Dias.toLocaleDateString("es-CL", { day: "2-digit", month: "short" });

  return (
    <>
      <Header titulo={publicacion.titulo} />
      <main className="pt-20 pb-28 md:pb-16 lg:pl-64 mx-auto max-w-[720px] px-4 md:px-6">
        <div className="mb-3">
          <Link href="/metricas" className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4">
              <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Métricas
          </Link>
        </div>

        <div className="space-y-4">
          {/* Header publicación */}
          <div className="bg-white rounded-2xl border border-gray-100 p-4 flex items-start gap-4">
            {/* eslint-disable-next-line @next/next/no-img-element -- imagen resuelta server-side (banco/legacy/placeholder), no un asset de Next. */}
            <img src={publicacion.imagenUrl} className="w-20 h-20 rounded-xl object-cover shrink-0 bg-gray-100" loading="lazy" alt="" />
            <div className="flex-1 min-w-0">
              <span className="inline-block text-[9px] font-bold tracking-widest px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-500 mb-1.5">
                {badgeLbl}
              </span>
              <p className="font-bold text-gray-900 text-base leading-snug">{publicacion.titulo}</p>
              {publicacion.precio ? <p className="text-sm text-gray-400 mt-0.5">CLP {publicacion.precio.toLocaleString("es-CL")}</p> : null}
              <a href={`${phpSiteUrl}${publicacion.editarHref}`} className="inline-block mt-2.5 text-xs font-semibold text-[#54A6D8] hover:underline">
                Editar publicación
              </a>
            </div>
          </div>

          {/* Hero: visitas totales */}
          <div className="bg-white rounded-2xl border border-gray-100 p-5 text-center">
            <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-2">Visitas totales · últimos 30 días</p>
            <div className="flex items-center justify-center gap-2">
              <span className="text-4xl font-extrabold text-gray-900 leading-none">{detalle.visitas30d.toLocaleString("es-CL")}</span>
              <DeltaBadge delta={detalle.deltaVisitas} />
            </div>
            <p className="text-[11px] text-gray-400 mt-2">Incluye visitas anónimas y de usuarios con sesión iniciada</p>
          </div>

          {/* Funnel de conversión */}
          <div className="bg-white rounded-2xl border border-gray-100 p-4">
            <div className="flex items-center flex-wrap gap-1.5 mb-3">
              <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Cómo avanzan hacia contratarte</p>
              <span className="text-[9px] font-semibold text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded-full border border-gray-100">
                Basado en visitas identificadas
              </span>
            </div>
            {detalle.funnel.length === 0 ? (
              <p className="text-xs text-gray-400">
                Aún no hay suficientes visitas identificadas (de usuarios con sesión iniciada) para calcular esto.
              </p>
            ) : (
              detalle.funnel.map((etapa, i) => {
                const base = detalle.funnel[0].valor || 1;
                const pctBarra = Math.round((etapa.valor / base) * 100);
                const anterior = i > 0 ? detalle.funnel[i - 1].valor : null;
                const pctConversion = anterior && anterior > 0 ? Math.round((etapa.valor / anterior) * 100) : null;
                return (
                  <div key={etapa.label} className="mb-3 last:mb-0">
                    <div className="flex justify-between items-center mb-1">
                      <span className="text-xs text-gray-600">{etapa.label}</span>
                      <span className="text-xs font-semibold text-gray-700">{etapa.valor}</span>
                    </div>
                    <div className="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                      <div className="h-full bg-[#54A6D8] rounded-full" style={{ width: `${pctBarra}%` }} />
                    </div>
                    {pctConversion !== null && <p className="text-[10px] text-gray-400 mt-1">{pctConversion}% de la etapa anterior</p>}
                  </div>
                );
              })
            )}
          </div>

          {/* Resumen */}
          <div className="bg-white rounded-2xl border border-gray-100 overflow-hidden">
            <div className="px-4 pt-4 pb-2">
              <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Resumen</p>
            </div>
            <div className="grid grid-cols-3 divide-x divide-gray-50 border-t border-gray-50">
              <div className="flex flex-col items-center justify-center px-3 py-4 text-center gap-1">
                <span className="text-2xl font-extrabold text-gray-900 leading-none">{formatoTiempo(detalle.tiempoPromedioSegundos)}</span>
                <span className="text-[11px] text-gray-400 leading-tight">
                  Tiempo
                  <br />
                  promedio
                </span>
                <DeltaBadge delta={detalle.deltaTiempo} />
              </div>
              <div className="flex flex-col items-center justify-center px-3 py-4 text-center gap-1">
                <span className="text-2xl font-extrabold text-gray-900 leading-none">{detalle.pctLeyo.toFixed(1)}%</span>
                <span className="text-[11px] text-gray-400 leading-tight">
                  Leyó
                  <br />
                  completo
                </span>
                <DeltaBadge delta={detalle.deltaLeyo} />
              </div>
              <div className="flex flex-col items-center justify-center px-3 py-4 text-center gap-1 bg-gray-50/70">
                <span className="text-2xl font-extrabold text-gray-900 leading-none">{detalle.visitasTotal.toLocaleString("es-CL")}</span>
                <span className="text-[11px] text-gray-400 leading-tight">
                  Visitas
                  <br />
                  histórico
                </span>
                {detalle.visitas30d > 0 && (
                  <span className="inline-flex items-center text-[10px] font-bold px-1.5 py-0.5 rounded-full text-gray-500 bg-gray-100">
                    +{detalle.visitas30d.toLocaleString("es-CL")} últimos 30d
                  </span>
                )}
              </div>
            </div>
          </div>

          {/* Visitas por día */}
          {detalle.visitas30d > 0 && (
            <div className="bg-white rounded-2xl border border-gray-100 p-4">
              <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Visitas por día — últimos 30 días</p>
              <MetricaSparkline valores={detalle.visitasPorDia} />
              <div className="flex justify-between mt-1.5">
                <span className="text-[10px] text-gray-300">{inicioVentana}</span>
                <span className="text-[10px] text-gray-300">Hoy</span>
              </div>
            </div>
          )}

          {/* Dispositivos */}
          <div className="bg-white rounded-2xl border border-gray-100 p-4">
            <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Dispositivos — últimos 30 días</p>
            {(["movil", "tablet", "desktop"] as const).map((key) => {
              const cnt = detalle.dispositivos[key];
              const totalDisp = detalle.dispositivos.movil + detalle.dispositivos.tablet + detalle.dispositivos.desktop;
              const pct = totalDisp > 0 ? Math.round((cnt / totalDisp) * 100) : 0;
              return (
                <div key={key} className="mb-2.5 last:mb-0">
                  <div className="flex justify-between items-center mb-1">
                    <span className="text-xs text-gray-600">{LABEL_DISPOSITIVO[key]}</span>
                    <span className="text-xs font-semibold text-gray-700">
                      {cnt} <span className="font-normal text-gray-400">({pct}%)</span>
                    </span>
                  </div>
                  <div className="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div className="h-full bg-[#54A6D8] rounded-full" style={{ width: `${pct}%` }} />
                  </div>
                </div>
              );
            })}
            {detalle.dispositivos.movil + detalle.dispositivos.tablet + detalle.dispositivos.desktop === 0 && (
              <p className="text-xs text-gray-400">Sin datos de dispositivo aún.</p>
            )}
          </div>

          {/* Orígenes */}
          {detalle.origenes.length > 0 && (
            <div className="bg-white rounded-2xl border border-gray-100 p-4">
              <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Orígenes — últimos 30 días</p>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-50">
                    <th className="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">Fuente</th>
                    <th className="text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">Visitas</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {detalle.origenes.map((o) => (
                    <tr key={o.origen}>
                      <td className="py-2 text-gray-700 text-xs">{o.origen}</td>
                      <td className="py-2 text-right font-semibold text-gray-900 text-xs">{o.total}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Ubicación */}
          <div className="bg-white rounded-2xl border border-gray-100 p-4">
            <p className="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Ubicación — últimos 30 días</p>
            {detalle.ubicaciones.length === 0 ? (
              <p className="text-xs text-gray-400">Sin datos de ubicación todavía.</p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-50">
                    <th className="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">Ciudad</th>
                    <th className="text-left text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">País</th>
                    <th className="text-right text-[10px] font-semibold text-gray-400 uppercase tracking-wide pb-2">Visitas</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {detalle.ubicaciones.map((u, i) => (
                    <tr key={i}>
                      <td className="py-2 text-gray-700 text-xs">{u.ciudad ?? "—"}</td>
                      <td className="py-2 text-gray-500 text-xs">{u.pais ?? "—"}</td>
                      <td className="py-2 text-right font-semibold text-gray-900 text-xs">{u.visitas}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </main>
    </>
  );
}
