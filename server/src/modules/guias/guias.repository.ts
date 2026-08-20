import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type {
  ApunteRelacionadoRow,
  ArticuloDetalleRow,
  ArticuloListadoRow,
  ArticuloRelacionadoRow,
  CategoriaHubRow,
  CategoriaRow,
  FaqArticuloRow,
  TutorRelacionadoRow,
} from "./guias.types.js";

interface CategoriaHubDbRow extends CategoriaHubRow, RowDataPacket {}
interface CategoriaDbRow extends CategoriaRow, RowDataPacket {}
interface ArticuloListadoDbRow extends ArticuloListadoRow, RowDataPacket {}
interface ArticuloDetalleDbRow extends ArticuloDetalleRow, RowDataPacket {}
interface FaqArticuloDbRow extends FaqArticuloRow, RowDataPacket {}
interface TutorRelacionadoDbRow extends TutorRelacionadoRow, RowDataPacket {}
interface ApunteRelacionadoDbRow extends ApunteRelacionadoRow, RowDataPacket {}
interface ArticuloRelacionadoDbRow extends ArticuloRelacionadoRow, RowDataPacket {}

// Puerto exacto de guias.php:28-34 (MODO 1) — JOIN interno con guias_articulos: solo
// categorías con al menos 1 artículo publicado aparecen, mismo criterio que el PHP real
// (categorías "vacías" no se listan en el hub general aunque estén habilitadas).
export async function getCategoriasHubGeneral(): Promise<CategoriaHubRow[]> {
  const [rows] = await pool.query<CategoriaHubDbRow[]>(
    `SELECT c.id, c.nombre, c.slug, c.descripcion_corta, COUNT(a.id) AS total_articulos
     FROM guias_categorias c
     JOIN guias_articulos a ON a.categoria_id = c.id AND a.estado = 'publicado'
     WHERE c.habilitada = 1 AND c.solo_tutores = 0
     GROUP BY c.id, c.nombre, c.slug, c.descripcion_corta
     ORDER BY c.orden`,
  );
  return rows;
}

// Puerto exacto de guias.php:43-47 / guia_post.php:65-69 (misma query en ambos archivos
// reales — WHERE slug=? AND habilitada=1).
export async function getCategoriaPorSlug(slug: string): Promise<CategoriaRow | null> {
  const [rows] = await pool.query<CategoriaDbRow[]>(
    "SELECT id, nombre, slug, descripcion_corta, solo_tutores, categoria_relacionada, filtro_relacionado FROM guias_categorias WHERE slug = ? AND habilitada = 1 LIMIT 1",
    [slug],
  );
  return rows[0] ?? null;
}

// Puerto exacto de guias.php:65-73 (MODO 2).
export async function getArticulosPublicadosPorCategoria(categoriaId: number): Promise<ArticuloListadoRow[]> {
  const [rows] = await pool.query<ArticuloListadoDbRow[]>(
    `SELECT id, titulo, slug, resumen, imagen_portada, fecha_publicacion
     FROM guias_articulos
     WHERE categoria_id = ? AND estado = 'publicado'
     ORDER BY fecha_publicacion DESC`,
    [categoriaId],
  );
  return rows;
}

// Puerto de roles.php::nb_es_tutor_activo() — mismo criterio en 2 pasos (publicación
// activa, o reputación de vendedor vía valoraciones/cantidad_votos legado).
export async function esTutorActivo(usuarioId: number): Promise<boolean> {
  if (usuarioId <= 0) return false;

  const [rowsPub] = await pool.query<RowDataPacket[]>(
    `SELECT
       EXISTS(SELECT 1 FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND COALESCE(visible,1) = 1) AS tiene_servicio,
       EXISTS(SELECT 1 FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND bloqueado = 0 AND COALESCE(visible,1) = 1) AS tiene_apunte`,
    [usuarioId, usuarioId],
  );
  const filaPub = rowsPub[0] as { tiene_servicio: number; tiene_apunte: number } | undefined;
  if (filaPub && (filaPub.tiene_servicio === 1 || filaPub.tiene_apunte === 1)) return true;

  const [rowsRep] = await pool.query<RowDataPacket[]>(
    `SELECT
       (SELECT cantidad_votos FROM alumnos WHERE id = ?) AS leg_qty,
       (SELECT COUNT(*) FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor') AS v_qty`,
    [usuarioId, usuarioId],
  );
  const filaRep = rowsRep[0] as { leg_qty: number | null; v_qty: number } | undefined;
  return !!filaRep && (filaRep.leg_qty ?? 0) + filaRep.v_qty > 0;
}

// ==================== Artículo individual ====================

// Puerto exacto de guia_post.php:92-96.
export async function getArticuloPublicado(categoriaId: number, slug: string): Promise<ArticuloDetalleRow | null> {
  const [rows] = await pool.query<ArticuloDetalleDbRow[]>(
    `SELECT id, titulo, slug, resumen, cuerpo, imagen_portada, autor_nombre, meta_description, fecha_publicacion
     FROM guias_articulos
     WHERE categoria_id = ? AND slug = ? AND estado = 'publicado'
     LIMIT 1`,
    [categoriaId, slug],
  );
  return rows[0] ?? null;
}

// Puerto exacto de guia_post.php:105-111 — tracking de "visto", solo aplica a contenido
// gateado de tutores con sesión de tutor ya validada (ver guias.controller.ts).
export async function registrarArticuloVisto(usuarioId: number, articuloId: number): Promise<void> {
  await pool.query(
    `INSERT INTO guias_articulos_vistos (usuario_id, articulo_id, fecha_visto)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE fecha_visto = NOW()`,
    [usuarioId, articuloId],
  );
}

// Puerto exacto de guia_post.php:116-121.
export async function getFaqsPorArticulo(articuloId: number): Promise<FaqArticuloRow[]> {
  const [rows] = await pool.query<FaqArticuloDbRow[]>(
    "SELECT pregunta, respuesta FROM guias_articulo_faqs WHERE articulo_id = ? ORDER BY orden",
    [articuloId],
  );
  return rows;
}

const WHERE_BASE_SERVICIOS =
  "TRIM(LOWER(s.estado)) IN ('aprobado','publicado','activo') AND s.visible = 1 AND COALESCE(a.visible,1) = 1 AND a.bloqueado = 0";

// Puerto exacto de guia_post.php:134-158 — 2 variantes (filtro_relacionado LIKE tiene
// prioridad sobre categoria_relacionada exacta), mismo LIMIT 4, mismo ORDER BY.
export async function getTutoresRelacionados(categoria: CategoriaRow): Promise<TutorRelacionadoRow[]> {
  const usaFiltro = !!categoria.filtro_relacionado;
  const criterio = usaFiltro ? categoria.filtro_relacionado! : categoria.categoria_relacionada!;
  const condicion = usaFiltro ? "s.titulo LIKE ?" : "s.categoria = ?";

  const [rows] = await pool.query<TutorRelacionadoDbRow[]>(
    `SELECT s.id, s.slug, s.titulo, a.nombre AS nombre_tutor, a.foto_perfil,
            COALESCE(dp.institucion, a.institucion) AS institucion_maestra
     FROM servicios s
     JOIN alumnos a ON a.id = s.alumno_id
     LEFT JOIN dominios_permitidos dp ON a.dominio = dp.dominio
     WHERE ${WHERE_BASE_SERVICIOS} AND ${condicion}
     ORDER BY s.id DESC
     LIMIT 4`,
    [criterio],
  );
  return rows;
}

// Puerto exacto de guia_post.php:161-176.
export async function getApuntesRelacionados(categoria: CategoriaRow): Promise<ApunteRelacionadoRow[]> {
  const usaFiltro = !!categoria.filtro_relacionado;
  const criterio = usaFiltro ? categoria.filtro_relacionado! : categoria.categoria_relacionada!;
  const condicion = usaFiltro ? "ap.titulo LIKE ?" : "ap.categoria = ?";

  const [rows] = await pool.query<ApunteRelacionadoDbRow[]>(
    `SELECT ap.id, ap.titulo
     FROM apuntes ap
     JOIN alumnos al ON al.id = ap.id_alumno
     WHERE ap.publico = 1 AND ap.visible = 1 AND al.visible = 1 AND al.bloqueado = 0 AND ${condicion}
     ORDER BY ap.fecha_subida DESC
     LIMIT 4`,
    [criterio],
  );
  return rows;
}

// Puerto exacto de guia_post.php:181-197 — indexable de la landing SEO real (/clases/{slug}
// en web/), usada para decidir si vale la pena linkear "Ver todas las clases de X".
export async function getIndexableSeo(categoriaNombre: string, tipo: "clases" | "apuntes"): Promise<boolean> {
  const [rows] = await pool.query<RowDataPacket[]>(
    `SELECT indexable FROM seo_categorias_contenido
     WHERE categoria = ? AND tipo IN (?, 'ambos')
     ORDER BY (tipo = 'ambos') ASC
     LIMIT 1`,
    [categoriaNombre, tipo],
  );
  const fila = rows[0] as { indexable: number } | undefined;
  return !!fila && fila.indexable === 1;
}

// Puerto exacto de guia_post.php:225-232.
export async function getArticulosRelacionados(categoriaId: number, excluirId: number): Promise<ArticuloRelacionadoRow[]> {
  const [rows] = await pool.query<ArticuloRelacionadoDbRow[]>(
    `SELECT id, slug, titulo, imagen_portada
     FROM guias_articulos
     WHERE categoria_id = ? AND id != ? AND estado = 'publicado'
     ORDER BY fecha_publicacion DESC
     LIMIT 3`,
    [categoriaId, excluirId],
  );
  return rows;
}
