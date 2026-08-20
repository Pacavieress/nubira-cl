// Puerto de las 2 consultas reales de app/mis_compras.php:43-85. monto en ambas tablas
// (compras, contratos) es int(11), no DECIMAL — a diferencia de servicios.precio, llega
// como number nativo desde mysql2 sin necesidad de parseo (ver servicios.types.ts para el
// contraste con columnas DECIMAL).

export interface ApunteCompradoRow {
  id: number;
  monto: number;
  fecha: Date;
  estado_pago: string;
  titulo: string;
  asignatura: string | null;
  archivo: string | null;
  institucion: string | null;
}

export interface ServicioContratadoRow {
  id: number;
  titulo: string;
  vendedor_nombre: string;
  monto: number;
  fecha_pago: Date | null;
  estado: string;
}

export interface ApunteComprado {
  id: number;
  titulo: string;
  asignatura: string | null;
  institucion: string | null;
  archivo: string | null;
  monto: number;
  fecha: Date;
  estadoPago: string;
}

export interface ServicioContratado {
  id: number;
  titulo: string;
  vendedorNombre: string;
  monto: number;
  fechaPago: Date | null;
  estado: string;
}

export interface MisComprasPublico {
  apuntes: ApunteComprado[];
  servicios: ServicioContratado[];
}
