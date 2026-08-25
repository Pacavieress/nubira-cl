// Puerto de admin_apuntes.php ("Gestión de Apuntes") — listado + búsqueda + UNA sola
// mutación: "alternar" (toggle publico/oculto, UPDATE de 1 columna, reversible, sin efecto
// externo). Alcance confirmado explícitamente con el usuario antes de construir (mismo
// criterio ya usado en Videos/Log Fail): quedan excluidas, con link directo al sitio PHP real —
// - aprobar/rechazar (acciones_apunte.php): además del UPDATE de estado, recalculan
//   gamificación de TODOS los servicios del tutor y escriben/borran una miniatura de email en
//   disco (GD) — combinación de side-effects entre tablas + escritura de filesystem.
// - eliminar (acciones_apunte.php): hard delete que borra filas de `compras` (historial de
//   pagos real) y múltiples archivos en disco — irreversible sobre datos transaccionales,
//   misma categoría de riesgo que "eliminar_usuario" en Usuarios (excluido por el mismo motivo).
// - guardar_imagen_editada (admin_apuntes.php): escribe en disco una imagen editada a mano
//   (censura de miniatura) — misma categoría de riesgo de escritura de filesystem que
//   Banners/Banco de Imágenes, ya excluidos en esta sesión.
export interface ApunteListado {
  id: number;
  titulo: string;
  autor: string;
  asignatura: string;
  fechaSubida: string;
  publico: boolean;
  estado: string;
  totalVentas: number;
  miniaturaUrl: string;
}

export interface ApuntesResumen {
  q: string;
  apuntes: ApunteListado[];
}
