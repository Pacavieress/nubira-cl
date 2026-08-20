import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ApunteMetricaRow, ServicioMetricaRow, TipoPublicacion } from "./metricas.types.js";

interface ServicioMetricaDbRow extends ServicioMetricaRow, RowDataPacket {}
interface ApunteMetricaDbRow extends ApunteMetricaRow, RowDataPacket {}
interface VisitasRow extends RowDataPacket {
  publicacion_id: number;
  total: number;
}

// Puerto exacto de metricas.php:25 (mismo WHERE/LIMIT/ORDER BY).
export async function getServiciosParaMetricas(alumnoId: number): Promise<ServicioMetricaRow[]> {
  const [rows] = await pool.query<ServicioMetricaDbRow[]>(
    `SELECT s.id, s.titulo, s.precio, s.imagen, s.imagen_banco_id, bi.archivo AS banco_archivo, s.fecha_publicacion
     FROM servicios s
     LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
     WHERE s.alumno_id = ? AND s.estado = 'aprobado' AND s.visible = 1
     ORDER BY s.fecha_publicacion DESC
     LIMIT 60`,
    [alumnoId],
  );
  return rows;
}

// Puerto exacto de metricas.php:38 (mismo WHERE/LIMIT/ORDER BY). `preview` se selecciona en
// el PHP real pero nunca se usa (obtenerMiniaturaApunte() la recibe como parámetro
// vestigial que ni siquiera se referencia en su cuerpo, ver el comentario de
// resolverPortadaApunte en media.ts) — no se selecciona acá.
export async function getApuntesParaMetricas(alumnoId: number): Promise<ApunteMetricaRow[]> {
  const [rows] = await pool.query<ApunteMetricaDbRow[]>(
    `SELECT id, titulo, precio, portada, archivo, fecha_subida
     FROM apuntes
     WHERE id_alumno = ? AND estado = 'aprobado' AND visible = 1
     ORDER BY fecha_subida DESC
     LIMIT 60`,
    [alumnoId],
  );
  return rows;
}

async function getVisitasPorRango(
  tipo: TipoPublicacion,
  ids: number[],
  desdeDiasAtras: number,
  hastaDiasAtras: number,
): Promise<Map<number, number>> {
  const mapa = new Map<number, number>();
  if (ids.length === 0) return mapa;

  const placeholders = ids.map(() => "?").join(",");
  const condicionFecha =
    hastaDiasAtras === 0
      ? `fecha_inicio >= DATE_SUB(NOW(), INTERVAL ${desdeDiasAtras} DAY)`
      : `fecha_inicio >= DATE_SUB(NOW(), INTERVAL ${desdeDiasAtras} DAY) AND fecha_inicio < DATE_SUB(NOW(), INTERVAL ${hastaDiasAtras} DAY)`;

  const [rows] = await pool.query<VisitasRow[]>(
    `SELECT publicacion_id, COUNT(*) as total
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id IN (${placeholders}) AND ${condicionFecha}
     GROUP BY publicacion_id`,
    [tipo, ...ids],
  );
  for (const row of rows) mapa.set(row.publicacion_id, row.total);
  return mapa;
}

// Puerto exacto de metricas.php:57-93: agregado batcheado por tipo (evita N+1), un mapa
// para los últimos 30 días y otro para el período anterior (30-60 días atrás, solo para
// la flecha de tendencia). desdeDiasAtras/hastaDiasAtras son literales de INTERVAL, no
// bind params — ambos valores están hardcodeados a 30/60 en las 2 únicas llamadas reales,
// nunca vienen de input de usuario.
export async function getVisitas30d(tipo: TipoPublicacion, ids: number[]): Promise<Map<number, number>> {
  return getVisitasPorRango(tipo, ids, 30, 0);
}

export async function getVisitasPrevias30d(tipo: TipoPublicacion, ids: number[]): Promise<Map<number, number>> {
  return getVisitasPorRango(tipo, ids, 60, 30);
}
