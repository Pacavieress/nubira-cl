import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { construirCondicionTexto, esBusquedaPaes } from "../../lib/busquedaTexto.js";
import type {
  ApunteDetalleRow,
  ApunteRow,
  SearchApuntesFilters,
  SearchApuntesResult,
} from "./apuntes.types.js";

interface ApunteRowPacket extends ApunteRow, RowDataPacket {}
interface ApunteDetalleRowPacket extends ApunteDetalleRow, RowDataPacket {}

// Puerto exacto de busqueda.php:293 — mismo set de 4 columnas que busca la página de
// búsqueda real (cargar_apuntes.php, la página de listado, busca un set distinto de 5:
// titulo/asignatura/nombre_curso/ia_keywords/categoria — ver nota de unificación en
// busquedaTexto.ts. Se prioriza el set de busqueda.php por ser el motor "real" de
// búsqueda del sitio, no el de un listado con caja de texto simple).
const CAMPOS_TEXTO_APUNTE = ["ap.titulo", "ap.descripcion", "ap.asignatura", "ap.materia"];

// Mismo criterio de visibilidad que cargar_apuntes.php:133-138. Exportada (Fase home):
// home.repository.ts la reutiliza para el carrusel de apuntes de vitrina.php en vez de
// replicar el WHERE distinto (más laxo — ni siquiera chequea visible/bloqueado) que esa
// página usa — mismo criterio de reutilizar la visibilidad ya establecida que
// tutores.controller.ts aplica con searchServiciosAprobados.
export const WHERE_VISIBLE = `WHERE ap.publico = 1 AND ap.visible = 1 AND al.visible = 1 AND al.bloqueado = 0`;

// Puerto de $cols en cargar_apuntes.php:178 — ap.descargas AS ventas_totales, mismo
// criterio de "ventas" que usa el listado real (no la fórmula distinta de vitrina.php,
// ver nota de tech-debt en CLAUDE.md sobre las 2 fórmulas de ventas_totales existentes).
// Exportada (Fase home) — mismo motivo que WHERE_VISIBLE arriba.
export const SELECT_APUNTE = `
  SELECT
    ap.id, ap.titulo, ap.precio, ap.descripcion, ap.fecha_subida,
    ap.portada, ap.preview, ap.archivo,
    COALESCE(dp.institucion, NULLIF(ap.institucion, ''), al.institucion) AS institucion,
    ap.descargas AS ventas_totales,
    ap.promo_gratis, ap.promo_limite, ap.promo_contador
  FROM apuntes ap
  JOIN alumnos al ON al.id = ap.id_alumno
  LEFT JOIN dominios_permitidos dp ON al.dominio = dp.dominio
`;

const NIVELES_VALIDOS = new Set(["universitario", "paes", "escolar"]);

function ordenSql(orden: string | undefined): string {
  switch (orden) {
    case "fecha_asc":
      return "ap.fecha_subida ASC, ap.id ASC";
    case "precio_desc":
      return "ap.precio DESC, ap.id DESC";
    case "precio_asc":
      return "ap.precio ASC, ap.id ASC";
    case "vendidos_desc":
      return "ventas_totales DESC, ap.id DESC";
    default:
      // Determinístico (mismo trade-off ya aprobado en Fase 4 para /api/servicios):
      // reemplaza el RAND($seed) de cargar_apuntes.php:173 para que la paginación por
      // offset sea confiable. Se pierde el shuffle de fairness de PHP a cambio.
      return "ap.fecha_subida DESC, ap.id DESC";
  }
}

export async function searchApuntesPublicos(filters: SearchApuntesFilters): Promise<SearchApuntesResult> {
  const conditions: string[] = [];
  const params: Array<string | number> = [];

  if (filters.nivel && NIVELES_VALIDOS.has(filters.nivel)) {
    conditions.push("ap.nivel_academico = ?");
    params.push(filters.nivel);
  }
  if (filters.precio === "gratis") {
    conditions.push("ap.precio = 0");
  } else if (filters.precio === "pagado") {
    conditions.push("ap.precio > 0");
  }
  if (filters.q) {
    let condicionQ = construirCondicionTexto(filters.q, CAMPOS_TEXTO_APUNTE, params);
    // Puerto exacto de busqueda.php:313-315 — refuerzo de PAES para apuntes: nivel
    // académico exacto o "paes" literal en 4 campos. Mismo cuidado de paréntesis que
    // servicios.repository.ts (ver comentario ahí): el bloque completo debe quedar
    // atómico para no romper la precedencia AND/OR del WHERE.
    if (esBusquedaPaes(filters.q)) {
      const likePaes = "%paes%";
      condicionQ = `(${condicionQ} OR ap.nivel_academico = 'paes' OR ap.titulo LIKE ? OR ap.descripcion LIKE ? OR ap.asignatura LIKE ? OR ap.categoria LIKE ?)`;
      params.push(likePaes, likePaes, likePaes, likePaes);
    }
    conditions.push(condicionQ);
  }
  if (filters.alumnoId !== undefined) {
    conditions.push("ap.id_alumno = ?");
    params.push(filters.alumnoId);
  }
  if (filters.materia) {
    conditions.push("ap.materia = ?");
    params.push(filters.materia);
  }

  const whereExtra = conditions.length > 0 ? `AND ${conditions.join(" AND ")}` : "";
  const offset = (filters.page - 1) * filters.limit;
  const fetchLimit = filters.limit + 1;

  const [rows] = await pool.query<ApunteRowPacket[]>(
    `${SELECT_APUNTE} ${WHERE_VISIBLE} ${whereExtra} ORDER BY ${ordenSql(filters.orden)} LIMIT ? OFFSET ?`,
    [...params, fetchLimit, offset],
  );

  const hayMas = rows.length > filters.limit;
  return { rows: hayMas ? rows.slice(0, filters.limit) : rows, hayMas };
}

// SELECT separado (mismo patrón que servicios.repository.ts Fase 6): trae columnas de
// detalle (descripción completa, publicador, tags IA...) que el listado no necesita.
//
// Institución: prioridad al.institucion > dp.institucion(dominio del alumno) > ap.institucion
// — puerto exacto de la cadena `$publicador['institucion'] ?: $publicador['institucion_dominio']
// ?: $apunte['institucion']` de ver_apunte.php:241 (institucionPublicador, lo que la página
// real muestra). Orden A PROPÓSITO DISTINTO del listado (que prioriza dp sobre ap/al) —
// no es un error, son 2 fuentes PHP distintas con prioridades distintas confirmadas
// leyendo cada archivo.
const SELECT_APUNTE_DETALLE = `
  SELECT
    ap.id, ap.titulo, ap.precio, ap.descripcion, ap.fecha_subida, ap.portada, ap.archivo,
    ap.asignatura, ap.materia, ap.nivel_academico, ap.categoria,
    COALESCE(NULLIF(al.institucion, ''), dp.institucion, NULLIF(ap.institucion, '')) AS institucion,
    ap.estado, ap.id_alumno, ap.ia_used, ap.ia_keywords,
    ap.descargas AS ventas_totales,
    ap.promo_gratis, ap.promo_limite, ap.promo_contador,
    al.nombre AS publicador_nombre, al.foto_perfil AS publicador_foto,
    al.verificacion_estado AS publicador_verificacion_estado
  FROM apuntes ap
  JOIN alumnos al ON al.id = ap.id_alumno
  LEFT JOIN dominios_permitidos dp ON al.dominio = dp.dominio
`;

// Gate = ap.estado = 'aprobado', sin excepción de dueño/admin — mismo criterio ya usado
// en servicios.repository.ts (WHERE_VISIBLE fuerza estado='aprobado' sin excepción
// tampoco). ver_apunte.php SÍ deja al dueño ver su propio apunte pendiente/rechazado
// (banner "en revisión"), pero esa excepción no se porta acá para no romper la simetría
// ya establecida con el detalle de servicios — decisión consciente de simplificación,
// no un descuido.
export async function getApunteDetalleById(id: number): Promise<ApunteDetalleRow | null> {
  const [rows] = await pool.query<ApunteDetalleRowPacket[]>(
    `${SELECT_APUNTE_DETALLE} WHERE ap.id = ? AND ap.estado = 'aprobado' LIMIT 1`,
    [id],
  );
  return rows[0] ?? null;
}
