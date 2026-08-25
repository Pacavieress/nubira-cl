import { redirect } from "next/navigation";
import { getAdminOfertas, type OrdenOfertas } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminOfertasPanel } from "@/components/AdminOfertasPanel";

interface OfertasPageProps {
  searchParams: Promise<{ orden?: string }>;
}

const ORDENES_VALIDOS: OrdenOfertas[] = ["recientes", "descuento", "vencer", "cupos", "activas", "precio_mayor", "precio_menor"];

function normalizarOrden(v: string | undefined): OrdenOfertas {
  return ORDENES_VALIDOS.includes(v as OrdenOfertas) ? (v as OrdenOfertas) : "recientes";
}

// Puerto de admin_ofertas.php ("Centro de Subsidios"). Ver AdminOfertasPanel.tsx para las 2
// mutaciones portadas completas (aplicar/quitar oferta, UPDATE puros sobre `servicios`).
export default async function AdminOfertasPage({ searchParams }: OfertasPageProps) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const { orden: ordenParam } = await searchParams;
  const orden = normalizarOrden(ordenParam);
  const servicios = await getAdminOfertas(orden);
  const ofertasActivas = servicios.filter((s) => s.isSubvencionado && !(s.ofertaTermino && s.ofertaTermino < new Date().toISOString().slice(0, 10))).length;

  return (
    <>
      <Header titulo="Subsidios" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1400px] mx-auto space-y-6">
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Centro de Subsidios Nubira</h1>
            <p className="text-sm text-gray-500 mt-1">Inyecta capital estratégico para potenciar tutores y atraer alumnos.</p>
          </div>
          <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm flex items-center gap-3">
            <div>
              <p className="text-[10px] uppercase font-bold text-gray-400">Ofertas Activas</p>
              <p className="text-lg font-black text-gray-900 leading-none">{ofertasActivas}</p>
            </div>
          </div>
        </div>

        <form method="get" className="flex items-center gap-2">
          <label className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Ordenar por</label>
          <select
            name="orden"
            defaultValue={orden}
            className="text-sm font-medium text-gray-700 border border-gray-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#54A6D8] bg-white shadow-sm cursor-pointer"
          >
            <option value="recientes">Más recientes</option>
            <option value="descuento">Mayor descuento</option>
            <option value="vencer">Próximas a vencer</option>
            <option value="cupos">Más cupos restantes</option>
            <option value="activas">Activas primero</option>
            <option value="precio_mayor">Precio original mayor</option>
            <option value="precio_menor">Precio original menor</option>
          </select>
          <button type="submit" className="bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white px-4 py-2 rounded-xl text-sm font-bold">
            Aplicar
          </button>
        </form>

        <AdminOfertasPanel servicios={servicios} />
      </main>
    </>
  );
}
