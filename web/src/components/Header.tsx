// Subconjunto de app/componentes/header.php acordado explícitamente (no el header
// completo): logo + breadcrumb + buscador. Se excluyen a propósito, no por falta de
// tiempo: ícono de perfil, botones "Publicar Apunte/Clase", modal de avisos oficiales,
// modal de onboarding, punto de alerta, tracking de dispositivo — todo depende de una
// sesión de usuario que web/ no tiene.
//
// El buscador envía a /busqueda del sitio PHP real (mismo action="/busqueda" method="GET"
// name="q" de header.php:174-188) — esa página no existe todavía en web/.

export function Header({ titulo }: { titulo: string }) {
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";

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
          <form
            action={`${phpSiteUrl}/busqueda`}
            method="GET"
            role="search"
            className="w-full flex items-center bg-gray-50 border border-gray-100 rounded-full focus-within:border-[#54A6D8] focus-within:bg-white transition-colors duration-200 overflow-hidden relative z-10 outline-none"
          >
            <div className="pl-3 text-gray-400 shrink-0 pointer-events-none">
              <svg className="w-3.5 h-3.5 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"
                />
              </svg>
            </div>
            <input
              type="search"
              name="q"
              className="w-full py-1.5 md:py-2 pl-2 pr-4 bg-transparent border-none focus:ring-0 text-gray-900 placeholder-gray-400 text-base md:text-sm cursor-pointer focus:cursor-text outline-none"
              placeholder="¿Qué buscas?"
              autoComplete="off"
            />
          </form>
        </div>

        {/* Sin ícono de perfil ni botones de publicar — dependen de sesión, fuera de alcance */}
        <div className="flex-shrink-0 w-8 md:w-9" aria-hidden="true" />
      </div>
    </nav>
  );
}
