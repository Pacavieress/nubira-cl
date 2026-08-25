import { redirect } from "next/navigation";
import { getAdminReclamos, type EstadoFiltroReclamos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminReclamosPanel } from "@/components/AdminReclamosPanel";

interface ReclamosPageProps {
  searchParams: Promise<{ estado?: string }>;
}

const ESTADOS_VALIDOS: EstadoFiltroReclamos[] = ["activos", "resuelto", "todos", "eliminado"];

function normalizarEstado(v: string | undefined): EstadoFiltroReclamos {
  return ESTADOS_VALIDOS.includes(v as EstadoFiltroReclamos) ? (v as EstadoFiltroReclamos) : "activos";
}

// Puerto de admin_reclamos.php ("Gestión de Reclamos") — bandeja de soporte con hilos de
// conversación. A diferencia de Videos/Ofertas, este SÍ porta escritura completa (responder,
// resolver, papelera/restaurar/eliminar_hard, acción en lote) en AdminReclamosPanel.tsx: son
// mutaciones internas puras sobre reclamos_sugerencias/reclamos_mensajes, sin correo ni push.
export default async function AdminReclamosPage({ searchParams }: ReclamosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { estado: estadoParam } = await searchParams;
  const estado = normalizarEstado(estadoParam);
  const { contadores, tickets } = await getAdminReclamos(estado);

  const tabs: { key: EstadoFiltroReclamos; label: string; count: number }[] = [
    { key: "activos", label: "Activos", count: contadores.activos },
    { key: "resuelto", label: "Resueltos", count: contadores.resuelto },
    { key: "todos", label: "Todos", count: contadores.todos },
    { key: "eliminado", label: "Papelera", count: contadores.eliminado },
  ];

  return (
    <>
      <Header titulo="Sugerencias" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1000px] mx-auto space-y-4">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Gestión de Reclamos</h1>
          <p className="text-sm text-gray-500 mt-1">Soporte y resoluciones administrativas</p>
        </div>

        <div className="flex gap-2 overflow-x-auto pb-1">
          {tabs.map((t) => (
            <a
              key={t.key}
              href={`?estado=${t.key}`}
              className={`shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wide transition-all ${
                estado === t.key ? "bg-gray-900 text-white" : "bg-white text-gray-600 border border-gray-200 hover:bg-gray-50"
              }`}
            >
              {t.label}
              {t.count > 0 && (
                <span className={`${estado === t.key ? "bg-white/20 text-white" : "bg-gray-100 text-gray-500"} text-[10px] px-1.5 py-0.5 rounded-md`}>
                  {t.count}
                </span>
              )}
            </a>
          ))}
        </div>

        <AdminReclamosPanel tickets={tickets} estadoFiltro={estado} />
      </main>
    </>
  );
}
