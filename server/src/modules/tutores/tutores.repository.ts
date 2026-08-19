import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ValoracionRow } from "../servicios/servicios.types.js";
import type { TutorRow } from "./tutores.types.js";

interface TutorRowPacket extends TutorRow, RowDataPacket {}
interface ValoracionRowPacket extends ValoracionRow, RowDataPacket {}

// Rating agregado del tutor-como-oferente desde valoraciones (Decisión D, Fase 6, RATIFICADA
// de nuevo al construir el perfil completo): id_evaluado = alumno.id AND rol_evaluado =
// 'vendedor'. NO se usa alumnos.calificacion_promedio/cantidad_votos — perfil.php:304 SÍ
// mezcla esos 2 campos legado con valoraciones (prom_t), pero esa mezcla reintroduce a
// propósito la doble fuente de verdad que la Fase 0.5 eliminó. Decisión confirmada: web/
// muestra el número "limpio" (solo valoraciones), aunque difiera del que hoy ve un
// visitante del sitio PHP para cualquier tutor con historial legado.
const SELECT_TUTOR = `
  SELECT
    a.id, a.nombre, a.bio, a.foto_perfil, a.verificacion_estado, a.tipo,
    a.universidad, a.anio_egreso, a.anios_experiencia,
    COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
    (SELECT COUNT(*) FROM valoraciones v WHERE v.id_evaluado = a.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS total_votos,
    (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.id_evaluado = a.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS rating_promedio
  FROM alumnos a
  LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
  WHERE a.id = ? AND a.visible = 1 AND a.bloqueado = 0
  LIMIT 1
`;

export async function getTutorById(id: number): Promise<TutorRow | null> {
  const [rows] = await pool.query<TutorRowPacket[]>(SELECT_TUTOR, [id]);
  return rows[0] ?? null;
}

// Puerto exacto de perfil.php:329 y :338 (2 queries separadas por rol_evaluado, cada una
// LIMIT 20) — misma condición (calificacion > 0), mismo orden (más reciente primero).
export async function getResenasPorRol(
  tutorId: number,
  rol: "vendedor" | "comprador",
): Promise<ValoracionRow[]> {
  const [rows] = await pool.query<ValoracionRowPacket[]>(
    `SELECT v.id, v.calificacion, v.comentario, v.fecha,
            a.nombre AS evaluador_nombre, a.foto_perfil AS evaluador_foto
     FROM valoraciones v
     JOIN alumnos a ON v.id_evaluador = a.id
     WHERE v.id_evaluado = ? AND v.rol_evaluado = ? AND v.calificacion > 0
     ORDER BY v.fecha DESC
     LIMIT 20`,
    [tutorId, rol],
  );
  return rows;
}
