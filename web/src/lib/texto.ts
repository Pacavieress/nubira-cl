// Puerto de la lógica de abreviación de nombre repetida en varios lugares de PHP
// (card_servicio_grid.php:87-95, detalle_servicio.php:792-798) — "Sofía Valentina C."
// se abrevia a "Sofía C.". Se extrae acá porque web/ la necesita en 2 lugares
// (ServicioCard y la página de detalle) y no tiene sentido triplicarla.
export function abreviarNombre(nombreCompleto: string | null): string {
  const partes = (nombreCompleto ?? "").trim().split(/\s+/).filter(Boolean);
  if (partes.length === 0) return "Profesor";
  const primero = partes[0]!.charAt(0).toUpperCase() + partes[0]!.slice(1).toLowerCase();
  if (partes.length >= 2) {
    const ultimo = partes[partes.length - 1]!;
    return `${primero} ${ultimo.charAt(0).toUpperCase()}.`;
  }
  return primero;
}

export function inicial(nombreCompleto: string | null): string {
  const texto = (nombreCompleto ?? "").trim();
  return texto ? texto.charAt(0).toUpperCase() : "U";
}
