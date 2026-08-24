import { redirect } from "next/navigation";
import { getAdminComprasApuntes } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { formatoCLP } from "@/lib/formato";
import { Header } from "@/components/Header";

interface ComprasApuntesPageProps {
  searchParams: Promise<{
    q_apunte?: string;
    q_comprador?: string;
    q_vendedor?: string;
    estado_pago?: string;
    fecha_desde?: string;
    fecha_hasta?: string;
    orden?: string;
  }>;
}

// Puerto de admin_compras_apuntes.php — 100% lectura (el propio PHP se declara así). Sin
// Client Component: filtros vía <form method="get"> nativo (igual que Recordatorios /
// Autores) y el acordeón por tutor con <details>/<summary> nativos de HTML — no hace falta
// JS para ninguna interacción de esta página.
export default async function AdminComprasApuntesPage({ searchParams }: ComprasApuntesPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const filtros = await searchParams;
  const datos = await getAdminComprasApuntes(filtros);
  const kpis = datos?.kpis ?? { totalCompras: 0, totalMonto: 0, totalTutores: 0 };
  const desync = datos?.desync ?? 0;
  const tutores = datos?.tutores ?? [];
  const hayFiltros = Object.values(filtros).some(Boolean);

  return (
    <>
      <Header titulo="Compras de Apuntes" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Compras de Apuntes</h1>
            <p className="text-sm text-gray-500 mt-1">Todas las transacciones de apuntes en la plataforma.</p>
          </div>
          <div className="flex items-center gap-3 flex-wrap">
            <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 flex items-center gap-3">
              <div>
                <p className="text-[10px] uppercase font-bold text-gray-400">Total Compras</p>
                <p className="text-lg font-black text-gray-900 leading-none">{kpis.totalCompras.toLocaleString("es-CL")}</p>
              </div>
            </div>
            <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 flex items-center gap-3">
              <div>
                <p className="text-[10px] uppercase font-bold text-gray-400">Monto Total</p>
                <p className="text-lg font-black text-gray-900 leading-none">{formatoCLP(kpis.totalMonto)}</p>
              </div>
            </div>
            <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 flex items-center gap-3">
              <div>
                <p className="text-[10px] uppercase font-bold text-gray-400">Tutores con Ventas</p>
                <p className="text-lg font-black text-gray-900 leading-none">{kpis.totalTutores.toLocaleString("es-CL")}</p>
              </div>
            </div>
          </div>
        </div>

        {desync > 0 && (
          <div className="px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700 font-semibold">
            {desync} compra{desync > 1 ? "s" : ""} confirmada{desync > 1 ? "s" : ""} sin registro en ventas_apuntes.
          </div>
        )}

        <form method="get" className="bg-white border border-gray-100 rounded-2xl p-4 space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
              <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Título del apunte</label>
              <input type="text" name="q_apunte" defaultValue={filtros.q_apunte} placeholder="Buscar por título..." className="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none" />
            </div>
            <div>
              <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Correo comprador</label>
              <input type="text" name="q_comprador" defaultValue={filtros.q_comprador} placeholder="comprador@correo.cl" className="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none" />
            </div>
            <div>
              <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Correo vendedor</label>
              <input type="text" name="q_vendedor" defaultValue={filtros.q_vendedor} placeholder="vendedor@correo.cl" className="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none" />
            </div>
            <div>
              <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Estado pago al vendedor</label>
              <select name="estado_pago" defaultValue={filtros.estado_pago ?? ""} className="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none">
                <option value="">Todos</option>
                <option value="1">Pagado al vendedor</option>
                <option value="0">Pendiente</option>
              </select>
            </div>
            <div>
              <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Desde</label>
              <input type="date" name="fecha_desde" defaultValue={filtros.fecha_desde} className="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none" />
            </div>
            <div>
              <label className="block text-[11px] font-bold text-gray-400 uppercase tracking-wide mb-1">Hasta</label>
              <input type="date" name="fecha_hasta" defaultValue={filtros.fecha_hasta} className="w-full text-sm border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none" />
            </div>
          </div>
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <div className="flex items-center gap-2">
              <label className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Ordenar por</label>
              <select name="orden" defaultValue={filtros.orden ?? "mayor_monto"} className="text-sm font-medium border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-[#54A6D8] outline-none">
                <option value="mayor_monto">Mayor monto vendido</option>
                <option value="mas_ventas">Más ventas</option>
                <option value="recientes">Más recientes</option>
                <option value="menor_monto">Menor monto vendido</option>
                <option value="alfabetico">Alfabético por tutor</option>
              </select>
            </div>
            <div className="flex items-center gap-2">
              {hayFiltros && (
                <a href="/admin/compras-apuntes" className="text-sm text-gray-400 hover:text-gray-600 font-medium">
                  Limpiar filtros
                </a>
              )}
              <button type="submit" className="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white px-5 py-2 rounded-xl text-sm font-bold">
                Aplicar
              </button>
            </div>
          </div>
        </form>

        <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden divide-y divide-gray-50">
          {tutores.length === 0 ? (
            <div className="px-6 py-16 text-center text-gray-400">
              {hayFiltros ? "Ningún tutor coincide con los filtros aplicados." : "Aún no hay compras de apuntes en la plataforma."}
            </div>
          ) : (
            tutores.map((t) => (
              <details key={t.vendedorId} className="group">
                <summary className="flex items-center gap-4 px-6 py-4 hover:bg-gray-50/60 transition-colors cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden">
                  <div className="w-7 h-7 rounded-lg bg-gray-100 flex items-center justify-center shrink-0 text-gray-500 font-bold text-base group-open:rotate-45 transition-transform">+</div>
                  <div className="min-w-0 flex-1">
                    <p className="font-bold text-gray-900 text-sm leading-tight truncate">{t.vendedorNombre}</p>
                    <p className="text-[11px] text-gray-400 truncate">{t.vendedorCorreo}</p>
                  </div>
                  <div className="hidden md:flex items-center gap-6 shrink-0 text-right">
                    <div>
                      <p className="text-[10px] uppercase font-bold text-gray-400 tracking-wide">Ventas</p>
                      <p className="text-sm font-black text-gray-800">{t.totalVentas}</p>
                    </div>
                    <div>
                      <p className="text-[10px] uppercase font-bold text-gray-400 tracking-wide">Total</p>
                      <p className="text-sm font-black text-gray-800">{formatoCLP(t.totalMonto)}</p>
                    </div>
                    <div>
                      <p className="text-[10px] uppercase font-bold text-gray-400 tracking-wide">Estado</p>
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide ${
                          t.pendientes === 0 ? "bg-green-50 text-green-700" : t.pagadas === 0 ? "bg-yellow-50 text-yellow-700" : "bg-blue-50 text-[#54A6D8]"
                        }`}
                      >
                        {t.pagadas}/{t.totalVentas} pagadas
                      </span>
                    </div>
                  </div>
                  <div className="flex md:hidden items-center gap-3 shrink-0 text-right">
                    <p className="text-sm font-black text-gray-800">{formatoCLP(t.totalMonto)}</p>
                    <p className="text-xs text-gray-400">{t.totalVentas} ventas</p>
                  </div>
                </summary>

                <div className="overflow-x-auto bg-gray-50/40 border-t border-gray-100">
                  <table className="w-full text-left text-xs whitespace-nowrap">
                    <thead className="text-gray-400 font-bold uppercase tracking-wider border-b border-gray-100">
                      <tr>
                        <th className="px-6 py-3">#</th>
                        <th className="px-6 py-3">Fecha</th>
                        <th className="px-6 py-3">Apunte</th>
                        <th className="px-6 py-3">Comprador</th>
                        <th className="px-6 py-3">Monto</th>
                        <th className="px-6 py-3">Estado</th>
                        <th className="px-6 py-3">Payment ID</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {t.detalle.map((d) => (
                        <tr key={d.id} className="hover:bg-white/70 transition-colors">
                          <td className="px-6 py-3 text-gray-400 font-mono">{d.id}</td>
                          <td className="px-6 py-3">
                            <p className="font-semibold text-gray-700">{new Date(d.fecha).toLocaleDateString("es-CL")}</p>
                            <p className="text-[10px] text-gray-400">{new Date(d.fecha).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })}</p>
                          </td>
                          <td className="px-6 py-3 max-w-[240px]">
                            <p className="font-bold text-gray-800 whitespace-normal leading-tight line-clamp-2">{d.apunteTitulo}</p>
                            {d.asignatura && <p className="text-[10px] text-gray-400 mt-0.5">{d.asignatura}</p>}
                          </td>
                          <td className="px-6 py-3">
                            <p className="font-semibold text-gray-700">{d.compradorNombre}</p>
                            <p className="text-[10px] text-gray-400">{d.compradorCorreo}</p>
                          </td>
                          <td className="px-6 py-3 font-bold text-gray-700">{formatoCLP(d.precio)}</td>
                          <td className="px-6 py-3">
                            {d.pagadoAlVendedor ? (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-green-50 text-green-700">Pagado</span>
                            ) : (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-yellow-50 text-yellow-700">Pendiente</span>
                            )}
                          </td>
                          <td className="px-6 py-3 font-mono text-[10px] text-gray-400 max-w-[160px] truncate" title={d.paymentId ?? ""}>
                            {d.paymentId ?? <span className="text-gray-300">—</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </details>
            ))
          )}
        </div>

        {datos?.detalleTruncado && (
          <div className="px-4 py-3 bg-yellow-50 border border-yellow-100 rounded-xl text-xs text-yellow-700 font-medium">
            Se muestran los primeros 1.000 registros de detalle. Usa los filtros para acotar la búsqueda.
          </div>
        )}
      </main>
    </>
  );
}
