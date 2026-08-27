import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { FiltrosServiciosMarketing } from "./adminMarketingCards.types.js";

interface ServicioMarketingRow extends RowDataPacket {
  id: number;
  titulo: string;
  categoria: string | null;
  institucion: string | null;
  fecha_publicacion: Date;
  video_estado: string;
  tutor_nombre: string;
}

// Puerto exacto de admin_marketing_cards.php:41-93 (tab servicios) — mismas condiciones base
// (estado='aprobado', visible=1) + los 5 filtros opcionales, mismo ORDER BY. Se omite
// `s.institucion IS NOT NULL`/similares: el PHP real tampoco los tiene, el WHERE ya filtra
// por igualdad exacta cuando el filtro viene seteado.
export async function listarServiciosMarketing(f: FiltrosServiciosMarketing): Promise<ServicioMarketingRow[]> {
  const condiciones = ["s.estado = 'aprobado'", "COALESCE(s.visible,1) = 1"];
  const params: string[] = [];

  if (f.categoria !== "") {
    condiciones.push("s.categoria = ?");
    params.push(f.categoria);
  }
  if (f.institucion !== "") {
    condiciones.push("s.institucion = ?");
    params.push(f.institucion);
  }
  if (f.conVideo) {
    condiciones.push("s.video_estado = 'aprobado'");
  }
  if (f.fechaDesde !== "") {
    condiciones.push("s.fecha_publicacion >= ?");
    params.push(`${f.fechaDesde} 00:00:00`);
  }
  if (f.fechaHasta !== "") {
    condiciones.push("s.fecha_publicacion <= ?");
    params.push(`${f.fechaHasta} 23:59:59`);
  }

  const [rows] = await pool.query<ServicioMarketingRow[]>(
    `SELECT s.id, s.titulo, s.categoria, s.institucion, s.fecha_publicacion, s.video_estado,
            a.nombre AS tutor_nombre
     FROM servicios s
     JOIN alumnos a ON s.alumno_id = a.id
     WHERE ${condiciones.join(" AND ")}
     ORDER BY s.fecha_publicacion DESC`,
    params,
  );
  return rows;
}

interface ValorRow extends RowDataPacket {
  valor: string;
}

// Puerto exacto de admin_marketing_cards.php:106-111 — opciones de filtro independientes
// del filtro activo (para no vaciar el dropdown al filtrar), mismo criterio.
export async function listarCategoriasDisponibles(): Promise<string[]> {
  const [rows] = await pool.query<ValorRow[]>(
    "SELECT DISTINCT categoria AS valor FROM servicios WHERE estado = 'aprobado' AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC",
  );
  return rows.map((r) => r.valor);
}

export async function listarInstitucionesDisponibles(): Promise<string[]> {
  const [rows] = await pool.query<ValorRow[]>(
    "SELECT DISTINCT institucion AS valor FROM servicios WHERE estado = 'aprobado' AND institucion IS NOT NULL AND institucion != '' ORDER BY institucion ASC",
  );
  return rows.map((r) => r.valor);
}

interface NovedadRow extends RowDataPacket {
  id: number;
  titulo: string;
  cuerpo: string;
  creado_en: Date;
}

// Puerto exacto de admin_marketing_cards.php:123-133 (historial, últimas 50). La tabla
// `novedades` YA EXISTE en la base real (confirmado con datos reales antes de portar) — sin
// auto-migración CREATE TABLE IF NOT EXISTS en cada request, mismo criterio que
// adminUsuarios.types.ts documenta para las auto-migraciones del PHP real ya corridas.
export async function listarNovedades(): Promise<NovedadRow[]> {
  const [rows] = await pool.query<NovedadRow[]>("SELECT id, titulo, cuerpo, creado_en FROM novedades ORDER BY creado_en DESC LIMIT 50");
  return rows;
}

// Puerto exacto del INSERT de admin_guardar_novedad.php:56-63.
export async function crearNovedad(titulo: string, cuerpo: string): Promise<number> {
  const [res] = await pool.query<ResultSetHeader>("INSERT INTO novedades (titulo, cuerpo) VALUES (?, ?)", [titulo, cuerpo]);
  return res.insertId;
}
