// Puerto de app/ventas_clases.php:40-63 — SOLO la parte de lectura (agrupación por día +
// desglose de montos). La acción "Ocultar" (selección múltiple, línea 96-108/404-428) NO
// se porta en esta pieza: en el PHP real dispara /app/eliminar_ventas.php, que ejecuta
// `DELETE FROM contratos WHERE id = ? AND vendedor_id = ?` — un borrado PERMANENTE de la
// fila de contrato/transacción, no un soft-hide (no usa ninguna columna oculto_*). Decisión
// explícita del usuario: portar el listado completo ahora, dejar esa acción destructiva
// para una sesión aparte donde se decida si se mantiene como delete real o se convierte a
// soft-hide de verdad.

export interface VentaClaseRow {
  id_contrato: number;
  titulo: string;
  imagen: string | null;
  comprador_nombre: string;
  comprador_email: string;
  monto: number;
  monto_subsidio: number | null;
  monto_comision: number | null;
  fecha_creacion: Date;
  fecha_pago: Date | null;
  estado: string;
  calificacion_vendedor: number;
}

export interface VentaClase {
  idContrato: number;
  titulo: string;
  imagenUrl: string;
  compradorNombre: string;
  compradorEmail: string;
  bruto: number;
  subsidio: number;
  comision: number;
  neto: number;
  fechaCreacion: Date;
  fechaPago: Date | null;
  estado: string;
  yaCalificado: boolean;
}
