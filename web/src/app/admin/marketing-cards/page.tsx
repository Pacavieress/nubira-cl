import { redirect } from "next/navigation";
import { getAdminMarketingNovedades, getAdminMarketingServicios } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminMarketingServiciosPanel } from "@/components/AdminMarketingServiciosPanel";
import { AdminMarketingNovedadesPanel } from "@/components/AdminMarketingNovedadesPanel";

interface MarketingCardsPageProps {
  searchParams: Promise<{ tab?: string; categoria?: string; institucion?: string; conVideo?: string; fechaDesde?: string; fechaHasta?: string }>;
}

// Puerto de admin_marketing_cards.php — un solo panel con 2 tabs (?tab=servicios|novedades,
// recarga real de página en el PHP real; acá Server Component leyendo `tab` de la URL, mismo
// criterio). Servicios = Pieza 1 (puro curador). Novedades = Pieza 2 (única mutación real de
// todo el panel — ver AdminMarketingNovedadesPanel.tsx).
export default async function AdminMarketingCardsPage({ searchParams }: MarketingCardsPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const sp = await searchParams;
  const tab = sp.tab === "novedades" ? "novedades" : "servicios";

  return (
    <>
      <Header titulo="Marketing / Cards" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1600px] mx-auto space-y-6">
        <div>
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Marketing / Cards</h1>
        </div>

        <div className="flex items-center gap-2 border-b border-gray-200">
          <a
            href="/admin/marketing-cards?tab=servicios"
            className={`px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors ${tab === "servicios" ? "border-[#54A6D8] text-[#54A6D8]" : "border-transparent text-gray-400 hover:text-gray-600"}`}
          >
            Servicios
          </a>
          <a
            href="/admin/marketing-cards?tab=novedades"
            className={`px-4 py-2.5 text-sm font-bold border-b-2 -mb-px transition-colors ${tab === "novedades" ? "border-[#54A6D8] text-[#54A6D8]" : "border-transparent text-gray-400 hover:text-gray-600"}`}
          >
            Novedades
          </a>
        </div>

        {tab === "servicios" ? <TabServicios searchParams={sp} /> : <TabNovedades />}
      </main>
    </>
  );
}

async function TabServicios({ searchParams: sp }: { searchParams: Awaited<MarketingCardsPageProps["searchParams"]> }) {
  const categoria = (sp.categoria ?? "").trim();
  const institucion = (sp.institucion ?? "").trim();
  const conVideo = sp.conVideo === "1";
  const FECHA_RE = /^\d{4}-\d{2}-\d{2}$/;
  const fechaDesde = FECHA_RE.test(sp.fechaDesde ?? "") ? (sp.fechaDesde as string) : "";
  const fechaHasta = FECHA_RE.test(sp.fechaHasta ?? "") ? (sp.fechaHasta as string) : "";

  const resumen = await getAdminMarketingServicios({ categoria, institucion, conVideo, fechaDesde, fechaHasta });

  return (
    <div className="space-y-6">
      <p className="text-gray-500 text-sm">
        Selecciona servicios y arma un carrusel de imágenes para redes sociales. Total con estos filtros: <strong>{resumen.total}</strong>
      </p>

      <form method="get" className="bg-white border border-gray-100 rounded-2xl shadow-sm p-4">
        <input type="hidden" name="tab" value="servicios" />
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Categoría</label>
            <select name="categoria" defaultValue={categoria} className="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none">
              <option value="">Todas</option>
              {resumen.categoriasDisponibles.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Institución</label>
            <select name="institucion" defaultValue={institucion} className="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:ring-2 focus:ring-[#54A6D8] outline-none">
              <option value="">Todas</option>
              {resumen.institucionesDisponibles.map((i) => (
                <option key={i} value={i}>
                  {i}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Desde</label>
            <input type="date" name="fechaDesde" defaultValue={fechaDesde} className="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none" />
          </div>
          <div>
            <label className="block text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Hasta</label>
            <input type="date" name="fechaHasta" defaultValue={fechaHasta} className="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[#54A6D8] outline-none" />
          </div>
          <div className="flex items-end gap-2">
            <label className="inline-flex items-center gap-2 px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white cursor-pointer select-none w-full">
              <input type="checkbox" name="conVideo" value="1" defaultChecked={conVideo} className="w-4 h-4 rounded accent-[#54A6D8]" />
              Solo con video
            </label>
          </div>
        </div>
        <div className="flex items-center gap-3 mt-3">
          <button type="submit" className="px-4 py-2.5 rounded-xl bg-[#54A6D8] hover:bg-blue-600 text-white text-sm font-bold transition-colors">
            Filtrar
          </button>
          <a href="/admin/marketing-cards?tab=servicios" className="px-4 py-2.5 rounded-xl border border-gray-200 text-gray-500 text-sm font-bold hover:bg-gray-50 transition-colors">
            Limpiar filtros
          </a>
        </div>
      </form>

      <AdminMarketingServiciosPanel servicios={resumen.servicios} />
    </div>
  );
}

async function TabNovedades() {
  const novedades = await getAdminMarketingNovedades();
  return (
    <div className="max-w-[1100px] mx-auto">
      <p className="text-gray-500 text-sm mb-4">Redacta anuncios de plataforma y genera sus imágenes para redes sociales.</p>
      <AdminMarketingNovedadesPanel novedadesIniciales={novedades} />
    </div>
  );
}
