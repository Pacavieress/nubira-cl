import Link from "next/link";
import { redirect } from "next/navigation";
import { getSesion } from "@/lib/sesion";
import { Header } from "@/components/Header";
import { ACCESOS_ADMIN } from "./admin-accesos";

// Puerto de la sección "Administración" de app/componentes/panel_gestion.php:199-228 —
// SOLO el hub/landing (grilla de accesos), no cada herramienta individual. Decisión
// confirmada explícitamente: las 31 herramientas reales (Usuarios, Retiros, Apuntes,
// etc.) siguen abriendo el .php real de nubira.local en pestaña nueva por ahora — portarlas
// una por una es trabajo aparte, no de esta pieza.
//
// Gate: mismo criterio que admin_panel.php:3 (sin sesión O rol!=='admin' -> redirect a
// login), pero acá vía getSesion() (sesiones_api + alumnos.rol fresco, ver
// server/src/modules/auth/) en vez de $_SESSION cacheada. Fail-closed: cualquier resultado
// que no sea esAdmin===true bounce a login, sin excepción — igual que el PHP real, incluso
// si el visitante SÍ tiene sesión pero no es admin.
//
// Sin badges (contadores rojos en vivo vía contar_alertas_sistema.php) — deferred a
// propósito, documentado en admin-accesos.ts.
export default async function AdminPage() {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  if (!sesion || !sesion.esAdmin) {
    redirect(`${phpSiteUrl}/login`);
  }

  return (
    <>
      <Header titulo="Administración" />
      <main className="pt-20 pb-28 md:pb-16 px-4 md:px-10 lg:ml-64 max-w-[1200px] mx-auto">
        <header className="mb-6">
          <h1 className="text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">Administración</h1>
          <p className="text-sm text-gray-500 mt-1">
            Cada acceso abre la herramienta real en nubira.local — todavía no están portadas a web/.
          </p>
        </header>

        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4 w-full">
          {ACCESOS_ADMIN.map((acceso) => {
            const claseTile =
              "group flex flex-col items-center text-center gap-2.5 p-3.5 rounded-xl bg-white border border-gray-100 hover:bg-gray-50/50 hover:border-gray-200 hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] active:scale-[0.98] transition-[transform,border-color,background-color,box-shadow] duration-150 ease-out";
            const contenido = (
              <>
                <div className="w-10 h-10 rounded-lg bg-gray-50 text-gray-500 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform duration-200">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={1.5}
                    stroke="currentColor"
                    className="w-5 h-5"
                    dangerouslySetInnerHTML={{ __html: acceso.iconoSvg }}
                  />
                </div>
                <span className="text-[13px] font-semibold text-gray-700 group-hover:text-gray-900 tracking-tight leading-tight">
                  {acceso.titulo}
                </span>
              </>
            );

            return acceso.interno ? (
              <Link key={acceso.href} href={acceso.href} className={claseTile}>
                {contenido}
              </Link>
            ) : (
              <a key={acceso.href} href={`${phpSiteUrl}${acceso.href}`} target="_blank" rel="noopener noreferrer" className={claseTile}>
                {contenido}
              </a>
            );
          })}
        </div>
      </main>
    </>
  );
}
