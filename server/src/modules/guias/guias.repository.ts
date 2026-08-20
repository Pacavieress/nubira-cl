import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ArticuloListadoRow, CategoriaHubRow, CategoriaRow } from "./guias.types.js";

interface CategoriaHubDbRow extends CategoriaHubRow, RowDataPacket {}
interface CategoriaDbRow extends CategoriaRow, RowDataPacket {}
interface ArticuloListadoDbRow extends ArticuloListadoRow, RowDataPacket {}

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
    "SELECT id, nombre, slug, descripcion_corta, solo_tutores FROM guias_categorias WHERE slug = ? AND habilitada = 1 LIMIT 1",
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
