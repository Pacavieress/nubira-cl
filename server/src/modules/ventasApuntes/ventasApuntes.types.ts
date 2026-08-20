// Puerto de app/ventas_apuntes.php:37-85 — SOLO lectura, mismo criterio que
// ventasClases.types.ts: la acción "Ocultar"/swipe-to-delete (líneas 471-519) dispara
// /app/eliminar_ventas_apuntes.php, un DELETE FROM ventas_apuntes PERMANENTE (no
// soft-hide) — decisión explícita del usuario de dejarla para una sesión aparte.
//
// precio es DECIMAL(10,2) en la BD real (a diferencia de compras/contratos, que usan
// int) — llega como string desde mysql2, se parsea a number en el mapper (mismo criterio
// que servicios.precio en servicios.mapper.ts).

export interface VentaApunteRow {
  id: number;
  apunte_id: number;
  fecha: Date;
  pagado_al_vendedor: number;
  titulo: string;
  archivo: string | null;
  comprador_nombre: string;
  precio: string;
}

export interface VentaApunte {
  id: number;
  apunteId: number;
  titulo: string;
  archivo: string | null;
  compradorNombre: string;
  precio: number;
  fecha: Date;
  pagadoAlVendedor: boolean;
}
