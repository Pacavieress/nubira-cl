import { redirect } from "next/navigation";
import { getAdminAutores } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminAutoresPanel } from "@/components/AdminAutoresPanel";

interface AutoresPageProps {
  searchParams: Promise<{ q?: string; filtro?: string }>;
}

// Puerto de admin_autores_servicios.php — SOLO el directorio de autores (búsqueda +
// completitud de perfil + historial de comunicación ya enviada). Ver AdminAutoresPanel.tsx
// para la nota de alcance sobre el modal "Escribir correo" excluido.
export default async function AdminAutoresPage({ searchParams }: AutoresPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { q = "", filtro } = await searchParams;
  const filtroIncompleto = filtro === "incompleto";
  const autores = await getAdminAutores({ q: q || undefined, filtro: filtroIncompleto ? "incompleto" : undefined });

  return (
    <>
      <Header titulo="Autores de Servicios" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Autores de Servicios</h1>
            <p className="text-sm text-gray-500 mt-1">Gestión de creadores de contenido y comunicación.</p>
          </div>

          <div className="flex items-center gap-3 w-full md:w-auto">
            <form method="get" className="flex-1 md:flex-none">
              {filtroIncompleto && <input type="hidden" name="filtro" value="incompleto" />}
              <input
                type="text"
                name="q"
                defaultValue={q}
                placeholder="Buscar por nombre o correo..."
                className="w-full md:w-64 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:border-[#54A6D8] focus:bg-white outline-none"
              />
            </form>
            <div className="flex gap-2 shrink-0">
              <a
                href={q ? `/admin/autores_servicios?q=${encodeURIComponent(q)}` : "/admin/autores_servicios"}
                className={`px-3 py-2 rounded-xl text-[11px] font-bold uppercase tracking-widest transition-colors ${!filtroIncompleto ? "bg-[#54A6D8] text-white" : "bg-gray-100 text-gray-500 hover:bg-gray-200"}`}
              >
                Todos
              </a>
              <a
                href={`/admin/autores_servicios?filtro=incompleto${q ? `&q=${encodeURIComponent(q)}` : ""}`}
                className={`px-3 py-2 rounded-xl text-[11px] font-bold uppercase tracking-widest transition-colors ${filtroIncompleto ? "bg-[#54A6D8] text-white" : "bg-gray-100 text-gray-500 hover:bg-gray-200"}`}
              >
                Incompletos
              </a>
            </div>
          </div>
        </div>

        <AdminAutoresPanel autores={autores} />
      </main>
    </>
  );
}
