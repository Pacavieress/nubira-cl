import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { SegmentoAviso, UsuarioBusqueda } from "./adminAvisos.types.js";

// total/destinatarios vienen como STRING desde mysql2 (COUNT/SUM sin CAST, mismo gotcha
// documentado en adminComprasApuntes) — el controller los convierte con Number().
interface StatsRow extends RowDataPacket {
  total: string | null;
  destinatarios: string | null;
}

interface CampanaRow extends RowDataPacket {
  id: number;
  titulo: string;
  mensaje: string;
  tipo: string;
  segmento: string;
  total_destinatarios: number;
  fecha_creacion: Date;
  leidos: string;
}

interface ImagenRow extends RowDataPacket {
  campana_id: number;
  archivo: string;
}

interface LectorRow extends RowDataPacket {
  nombre: string;
  institucion: string | null;
  fecha_leido: Date;
}

// Puerto exacto de admin_avisos.php:15 (métricas globales). COUNT(*) sin GROUP BY siempre
// devuelve exactamente 1 fila.
export async function getStats(): Promise<StatsRow> {
  const [rows] = await pool.query<StatsRow[]>(
    "SELECT COUNT(*) total, SUM(total_destinatarios) destinatarios FROM avisos_campanas",
  );
  return rows[0] as StatsRow;
}

// Puerto exacto de admin_avisos.php:19-26 (mismo LIMIT 50, mismo ORDER BY, mismo subquery
// de leídos por campaña).
export async function listarCampanas(): Promise<CampanaRow[]> {
  const [rows] = await pool.query<CampanaRow[]>(
    `SELECT c.*,
            (SELECT COUNT(*) FROM avisos_admin WHERE campana_id = c.id AND leido = 1) AS leidos
     FROM avisos_campanas c
     ORDER BY c.fecha_creacion DESC
     LIMIT 50`,
  );
  return rows;
}

// Imágenes de las 50 campañas listadas, en un solo round-trip (admin_obtener_campana.php
// hace esta misma query pero campaña por campaña bajo demanda; acá se trae todo de una vez
// porque el listado ya muestra las 50 campañas completas, sin acordeón de carga diferida).
export async function listarImagenesDeCampanas(campanaIds: number[]): Promise<ImagenRow[]> {
  if (campanaIds.length === 0) return [];
  const [rows] = await pool.query<ImagenRow[]>(
    `SELECT campana_id, archivo FROM avisos_imagenes WHERE campana_id IN (?) ORDER BY campana_id ASC, orden ASC`,
    [campanaIds],
  );
  return rows;
}

// Puerto exacto de admin_avisos_detalle.php:18-25 (mismo LIMIT 500, mismo filtro leido=1).
export async function listarLectores(campanaId: number): Promise<LectorRow[]> {
  const [rows] = await pool.query<LectorRow[]>(
    `SELECT a.nombre, a.institucion, av.fecha_leido
     FROM avisos_admin av
     JOIN alumnos a ON a.id = av.destino_id
     WHERE av.campana_id = ? AND av.leido = 1 AND av.fecha_leido IS NOT NULL
     ORDER BY av.fecha_leido DESC
     LIMIT 500`,
    [campanaId],
  );
  return rows;
}

// Puerto exacto del subquery de "tutor" de admin_enviar_aviso_masivo.php:85-89 — usuario con
// al menos 1 publicación aprobada (servicio o apunte).
const SQL_TUTORES_IDS = `(
    SELECT DISTINCT alumno_id FROM servicios WHERE estado = 'aprobado' AND COALESCE(visible, 1) = 1
    UNION
    SELECT DISTINCT id_alumno FROM apuntes WHERE estado = 'aprobado' AND bloqueado = 0 AND COALESCE(visible, 1) = 1
)`;

interface IdRow extends RowDataPacket {
  id: number;
}

// Puerto exacto de admin_enviar_aviso_masivo.php:91-126 — resuelve la lista de IDs
// destinatarios según segmento. 'usuario' se valida aparte (ver existeUsuarioValido) antes
// de llamar acá; si llega sin usuarioId válido, esta función simplemente no lo encuentra
// (WHERE id = ? con ? = 0 nunca matchea un alumno real).
export async function resolverDestinatarios(segmento: SegmentoAviso, usuarioId: number | null): Promise<number[]> {
  let sql: string;
  const params: number[] = [];

  switch (segmento) {
    case "tutores":
      sql = `SELECT id FROM alumnos WHERE id IN ${SQL_TUTORES_IDS} AND rol != 'admin' AND visible = 1 AND bloqueado = 0`;
      break;
    case "no_tutores":
      sql = `SELECT id FROM alumnos WHERE id NOT IN ${SQL_TUTORES_IDS} AND rol != 'admin' AND visible = 1 AND bloqueado = 0`;
      break;
    case "usuario":
      sql = "SELECT id FROM alumnos WHERE id = ?";
      params.push(usuarioId ?? 0);
      break;
    case "todos":
    default:
      sql = "SELECT id FROM alumnos WHERE rol != 'admin' AND visible = 1 AND bloqueado = 0";
      break;
  }

  const [rows] = await pool.query<IdRow[]>(sql, params);
  return rows.map((r) => r.id);
}

// Puerto exacto de la validación de admin_enviar_aviso_masivo.php:108.
export async function existeUsuarioValido(usuarioId: number): Promise<boolean> {
  const [rows] = await pool.query<IdRow[]>("SELECT id FROM alumnos WHERE id = ? AND rol != 'admin' AND visible = 1 AND bloqueado = 0", [usuarioId]);
  return rows.length > 0;
}

interface UsuarioBusquedaRow extends UsuarioBusqueda, RowDataPacket {}

// Puerto exacto de admin_buscar_usuarios.php:18-27 (mismo LIMIT 10).
export async function buscarUsuarios(q: string): Promise<UsuarioBusqueda[]> {
  const like = `%${q}%`;
  const [rows] = await pool.query<UsuarioBusquedaRow[]>(
    `SELECT id, nombre, correo, COALESCE(institucion, '') AS institucion
     FROM alumnos
     WHERE (nombre LIKE ? OR correo LIKE ?) AND rol != 'admin' AND visible = 1 AND bloqueado = 0
     ORDER BY nombre ASC
     LIMIT 10`,
    [like, like],
  );
  return rows;
}

// Puerto exacto de admin_enviar_aviso_masivo.php:148-176 (transacción campaña + N avisos),
// SIN la sección 7c de imágenes (fuera de alcance de esta pieza — ver nota en
// adminAvisos.types.ts). Devuelve el id de la campaña recién creada.
export async function crearCampanaConDestinatarios(
  adminId: number,
  titulo: string,
  mensaje: string,
  tipo: string,
  segmento: string,
  destinatarios: number[],
): Promise<number> {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    const [resCampana] = await conn.query<ResultSetHeader>(
      `INSERT INTO avisos_campanas (admin_id, titulo, mensaje, tipo, segmento, total_destinatarios)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [adminId, titulo, mensaje, tipo, segmento, destinatarios.length],
    );
    const campanaId = resCampana.insertId;

    for (const destinoId of destinatarios) {
      await conn.query(
        `INSERT INTO avisos_admin (admin_id, destino_id, mensaje, tipo, campana_id)
         VALUES (?, ?, ?, ?, ?)`,
        [adminId, destinoId, mensaje, tipo, campanaId],
      );
    }

    await conn.commit();
    return campanaId;
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}
