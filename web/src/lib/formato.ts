export function formatoCLP(valor: number): string {
  return `$${valor.toLocaleString("es-CL")}`;
}

// Puerto de abreviar_conteo() (app/cargar_apuntes.php:31-36) — usado para "1.2K descargas".
// PHP concatena un float de round(): 2.0 se imprime "2", no "2.0" — toFixed(1) siempre deja
// el decimal, así que se recorta a mano cuando es entero para igualar ese comportamiento.
export function abreviarConteo(n: number): string {
  const valor = Math.trunc(n);
  if (valor >= 1_000_000) return `${formatearCorto(valor / 1_000_000)}M`;
  if (valor >= 1000) return `${formatearCorto(valor / 1000)}K`;
  return String(valor);
}

function formatearCorto(n: number): string {
  const redondeado = Math.round(n * 10) / 10;
  return Number.isInteger(redondeado) ? String(redondeado) : redondeado.toFixed(1);
}

// Puerto de la agrupación por día de ventas_clases.php:136-146 y ventas_apuntes.php:66-84
// ("Hoy"/"Ayer"/fecha). Mejora deliberada documentada: ventas_clases.php arma la etiqueta
// con `date('d M Y', ...)` SIN traducir el mes (ventas_apuntes.php sí tiene una tabla
// $meses_es para lo mismo) — confirmado leyendo ambos archivos completos, no es una
// decisión de diseño, es una traducción faltante en uno de los 2 archivos gemelos. Hoy en
// producción "Mis Ganancias" muestra fechas en inglés ("15 Aug 2026") mientras "Ventas de
// Apuntes" las muestra en español ("15 Ago 2026") para el mismo tipo de agrupación. Se
// unifica acá a español (toLocaleDateString('es-CL', ...), mismo patrón ya usado en
// tutores/[id]/page.tsx y servicios/[id]/page.tsx) para ambas páginas — consistente con
// feedback_idioma.md (español Chile obligatorio en toda la UI), no una simplificación.
export function etiquetaDia(fechaIso: string): string {
  const fecha = new Date(fechaIso);
  const hoy = new Date();
  const ayer = new Date();
  ayer.setDate(hoy.getDate() - 1);

  const mismoDiaCalendario = (a: Date, b: Date) =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

  if (mismoDiaCalendario(fecha, hoy)) return "Hoy";
  if (mismoDiaCalendario(fecha, ayer)) return "Ayer";
  return fecha.toLocaleDateString("es-CL", { day: "2-digit", month: "short", year: "numeric" });
}

// Clave de agrupación estable (YYYY-MM-DD en horario local) — separada de la etiqueta
// visible para no agrupar dos fechas distintas bajo la misma etiqueta "Hoy"/"Ayer" un
// segundo antes/después de medianoche.
export function claveDia(fechaIso: string): string {
  const fecha = new Date(fechaIso);
  return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, "0")}-${String(fecha.getDate()).padStart(2, "0")}`;
}
