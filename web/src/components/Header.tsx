import Link from "next/link";
import { getSesion } from "@/lib/sesion";
import { HeaderSearchForm } from "./HeaderSearchForm";

// Puerto COMPLETO de app/componentes/header.php — [26/08/2026, alcance confirmado con el
// usuario: "avatar real + botones publicar + punto de alerta"] reemplaza el recorte
// anterior ("logo+breadcrumb+buscador", documentado como excluyendo todo lo que dependía
// de sesión). Esa exclusión quedó obsoleta: hoy 51 páginas usan este componente con sesión
// real vía getSesion() (/mi-perfil, /admin/*, /mis-compras, etc.), pero Header nunca la
// consultaba — así que mostraba el mismo hueco vacío tanto a un admin logueado como a un
// visitante anónimo.
//
// Este componente hace su PROPIA consulta de sesión (llama a getSesion() acá adentro, no
// la recibe por prop) — mismo patrón que header.php, un include standalone con su propia
// lectura de $_SESSION/DB, sin depender de lo que ya haya resuelto la página que lo
// incluye. Se paga un roundtrip extra a server/ en páginas que YA llaman getSesion() por su
// cuenta (ej. /mi-perfil, /admin/*) — aceptado a cambio de no enhebrar sesión como prop por
// los 51 call sites existentes.
//
// Deliberadamente NO portado (fuera del alcance confirmado, documentado, no olvidado):
//   - Botón "Cómo funciona" + modal de onboarding (sistema propio; nota aparte en
//     CLAUDE.md sobre su futura migración a tabla editable).
//   - Modal de avisos oficiales admin->usuario (carrusel/CTA, ~300 líneas propias).
//   - Tracker de dispositivo silencioso (analítica, sin equivalente en server/).
//   - redir preciso en el ícono de perfil anónimo: header.php arma
//     '/login?redir=' . $_SERVER['REQUEST_URI']; un Server Component de Next no expone el
//     pathname actual sin agregar middleware nuevo solo para esto — desproporcionado. El
//     ícono anónimo enlaza a `${phpSiteUrl}/login` sin redir.
//
// `q` es opcional: solo busqueda/page.tsx lo pasa (prefill del input con el término ya
// buscado, igual que header.php:185 `value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"`).
export async function Header({ titulo, q }: { titulo: string; q?: string }) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";
  const sesion = await getSesion();

  return (
    <nav className="fixed top-0 w-full bg-white/95 backdrop-blur-md border-b border-gray-100/80 z-50 h-14">
      <div className="w-full flex items-center justify-between px-4 md:px-8 h-full gap-3 md:gap-6">
        <div className="flex items-center gap-4 flex-shrink-0">
          <a href={phpSiteUrl} className="flex items-center">
            <img src="/logo.webp" alt="Nubira" className="h-6 md:h-7 w-auto object-contain" />
          </a>
          <div className="hidden lg:flex items-center gap-2 text-xs text-gray-500">
            <a href={phpSiteUrl} className="hover:text-[#54A6D8] transition-colors duration-200">
              Inicio
            </a>
            <span className="text-gray-300">/</span>
            <span className="text-gray-900 font-semibold">{titulo}</span>
          </div>
        </div>

        <div className="flex-1 max-w-xl mx-1 md:mx-4">
          <HeaderSearchForm q={q} />
        </div>

        <div className="flex flex-shrink-0 items-center gap-2 md:gap-4">
          {sesion?.mostrarBotonesPublicar && (
            <div className="hidden lg:flex items-center gap-3">
              <Link
                href="/formulario-subir-apunte"
                className="px-4 py-1.5 bg-blue-50 hover:bg-blue-100 border border-blue-100 text-[#54A6D8] text-xs font-semibold rounded-xl transition-all duration-200 flex items-center gap-2"
              >
                <svg className="w-4 h-4 text-[#54A6D8]" viewBox="0 0 24 24" fill="currentColor">
                  <path
                    fillRule="evenodd"
                    clipRule="evenodd"
                    d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0016.5 9h-1.875a1.875 1.875 0 01-1.875-1.875V5.25A3.75 3.75 0 009 1.5H5.625zM7.5 15a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5A.75.75 0 017.5 15zm.75 2.25a.75.75 0 000 1.5H12a.75.75 0 000-1.5H8.25z"
                  />
                  <path d="M12.971 1.816A5.23 5.23 0 0114.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 013.434 1.279 9.768 9.768 0 00-6.963-6.963z" />
                </svg>
                <span>Publicar Apunte</span>
              </Link>
              <Link
                href="/publicar-servicio"
                className="px-4 py-1.5 bg-[#54A6D8] hover:bg-blue-600 text-white text-xs font-semibold rounded-xl transition-all duration-200 flex items-center gap-2"
              >
                <svg className="w-4 h-4 text-white" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M11.7 2.805a.75.75 0 01.6 0A60.65 60.65 0 0122.83 8.72a.75.75 0 01-.231 1.337 49.949 49.949 0 00-9.902 3.912l-.003.002-.34.18a.75.75 0 01-.707 0A50.009 50.009 0 001.402 10.06a.75.75 0 01-.231-1.337A60.653 60.653 0 0111.7 2.805z" />
                  <path d="M13.06 15.473a48.45 48.45 0 017.666-3.282c.134 1.414.22 2.843.255 4.285a.75.75 0 01-.46.71 47.878 47.878 0 00-8.105 4.342.75.75 0 01-.832 0 47.877 47.877 0 00-8.104-4.342.75.75 0 01-.461-.71c.035-1.442.121-2.87.255-4.286A48.4 48.4 0 0110.94 15.473c.191.1.404.154.618.154.214 0 .427-.054.618-.154z" />
                </svg>
                <span>Publicar Clase</span>
              </Link>
            </div>
          )}

          {sesion ? (
            <Link href="/mi-perfil" className="relative hidden lg:block group" title="Mi Perfil">
              <div className="w-8 h-8 md:w-9 md:h-9 rounded-full bg-blue-50 border border-gray-100 flex items-center justify-center text-[#54A6D8] text-[10px] md:text-xs font-semibold overflow-hidden transition-transform duration-200 hover:scale-105">
                {sesion.fotoPerfil ? (
                  // eslint-disable-next-line @next/next/no-img-element -- foto servida por el sitio PHP real (app/perfil/fotos/), no un asset de Next.
                  <img
                    src={`${phpSiteUrl}/app/perfil/fotos/${encodeURIComponent(sesion.fotoPerfil)}`}
                    alt="Perfil"
                    className="w-full h-full object-cover"
                  />
                ) : (
                  sesion.iniciales
                )}
              </div>
              {sesion.perfilIncompleto && (
                <span className="absolute top-0 right-0 -mr-0.5 -mt-0.5 w-2.5 h-2.5 bg-red-500 border-2 border-white rounded-full" />
              )}
            </Link>
          ) : (
            <a href={`${phpSiteUrl}/login`} className="relative hidden lg:block group" title="Invitado - Iniciar Sesión">
              <div className="w-8 h-8 md:w-9 md:h-9 rounded-full bg-blue-50 border border-gray-100 flex items-center justify-center text-[#54A6D8] text-[10px] md:text-xs font-semibold overflow-hidden transition-transform duration-200 hover:scale-105">
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                  />
                </svg>
              </div>
            </a>
          )}
        </div>
      </div>
    </nav>
  );
}
