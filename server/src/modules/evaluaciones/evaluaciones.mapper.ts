import type { EvaluacionRecibida, EvaluacionRow } from "./evaluaciones.types.js";

// nombre_completo real del PHP es `$r['nombre'] . ' ' . ($r['apellidos'] ?? '')`, pero
// apellidos es siempre '' (ver evaluaciones.types.ts) — el resultado visible es idéntico a
// usar solo nombre (el espacio final de la concatenación PHP no es visible en HTML
// renderizado). u.nombre puede ser null si el evaluador (id_evaluador) fue borrado —
// LEFT JOIN, no INNER — mismo fallback 'Usuario' que formatearNombrePrivado() usa en
// mis_compras.php para el mismo caso.
export function mapEvaluacionRow(row: EvaluacionRow): EvaluacionRecibida {
  return {
    id: row.id,
    nombreEvaluador: row.nombre ?? "Usuario",
    calificacion: row.calificacion,
    comentario: row.comentario,
    fecha: row.fecha,
    servicioTitulo: row.servicio_titulo,
  };
}
