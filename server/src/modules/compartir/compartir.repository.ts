import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { DatosPreguntasCompartir, FormatoShare, MateriaCompartir, OpcionLetra, PreguntaCompartir } from "./compartir.types.js";

export async function getMateriaActiva(slug: string): Promise<MateriaCompartir | null> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT slug, nombre FROM materias WHERE slug = ? AND activa = 1 LIMIT 1", [slug]);
  return (rows[0] as MateriaCompartir) ?? null;
}

// Puerto de app/track_share.php (tipo='desafio') — tabla real ya existía con filas de
// producción (confirmado con SELECT antes de tocar nada), ver
// sql/pendientes/shares_desafio_fase1.sql.
export async function registrarShareDesafio(materiaSlug: string, formato: FormatoShare, ip: string | null, userAgent: string | null): Promise<void> {
  await pool.query("INSERT INTO shares_desafio (materia_slug, formato, ip, user_agent) VALUES (?, ?, ?, ?)", [materiaSlug, formato, ip, userAgent]);
}

interface PreguntaCompartirDbRow extends RowDataPacket {
  id: number;
  materia_slug: string;
  tipo: string;
  enunciado: string;
  opcion_a: string | null;
  opcion_b: string | null;
  opcion_c: string | null;
  opcion_d: string | null;
}

// Puerto exacto de nb_datos_preguntas_desafio() (imagen_compartir_desafio.php:158-203) —
// exige exactamente 3 ids positivos distintos, las 3 filas existen/activas/revisadas, Y
// las 3 comparten la MISMA materia (evita una card con badge de materia incoherente).
// respuesta_correcta NUNCA se selecciona acá — mismo criterio de seguridad que
// cargar_desafio.php: esta ruta jamás debe poder filtrar cuál opción es la correcta.
export async function getPreguntasParaCompartir(ids: number[]): Promise<DatosPreguntasCompartir | null> {
  if (ids.length !== 3 || new Set(ids).size !== 3 || ids.some((id) => !Number.isInteger(id) || id <= 0)) {
    return null;
  }

  const [rows] = await pool.query<PreguntaCompartirDbRow[]>(
    `SELECT id, materia_slug, tipo, enunciado, opcion_a, opcion_b, opcion_c, opcion_d
     FROM desafio_preguntas
     WHERE id IN (?, ?, ?) AND activa = 1 AND revisado_por_admin = 1`,
    ids,
  );
  if (rows.length !== 3) return null;

  const porId = new Map(rows.map((r) => [r.id, r]));
  const slugsUnicos = new Set(rows.map((r) => r.materia_slug));
  if (slugsUnicos.size !== 1) return null;

  const materia = await getMateriaActiva(rows[0]!.materia_slug);
  if (!materia) return null;

  const preguntas: PreguntaCompartir[] = ids.map((id) => {
    const row = porId.get(id)!;
    const opciones: PreguntaCompartir["opciones"] = {};
    const mapaCols: Record<OpcionLetra, string | null> = { a: row.opcion_a, b: row.opcion_b, c: row.opcion_c, d: row.opcion_d };
    for (const letra of ["a", "b", "c", "d"] as OpcionLetra[]) {
      const valor = mapaCols[letra];
      if (valor !== null && valor !== "") opciones[letra] = valor;
    }
    return { id: row.id, tipo: row.tipo, enunciado: row.enunciado, opciones };
  });

  return { materia, preguntas };
}
