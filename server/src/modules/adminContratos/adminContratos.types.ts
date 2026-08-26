// Puerto de admin_contratos.php — lectura (stats + listado filtrable + modal de detalle) +
// [26/08/2026, Grupo de Contratación] liberar/cancelar/revertir contrato, las 3 mutaciones
// que este comentario documentaba antes como excluidas a propósito. "eliminar contrato"
// (borrado permanente de fila) sigue fuera — no se tocó, no estaba en el alcance
// confirmado de esta pieza.
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
