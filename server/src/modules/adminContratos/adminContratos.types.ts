// Puerto de admin_contratos.php — SOLO la parte de lectura (stats + listado filtrable +
// modal de detalle). Deliberadamente SIN las acciones de escritura del PHP real
// (liberar/cancelar/revertir/eliminar contrato — cada una es un archivo aparte,
// /app/{liberar,cancelar,revertir,eliminar}_contrato.php, que mueve dinero real o borra
// filas de forma permanente): decisión de alcance, no un olvido — mismo criterio que
// mi-billetera.php, que enlaza al sitio PHP real para "Solicitar Retiro" en vez de
// reconstruir ese flujo.
export type EstadoContrato = "pendiente_pago" | "en_progreso" | "liberado" | "cancelado";

export interface ContratoAdmin {
  id: number;
  estado: string;
  monto: number;
  fechaCreacion: string;
  fechaEstimada: string | null;
  fechaCierre: string | null;
  conversacionId: number | null;
  servicioTitulo: string;
  compradorNombre: string;
  vendedorNombre: string;
}

export interface ContratosResumen {
  stats: Record<EstadoContrato, number>;
  total: number;
  contratos: ContratoAdmin[];
}
