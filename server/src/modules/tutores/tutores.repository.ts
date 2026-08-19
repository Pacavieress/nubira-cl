import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { TutorRow } from "./tutores.types.js";

interface TutorRowPacket extends TutorRow, RowDataPacket {}

// Rating agregado del tutor-como-oferente desde valoraciones (Decisión D, Fase 6):
// id_evaluado = alumno.id AND rol_evaluado = 'vendedor'. NO se usa
// alumnos.calificacion_promedio — esa columna la calcula helpers/reputacion_helper.php
// combinando AMBOS roles (comprador+vendedor), un concepto distinto ("reputación general"),
// y reintroduciría el mismo tipo de inconsistencia de doble fuente que se corrigió en
// Fase 0.5, solo que por otra vía.
const SELECT_TUTOR = `
  SELECT
    a.id, a.nombre, a.bio, a.foto_perfil, a.verificacion_estado,
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
