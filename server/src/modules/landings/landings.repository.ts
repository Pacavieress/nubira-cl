import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { SELECT_SERVICIO, WHERE_VISIBLE } from "../servicios/servicios.repository.js";
import type { ServicioRow } from "../servicios/servicios.types.js";
import type { LandingClasesRaw, SeoCategoriaContenidoRow } from "./landings.types.js";

interface ServicioRowPacket extends ServicioRow, RowDataPacket {}
interface SeoRowPacket extends SeoCategoriaContenidoRow, RowDataPacket {}

// Puerto exacto de landing_categoria.php:29-33 — prefiere una fila tipo='clases' exacta
// sobre una fila tipo='ambos' genérica cuando existen ambas (mismo ORDER BY que PHP).
async function fetchSeoRow(categoria: string): Promise<SeoCategoriaContenidoRow | null> {
  const [rows] = await pool.query<SeoRowPacket[]>(
    `SELECT titulo_h1, parrafo_intro, meta_description, filtro_titulo, indexable
     FROM seo_categorias_contenido
     WHERE categoria = ? AND tipo IN ('clases', 'ambos')
     ORDER BY (tipo = 'ambos') ASC
     LIMIT 1`,
    [categoria],
  );
  return rows[0] ?? null;
}

// Puerto exacto de landing_categoria.php:56-89 (rama tipo='clases') — 3 estrategias de
// filtro en orden de prioridad: PAES (LIKE ancho + es_paes=1) > filtro_titulo (LIKE de
// seo_categorias_contenido) > categoria exacta.
async function fetchServicios(categoria: string, filtroTitulo: string | null): Promise<ServicioRow[]> {
  let condicion: string;
  let params: string[];

  if (categoria === "PAES") {
    const like = "%PAES%";
    condicion =
      "(s.titulo LIKE ? OR s.descripcion LIKE ? OR s.categoria LIKE ? OR s.materia LIKE ? OR s.asignatura LIKE ? OR s.area LIKE ? OR s.es_paes = 1)";
    params = [like, like, like, like, like, like];
  } else if (filtroTitulo) {
    condicion = "s.titulo LIKE ?";
    params = [filtroTitulo];
  } else {
    condicion = "s.categoria = ?";
    params = [categoria];
  }

  const [rows] = await pool.query<ServicioRowPacket[]>(
    `${SELECT_SERVICIO} ${WHERE_VISIBLE} AND ${condicion} ORDER BY s.fecha_publicacion DESC`,
    params,
  );
  return rows;
}

export async function getLandingClasesRaw(categoria: string): Promise<LandingClasesRaw> {
  const seoRow = await fetchSeoRow(categoria);
  const servicios = await fetchServicios(categoria, seoRow?.filtro_titulo ?? null);
  return { categoria, seoRow, servicios };
}
