import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { MateriaRow, PreguntaCorreccionRow, PreguntaDesafioRow } from "./desafio.types.js";

interface MateriaDbRow extends MateriaRow, RowDataPacket {}
interface PreguntaDesafioDbRow extends PreguntaDesafioRow, RowDataPacket {}
interface PreguntaCorreccionDbRow extends PreguntaCorreccionRow, RowDataPacket {}

// Puerto exacto de desafio.php:22-26.
export async function getMateriasActivas(): Promise<MateriaRow[]> {
  const [rows] = await pool.query<MateriaDbRow[]>("SELECT slug, nombre FROM materias WHERE activa = 1 ORDER BY orden ASC");
  return rows;
}

// Puerto exacto de cargar_desafio.php:34-38.
export async function esMateriaValida(slug: string): Promise<boolean> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT 1 FROM materias WHERE slug = ? AND activa = 1 LIMIT 1", [slug]);
  return rows.length > 0;
}

// Puerto exacto de cargar_desafio.php:100-105 — default 2 (medio) para quien nunca jugó
// esta materia.
export async function getNivelActual(usuarioId: number, materiaSlug: string): Promise<number> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT nivel_actual FROM desafio_progreso WHERE usuario_id = ? AND materia_slug = ?", [
    usuarioId,
    materiaSlug,
  ]);
  const fila = rows[0] as { nivel_actual: number } | undefined;
  return fila ? fila.nivel_actual : 2;
}

// Puerto exacto de nb_desafio_preguntas_candidatas() (cargar_desafio.php:60-84) — mismo
// filtro (materia + activa + revisado_por_admin + no vistas por este usuario), mismo
// ORDER BY RAND() LIMIT 3, con o sin restricción de dificultad.
export async function getPreguntasCandidatas(
  materiaSlug: string,
  usuarioId: number,
  dificultades: number[] | null,
): Promise<PreguntaDesafioRow[]> {
  let sql = `SELECT id, tipo, enunciado, desarrollo, opcion_a, opcion_b, opcion_c, opcion_d,
                    tiempo_limite_segundos, nivel_paes
             FROM desafio_preguntas
             WHERE materia_slug = ? AND activa = 1 AND revisado_por_admin = 1
               AND id NOT IN (SELECT pregunta_id FROM desafio_preguntas_vistas WHERE usuario_id = ?)`;
  const params: (string | number)[] = [materiaSlug, usuarioId];

  if (dificultades && dificultades.length > 0) {
    sql += ` AND dificultad IN (${dificultades.map(() => "?").join(",")})`;
    params.push(...dificultades);
  }
  sql += " ORDER BY RAND() LIMIT 3";

  const [rows] = await pool.query<PreguntaDesafioDbRow[]>(sql, params);
  return rows;
}

// Puerto exacto de cargar_desafio.php:110-117 — reset silencioso de "vistas" cuando el
// banco de la materia ya se agotó para este usuario (no un error, solo agotamiento).
export async function resetVistasParaMateria(usuarioId: number, materiaSlug: string): Promise<void> {
  await pool.query(
    `DELETE dpv FROM desafio_preguntas_vistas dpv
     INNER JOIN desafio_preguntas dp ON dp.id = dpv.pregunta_id
     WHERE dpv.usuario_id = ? AND dp.materia_slug = ?`,
    [usuarioId, materiaSlug],
  );
}

// Puerto exacto de responder_desafio.php:65-79 — re-valida que las 3 preguntas existan,
// pertenezcan de verdad a la materia declarada, y sigan activas/aprobadas (nunca confía en
// lo que mandó el cliente).
export async function getPreguntasParaCorregir(preguntaIds: number[], materiaSlug: string): Promise<PreguntaCorreccionRow[]> {
  const [rows] = await pool.query<PreguntaCorreccionDbRow[]>(
    `SELECT id, tipo, respuesta_correcta FROM desafio_preguntas
     WHERE id IN (${preguntaIds.map(() => "?").join(",")}) AND materia_slug = ? AND activa = 1 AND revisado_por_admin = 1`,
    [...preguntaIds, materiaSlug],
  );
  return rows;
}

// Puerto exacto de responder_desafio.php:103-106.
export async function insertarIntento(usuarioId: number, materiaSlug: string, aciertos: number): Promise<void> {
  await pool.query<ResultSetHeader>("INSERT INTO desafio_intentos (usuario_id, materia_slug, aciertos) VALUES (?, ?, ?)", [
    usuarioId,
    materiaSlug,
    aciertos,
  ]);
}

// Puerto exacto de responder_desafio.php:111-122 — ON DUPLICATE defensivo (mismo criterio
// que el PHP real: un reset de banco entre carga y respuesta no debe romper por la UNIQUE KEY).
export async function marcarPreguntasVistas(usuarioId: number, preguntaIds: [number, number, number]): Promise<void> {
  await pool.query(
    `INSERT INTO desafio_preguntas_vistas (usuario_id, pregunta_id) VALUES (?,?),(?,?),(?,?)
     ON DUPLICATE KEY UPDATE fecha_visto = NOW()`,
    [usuarioId, preguntaIds[0], usuarioId, preguntaIds[1], usuarioId, preguntaIds[2]],
  );
}

// Puerto exacto de responder_desafio.php:131-139 — UPSERT atómico (evita el race condition
// de leer nivel_actual y escribirlo en 2 pasos separados), tope [1,3].
export async function actualizarProgreso(usuarioId: number, materiaSlug: string, delta: number): Promise<void> {
  const nivelInicial = Math.max(1, Math.min(3, 2 + delta));
  await pool.query(
    `INSERT INTO desafio_progreso (usuario_id, materia_slug, nivel_actual) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE nivel_actual = LEAST(3, GREATEST(1, nivel_actual + ?))`,
    [usuarioId, materiaSlug, nivelInicial, delta],
  );
}

// Puerto exacto de responder_desafio.php:142-148.
export async function getCategoriaServicioPorMateria(materiaSlug: string): Promise<string | null> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT categoria_servicio FROM materia_categoria_map WHERE materia_slug = ?", [
    materiaSlug,
  ]);
  const fila = rows[0] as { categoria_servicio: string } | undefined;
  return fila?.categoria_servicio ?? null;
}
