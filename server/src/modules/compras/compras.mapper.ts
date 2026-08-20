import type { ApunteComprado, ApunteCompradoRow, ServicioContratado, ServicioContratadoRow } from "./compras.types.js";

export function mapApunteCompradoRow(row: ApunteCompradoRow): ApunteComprado {
  return {
    id: row.id,
    titulo: row.titulo,
    asignatura: row.asignatura,
    institucion: row.institucion,
    archivo: row.archivo,
    monto: row.monto,
    fecha: row.fecha,
    estadoPago: row.estado_pago,
  };
}

export function mapServicioContratadoRow(row: ServicioContratadoRow): ServicioContratado {
  return {
    id: row.id,
    titulo: row.titulo,
    vendedorNombre: row.vendedor_nombre,
    monto: row.monto,
    fechaPago: row.fecha_pago,
    estado: row.estado,
  };
}
