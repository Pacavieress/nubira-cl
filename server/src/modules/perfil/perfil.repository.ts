import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface ServicioResumenRow extends RowDataPacket {
  id: number;
  horarios_json: string | null;
  video_estado: string;
  descripcion: string | null;
}

// Puerto fusionado de 2 queries reales que perfil.php corre por separado con el MISMO
// WHERE (alumno_id=? AND estado='aprobado' AND visible=1): la de completitud
// (perfil.php:239, solo id/horarios_json/video_estado) y la lectura de $publicaciones que
// alimenta el checklist de gamificación (perfil.php:349-359, ahí sí trae descripcion). Una
// sola query cubre ambos usos — no hay razón de negocio para mantenerlas separadas acá.
export async function getServiciosPropiosResumen(alumnoId: number): Promise<ServicioResumenRow[]> {
  const [rows] = await pool.query<ServicioResumenRow[]>(
    "SELECT id, horarios_json, video_estado, descripcion FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND COALESCE(visible, 1) = 1",
    [alumnoId],
  );
  return rows;
}

interface VistasMaxScoreRow extends RowDataPacket {
  vistas_perfil: number;
  max_score: number;
}

// Puerto de perfil.php:152-160 (columnas vistas_perfil y max_score de esa misma query
// maestra) — separado en su propia query acá porque el resto de esos campos ya los trae
// getTutorById() (tutores.repository.ts), reutilizado tal cual para no duplicar el SELECT
// base de alumnos.
export async function getVistasYMaxScore(alumnoId: number): Promise<{ vistasPerfil: number; maxScore: number }> {
  const [rows] = await pool.query<VistasMaxScoreRow[]>(
    `SELECT a.vistas_perfil,
            COALESCE((SELECT MAX(score_nubira) FROM servicios WHERE alumno_id = a.id AND estado = 'aprobado' AND visible = 1), 0) AS max_score
     FROM alumnos a WHERE a.id = ? LIMIT 1`,
    [alumnoId],
  );
  const row = rows[0];
  return { vistasPerfil: row?.vistas_perfil ?? 0, maxScore: row?.max_score ?? 0 };
}

// Puerto de app/actualizar_bio.php:132-140.
export async function actualizarBioAlumno(alumnoId: number, bio: string): Promise<void> {
  await pool.query("UPDATE alumnos SET bio = ? WHERE id = ?", [bio, alumnoId]);
}

// --- Recalculo de score_nubira — puerto de actualizar_score_servicio() y su llamador en
// bucle (app/helpers/usuario_helper.php:56-141, app/actualizar_bio.php:148-159) ---

const FOTOS_PROHIBIDAS = new Set(["default.png", "default_avatar.webp", "default_avatar.png", ""]);

// Puerto de nb_fotos_prohibidas() (usuario_helper.php:30-32).
export function esFotoValida(fotoPerfil: string | null): boolean {
  return !FOTOS_PROHIBIDAS.has((fotoPerfil ?? "").trim());
}

// Puerto de la subquery de apuntes en actualizar_score_servicio() (usuario_helper.php:
// 99-111) — mismo gate (estado='aprobado' AND bloqueado=0), SIN el filtro de visible que
// sí usa la lista pública de apuntes (searchApuntesPublicos): el PHP real que se está
// portando acá no lo tiene.
export async function contarApuntesAprobadosParaScore(alumnoId: number): Promise<number> {
  const [rows] = await pool.query<RowDataPacket[]>(
    "SELECT COUNT(*) AS total FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND bloqueado = 0",
    [alumnoId],
  );
  return Number((rows[0] as { total: number } | undefined)?.total ?? 0);
}

// Puerto EXACTO de la subquery de reseñas en actualizar_score_servicio()
// (usuario_helper.php:113-122) — a propósito SIN el filtro calificacion > 0 que sí usa
// getResenasPorRol() (tutores.repository.ts): son 2 funciones PHP distintas con criterios
// distintos, no una inconsistencia a corregir acá. Mismo conteo se reutiliza tanto para
// recalcular el score al guardar la bio como para la misión "3 reseñas" en la lectura del
// perfil (perfil.php:687 usa el MISMO $v_qty sin ese filtro).
export async function contarResenasVendedorParaScore(alumnoId: number): Promise<number> {
  const [rows] = await pool.query<RowDataPacket[]>(
    "SELECT COUNT(id) AS total FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor'",
    [alumnoId],
  );
  return Number((rows[0] as { total: number } | undefined)?.total ?? 0);
}

interface ServicioParaScoreRow extends RowDataPacket {
  id: number;
  descripcion: string | null;
  video_estado: string;
}

// Puerto de la query de actualizar_bio.php:150 (SELECT id FROM servicios WHERE
// alumno_id=?) — a propósito SIN filtro de estado/visible: el PHP real recalcula el score
// de TODOS los servicios del alumno al guardar la bio, no solo los aprobados/visibles.
export async function getTodosServiciosPropiosParaScore(alumnoId: number): Promise<ServicioParaScoreRow[]> {
  const [rows] = await pool.query<ServicioParaScoreRow[]>(
    "SELECT id, descripcion, video_estado FROM servicios WHERE alumno_id = ?",
    [alumnoId],
  );
  return rows;
}

export async function actualizarScoreServicio(servicioId: number, score: number): Promise<void> {
  await pool.query("UPDATE servicios SET score_nubira = ? WHERE id = ?", [score, servicioId]);
}
