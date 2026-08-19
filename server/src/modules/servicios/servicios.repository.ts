import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type {
  SearchServiciosFilters,
  SearchServiciosResult,
  ServicioDetalleRow,
  ServicioRow,
} from "./servicios.types.js";

interface ServicioRowPacket extends ServicioRow, RowDataPacket {}
interface ServicioDetalleRowPacket extends ServicioDetalleRow, RowDataPacket {}

// Mismo JOIN y mismo patrón de rating ya corregidos en PHP en la Fase 0.5
// (vitrina.php, detalle_servicio.php, cargar_vistos.php, render_card.php,
// cargar_fila_inteligente.php): fuente de verdad = valoraciones, NO
// contratos.calificacion_comprador. No reintroducir el patrón viejo acá.
const SELECT_SERVICIO = `
  SELECT
    s.id, s.slug, s.titulo, s.categoria, s.modalidad, s.precio, s.precio_oferta,
    s.cupos_oferta, s.oferta_termino, s.imagen, s.score_nubira, s.video_estado, s.es_paes,
    COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
    a.id AS alumno_id, a.nombre AS nombre_tutor, a.foto_perfil,
    bi.archivo AS banco_archivo,
    (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS total_votos,
    (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS rating_promedio
  FROM servicios s
  INNER JOIN alumnos a ON s.alumno_id = a.id
  LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
  LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
`;

const WHERE_VISIBLE = `WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0`;

// Orden DETERMINÍSTICO (Fase 4, decisión 1 ya aprobada) — sin RAND()/SHA2() como usa PHP.
// s.id DESC como desempate final garantiza que la misma página siempre devuelva el mismo
// resultado, sin importar cuándo se pida — a costa de perder el "shuffle" de fairness que
// tiene la vitrina de PHP entre servicios de score similar (cambio de producto consciente).
const ORDER_DETERMINISTICO = `ORDER BY s.score_nubira DESC, total_votos DESC, rating_promedio DESC, s.id DESC`;

export async function searchServiciosAprobados(
  filters: SearchServiciosFilters,
): Promise<SearchServiciosResult> {
  const conditions: string[] = [];
  const params: Array<string | number> = [];

  if (filters.categoria) {
    conditions.push("s.categoria = ?");
    params.push(filters.categoria);
  }
  if (filters.modalidad) {
    conditions.push("s.modalidad = ?");
    params.push(filters.modalidad);
  }
  if (filters.institucion) {
    conditions.push("COALESCE(dp.institucion, a.institucion) = ?");
    params.push(filters.institucion);
  }
  if (filters.q) {
    conditions.push("(s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ?)");
    const like = `%${filters.q}%`;
    params.push(like, like, like);
  }
  if (filters.alumnoId !== undefined) {
    conditions.push("s.alumno_id = ?");
    params.push(filters.alumnoId);
  }

  const whereExtra = conditions.length > 0 ? `AND ${conditions.join(" AND ")}` : "";
  const offset = (filters.page - 1) * filters.limit;
  // Pedimos limit+1: si vuelven más filas que el limit pedido, sabemos que hay
  // página siguiente sin necesitar un COUNT(*) aparte (mismo truco que cargar_servicios.php).
  const fetchLimit = filters.limit + 1;

  const [rows] = await pool.query<ServicioRowPacket[]>(
    `${SELECT_SERVICIO} ${WHERE_VISIBLE} ${whereExtra} ${ORDER_DETERMINISTICO} LIMIT ? OFFSET ?`,
    [...params, fetchLimit, offset],
  );

  const hayMas = rows.length > filters.limit;
  return { rows: hayMas ? rows.slice(0, filters.limit) : rows, hayMas };
}

export async function getServicioById(id: number): Promise<ServicioRow | null> {
  const [rows] = await pool.query<ServicioRowPacket[]>(
    `${SELECT_SERVICIO} ${WHERE_VISIBLE} AND s.id = ? LIMIT 1`,
    [id],
  );
  return rows[0] ?? null;
}

// SELECT separado (Fase 6): trae columnas de detalle (descripción, ubicación, horario...)
// que el listado NO necesita — evita cargarlas en cada card de /api/servicios.
const SELECT_SERVICIO_DETALLE = `
  SELECT
    s.id, s.slug, s.titulo, s.categoria, s.modalidad, s.precio, s.precio_oferta,
    s.cupos_oferta, s.oferta_termino, s.imagen, s.score_nubira, s.video_estado, s.es_paes,
    s.descripcion, s.ubicacion, s.duracion_minutos, s.horarios_json, s.nivel,
    s.materia, s.area, s.asignatura,
    COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
    a.id AS alumno_id, a.nombre AS nombre_tutor, a.foto_perfil,
    bi.archivo AS banco_archivo,
    (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS total_votos,
    (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS rating_promedio
  FROM servicios s
  INNER JOIN alumnos a ON s.alumno_id = a.id
  LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
  LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
`;

export async function getServicioDetalleById(id: number): Promise<ServicioDetalleRow | null> {
  const [rows] = await pool.query<ServicioDetalleRowPacket[]>(
    `${SELECT_SERVICIO_DETALLE} ${WHERE_VISIBLE} AND s.id = ? LIMIT 1`,
    [id],
  );
  return rows[0] ?? null;
}
