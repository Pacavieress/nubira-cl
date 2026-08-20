import { redirect } from "next/navigation";
import { getMisPublicaciones } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { MisPublicacionesLista } from "@/components/MisPublicacionesLista";

// Puerto de app/mis_servicios.php (título real: "Mis Publicaciones") — mismo gate (línea
// 9: sin sesión -> /login) y mismas 3 acciones reales (eliminar_servicio/
// reactivar_servicio/eliminar_apunte), confirmadas como soft-delete real, no un DELETE
// permanente — ver server/src/modules/misPublicaciones/ para el detalle completo.
export default async function MisPublicacionesPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion) {
    redirect(`${phpSiteUrl}/login`);
  }

  const publicaciones = await getMisPublicaciones();

  return (
    <>
      <Header titulo="Mis Publicaciones" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-8 lg:ml-64 max-w-[1000px] mx-auto">
        <header className="mb-2">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Mis Publicaciones</h1>
        </header>

        <MisPublicacionesLista
          serviciosIniciales={publicaciones?.servicios ?? []}
          apuntesIniciales={publicaciones?.apuntes ?? []}
          phpSiteUrl={phpSiteUrl}
        />
      </main>
    </>
  );
}
