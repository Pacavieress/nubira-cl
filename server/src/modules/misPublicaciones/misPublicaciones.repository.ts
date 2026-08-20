import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ApuntePublicadoRow, ServicioPublicadoRow } from "./misPublicaciones.types.js";

interface ServicioPublicadoDbRow extends ServicioPublicadoRow, RowDataPacket {}
interface ApuntePublicadoDbRow extends ApuntePublicadoRow, RowDataPacket {}

// Puerto exacto de mis_servicios.php:101 (mismo WHERE/ORDER BY, COALESCE(visible,1)=1).
export async function getServiciosPublicadosByAlumno(alumnoId: number): Promise<ServicioPublicadoRow[]> {
  const [rows] = await pool.query<ServicioPublicadoDbRow[]>(
    `SELECT id, titulo, imagen, estado, modalidad, precio, slug
     FROM servicios
     WHERE alumno_id = ? AND COALESCE(visible, 1) = 1
     ORDER BY fecha_publicacion DESC`,
    [alumnoId],
  );
  return rows;
}

// Puerto de mis_servicios.php:115 — `id_alumno` es la columna real (confirmado con SHOW
// COLUMNS); el fallback a `alumno_id` del PHP real (líneas 117-120, "columna no existe")
// es defensivo contra un esquema que nunca se dio en la práctica, no se replica.
export async function getApuntesPublicadosByAlumno(alumnoId: number): Promise<ApuntePublicadoRow[]> {
  const [rows] = await pool.query<ApuntePublicadoDbRow[]>(
    `SELECT id, titulo, archivo, precio, publico
     FROM apuntes
     WHERE id_alumno = ? AND COALESCE(visible, 1) = 1
     ORDER BY fecha_subida DESC`,
    [alumnoId],
  );
  return rows;
}

// Soft-delete real (mis_servicios.php:27) — NO un DELETE, a diferencia del hallazgo de
// eliminar_ventas.php en la pieza anterior. Ámbito por dueño (alumno_id=?) igual que el
// PHP real: si el id no le pertenece al usuario, el UPDATE afecta 0 filas y no pasa nada —
// sin distinguir "no encontrado" de "no es tuyo" (mismo comportamiento silencioso del PHP,
// que tampoco chequea affected_rows para decidir éxito/error).
export async function ocultarServicio(id: number, alumnoId: number): Promise<void> {
  await pool.query<ResultSetHeader>("UPDATE servicios SET visible = 0 WHERE id = ? AND alumno_id = ?", [id, alumnoId]);
}

export async function reactivarServicio(id: number, alumnoId: number): Promise<void> {
  await pool.query<ResultSetHeader>("UPDATE servicios SET estado = 'aprobado' WHERE id = ? AND alumno_id = ?", [
    id,
    alumnoId,
  ]);
}

export async function ocultarApunte(id: number, alumnoId: number): Promise<void> {
  await pool.query<ResultSetHeader>("UPDATE apuntes SET visible = 0 WHERE id = ? AND id_alumno = ?", [id, alumnoId]);
}
