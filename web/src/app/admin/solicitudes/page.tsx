import { redirect } from "next/navigation";
import { getAdminSolicitudes, type EstadoSolicitud } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";

interface SolicitudesPageProps {
  searchParams: Promise<{ estado?: string }>;
}

function normalizarEstado(v: string | undefined): EstadoSolicitud {
  return v === "pendiente" || v === "revisada" ? v : "";
}

// Puerto de admin_solicitudes.php ("Solicitudes de Institución") — 100% lectura. Ver la nota
// de alcance en server/src/modules/adminSolicitudes/adminSolicitudes.types.ts (aprobar/rechazar
// envían correo real, eliminar_masivo es hard DELETE, marcar_revisada es de un solo sentido —
// las 3 acciones quedan excluidas). Sin Client Component: filtro vía tabs con <a href>, mismo
// patrón que Reportes.
export default async function AdminSolicitudesPage({ searchParams }: SolicitudesPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { estado: estadoParam } = await searchParams;
  const estado = normalizarEstado(estadoParam);
  const resumen = await getAdminSolicitudes(estado);
  const solicitudes = resumen?.solicitudes ?? [];

  const tabs: { key: EstadoSolicitud; label: string }[] = [
    { key: "", label: "Todas" },
    { key: "pendiente", label: "Pendientes" },
    { key: "revisada", label: "Revisadas" },
  ];

  return (
    <>
      <Header titulo="Solicitudes" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row justify-between items-end gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Solicitudes</h1>
            <p className="text-sm text-gray-500 mt-1">Gestión de peticiones de nueva institución.</p>
          </div>
          <div className="bg-white p-1 rounded-xl border border-gray-200 flex gap-1">
            {tabs.map((t) => (
              <a
                key={t.key || "todas"}
                href={t.key ? `?estado=${t.key}` : "?"}
                className={`px-4 py-2 rounded-lg text-sm font-bold transition-all ${estado === t.key ? "bg-blue-50 text-[#54A6D8]" : "text-gray-500 hover:bg-gray-50"}`}
              >
                {t.label}
              </a>
            ))}
          </div>
        </div>

        <div className="bg-white border border-gray-100 rounded-3xl overflow-hidden">
          {solicitudes.length === 0 ? (
            <div className="px-6 py-16 text-center text-gray-400">No hay solicitudes{estado ? "s" : ""}.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-gray-50 text-gray-500 text-[11px] uppercase font-bold tracking-widest border-b border-gray-100">
                  <tr>
                    <th className="px-6 py-4">Institución</th>
                    <th className="px-6 py-4">Solicitante</th>
                    <th className="px-6 py-4">Fecha</th>
                    <th className="px-6 py-4 text-right">Estado</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {solicitudes.map((s) => (
                    <tr key={s.id} className="hover:bg-gray-50 transition-colors">
                      <td className="px-6 py-4">
                        <p className="font-bold text-gray-900">{s.institucion}</p>
                        <div className="text-xs text-gray-400 mt-1">ID: #{s.id}</div>
                      </td>
                      <td className="px-6 py-4 text-gray-700">{s.email}</td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {s.fecha ? (
                          <>
                            <div className="text-sm text-gray-900">{new Date(s.fecha).toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" })}</div>
                            <div className="text-xs text-gray-400">{new Date(s.fecha).toLocaleTimeString("es-CL", { hour: "2-digit", minute: "2-digit" })}</div>
                          </>
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        {s.estado === "pendiente" ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest bg-amber-50 text-amber-600">Pendiente</span>
                        ) : (
                          <div className="inline-flex flex-col items-end gap-1">
                            <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[10px] font-black uppercase tracking-widest bg-gray-100 text-gray-500">Revisada</span>
                            {s.correoEnviado && <span className="text-[10px] text-emerald-600 font-bold uppercase tracking-widest">Notificado</span>}
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {solicitudes.some((s) => s.estado === "pendiente") && (
          <p className="text-xs text-gray-400">
            Aprobar, rechazar y eliminar solicitudes se hace en el{" "}
            <a href={`${phpSiteUrl}/admin/solicitudes`} target="_blank" rel="noopener noreferrer" className="text-[#54A6D8] hover:underline font-medium">
              sitio real
            </a>{" "}
            (esas acciones envían correo o son irreversibles).
          </p>
        )}
      </main>
    </>
  );
}
