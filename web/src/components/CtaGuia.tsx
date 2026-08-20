// Puerto de app/componentes/cta_guia.php — mismo criterio "sin datos reales, sin CTA"
// (cantidad<=0 || !link -> no renderizar nada, nunca un link muerto). Se usa para AMBOS
// bloques (tutores y apuntes) como secciones propias junto al contenido relacionado, no
// insertado a mitad del cuerpo del artículo — ver guias.types.ts::GuiaArticuloDetalle
// para el porqué completo de esa simplificación deliberada.
//
// BUG REAL ENCONTRADO Y CORREGIDO (no una decisión de diseño): en el PHP real,
// guia_post.php pasa el slug crudo de la categoría (ej. "matematicas") como
// 'link' => $link_ver_clases directo a nb_cta_guia(), que lo usa tal cual como
// href="<?= $link ?>" — sin el prefijo /clases/. El botón "Ver tutores"/"Ver apuntes"
// dentro del CTA queda con un href relativo roto (resuelve contra la URL actual del
// artículo, no hacia /clases/matematicas). Confirmado comparando contra el ÚNICO otro
// link real de la misma página (guia_post.php:396, "Ver todas las clases de X"), que SÍ
// antepone /clases/ correctamente — mismo slug, 2 hrefs distintos en el mismo archivo,
// uno roto y uno funcional. Acá se usa el prefijo correcto en ambos casos.
// `avatares` viene ya resuelto a URL absoluta (TutorRelacionado.fotoUrl, construido en
// server/src/modules/guias/guias.mapper.ts::mapTutorRelacionadoRow) — a diferencia del PHP
// real, que recibe el nombre de archivo crudo y arma el src acá mismo. No se vuelve a
// prefijar nada en este componente para no duplicar el origin.
export function CtaGuiaTutores({ cantidad, categoria, link, avatares }: { cantidad: number; categoria: string; link: string | null; avatares: string[] }) {
  if (cantidad <= 0 || !link || !categoria) return null;

  const titulo = `${cantidad} ${cantidad === 1 ? "tutor" : "tutores"} de ${categoria} disponible${cantidad === 1 ? "" : "s"} ahora`;
  const avataresValidos = avatares.filter(Boolean).slice(0, 3);

  return (
    <div className="relative overflow-hidden rounded-2xl border border-[#54A6D8]/15 bg-[#54A6D8]/[0.05] p-5 md:p-6 my-8">
      <span className="absolute left-0 top-0 bottom-0 w-[3px] bg-[#54A6D8]" />
      <div className="flex flex-col sm:flex-row sm:items-center gap-4">
        <div className="flex -space-x-3 shrink-0">
          {avataresValidos.length === 0 ? (
            <div className="w-10 h-10 rounded-full ring-2 ring-white bg-blue-100 flex items-center justify-center text-[#54A6D8]">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
                <path strokeLinecap="round" strokeLinejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0012 20.904a48.627 48.627 0 008.232-4.41 60.46 60.46 0 00-.491-6.347M4.26 10.147A48.639 48.639 0 0112 5.43a48.639 48.639 0 017.74 4.717M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5z" />
              </svg>
            </div>
          ) : (
            avataresValidos.map((fotoUrl, i) => (
              // eslint-disable-next-line @next/next/no-img-element
              <img key={i} src={fotoUrl} alt="" className="w-10 h-10 rounded-full ring-2 ring-white bg-blue-100 object-cover" />
            ))
          )}
        </div>

        <div className="flex-1 min-w-0">
          <p className="font-bold text-gray-900 text-[15px] leading-snug tracking-[-0.01em]">{titulo}</p>
          <p className="text-sm text-gray-500 font-normal mt-0.5">Agenda una clase particular y avanza más rápido.</p>
        </div>

        <a
          href={`/clases/${link}`}
          className="shrink-0 inline-flex items-center justify-center gap-1.5 bg-gradient-to-r from-sky-400 to-[#54A6D8] text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow-sm shadow-blue-200 hover:shadow-md transition-all duration-150 hover:scale-[1.02] active:scale-95 whitespace-nowrap"
        >
          Ver tutores
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3">
            <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
          </svg>
        </a>
      </div>
    </div>
  );
}

// `link` viene de linkVerApuntes (server/), que depende de seo_categorias_contenido con
// tipo IN ('apuntes','ambos') — confirmado contra la BD real (mismo hallazgo que
// server/src/modules/landings/landings.types.ts): CERO filas existen para tipo='apuntes'
// hoy, así que `link` siempre es null y este componente nunca renderiza en la práctica.
// Tampoco existe un /apuntes/{slug} filtrado por categoría en web/ (colisionaría con la
// ruta de listado /apuntes ya construida) ni en el PHP real (guia_post.php tampoco tiene
// un link explícito equivalente al de "Ver todas las clases de X" para apuntes) — tratar
// esto como código muerto hasta que exista contenido SEO real de apuntes que portar.
export function CtaGuiaApuntes({ cantidad, categoria, link }: { cantidad: number; categoria: string; link: string | null }) {
  if (cantidad <= 0 || !link || !categoria) return null;

  const titulo = `${cantidad} ${cantidad === 1 ? "apunte" : "apuntes"} de ${categoria} disponible${cantidad === 1 ? "" : "s"}`;

  return (
    <div className="relative overflow-hidden rounded-2xl border border-orange-400/15 bg-orange-400/[0.05] p-5 md:p-6 my-8">
      <span className="absolute left-0 top-0 bottom-0 w-[3px] bg-orange-400" />
      <div className="flex flex-col sm:flex-row sm:items-center gap-4">
        <div className="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center text-orange-500 shrink-0">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
            />
          </svg>
        </div>

        <div className="flex-1 min-w-0">
          <p className="font-bold text-gray-900 text-[15px] leading-snug tracking-[-0.01em]">{titulo}</p>
          <p className="text-sm text-gray-500 font-normal mt-0.5">Resúmenes y guías hechas por otros estudiantes.</p>
        </div>

        <a
          href={`/clases/${link}`}
          className="shrink-0 inline-flex items-center justify-center gap-1.5 bg-gradient-to-r from-orange-300 to-orange-500 text-white text-sm font-bold px-5 py-2.5 rounded-xl shadow-sm shadow-orange-200 hover:shadow-md transition-all duration-150 hover:scale-[1.02] active:scale-95 whitespace-nowrap"
        >
          Ver apuntes
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3">
            <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
          </svg>
        </a>
      </div>
    </div>
  );
}
