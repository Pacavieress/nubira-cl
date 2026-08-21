import { redirect } from "next/navigation";
import { getAdminDominios } from "@/lib/api";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { AdminDominiosPanel } from "@/components/AdminDominiosPanel";

// Puerto de admin_dominios.php — gestor de instituciones/dominios de correo permitidos
// (tabla dominios_permitidos). Mismo gate que el resto de /admin/* (rol!=='admin' ->
// redirect a login). Fuera de alcance a propósito: el live-search del PHP real filtra
// filas ya renderizadas en el DOM con JS plano — acá se reimplementa igual (useState +
// filter en AdminDominiosPanel), no hay diferencia de comportamiento.
export default async function AdminDominiosPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  const dominios = await getAdminDominios();

  return (
    <>
      <Header titulo="Gestión de Universidades" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1200px] mx-auto">
        <header className="mb-6">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Gestión de Universidades</h1>
          <p className="text-sm text-gray-500 mt-1">Administra los accesos permitidos por dominio de correo.</p>
        </header>

        <AdminDominiosPanel dominiosIniciales={dominios} />
      </main>
    </>
  );
}
