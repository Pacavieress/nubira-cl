import { getVistosRecientes } from "@/lib/api";
import { ApunteCardCarrusel } from "./ApunteCardCarrusel";
import { Carrusel } from "./Carrusel";
import { ServicioCardCarrusel } from "./ServicioCardCarrusel";

// Puerto de la sección "Sigue donde lo dejaste" de app/vitrina.php:832-854 — gated por
// sesión en el PHP real (`!$is_guest`) y alimentada por app/cargar_vistos.php (histórico
// de nubira_behavior_logs, con un umbral mínimo de 3 vistas para aparecer). Acá ambos
// gates (sin sesión / bajo el umbral) viven en el propio endpoint
// (server/src/modules/vistosRecientes/) y colapsan al mismo resultado — null o [] — así
// que este componente no necesita distinguirlos: los dos casos significan "no renderizar
// nada", igual que el data-hide-target del PHP oculta el wrapper #sec-recientes-wrapper
// completo.
//
// etiquetaTipo="CLASES"/"APUNTE" — único call site de ese prop (ver ServicioCardCarrusel.tsx/
// ApunteCardCarrusel.tsx): esta es la única sección de la home que mezcla servicios y
// apuntes en una misma lista, así que necesita distinguir el tipo donde el resto de
// carruseles muestra la institución del tutor.
export async function SigueDondeLoDejaste() {
  const items = await getVistosRecientes();
  if (!items || items.length === 0) return null;

  return (
    <section className="mb-3 md:mb-5 relative animate-fade-in-up transition-all duration-500 delay-100">
      <div className="flex items-end justify-between mb-3 px-4 md:px-10 md:pl-11">
        <h2 className="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Sigue donde lo dejaste</h2>
      </div>
      <Carrusel>
        {items.map((item) =>
          item.tipo === "servicio" ? (
            <ServicioCardCarrusel
              key={`s-${item.servicio.id}`}
              servicio={item.servicio}
              ancho="sm"
              etiquetaTipo="CLASES"
            />
          ) : (
            <ApunteCardCarrusel key={`a-${item.apunte.id}`} apunte={item.apunte} etiquetaTipo="APUNTE" />
          ),
        )}
      </Carrusel>
    </section>
  );
}

// Puerto exacto de los 4 placeholders de vitrina.php:840-849 — mismo wrapper de sección/
// título que la versión real arriba, para que no haya salto de layout cuando el
// contenido real la reemplaza.
export function SigueDondeLoDejasteSkeleton() {
  return (
    <section className="mb-3 md:mb-5 relative animate-fade-in-up transition-all duration-500 delay-100">
      <div className="flex items-end justify-between mb-3 px-4 md:px-10 md:pl-11">
        <h2 className="text-lg md:text-xl font-medium text-[#222222] tracking-[-0.01em]">Sigue donde lo dejaste</h2>
      </div>
      <div className="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-3 pt-1 no-scrollbar scroll-smooth pl-4 pr-4 md:pl-10 md:pr-10 min-h-[108px] md:min-h-[124px] items-stretch">
        {[0, 1, 2, 3].map((i) => (
          <div
            key={i}
            className="flex-shrink-0 w-[220px] md:w-[300px] h-[96px] md:h-[112px] bg-white rounded-2xl border border-gray-100 p-2 md:p-3 flex gap-3 snap-start opacity-60 overflow-hidden"
          >
            <div className="w-20 h-20 md:w-24 md:h-24 rounded-xl bg-gray-100 flex-shrink-0 animate-pulse self-center" />
            <div className="flex flex-col justify-center w-full gap-2">
              <div className="h-2 bg-gray-200 rounded w-1/3 animate-pulse" />
              <div className="h-2.5 bg-gray-200 rounded w-full animate-pulse" />
              <div className="h-2.5 bg-gray-200 rounded w-2/3 animate-pulse mt-0.5" />
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
