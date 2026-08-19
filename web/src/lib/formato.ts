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
