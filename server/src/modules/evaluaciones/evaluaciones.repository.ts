import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { EvaluacionRow } from "./evaluaciones.types.js";

interface EvaluacionDbRow extends EvaluacionRow, RowDataPacket {}

// Equivalente funcional de la "OPCION C" real de mis_evaluaciones.php:73-77 (ver
// evaluaciones.types.ts para el porqué) — mismo WHERE/ORDER BY, columnas explícitas en
// vez de `v.*` porque la página real solo usa id/calificacion/comentario/fecha de
// valoraciones. Sin filtro calificacion>0 y sin LIMIT — ninguno de los 2 existe en el
// PHP real (a diferencia de getResenasPorRol en tutores.repository.ts), así que tampoco
// se agregan acá.
export async function getEvaluacionesPorRol(
  usuarioId: number,
  rol: "vendedor" | "comprador",
): Promise<EvaluacionRow[]> {
  const [rows] = await pool.query<EvaluacionDbRow[]>(
    `SELECT v.id, v.calificacion, v.comentario, v.fecha, u.nombre, s.titulo AS servicio_titulo
     FROM valoraciones v
     LEFT JOIN alumnos u ON v.id_evaluador = u.id
     LEFT JOIN servicios s ON v.servicio_id = s.id
     WHERE v.id_evaluado = ? AND v.rol_evaluado = ?
     ORDER BY v.fecha DESC`,
    [usuarioId, rol],
  );
  return rows;
}
