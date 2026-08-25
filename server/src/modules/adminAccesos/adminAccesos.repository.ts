import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface TraficoRow extends RowDataPacket {
  usuario_id: number;
  ip_usuario: string | null;
  ultima_actividad: Date;
  total_acciones: number;
  ultima_url: string | null;
  ultima_accion_txt: string | null;
  nombre: string | null;
  foto_perfil: string | null;
  institucion: string | null;
  correo: string | null;
}
interface ContadoresRow extends RowDataPacket {
  alumnos: number;
  invitados: number;
  bots: number;
}

// Puerto exacto de admin_accesos_vitrina.php:313-334 (tab "Tráfico Real"). El PHP real relaja
// ONLY_FULL_GROUP_BY a nivel de sesión MySQL antes de correr la query (selecciona ip_usuario
// sin agregarlo ni incluirlo en el GROUP BY — MySQL toma un valor arbitrario del grupo, sin
// impacto real ya que solo es un dato de exhibición). Node usa un pool de conexiones
// compartidas (a diferencia de PHP, 1 conexión por request) — se relaja sql_mode SOLO en la
// conexión tomada del pool para esta query puntual y se restaura antes de devolverla, para no
// dejar esa relajación pegada en una conexión que el pool reutilice después para otra query.
export async function listarTrafico(): Promise<{ usuarios: TraficoRow[]; contadores: ContadoresRow }> {
  const conn = await pool.getConnection();
  let usuarios: TraficoRow[];
  try {
    await conn.query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    const [rows] = await conn.query<TraficoRow[]>(
      `SELECT h1.usuario_id, h1.ip_usuario, h1.ultima_actividad, h1.total_acciones,
              h2.url as ultima_url, h2.accion as ultima_accion_txt,
              a.nombre, a.foto_perfil, a.institucion, a.correo
       FROM (
           SELECT IFNULL(usuario_id, 0) as usuario_id, ip_usuario, MAX(fecha) as ultima_actividad, COUNT(id) as total_acciones
           FROM historial_actividad
           WHERE fecha >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND es_bot = 0
           GROUP BY IFNULL(usuario_id, 0), CASE WHEN IFNULL(usuario_id, 0) = 0 THEN ip_usuario ELSE '1' END
       ) h1
       LEFT JOIN historial_actividad h2 ON
            IFNULL(h2.usuario_id, 0) = h1.usuario_id AND
            (h1.usuario_id != 0 OR h2.ip_usuario = h1.ip_usuario) AND
            h2.fecha = h1.ultima_actividad AND
            h2.es_bot = 0
       LEFT JOIN alumnos a ON h1.usuario_id = a.id
       ORDER BY h1.ultima_actividad DESC LIMIT 150`,
    );
    usuarios = rows;
    await conn.query("SET SESSION sql_mode=@@GLOBAL.sql_mode");
  } finally {
    conn.release();
  }

  const [contadorRows] = await pool.query<ContadoresRow[]>(
    `SELECT
        SUM(CASE WHEN es_bot = 0 AND usuario_id IS NOT NULL AND usuario_id > 0 THEN 1 ELSE 0 END) as alumnos,
        SUM(CASE WHEN es_bot = 0 AND (usuario_id IS NULL OR usuario_id = 0) THEN 1 ELSE 0 END) as invitados,
        SUM(CASE WHEN es_bot = 1 THEN 1 ELSE 0 END) as bots
     FROM historial_actividad
     WHERE fecha >= DATE_SUB(NOW(), INTERVAL 14 DAY)`,
  );

  return { usuarios, contadores: contadorRows[0] ?? ({ alumnos: 0, invitados: 0, bots: 0 } as ContadoresRow) };
}

interface BotRow extends RowDataPacket {
  ip_usuario: string;
  user_agent: string | null;
  total_hits: number;
  urls_unicas: number;
  ultima_visita: Date;
  primera_visita: Date;
}
interface StatsBotsRow extends RowDataPacket {
  total_eventos: number;
  ips_unicas: number;
  bots_unicos: number;
}

// Puerto exacto de admin_accesos_vitrina.php:287-308 (tab "Bots / Crawlers").
export async function listarBots(): Promise<{ bots: BotRow[]; stats: StatsBotsRow }> {
  const [bots] = await pool.query<BotRow[]>(
    `SELECT ip_usuario, user_agent, COUNT(id) as total_hits, MAX(fecha) as ultima_visita, MIN(fecha) as primera_visita,
            COUNT(DISTINCT url) as urls_unicas
     FROM historial_actividad
     WHERE es_bot = 1 AND fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY ip_usuario, user_agent
     ORDER BY total_hits DESC, ultima_visita DESC
     LIMIT 100`,
  );
  const [statsRows] = await pool.query<StatsBotsRow[]>(
    `SELECT COUNT(id) as total_eventos, COUNT(DISTINCT ip_usuario) as ips_unicas, COUNT(DISTINCT user_agent) as bots_unicos
     FROM historial_actividad WHERE es_bot = 1 AND fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)`,
  );
  return { bots, stats: statsRows[0] ?? ({ total_eventos: 0, ips_unicas: 0, bots_unicos: 0 } as StatsBotsRow) };
}

interface PaginaRow extends RowDataPacket {
  url: string;
  hits: number;
  uniques: number;
}
interface TotalRow extends RowDataPacket {
  total: number;
}

// Puerto exacto de admin_accesos_vitrina.php:265-274 (tab "Top Páginas").
export async function listarPaginas(): Promise<{ paginas: PaginaRow[]; totalHits: number }> {
  const [paginas] = await pool.query<PaginaRow[]>(
    `SELECT url, COUNT(*) AS hits, COUNT(DISTINCT COALESCE(usuario_id, ip_usuario)) AS uniques
     FROM historial_actividad
     WHERE es_bot = 0 AND fecha > DATE_SUB(NOW(), INTERVAL 14 DAY)
     GROUP BY url ORDER BY hits DESC LIMIT 50`,
  );
  const [totalRows] = await pool.query<TotalRow[]>(
    "SELECT COUNT(*) as total FROM historial_actividad WHERE es_bot = 0 AND fecha > DATE_SUB(NOW(), INTERVAL 14 DAY)",
  );
  return { paginas, totalHits: totalRows[0]?.total ?? 0 };
}

interface FallidaRow extends RowDataPacket {
  termino: string;
  total_intentos: number;
  ultima_busqueda: Date;
}

// Puerto exacto de admin_accesos_vitrina.php:279-282 (tab "Búsquedas Fallidas").
export async function listarFallidas(): Promise<FallidaRow[]> {
  const [rows] = await pool.query<FallidaRow[]>(
    "SELECT termino, COUNT(*) as total_intentos, MAX(fecha) as ultima_busqueda FROM busquedas_fallidas GROUP BY termino ORDER BY total_intentos DESC, ultima_busqueda DESC LIMIT 50",
  );
  return rows;
}

// Puerto exacto del bloque "Acciones POST" (admin_accesos_vitrina.php:46-58).
export async function eliminarEventos(ids: number[]): Promise<number> {
  if (ids.length === 0) return 0;
  const conn = await pool.getConnection();
  try {
    let afectados = 0;
    for (const id of ids) {
      const [res] = await conn.query<ResultSetHeader>("DELETE FROM historial_actividad WHERE id = ?", [id]);
      afectados += res.affectedRows;
    }
    return afectados;
  } finally {
    conn.release();
  }
}

export async function purgarBotsAntiguos(): Promise<number> {
  const [res] = await pool.query<ResultSetHeader>("DELETE FROM historial_actividad WHERE es_bot = 1 AND fecha < DATE_SUB(NOW(), INTERVAL 30 DAY)");
  return res.affectedRows;
}

interface ExportRow extends RowDataPacket {
  id: number;
  usuario_id: number | null;
  nombre: string | null;
  accion: string;
  detalle: string | null;
  url: string | null;
  ip_usuario: string;
  es_bot: number | null;
  user_agent: string | null;
  fecha: Date;
}

export interface FiltrosExport {
  uid: number | null;
  fecha: string | null;
  incluirBots: boolean;
}

// Puerto exacto de admin_accesos_vitrina.php:66-119 (exportación CSV).
export async function listarParaExportar(f: FiltrosExport): Promise<ExportRow[]> {
  let sql = "SELECT h.*, a.nombre FROM historial_actividad h LEFT JOIN alumnos a ON h.usuario_id = a.id WHERE 1=1";
  const params: (string | number)[] = [];

  if (!f.incluirBots) sql += " AND h.es_bot = 0";

  if (f.uid !== null) {
    if (f.uid === 0) {
      sql += " AND (h.usuario_id IS NULL OR h.usuario_id = 0)";
    } else {
      sql += " AND h.usuario_id = ?";
      params.push(f.uid);
    }
  }
  if (f.fecha) {
    sql += " AND DATE(h.fecha) = ?";
    params.push(f.fecha);
  }
  sql += " ORDER BY h.id DESC";

  const [rows] = await pool.query<ExportRow[]>(sql, params);
  return rows;
}

// ============================================================
// VISTA DETALLE (usuario registrado o invitado por IP)
// ============================================================

interface StatsDetalleRow extends RowDataPacket {
  total_acciones: number;
  max_f: Date | null;
  min_f: Date | null;
  total_ips: number;
  fue_bot: number;
  urls_unicas: number;
}
interface AlumnoRow extends RowDataPacket {
  nombre: string | null;
  correo: string | null;
  foto_perfil: string | null;
}
interface AccionFavRow extends RowDataPacket {
  accion: string;
}
interface IpRow extends RowDataPacket {
  ip_usuario: string;
}
interface ReferrerRow extends RowDataPacket {
  referrer: string | null;
  utm_source: string | null;
}
interface ConversionRow extends RowDataPacket {
  primer_contacto: Date | null;
  primer_apunte: Date | null;
}
interface HistorialRow extends RowDataPacket {
  id: number;
  accion: string;
  detalle: string | null;
  url: string | null;
  ip_usuario: string | null;
  fecha: Date;
  es_bot: number;
}

const COLUMNAS_ORDEN_VALIDAS = new Set(["fecha", "accion", "id"]);

export interface DetalleParams {
  usuarioId: number;
  filtroIp: string | null;
  col: string;
  ord: string;
}

// Puerto exacto de admin_accesos_vitrina.php:131-260 (vista de detalle por usuario/invitado).
export async function obtenerDetalle(params: DetalleParams) {
  const { usuarioId, filtroIp, col, ord } = params;
  const isGuest = usuarioId === 0;
  const colOrden = COLUMNAS_ORDEN_VALIDAS.has(col) ? col : "id";
  const direccion = ord === "asc" ? "ASC" : "DESC";

  const [statsRows] =
    isGuest && filtroIp
      ? await pool.query<StatsDetalleRow[]>(
          "SELECT COUNT(id) as total_acciones, MAX(fecha) as max_f, MIN(fecha) as min_f, COUNT(DISTINCT ip_usuario) as total_ips, MAX(es_bot) as fue_bot, COUNT(DISTINCT url) as urls_unicas FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ?",
          [filtroIp],
        )
      : await pool.query<StatsDetalleRow[]>(
          "SELECT COUNT(id) as total_acciones, MAX(fecha) as max_f, MIN(fecha) as min_f, COUNT(DISTINCT ip_usuario) as total_ips, MAX(es_bot) as fue_bot, COUNT(DISTINCT url) as urls_unicas FROM historial_actividad WHERE usuario_id = ?",
          [usuarioId],
        );
  const stats = statsRows[0] ?? { total_acciones: 0, max_f: null, min_f: null, total_ips: 0, fue_bot: 0, urls_unicas: 0 };

  const [favRows] =
    isGuest && filtroIp
      ? await pool.query<AccionFavRow[]>(
          "SELECT accion, COUNT(id) as freq FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ? GROUP BY accion ORDER BY freq DESC LIMIT 1",
          [filtroIp],
        )
      : await pool.query<AccionFavRow[]>(
          "SELECT accion, COUNT(id) as freq FROM historial_actividad WHERE usuario_id = ? GROUP BY accion ORDER BY freq DESC LIMIT 1",
          [usuarioId],
        );
  const accionFav = favRows[0]?.accion ?? "N/A";

  let targetIp: string | null = filtroIp;
  if (!isGuest || !filtroIp) {
    const [ipRows] = await pool.query<IpRow[]>(
      "SELECT ip_usuario FROM historial_actividad WHERE usuario_id = ? AND ip_usuario IS NOT NULL AND ip_usuario != '' ORDER BY id DESC LIMIT 1",
      [usuarioId],
    );
    targetIp = isGuest ? filtroIp : (ipRows[0]?.ip_usuario ?? null);
  }

  // NOTA: la síntesis del nombre para invitado (hash MD5 corto de la IP, "Invitado XXXXX")
  // se hace en el controller (necesita crypto), no acá — repository.ts solo devuelve datos
  // crudos de BD.
  let usuarioTarget: { nombre: string | null; correo: string | null; fotoPerfil: string | null };
  if (isGuest) {
    usuarioTarget = { nombre: null, correo: null, fotoPerfil: null };
  } else {
    const [alumnoRows] = await pool.query<AlumnoRow[]>("SELECT nombre, correo, foto_perfil FROM alumnos WHERE id = ?", [usuarioId]);
    const a = alumnoRows[0];
    usuarioTarget = { nombre: a?.nombre ?? "Usuario Desconocido", correo: a?.correo ?? null, fotoPerfil: a?.foto_perfil ?? null };
  }

  let historial: HistorialRow[];
  if (isGuest) {
    const limit = 500;
    [historial] = filtroIp
      ? ((await pool.query<HistorialRow[]>(
          `SELECT * FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ? ORDER BY ${colOrden} ${direccion} LIMIT ${limit}`,
          [filtroIp],
        )) as [HistorialRow[], unknown])
      : ((await pool.query<HistorialRow[]>(
          `SELECT * FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) ORDER BY ${colOrden} ${direccion} LIMIT ${limit}`,
        )) as [HistorialRow[], unknown]);
  } else {
    [historial] = (await pool.query<HistorialRow[]>(
      `SELECT * FROM historial_actividad WHERE usuario_id = ? ORDER BY ${colOrden} ${direccion} LIMIT 300`,
      [usuarioId],
    )) as [HistorialRow[], unknown];
  }

  let primerReferrer: string | null = null;
  let primerUtm: string | null = null;
  if (!isGuest) {
    const [refRows] = await pool.query<ReferrerRow[]>(
      "SELECT referrer, utm_source FROM historial_actividad WHERE usuario_id = ? AND (referrer IS NOT NULL OR utm_source IS NOT NULL) ORDER BY fecha ASC LIMIT 1",
      [usuarioId],
    );
    if (refRows[0]) {
      primerReferrer = refRows[0].referrer;
      primerUtm = refRows[0].utm_source;
    }
  } else if (filtroIp) {
    const [refRows] = await pool.query<ReferrerRow[]>(
      "SELECT referrer, utm_source FROM historial_actividad WHERE (usuario_id IS NULL OR usuario_id = 0) AND ip_usuario = ? AND (referrer IS NOT NULL OR utm_source IS NOT NULL) ORDER BY fecha ASC LIMIT 1",
      [filtroIp],
    );
    if (refRows[0]) {
      primerReferrer = refRows[0].referrer;
      primerUtm = refRows[0].utm_source;
    }
  }

  let conv: ConversionRow = { primer_contacto: null, primer_apunte: null } as ConversionRow;
  if (!isGuest) {
    const [convRows] = await pool.query<ConversionRow[]>(
      "SELECT MIN(CASE WHEN accion = 'CONTACTO' THEN fecha END) as primer_contacto, MIN(CASE WHEN accion = 'PUBLICAR_APUNTE' THEN fecha END) as primer_apunte FROM historial_actividad WHERE usuario_id = ?",
      [usuarioId],
    );
    conv = convRows[0] ?? conv;
  }

  const diasDesdePrimera = stats.min_f ? Math.floor((Date.now() - new Date(stats.min_f).getTime()) / 86_400_000) : 0;
  const online = stats.max_f ? Date.now() - new Date(stats.max_f).getTime() < 300_000 : false;

  return {
    stats,
    accionFav,
    targetIp,
    usuarioTarget,
    historial,
    primerReferrer,
    primerUtm,
    conv,
    diasDesdePrimera,
    online,
    isGuest,
  };
}
