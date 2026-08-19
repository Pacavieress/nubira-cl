export function formatoCLP(valor: number): string {
  return `$${valor.toLocaleString("es-CL")}`;
}
