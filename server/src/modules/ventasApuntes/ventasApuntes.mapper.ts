import type { VentaApunte, VentaApunteRow } from "./ventasApuntes.types.js";

export function mapVentaApunteRow(row: VentaApunteRow): VentaApunte {
  return {
    id: row.id,
    apunteId: row.apunte_id,
    titulo: row.titulo,
    archivo: row.archivo,
    compradorNombre: row.comprador_nombre,
    precio: Number(row.precio),
    fecha: row.fecha,
    pagadoAlVendedor: row.pagado_al_vendedor === 1,
  };
}
