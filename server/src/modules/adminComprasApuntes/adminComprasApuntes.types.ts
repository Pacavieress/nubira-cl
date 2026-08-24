// Puerto de admin_compras_apuntes.php — 100% lectura (el propio PHP se declara así en su
// docblock: "OBJETIVO: Visibilidad total de transacciones de apuntes (solo lectura)"). El
// único UPDATE del archivo (marcar como revisadas las compras pendientes, línea 32) es un
// side-effect de "limpiar el badge del panel de gestión" sin impacto en datos de negocio —
// se porta igual, dispara en cada GET, mismo criterio que el PHP real.
export type OrdenComprasApuntes = "mayor_monto" | "mas_ventas" | "recientes" | "menor_monto" | "alfabetico";

export interface ComprasApuntesFiltros {
  qApunte?: string;
  qComprador?: string;
  qVendedor?: string;
  estadoPago?: "0" | "1";
  fechaDesde?: string;
  fechaHasta?: string;
  orden?: OrdenComprasApuntes;
}

export interface VentaApunteDetalle {
  id: number;
  fecha: string;
  apunteTitulo: string;
  asignatura: string | null;
  compradorNombre: string;
  compradorCorreo: string;
  precio: number;
  pagadoAlVendedor: boolean;
  paymentId: string | null;
}

export interface TutorVentas {
  vendedorId: number;
  vendedorNombre: string;
  vendedorCorreo: string;
  totalVentas: number;
  totalMonto: number;
  ultimaVenta: string;
  pagadas: number;
  pendientes: number;
  detalle: VentaApunteDetalle[];
}

export interface ComprasApuntesResumen {
  kpis: { totalCompras: number; totalMonto: number; totalTutores: number };
  desync: number;
  tutores: TutorVentas[];
  detalleTruncado: boolean;
}
