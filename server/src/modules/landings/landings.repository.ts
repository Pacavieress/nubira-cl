import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import { SELECT_APUNTE, WHERE_VISIBLE as WHERE_VISIBLE_APUNTE } from "../apuntes/apuntes.repository.js";
import type { ApunteRow } from "../apuntes/apuntes.types.js";
import { SELECT_SERVICIO, WHERE_VISIBLE } from "../servicios/servicios.repository.js";
import type { ServicioRow } from "../servicios/servicios.types.js";
import type { LandingApuntesRaw, LandingClasesRaw, SeoCategoriaContenidoRow } from "./landings.types.js";

interface ServicioRowPacket extends ServicioRow, RowDataPacket {}
interface ApunteRowPacket extends ApunteRow, RowDataPacket {}
interface SeoRowPacket extends SeoCategoriaContenidoRow, RowDataPacket {}

// Puerto exacto de landing_categoria.php:29-33 — prefiere una fila tipo=<tipo> exacta
// sobre una fila tipo='ambos' genérica cuando existen ambas (mismo ORDER BY que PHP).
// `tipo` generaliza lo que antes estaba hardcodeado a 'clases' — mismo parámetro
// `$tipo` que el PHP real ya usaba en este bind (línea 35: `$st->bind_param("ss",
// $categoria, $tipo)`), no una simplificación nueva.
async function fetchSeoRow(categoria: string, tipo: "clases" | "apuntes"): Promise<SeoCategoriaContenidoRow | null> {
  const [rows] = await pool.query<SeoRowPacket[]>(
    `SELECT titulo_h1, parrafo_intro, meta_description, filtro_titulo, indexable
     FROM seo_categorias_contenido
     WHERE categoria = ? AND tipo IN (?, 'ambos')
     ORDER BY (tipo = 'ambos') ASC
     LIMIT 1`,
    [categoria, tipo],
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
  const seoRow = await fetchSeoRow(categoria, "clases");
  const servicios = await fetchServicios(categoria, seoRow?.filtro_titulo ?? null);
  return { categoria, seoRow, servicios };
}

// Puerto exacto de landing_categoria.php:90-115 (rama tipo='apuntes') — 3 estrategias de
// filtro en el MISMO orden que la rama de clases, pero sin `es_paes`/`area` (esas 2
// columnas no existen en `apuntes`, ver el comentario del PHP real en esa misma rama:
// "Apuntes no tiene columna 'area'").
async function fetchApuntes(categoria: string, filtroTitulo: string | null): Promise<ApunteRow[]> {
  let condicion: string;
  let params: string[];

  if (categoria === "PAES") {
    const like = "%PAES%";
    condicion = "(ap.titulo LIKE ? OR ap.descripcion LIKE ? OR ap.asignatura LIKE ? OR ap.materia LIKE ? OR ap.nivel_academico = 'paes')";
    params = [like, like, like, like];
  } else if (filtroTitulo) {
    condicion = "ap.titulo LIKE ?";
    params = [filtroTitulo];
  } else {
    condicion = "ap.categoria = ?";
    params = [categoria];
  }

  const [rows] = await pool.query<ApunteRowPacket[]>(
    `${SELECT_APUNTE} ${WHERE_VISIBLE_APUNTE} AND ${condicion} ORDER BY ap.fecha_subida DESC`,
    params,
  );
  return rows;
}

export async function getLandingApuntesRaw(categoria: string): Promise<LandingApuntesRaw> {
  const seoRow = await fetchSeoRow(categoria, "apuntes");
  const apuntes = await fetchApuntes(categoria, seoRow?.filtro_titulo ?? null);
  return { categoria, seoRow, apuntes };
}
