import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

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
