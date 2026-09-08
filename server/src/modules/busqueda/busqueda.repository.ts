import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { construirCondicionTexto, esBusquedaPaes } from "../../lib/busquedaTexto.js";
import type { ApunteRow } from "../apuntes/apuntes.types.js";
import type { ServicioRow } from "../servicios/servicios.types.js";
import type { BusquedaFilters, OrdenBusqueda } from "./busqueda.types.js";

interface ServicioRowPacket extends ServicioRow, RowDataPacket {}
interface ApunteRowPacket extends ApunteRow, RowDataPacket {}
interface CountRowPacket extends RowDataPacket {
  total: number;
}
interface CategoriaRowPacket extends RowDataPacket {
  categoria: string;
}

// Puerto exacto de busqueda.php:312/316 — mismo set de campos que el motor de búsqueda
// GLOBAL real, deliberadamente distinto del set usado por /api/servicios y /api/apuntes
// (que sirven las vitrinas, no esta página — ver la nota gemela en esos repositorios).
const CAMPOS_TEXTO_SERVICIO = ["s.titulo", "s.descripcion", "s.categoria", "s.materia", "s.asignatura", "s.area"];
const CAMPOS_TEXTO_APUNTE = ["ap.titulo", "ap.descripcion", "ap.asignatura", "ap.materia"];

// Puerto exacto de busqueda.php:432-443 (LEFT JOIN alumnos, no INNER como
// servicios.repository.ts::SELECT_SERVICIO) y de su WHERE (sin s.visible=1 — busqueda.php
// nunca chequea esa columna, a propósito distinto de WHERE_VISIBLE del listado normal).
const SELECT_SERVICIO_BUSQUEDA = `
  SELECT
    s.id, s.slug, s.titulo, s.categoria, s.modalidad, s.precio, s.precio_oferta,
    s.cupos_oferta, s.oferta_termino, s.is_subvencionado, s.imagen, s.score_nubira, s.video_estado, s.es_paes,
    COALESCE(dp.institucion, a.institucion) AS institucion_maestra,
    a.id AS alumno_id, a.nombre AS nombre_tutor, a.foto_perfil,
    bi.archivo AS banco_archivo,
    (SELECT COUNT(*) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS total_votos,
    (SELECT AVG(v.calificacion) FROM valoraciones v WHERE v.servicio_id = s.id AND v.calificacion > 0 AND v.rol_evaluado = 'vendedor') AS rating_promedio
  FROM servicios s
  LEFT JOIN alumnos a ON s.alumno_id = a.id
  LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
  LEFT JOIN banco_imagenes bi ON bi.id = s.imagen_banco_id
`;
const WHERE_SERVICIOS_BUSQUEDA = "WHERE s.estado = 'aprobado' AND a.bloqueado = 0";

// Puerto exacto de busqueda.php:458-465 (LEFT JOIN alumnos, institucion con solo 2
// niveles de COALESCE — sin el NULLIF(ap.institucion,'') que sí tiene apuntes.repository.ts,
// porque busqueda.php no lo tiene tampoco).
const SELECT_APUNTE_BUSQUEDA = `
  SELECT
    ap.id, ap.titulo, ap.precio, ap.descripcion, ap.fecha_subida, ap.portada, ap.preview, ap.archivo,
    COALESCE(dp.institucion, a.institucion) AS institucion,
    ap.descargas AS ventas_totales,
    ap.promo_gratis, ap.promo_limite, ap.promo_contador
  FROM apuntes ap
  LEFT JOIN alumnos a ON ap.id_alumno = a.id
  LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
`;
const WHERE_APUNTES_BUSQUEDA = "WHERE ap.publico = 1 AND a.bloqueado = 0";

// Puerto exacto de busqueda.php:229-245 — mapa_ordenes / mapa_ordenes_apuntes. El RAND()
// con semilla de bucket de 30 min es intencional (no un residuo): mismo trade-off ya
// aceptado en /api/servicios de perder este shuffle NO aplica acá, porque esta pieza es
// un puerto fiel de busqueda.php específicamente, no del listado general.
function ordenServicios(orden: OrdenBusqueda): string {
  switch (orden) {
    case "precio_asc":
      return "s.precio ASC";
    case "precio_desc":
      return "s.precio DESC";
    case "calificacion":
      return "s.score_nubira DESC, RAND(FLOOR(UNIX_TIMESTAMP()/1800)), rating_promedio DESC, total_votos DESC";
    default:
      return "s.score_nubira DESC, RAND(FLOOR(UNIX_TIMESTAMP()/1800)), rating_promedio DESC, s.precio DESC, s.id DESC";
  }
}

function ordenApuntes(orden: OrdenBusqueda): string {
  switch (orden) {
    case "precio_asc":
      return "ap.precio ASC";
    case "precio_desc":
      return "ap.precio DESC";
    case "calificacion":
      return "ap.descargas DESC";
    default:
      return "ap.id DESC";
  }
}

interface CondicionesBusqueda {
  where: string;
  params: Array<string | number>;
}

// excluirCategoria=true arma la misma combinación que $sql_extra_s_facet en busqueda.php
// (todos los filtros MENOS categoría) — para que el facet de categorías-con-resultados no
// se auto-excluya la categoría ya elegida.
function condicionesServicios(f: BusquedaFilters, excluirCategoria: boolean): CondicionesBusqueda {
  const params: Array<string | number> = [];
  let where = f.q.length > 1 ? construirCondicionTexto(f.q, CAMPOS_TEXTO_SERVICIO, params) : "1=1";

  if (esBusquedaPaes(f.q)) {
    where = `(${where}) OR s.es_paes = 1 OR s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ?`;
    params.push("%paes%", "%paes%", "%paes%");
  }

  let extra = "";
  if (!excluirCategoria && f.categoria) {
    extra += " AND s.categoria = ?";
    params.push(f.categoria);
  }
  if (f.precioMin !== null) {
    extra += " AND s.precio >= ?";
    params.push(f.precioMin);
  }
  if (f.precioMax !== null) {
    extra += " AND s.precio <= ?";
    params.push(f.precioMax);
  }
  if (f.video) {
    extra += " AND s.video_estado = 'aprobado'";
  }

  return { where: `${WHERE_SERVICIOS_BUSQUEDA} AND (${where})${extra}`, params };
}

function condicionesApuntes(f: BusquedaFilters): CondicionesBusqueda {
  const params: Array<string | number> = [];
  let where = f.q.length > 1 ? construirCondicionTexto(f.q, CAMPOS_TEXTO_APUNTE, params) : "1=1";

  if (esBusquedaPaes(f.q)) {
    where = `(${where}) OR ap.nivel_academico = 'paes' OR ap.titulo LIKE ? OR ap.descripcion LIKE ? OR ap.asignatura LIKE ? OR ap.categoria LIKE ?`;
    params.push("%paes%", "%paes%", "%paes%", "%paes%");
  }

  let extra = "";
  if (f.precioMin !== null) {
    extra += " AND ap.precio >= ?";
    params.push(f.precioMin);
  }
  if (f.precioMax !== null) {
    extra += " AND ap.precio <= ?";
    params.push(f.precioMax);
  }

  return { where: `${WHERE_APUNTES_BUSQUEDA} AND (${where})${extra}`, params };
}

export async function countServiciosBusqueda(f: BusquedaFilters): Promise<number> {
  const { where, params } = condicionesServicios(f, false);
  const [rows] = await pool.query<CountRowPacket[]>(
    `SELECT COUNT(*) AS total FROM servicios s LEFT JOIN alumnos a ON s.alumno_id = a.id ${where}`,
    params,
  );
  return rows[0]?.total ?? 0;
}

export async function countApuntesBusqueda(f: BusquedaFilters): Promise<number> {
  const { where, params } = condicionesApuntes(f);
  const [rows] = await pool.query<CountRowPacket[]>(
    `SELECT COUNT(*) AS total FROM apuntes ap LEFT JOIN alumnos a ON ap.id_alumno = a.id ${where}`,
    params,
  );
  return rows[0]?.total ?? 0;
}

// Puerto exacto de busqueda.php:414-427 — categorías con >=1 resultado real bajo los
// filtros actuales (sin la categoría misma).
export async function getCategoriasConResultadosBusqueda(f: BusquedaFilters): Promise<string[]> {
  const { where, params } = condicionesServicios(f, true);
  const [rows] = await pool.query<CategoriaRowPacket[]>(
    `SELECT s.categoria, COUNT(*) AS total FROM servicios s LEFT JOIN alumnos a ON s.alumno_id = a.id ${where} GROUP BY s.categoria`,
    params,
  );
  return rows.map((r) => r.categoria);
}

export async function searchServiciosBusqueda(f: BusquedaFilters, limit: number, offset: number): Promise<ServicioRow[]> {
  const { where, params } = condicionesServicios(f, false);
  const [rows] = await pool.query<ServicioRowPacket[]>(
    `${SELECT_SERVICIO_BUSQUEDA} ${where} ORDER BY ${ordenServicios(f.orden)} LIMIT ? OFFSET ?`,
    [...params, limit, offset],
  );
  return rows;
}

export async function searchApuntesBusqueda(f: BusquedaFilters, limit: number, offset: number): Promise<ApunteRow[]> {
  const { where, params } = condicionesApuntes(f);
  const [rows] = await pool.query<ApunteRowPacket[]>(
    `${SELECT_APUNTE_BUSQUEDA} ${where} ORDER BY ${ordenApuntes(f.orden)} LIMIT ? OFFSET ?`,
    [...params, limit, offset],
  );
  return rows;
}

// Puerto exacto de busqueda.php:478-487 — sensor de búsqueda sin resultados.
export async function insertBusquedaFallida(termino: string, usuarioId: number): Promise<void> {
  await pool.query("INSERT INTO busquedas_fallidas (termino, usuario_id, fecha) VALUES (?, ?, NOW())", [termino, usuarioId]);
}
