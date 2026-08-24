import { redirect } from "next/navigation";
import { getAdminReportesServicios, type EstadoReporte } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminReportesServiciosPanel } from "@/components/AdminReportesServiciosPanel";

interface ReportesPageProps {
  searchParams: Promise<{ estado?: string }>;
}

function normalizarEstado(v: string | undefined): EstadoReporte {
  return v === "revisados" || v === "todos" ? v : "pendientes";
}

// Puerto de admin_reportes_servicios.php ("Reportes"). Ver AdminReportesServiciosPanel.tsx
// para la nota de alcance sobre 'marcar_revisado', excluido.
export default async function AdminReportesServiciosPage({ searchParams }: ReportesPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { estado: estadoParam } = await searchParams;
  const estado = normalizarEstado(estadoParam);
  const resumen = await getAdminReportesServicios(estado);
  const reportes = resumen?.reportes ?? [];
  const countPendientes = resumen?.countPendientes ?? 0;

  const tabs: { key: EstadoReporte; label: string }[] = [
    { key: "pendientes", label: "Pendientes" },
    { key: "revisados", label: "Revisados" },
    { key: "todos", label: "Historial Completo" },
  ];

  return (
    <>
      <Header titulo="Reportes" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row justify-between items-end gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Reportes de Servicios</h1>
            <p className="text-sm text-gray-500 mt-1">Supervisión de contenido reportado por la comunidad.</p>
          </div>
          <div className="bg-white p-1 rounded-xl border border-gray-200 flex gap-1">
            {tabs.map((t) => (
              <a
                key={t.key}
                href={`?estado=${t.key}`}
                className={`px-4 py-2 rounded-lg text-sm font-bold transition-all ${estado === t.key ? "bg-blue-50 text-[#54A6D8]" : "text-gray-500 hover:bg-gray-50"}`}
              >
                {t.label}
                {t.key === "pendientes" && <span className="ml-1 bg-red-100 text-red-700 px-1.5 py-0.5 rounded-full text-[10px]">{countPendientes}</span>}
              </a>
            ))}
          </div>
        </div>

        <AdminReportesServiciosPanel reportes={reportes} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
