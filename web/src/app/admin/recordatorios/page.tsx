import { redirect } from "next/navigation";
import { getAdminRecordatorios } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";

interface RecordatoriosPageProps {
  searchParams: Promise<{ fecha?: string; tipo?: string; estado?: string }>;
}

// Puerto de admin_recordatorios.php — monitor 100% lectura de acciones_pendientes
// (correos automáticos de reenganche 3/7/14 días). Filtros vía <form method="get"> nativo
// (sin JS: la misma navegación con query string que ya usaba el PHP real) — no hace falta
// Client Component acá. Fuera de alcance a propósito: el botón "Ejecutar ahora" del PHP
// real dispara ejecutar_recordatorios.php (trigger manual del cron de envío masivo) — esta
// pieza es de monitoreo, no de operar el cron.
export default async function AdminRecordatoriosPage({ searchParams }: RecordatoriosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { fecha = "", tipo = "", estado = "" } = await searchParams;
  const datos = await getAdminRecordatorios({ fecha, tipo, estado });
  const enviadosHoy = datos?.enviadosHoy ?? 0;
  const pendientesHoy = datos?.pendientesHoy ?? 0;
  const registros = datos?.registros ?? [];

  return (
    <>
      <Header titulo="Recordatorios Automáticos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <header>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Recordatorios Automáticos</h1>
          <p className="text-sm text-gray-500 mt-1">Monitoreo de correos de reenganche programados.</p>
        </header>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div className="bg-blue-50 border border-blue-100 p-4 rounded-xl text-blue-700 text-center">
            <p className="text-2xl font-bold">{enviadosHoy}</p>
            <p className="text-sm">Correos enviados hoy</p>
          </div>
          <div className="bg-amber-50 border border-amber-100 p-4 rounded-xl text-amber-700 text-center">
            <p className="text-2xl font-bold">{pendientesHoy}</p>
            <p className="text-sm">Pendientes / con error</p>
          </div>
          <div className="bg-gray-50 border border-gray-100 p-4 rounded-xl text-gray-600 text-center">
            <p className="text-2xl font-bold">{registros.length}</p>
            <p className="text-sm">Registros mostrados</p>
          </div>
        </div>

        <form method="get" className="bg-white border border-gray-100 rounded-2xl p-4 flex flex-col sm:flex-wrap md:flex-row md:items-end gap-4">
          <div className="flex flex-col w-full sm:w-auto">
            <label className="text-sm font-semibold text-gray-600 mb-1">Fecha envío</label>
            <input type="date" name="fecha" defaultValue={fecha} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full" />
          </div>
          <div className="flex flex-col w-full sm:w-auto">
            <label className="text-sm font-semibold text-gray-600 mb-1">Tipo de recordatorio</label>
            <select name="tipo" defaultValue={tipo} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full">
              <option value="">Todos</option>
              <option value="recordatorio_3dias">3 días – Publicar</option>
              <option value="recordatorio_7dias">7 días – Explorar</option>
              <option value="recordatorio_14dias">14 días – Reenganche</option>
            </select>
          </div>
          <div className="flex flex-col w-full sm:w-auto">
            <label className="text-sm font-semibold text-gray-600 mb-1">Estado</label>
            <select name="estado" defaultValue={estado} className="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full">
              <option value="">Todos</option>
              <option value="enviado">Enviado</option>
              <option value="pendiente">Pendiente</option>
            </select>
          </div>
          <div className="flex gap-2">
            <button type="submit" className="bg-[#54A6D8] hover:bg-blue-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">
              Filtrar
            </button>
            <a href="/admin/recordatorios" className="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold">
              Limpiar
            </a>
          </div>
        </form>

        <div className="bg-white border border-gray-100 rounded-2xl overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100 text-gray-500 uppercase text-xs">
                <tr>
                  <th className="py-3 px-4 text-left">#</th>
                  <th className="py-3 px-4 text-left">Alumno</th>
                  <th className="py-3 px-4 text-left">Correo</th>
                  <th className="py-3 px-4 text-left">Tipo</th>
                  <th className="py-3 px-4 text-center">Etapa</th>
                  <th className="py-3 px-4 text-center">Programado</th>
                  <th className="py-3 px-4 text-center">Enviado</th>
                  <th className="py-3 px-4 text-center">Estado</th>
                  <th className="py-3 px-4 text-left">Motivo / Observación</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {registros.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="text-center py-8 text-gray-400">
                      Sin registros con esos filtros
                    </td>
                  </tr>
                ) : (
                  registros.map((r) => (
                    <tr key={r.id} className="hover:bg-gray-50">
                      <td className="py-2.5 px-4 text-gray-400">{r.id}</td>
                      <td className="py-2.5 px-4 font-medium text-gray-800">{r.alumno ?? "—"}</td>
                      <td className="py-2.5 px-4 text-gray-600">{r.correo ?? "—"}</td>
                      <td className="py-2.5 px-4 font-semibold text-[#54A6D8]">{r.tipo}</td>
                      <td className="py-2.5 px-4 text-center">{r.etapa}</td>
                      <td className="py-2.5 px-4 text-center text-gray-500">
                        {new Date(r.programadoPara).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}
                      </td>
                      <td className="py-2.5 px-4 text-center text-gray-500">
                        {r.enviadoEn ? new Date(r.enviadoEn).toLocaleString("es-CL", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—"}
                      </td>
                      <td className="py-2.5 px-4 text-center">
                        {r.estado === "enviado" ? (
                          <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-xs font-semibold">Enviado</span>
                        ) : (
                          <span className="bg-amber-100 text-amber-700 px-2 py-1 rounded text-xs font-semibold">{r.estado}</span>
                        )}
                      </td>
                      <td className="py-2.5 px-4 text-gray-600">{r.motivoOmision ?? ""}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        <p className="text-sm text-gray-400">Mostrando {registros.length} registros filtrados.</p>
      </main>
    </>
  );
}
