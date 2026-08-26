import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type {
  ApunteDetalleMetricaRow,
  ApunteMetricaRow,
  OrigenStat,
  ServicioDetalleRow,
  ServicioMetricaRow,
  StatsVentanaRow,
  TipoPublicacion,
  UbicacionRow,
} from "./metricas.types.js";

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

// ============================================================================
// Detalle por publicación — puerto de metricas_detalle.php
// ============================================================================

interface ServicioDetalleDbRow extends ServicioDetalleRow, RowDataPacket {}
interface ApunteDetalleMetricaDbRow extends ApunteDetalleMetricaRow, RowDataPacket {}
interface StatsVentanaDbRow extends StatsVentanaRow, RowDataPacket {}
interface OrigenDbRow extends OrigenStat, RowDataPacket {}
interface UbicacionDbRow extends UbicacionRow, RowDataPacket {}
interface DiaRow extends RowDataPacket {
  dia: string;
  total: number;
}
interface DispositivoRow extends RowDataPacket {
  dispositivo: string;
  total: number;
}
interface ContadorRow extends RowDataPacket {
  total: number;
}

// Puerto exacto de metricas_detalle.php:31-46 — ownership en el WHERE mismo (id=? AND
// alumno_id=?/id_alumno=?), igual que el resto de este port: si la publicación no es del
// usuario o no existe, 0 filas, no un 403 explícito (mismo comportamiento que el PHP real,
// que redirige a /metricas en ambos casos sin distinguir).
export async function getServicioParaDetalleMetrica(id: number, alumnoId: number): Promise<ServicioDetalleRow | null> {
  const [rows] = await pool.query<ServicioDetalleDbRow[]>(
    `SELECT s.id, s.titulo, s.precio, s.imagen, s.imagen_banco_id, bi.archivo AS banco_archivo
     FROM servicios s
     LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
     WHERE s.id = ? AND s.alumno_id = ? AND s.estado = 'aprobado'
     LIMIT 1`,
    [id, alumnoId],
  );
  return rows[0] ?? null;
}

export async function getApunteParaDetalleMetrica(id: number, alumnoId: number): Promise<ApunteDetalleMetricaRow | null> {
  const [rows] = await pool.query<ApunteDetalleMetricaDbRow[]>(
    `SELECT id, titulo, precio, portada, archivo FROM apuntes WHERE id = ? AND id_alumno = ? AND estado = 'aprobado' LIMIT 1`,
    [id, alumnoId],
  );
  return rows[0] ?? null;
}

// mysql2 devuelve ROUND()/AVG()/SUM() como string cuando el resultado es DECIMAL (mismo
// comportamiento ya documentado para SUM() en otros repositorios de este port, ej.
// miBilletera.repository.ts) — se coacciona a number acá, en el único lugar que lee estas
// 2 queries, para que StatsVentanaRow sea number de verdad y no "number en el tipo, string
// en runtime".
function coaccionarStats(row: StatsVentanaRow | undefined): StatsVentanaRow {
  if (!row) return { total: 0, tiempo_prom: 0, pct_leyo: 0 };
  return { total: Number(row.total), tiempo_prom: Number(row.tiempo_prom), pct_leyo: Number(row.pct_leyo) };
}

// Puerto exacto de metricas_detalle.php:54-70 — mismas 3 métricas agregadas, mismo
// `fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)`.
export async function getStats30d(tipo: TipoPublicacion, id: number): Promise<StatsVentanaRow> {
  const [rows] = await pool.query<StatsVentanaDbRow[]>(
    `SELECT
       COUNT(*) as total,
       COALESCE(ROUND(AVG(tiempo_segundos)), 0) as tiempo_prom,
       COALESCE(ROUND(SUM(leyo_completo) / COUNT(*) * 100, 1), 0) as pct_leyo
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ? AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)`,
    [tipo, id],
  );
  return coaccionarStats(rows[0]);
}

// Puerto exacto de metricas_detalle.php:84-102 — mismo período de comparación (30-60 días
// atrás), misma agregación que getStats30d.
export async function getStatsPeriodoAnterior(tipo: TipoPublicacion, id: number): Promise<StatsVentanaRow> {
  const [rows] = await pool.query<StatsVentanaDbRow[]>(
    `SELECT
       COUNT(*) as total,
       COALESCE(ROUND(AVG(tiempo_segundos)), 0) as tiempo_prom,
       COALESCE(ROUND(SUM(leyo_completo) / COUNT(*) * 100, 1), 0) as pct_leyo
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ?
       AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 60 DAY)
       AND fecha_inicio <  DATE_SUB(NOW(), INTERVAL 30 DAY)`,
    [tipo, id],
  );
  return coaccionarStats(rows[0]);
}

// Puerto exacto de metricas_detalle.php:72-78 (histórico completo, sin filtro de fecha).
export async function getVisitasTotalHistorico(tipo: TipoPublicacion, id: number): Promise<number> {
  const [rows] = await pool.query<ContadorRow[]>(
    `SELECT COUNT(*) as total FROM vistas_detalle WHERE tipo = ? AND publicacion_id = ?`,
    [tipo, id],
  );
  return rows[0]?.total ?? 0;
}

// Puerto exacto de metricas_detalle.php:111-120 (visitas identificadas, base del funnel).
export async function getVisitasIdentificadas30d(tipo: TipoPublicacion, id: number): Promise<number> {
  const [rows] = await pool.query<ContadorRow[]>(
    `SELECT COUNT(DISTINCT user_id) as total FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ? AND user_id IS NOT NULL AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)`,
    [tipo, id],
  );
  return rows[0]?.total ?? 0;
}

// Puerto exacto de metricas_detalle.php:124-134 (solo servicio: cuántas de las visitas
// identificadas iniciaron un chat sobre ESTE servicio).
export async function getFunnelChatearon(servicioId: number): Promise<number> {
  const [rows] = await pool.query<ContadorRow[]>(
    `SELECT COUNT(DISTINCT user_id) as total FROM vistas_detalle
     WHERE tipo = 'servicio' AND publicacion_id = ? AND user_id IS NOT NULL
       AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       AND user_id IN (SELECT comprador_id FROM conversaciones WHERE servicio_id = ?)`,
    [servicioId, servicioId],
  );
  return rows[0]?.total ?? 0;
}

// Puerto exacto de metricas_detalle.php:136-146 (solo servicio: cuántas contrataron).
export async function getFunnelContrataron(servicioId: number): Promise<number> {
  const [rows] = await pool.query<ContadorRow[]>(
    `SELECT COUNT(DISTINCT user_id) as total FROM vistas_detalle
     WHERE tipo = 'servicio' AND publicacion_id = ? AND user_id IS NOT NULL
       AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       AND user_id IN (SELECT comprador_id FROM contratos WHERE servicio_id = ?)`,
    [servicioId, servicioId],
  );
  return rows[0]?.total ?? 0;
}

// Puerto exacto de metricas_detalle.php:148-158 (solo apunte: cuántas compraron).
export async function getFunnelCompraron(apunteId: number): Promise<number> {
  const [rows] = await pool.query<ContadorRow[]>(
    `SELECT COUNT(DISTINCT user_id) as total FROM vistas_detalle
     WHERE tipo = 'apunte' AND publicacion_id = ? AND user_id IS NOT NULL
       AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
       AND user_id IN (SELECT comprador_id FROM ventas_apuntes WHERE apunte_id = ?)`,
    [apunteId, apunteId],
  );
  return rows[0]?.total ?? 0;
}

// Puerto exacto de metricas_detalle.php:164-178 — mapa día->conteo de los últimos 30 días
// reales con datos (días sin visitas simplemente no aparecen en el resultado; el relleno a
// 30 posiciones consecutivas, incluidos los ceros, se hace en el mapper — mismo criterio
// que el `for ($i = 29; $i >= 0; $i--)` del PHP real).
export async function getVisitasPorDiaRaw(tipo: TipoPublicacion, id: number): Promise<Map<string, number>> {
  const [rows] = await pool.query<DiaRow[]>(
    // DATE_FORMAT(...,'%Y-%m-%d'), no DATE(): mysql2 devuelve DATE() como objeto Date de
    // JS (no un string), y String(esaFecha) da algo como "Wed Aug 26 2026 00:00:00 GMT...",
    // que nunca calza con las claves 'YYYY-MM-DD' que arma buildVisitasPorDia() en el
    // mapper — confirmado con un test real que fallaba en silencio (siempre 0) antes de
    // este fix. DATE_FORMAT fuerza un string real desde MySQL, sin ambigüedad de tipo.
    `SELECT DATE_FORMAT(fecha_inicio, '%Y-%m-%d') as dia, COUNT(*) as total
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ? AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY DATE_FORMAT(fecha_inicio, '%Y-%m-%d') ORDER BY dia`,
    [tipo, id],
  );
  const mapa = new Map<string, number>();
  for (const row of rows) mapa.set(row.dia, row.total);
  return mapa;
}

// Puerto exacto de metricas_detalle.php:188-205.
export async function getDispositivosRaw(tipo: TipoPublicacion, id: number): Promise<Map<string, number>> {
  const [rows] = await pool.query<DispositivoRow[]>(
    `SELECT dispositivo, COUNT(*) as total
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ? AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND dispositivo IS NOT NULL
     GROUP BY dispositivo`,
    [tipo, id],
  );
  const mapa = new Map<string, number>();
  for (const row of rows) mapa.set(row.dispositivo, row.total);
  return mapa;
}

// Puerto exacto de metricas_detalle.php:210-223 (TOP 5, sin parsear la URL todavía —
// det_parse_origen() se aplica en el mapper).
export async function getOrigenesRaw(tipo: TipoPublicacion, id: number): Promise<OrigenStat[]> {
  const [rows] = await pool.query<OrigenDbRow[]>(
    `SELECT origen, COUNT(*) as total
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ? AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND origen IS NOT NULL AND origen != ''
     GROUP BY origen ORDER BY total DESC LIMIT 5`,
    [tipo, id],
  );
  return rows;
}

// Puerto exacto de metricas_detalle.php:227-242 (TOP 5).
export async function getUbicaciones(tipo: TipoPublicacion, id: number): Promise<UbicacionRow[]> {
  const [rows] = await pool.query<UbicacionDbRow[]>(
    `SELECT ciudad, pais, COUNT(*) as visitas
     FROM vistas_detalle
     WHERE tipo = ? AND publicacion_id = ? AND fecha_inicio >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND pais IS NOT NULL
     GROUP BY pais, ciudad
     ORDER BY visitas DESC
     LIMIT 5`,
    [tipo, id],
  );
  return rows;
}
