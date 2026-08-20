import { getHome } from "@/lib/api";
import { ApunteCard } from "@/components/ApunteCard";
import { Carrusel } from "@/components/Carrusel";
import { Header } from "@/components/Header";
import { ServicioCard } from "@/components/ServicioCard";

// Puerto de app/vitrina.php — SOLO las secciones realmente activas en producción. Ver
// server/src/modules/home/home.types.ts para el detalle completo de qué se excluyó y
// por qué (4 secciones muertas confirmadas por grep de flags hardcodeados a `false`,
// "Sigue donde lo dejaste" por depender de sesión, y el motor de afinidad/personalización
// porque para cualquier visitante sin sesión — el único caso en web/ — cae igual a su
// propio fallback, que es lo que este endpoint ya devuelve).
export default async function Home() {
  const data = await getHome();
  const phpSiteUrl = process.env.PHP_SITE_URL ?? "http://nubira.local";

  return (
    <>
      <Header titulo="Inicio" />
      <main className="w-full pt-20 pb-24 lg:pb-10 lg:ml-64 max-w-full">
        <div className="px-4 md:px-10 md:pl-12 mb-2">
          <h1 className="sr-only md:not-sr-only text-xl md:text-2xl font-medium text-[#222222] tracking-[-0.01em]">
            Tutores, apuntes y clases particulares universitarias en Chile
          </h1>
        </div>

        {data.banner && (
          <div className="mb-3 md:mb-5 px-4 md:px-10">
            <a href={data.banner.enlace ?? "#"} className="block rounded-2xl overflow-hidden border border-gray-200">
              <img src={data.banner.imagenUrl} alt={data.banner.titulo} className="h-32 md:h-64 w-full object-cover" />
            </a>
          </div>
        )}

        {data.serviciosRecomendados.length > 0 && (
          <Seccion titulo="Tutorías recomendadas" verTodoHref="/servicios">
            <Carrusel>
              {data.serviciosRecomendados.map((s) => (
                <TarjetaCarrusel key={s.id}>
                  <ServicioCard servicio={s} />
                </TarjetaCarrusel>
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.serviciosNuevos.length > 0 && (
          <Seccion titulo="Tutorías nuevas">
            <Carrusel>
              {data.serviciosNuevos.map((s) => (
                <TarjetaCarrusel key={s.id}>
                  <ServicioCard servicio={s} />
                </TarjetaCarrusel>
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.apuntesRecomendados.length > 0 && (
          <Seccion titulo="Apuntes de los que aprobaron" verTodoHref="/apuntes">
            <Carrusel>
              {data.apuntesRecomendados.map((a) => (
                <TarjetaCarrusel key={a.id}>
                  <ApunteCard apunte={a} />
                </TarjetaCarrusel>
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.clasesPaes.length > 0 && (
          // Ver todo -> /clases/paes existe en el sitio PHP real pero no en web/ todavía
          // — mismo criterio que "Recursos" en Sidebar.tsx: enlaza afuera en vez de a una
          // ruta interna rota.
          <Seccion titulo="PAES" verTodoHref={`${phpSiteUrl}/clases/paes`} verTodoExterno>
            <Carrusel>
              {data.clasesPaes.map((s) => (
                <TarjetaCarrusel key={s.id}>
                  <ServicioCard servicio={s} />
                </TarjetaCarrusel>
              ))}
            </Carrusel>
          </Seccion>
        )}

        {data.ofertas.length > 0 && (
          <Seccion titulo="Precios de última hora">
            <Carrusel>
              {data.ofertas.map((s) => (
                <TarjetaCarrusel key={s.id}>
                  <ServicioCard servicio={s} />
                </TarjetaCarrusel>
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
  children,
}: {
  titulo: string;
  verTodoHref?: string;
  verTodoExterno?: boolean;
  children: React.ReactNode;
}) {
  return (
    <section className="mb-3 md:mb-5">
      <div className="flex items-end justify-between mb-3 px-4 md:px-10 md:pl-11 gap-3">
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

// Envoltorio de ancho fijo para meter una ServicioCard/ApunteCard (pensadas para grilla,
// w-full dentro de su celda) dentro de un carrusel horizontal — mismo ancho que las cards
// de carrusel reales de vitrina.php (w-[220px] md:w-[240px]).
function TarjetaCarrusel({ children }: { children: React.ReactNode }) {
  return <div className="flex-shrink-0 w-[220px] md:w-[240px] snap-start">{children}</div>;
}
