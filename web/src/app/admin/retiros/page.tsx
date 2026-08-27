import { redirect } from "next/navigation";
import { getAdminRetiros } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminRetirosPanel } from "@/components/AdminRetirosPanel";

interface RetirosPageProps {
  searchParams: Promise<{ estado?: string; institucion?: string }>;
}

const ESTADOS_TABS = [
  { estado: "pendiente", label: "Pendientes" },
  { estado: "aprobado", label: "Aprobadas" },
  { estado: "rechazado", label: "Rechazadas" },
  { estado: "todas", label: "Todas" },
] as const;

// Puerto de app/admin_retiros.php — autorizado por el usuario con alcance completo,
// incluyendo aprobar/rechazar retiros reales (a diferencia de los paneles admin anteriores
// de esta migración). Ver AdminRetirosPanel.tsx para el detalle de las mutaciones y el
// correo real que se envía al aprobar/rechazar.
export default async function AdminRetirosPage({ searchParams }: RetirosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();
  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { estado: estadoCrudo, institucion } = await searchParams;
  const estado = estadoCrudo && ESTADOS_TABS.some((t) => t.estado === estadoCrudo) ? estadoCrudo : "pendiente";
  const institucionActual = institucion ?? "";
  const datos = await getAdminRetiros(estado, institucionActual);

  return (
    <>
      <Header titulo="Gestión Financiera" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <header>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Gestión Financiera</h1>
          <p className="text-sm text-gray-500 mt-1">Retiros de tutores — revisión, aprobación y configuración.</p>
        </header>

        <div className="flex bg-gray-100 p-1 rounded-xl overflow-x-auto w-full md:w-fit">
          {ESTADOS_TABS.map((t) => (
            <a
              key={t.estado}
              href={`/admin/retiros?estado=${t.estado}${institucionActual ? `&institucion=${institucionActual}` : ""}`}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap ${
                estado === t.estado ? "bg-white text-[#54A6D8] shadow-sm" : "text-gray-500 hover:text-gray-700"
              }`}
            >
              {t.label}
            </a>
          ))}
        </div>

        <AdminRetirosPanel solicitudesIniciales={datos?.solicitudes ?? []} configuracionInicial={datos?.configuracion ?? { minimoRetiro: 10000, comisionActual: 0 }} />
      </main>
    </>
  );
}
