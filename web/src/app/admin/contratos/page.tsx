import { redirect } from "next/navigation";
import { getAdminContratos } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminContratosPanel } from "@/components/AdminContratosPanel";

interface ContratosPageProps {
  searchParams: Promise<{ estado?: string }>;
}

const ESTADOS_VALIDOS = ["pendiente_pago", "en_progreso", "liberado", "cancelado"];

const TARJETAS: { estado: string | null; label: string; colorLabel: string; anillo: string }[] = [
  { estado: null, label: "Total", colorLabel: "text-gray-400", anillo: "" },
  { estado: "pendiente_pago", label: "Pendientes", colorLabel: "text-yellow-600", anillo: "ring-2 ring-yellow-400" },
  { estado: "en_progreso", label: "En Curso", colorLabel: "text-[#54A6D8]", anillo: "ring-2 ring-sky-400" },
  { estado: "liberado", label: "Liberados", colorLabel: "text-green-600", anillo: "ring-2 ring-green-400" },
  { estado: "cancelado", label: "Conflictos", colorLabel: "text-red-500", anillo: "ring-2 ring-red-400" },
];

// Puerto de admin_contratos.php — SOLO lectura (stats + listado filtrable + detalle). Ver
// AdminContratosPanel.tsx para la nota de alcance sobre las acciones de escritura
// excluidas (liberar/cancelar/revertir/eliminar).
export default async function AdminContratosPage({ searchParams }: ContratosPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { estado: estadoCrudo } = await searchParams;
  const estado = estadoCrudo && ESTADOS_VALIDOS.includes(estadoCrudo) ? estadoCrudo : undefined;
  const datos = await getAdminContratos(estado);
  const stats = datos?.stats ?? {};
  const total = datos?.total ?? 0;
  const contratos = datos?.contratos ?? [];

  return (
    <>
      <Header titulo="Administración de Contratos" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <header>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Administración de Contratos</h1>
          <p className="text-sm text-gray-500 mt-1">Gestión financiera y resolución de disputas.</p>
        </header>

        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          {TARJETAS.map((t) => (
            <a
              key={t.label}
              href={t.estado ? `/admin/contratos?estado=${t.estado}` : "/admin/contratos"}
              className={`bg-white p-4 rounded-2xl border border-gray-100 hover:shadow-md transition-all ${t.estado === estado || (!t.estado && !estado) ? t.anillo : ""}`}
            >
              <div className={`text-xs font-bold uppercase tracking-wider mb-1 ${t.colorLabel}`}>{t.label}</div>
              <div className="text-2xl font-bold text-gray-900">{t.estado ? (stats[t.estado] ?? 0) : total}</div>
            </a>
          ))}
        </div>

        <AdminContratosPanel contratos={contratos} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
