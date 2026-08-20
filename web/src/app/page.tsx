import { getHome } from "@/lib/api";
import { ApunteCardCarrusel } from "@/components/ApunteCardCarrusel";
import { Carrusel } from "@/components/Carrusel";
import { Header } from "@/components/Header";
import { ServicioCardCarrusel } from "@/components/ServicioCardCarrusel";

// Puerto de app/vitrina.php — SOLO las secciones realmente activas en producción. Ver
// server/src/modules/home/home.types.ts para el detalle completo de qué se excluyó y
// por qué (4 secciones muertas confirmadas por grep de flags hardcodeados a `false`,
// "Sigue donde lo dejaste" por depender de sesión, y el motor de afinidad/personalización
// porque para cualquier visitante sin sesión — el único caso en web/ — cae igual a su
// propio fallback, que es lo que este endpoint ya devuelve).
//
// Auditoría de fidelidad visual (espaciados/márgenes/paddings/anchos/alineaciones) hecha
// línea por línea contra vitrina.php real. Hallazgos aplicados acá:
//   - <main>: vitrina.php:827 usa "pt-16 md:pt-20 pb-36 md:pb-0 lg:ml-56 max-w-full
//     mx-auto block" — es la ÚNICA página real del sitio con ese pt/pb (las otras 6 usan
//     "pt-4/pt-20 pb-28/32 md:pb-10/16/20"), no es un error de lectura, vitrina.php
//     genuinamente diverge del resto del sitio.
//   - El bloque del banner inline (antes en esta página) se eliminó: `$banner_inline` se
//     consulta en vitrina.php:678-701 pero NUNCA se renderiza en ningún lugar del archivo
//     — confirmado con grep de "banner" en todo el archivo. Es código muerto, no una
//     sección oculta condicionalmente como las otras 4.
//   - Las cards de servicios/apuntes del carrusel de home son un diseño DISTINTO al de
//     las cards de grilla (/servicios, /apuntes) — ver ServicioCardCarrusel.tsx y
//     ApunteCardCarrusel.tsx para el detalle línea por línea contra vitrina.php.
//   - Cada sección tiene su propio align-items/gap-3 real (no todas son idénticas):
//     recomendadas=items-end+gap-3, nuevas=items-end sin link, apuntes=items-center SIN
//     gap-3 (aunque tiene link — así es en el PHP real), PAES=items-center+gap-3,
//     ofertas=items-end sin link. Confirmado con grep línea por línea de las 5 secciones.
export default async function Home() {
  const data = await getHome();
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";

  return (
    <>
      <Header titulo="Inicio" />
      <main className="pt-16 md:pt-20 pb-36 md:pb-0 lg:ml-56 max-w-full mx-auto block">
        <div className="px-4 md:px-10 md:pl-12 pt-0 pb-0 md:pt-1 md:pb-2">
          <h1 className="sr-only md:not-sr-only text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">
            Tutores, apuntes y clases particulares universitarias en Chile
          </h1>
        </div>

        {data.serviciosRecomendados.length > 0 && (
          <Seccion titulo="Tutorías recomendadas" verTodoHref="/servicios" align="end" gap>
            <Carrusel>
              {data.serviciosRecomendados.map((s) => (
                <ServicioCardCarrusel key={s.id} servicio={s} />
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.serviciosNuevos.length > 0 && (
          <Seccion titulo="Tutorías nuevas" align="end">
            <Carrusel>
              {data.serviciosNuevos.map((s) => (
                <ServicioCardCarrusel key={s.id} servicio={s} />
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.apuntesRecomendados.length > 0 && (
          <Seccion titulo="Apuntes de los que aprobaron" verTodoHref="/apuntes" align="center">
            <Carrusel>
              {data.apuntesRecomendados.map((a) => (
                <ApunteCardCarrusel key={a.id} apunte={a} />
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.clasesPaes.length > 0 && (
          // Ver todo -> /clases/paes existe en el sitio PHP real pero no en web/ todavía
          // — mismo criterio que "Recursos" en Sidebar.tsx: enlaza afuera en vez de a una
          // ruta interna rota.
          <Seccion titulo="PAES" verTodoHref={`${phpSiteUrl}/clases/paes`} verTodoExterno align="center" gap>
            <Carrusel>
              {data.clasesPaes.map((s) => (
                <ServicioCardCarrusel key={s.id} servicio={s} />
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.ofertas.length > 0 && (
          <Seccion titulo="Precios de última hora" align="end">
            <Carrusel>
              {data.ofertas.map((s) => (
                <ServicioCardCarrusel key={s.id} servicio={s} ancho="sm" />
              ))}
            </Carrusel>
          </Seccion>
        )}
      </main>
    </>
  );
}

function Seccion({
  titulo,
  verTodoHref,
  verTodoExterno,
  align,
  gap,
  children,
}: {
  titulo: string;
  verTodoHref?: string;
  verTodoExterno?: boolean;
  align: "end" | "center";
  gap?: boolean;
  children: React.ReactNode;
}) {
  // Ternario con clases literales completas a propósito (no `items-${align}`) — Tailwind
  // extrae candidatos por texto literal en el archivo, no evalúa interpolaciones en
  // runtime, así que una clase armada dinámicamente no queda garantizada en el CSS
  // compilado de esta página aunque "funcione por casualidad" si otro archivo ya usa el
  // mismo literal.
  const claseAlign = align === "center" ? "items-center" : "items-end";

  return (
    <section className="mb-3 md:mb-5">
      <div className={`flex ${claseAlign} justify-between mb-3 px-4 md:px-10 md:pl-11${gap ? " gap-3" : ""}`}>
        {/* md:-ml-2 (-8px) — puerto exacto de vitrina.php:778-785 ("Ajuste óptico fino de
            títulos en escritorio"): compensa el desfase real entre el padding del header
            (md:pl-11, 44px) y el del carrusel de abajo (md:pl-10, 40px). Se aplica acá, NO
            en globals.css, porque esa regla en PHP vive en el <style> propio de vitrina.php
            (cada página PHP es autocontenida) — ponerla global filtraría el mismo -8px a
            /tutores/[id] y otras páginas que no tienen ese mismo desfase de padding. */}
        <h2 className="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em] md:-ml-2">{titulo}</h2>
        {verTodoHref && (
          <a
            href={verTodoHref}
            target={verTodoExterno ? "_blank" : undefined}
            rel={verTodoExterno ? "noopener noreferrer" : undefined}
            className="text-xs font-medium text-[#54A6D8] hover:underline bg-gray-50 px-3 py-1.5 rounded-2xl border border-[#f0f0f0] flex items-center gap-1 shrink-0"
          >
            Ver todo
            <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
            </svg>
          </a>
        )}
      </div>
      {children}
    </section>
  );
}
