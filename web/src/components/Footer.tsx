// Puerto de app/componentes/footer_minimal.php — footer no intrusivo con copyright +
// links legales/contacto/redes. Solo visible en lg+ (mismo `hidden lg:flex` del PHP real:
// en mobile ya hay bastante navegación fija con Sidebar/BottomNav).
//
// Íconos: instagram tiene su path real en app/iconos.php (case 'instagram'), copiado tal
// cual. envelope/facebook no existen en ese catálogo (es un sistema de íconos propio tipo
// Heroicons outline, no logos de marca) — el PHP real los trae de Font Awesome
// (`fa-solid fa-envelope`, `fa-brands fa-facebook`), que web/ no carga en ningún lado
// (todo el resto del port usa SVG inline, nunca FA). Se reemplazan acá por SVG equivalentes
// del mismo estilo que el resto del sitio: envelope en outline (mismo stroke-width 1.5 que
// iconos.php), facebook en el logo de marca sólido estándar (fill, como cualquier logo).
export function Footer() {
  return (
    <footer className="hidden lg:flex mt-12 border-t border-gray-100 pt-6 pb-8 flex-col md:flex-row justify-between items-center gap-4 px-2 w-full">
      <div className="text-[12px] text-gray-400 font-medium text-center md:text-left">
        &copy; 2025 - {new Date().getFullYear()} Nubira.cl. Todos los derechos reservados.
      </div>

      <div className="flex flex-wrap justify-center gap-4 md:gap-6 text-[12px] font-bold text-gray-500">
        <a href="/sobre-nosotros" className="hover:text-[#54A6D8] transition-colors">
          Sobre Nosotros
        </a>
        <a href="/terminos" className="hover:text-[#54A6D8] transition-colors">
          Términos
        </a>
        <a href="/privacidad" className="hover:text-[#54A6D8] transition-colors">
          Privacidad
        </a>
        <a href="mailto:contacto@nubira.cl" className="hover:text-[#54A6D8] transition-colors flex items-center gap-1.5">
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"
            />
          </svg>
          Soporte
        </a>
        <a
          href="https://instagram.com/nubira.cl"
          target="_blank"
          rel="noopener noreferrer"
          className="hover:text-[#54A6D8] transition-colors flex items-center gap-1.5"
        >
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
            <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
            <path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z" />
            <circle cx="17.5" cy="6.5" r="0.5" fill="currentColor" />
          </svg>
          Instagram
        </a>
        <a
          href="https://facebook.com/nubira.cl"
          target="_blank"
          rel="noopener noreferrer"
          className="hover:text-[#54A6D8] transition-colors flex items-center gap-1.5"
        >
          <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
            <path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" />
          </svg>
          Facebook
        </a>
      </div>
    </footer>
  );
}
