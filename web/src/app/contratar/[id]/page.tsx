import { notFound, redirect } from "next/navigation";
import { getContratoCheckout } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { parsearHorariosServicio } from "@/lib/horarios";
import { formatoCLP } from "@/lib/formato";
import { abreviarNombre } from "@/lib/texto";
import { Header } from "@/components/Header";
import { ContratarForm } from "@/components/ContratarForm";

interface ContratarPageProps {
  params: Promise<{ id: string }>;
}

// Puerto de contratar_servicio.php (GET) — Grupo de Contratación, 26/08/2026. La rama de
// selección de horario/cupón/notas y el submit viven en ContratarForm (client component,
// necesita interactividad real). Esta página solo hace el gate de sesión + la carga inicial
// (mismos 2 chequeos que el PHP real: servicio existe y no es el propio).
export default async function ContratarPage({ params }: ContratarPageProps) {
  const { id } = await params;
  const servicioId = Number(id);
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";

  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    notFound();
  }

  const sesion = await getSesion();
  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const datos = await getContratoCheckout(servicioId);
  if (!datos) {
    notFound();
  }

  const { servicio } = datos;
  const disponibilidad = parsearHorariosServicio(servicio.horarios);

  return (
    <>
      <Header titulo="Contratar servicio" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[900px] mx-auto">
        <header className="mb-6">
          <h1 className="text-2xl font-medium text-[#222222] tracking-[-0.01em]">Reserva tu clase</h1>
          <p className="text-gray-500 text-sm mt-0.5">Elige un horario disponible y confirma tu reserva.</p>
        </header>

        <div className="bg-white rounded-2xl border border-gray-100 shadow-[0_1px_3px_rgba(0,0,0,0.04)] p-5 mb-6 flex items-center gap-4">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={servicio.imagenUrl} alt="" className="w-16 h-16 rounded-xl object-cover border border-gray-100 shrink-0" />
          <div className="min-w-0 flex-1">
            <h2 className="font-medium text-[#222222] truncate">{servicio.titulo}</h2>
            <p className="text-sm text-gray-500 truncate">
              Con {abreviarNombre(servicio.vendedorNombre)}
              {servicio.institucion ? ` · ${servicio.institucion}` : ""}
            </p>
          </div>
          <div className="text-right shrink-0">
            {servicio.esOferta && (
              <p className="text-xs text-gray-400 line-through">{formatoCLP(servicio.precioOriginal)}</p>
            )}
            <p className="text-lg font-medium text-[#222222]">{servicio.montoInicial > 0 ? formatoCLP(servicio.montoInicial) : "Gratis"}</p>
          </div>
        </div>

        <ContratarForm servicio={servicio} disponibilidad={disponibilidad} cuponInicial={datos.cupon} phpSiteUrl={phpSiteUrl} />
      </main>
    </>
  );
}
