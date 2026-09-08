import type { Request, Response } from "express";
import { mapApunteRow } from "../apuntes/apuntes.mapper.js";
import { mapServicioRow } from "../servicios/servicios.mapper.js";
import {
  countApuntesBusqueda,
  countServiciosBusqueda,
  getCategoriasConResultadosBusqueda,
  insertBusquedaFallida,
  searchApuntesBusqueda,
  searchServiciosBusqueda,
} from "./busqueda.repository.js";
import type { BusquedaFilters, OrdenBusqueda, TabBusqueda } from "./busqueda.types.js";

// Puerto exacto de busqueda.php:176/181/168 — mismas 3 whitelists.
const ORDENES_VALIDOS = new Set<string>(["", "precio_asc", "precio_desc", "calificacion"]);
// Orden canónico de busqueda.php:181 — el filtro de categoría del selector se arma
// recorriendo esta lista en este orden, no el orden que devuelva el GROUP BY de la BD.
const CATEGORIAS_VALIDAS_ORDEN = [
  "Matemáticas", "Química", "Física", "Biología", "Programación", "Idiomas", "Historia",
  "Lenguaje", "Economía", "Diseño", "Derecho", "Asesoría", "Otros",
];
const CATEGORIAS_VALIDAS = new Set(CATEGORIAS_VALIDAS_ORDEN);
const TABS_VALIDOS = new Set<string>(["todo", "clases", "apuntes"]);
const PREVIEW = 8;
const POR_PAGINA = 20;

function parseOrden(value: unknown): OrdenBusqueda {
  return typeof value === "string" && ORDENES_VALIDOS.has(value) ? (value as OrdenBusqueda) : "";
}
function parseCategoria(value: unknown): string {
  return typeof value === "string" && CATEGORIAS_VALIDAS.has(value) ? value : "";
}
function parsePrecio(value: unknown): number | null {
  if (typeof value !== "string" || value === "") return null;
  const n = Number(value);
  return Number.isFinite(n) ? Math.max(0, Math.trunc(n)) : null;
}
function parseTab(value: unknown): TabBusqueda {
  return typeof value === "string" && TABS_VALIDOS.has(value) ? (value as TabBusqueda) : "todo";
}
function parsePagina(value: unknown): number {
  const n = Number(value);
  return Number.isInteger(n) && n >= 1 ? n : 1;
}

export async function getBusqueda(req: Request, res: Response): Promise<void> {
  // Puerto exacto de busqueda.php:158-159 — tope defensivo de 100 chars ANTES de
  // cualquier query o el INSERT a busquedas_fallidas.
  const qRaw = typeof req.query.q === "string" ? req.query.q.trim() : "";
  const q = Array.from(qRaw).slice(0, 100).join("");

  const filtros: BusquedaFilters = {
    q,
    orden: parseOrden(req.query.orden),
    categoria: parseCategoria(req.query.categoria),
    precioMin: parsePrecio(req.query.precio_min),
    precioMax: parsePrecio(req.query.precio_max),
    video: req.query.video === "1" || req.query.video === "true",
    tab: parseTab(req.query.tipo),
    pagina: parsePagina(req.query.pagina),
  };

  const hayFiltrosActivos = filtros.categoria !== "" || filtros.precioMin !== null || filtros.precioMax !== null || filtros.video;

  // Puerto exacto de busqueda.php:220 — sin q real ni filtros, no se ejecuta ninguna
  // query (la página muestra el estado de "trending" fijo, ver busqueda/page.tsx).
  if (q.length <= 1 && !hayFiltrosActivos) {
    res.status(200).json({
      data: {
        servicios: [],
        apuntes: [],
        totalServicios: 0,
        totalApuntes: 0,
        categoriasConResultados: [],
        tab: filtros.tab,
        pagina: 1,
        totalPaginas: 1,
      },
    });
    return;
  }

  const [totalServicios, totalApuntes, categoriasConResultadosRaw] = await Promise.all([
    countServiciosBusqueda(filtros),
    countApuntesBusqueda(filtros),
    getCategoriasConResultadosBusqueda(filtros),
  ]);
  const setResultados = new Set(categoriasConResultadosRaw);
  const categoriasConResultados = CATEGORIAS_VALIDAS_ORDEN.filter(
    (cat) => setResultados.has(cat) || cat === filtros.categoria,
  );

  // Puerto exacto de busqueda.php:373-391 — LIMIT/OFFSET según el tab activo: preview
  // corto (8) en "todo", paginación completa (20) en "clases"/"apuntes".
  const ejecutarServicios = filtros.tab !== "apuntes";
  const ejecutarApuntes = filtros.tab !== "clases";

  let limitS = PREVIEW;
  let offsetS = 0;
  let limitA = PREVIEW;
  let offsetA = 0;
  let pagina = filtros.pagina;
  let totalPaginas = 1;

  if (filtros.tab === "clases") {
    totalPaginas = Math.max(1, Math.ceil(totalServicios / POR_PAGINA));
    pagina = Math.min(pagina, totalPaginas);
    limitS = POR_PAGINA;
    offsetS = (pagina - 1) * POR_PAGINA;
  } else if (filtros.tab === "apuntes") {
    totalPaginas = Math.max(1, Math.ceil(totalApuntes / POR_PAGINA));
    pagina = Math.min(pagina, totalPaginas);
    limitA = POR_PAGINA;
    offsetA = (pagina - 1) * POR_PAGINA;
  }

  const [serviciosRows, apuntesRows] = await Promise.all([
    ejecutarServicios ? searchServiciosBusqueda(filtros, limitS, offsetS) : Promise.resolve([]),
    ejecutarApuntes ? searchApuntesBusqueda(filtros, limitA, offsetA) : Promise.resolve([]),
  ]);

  // Puerto exacto de busqueda.php:479-487 — sensor de búsqueda sin resultados. req.usuarioId
  // lo pone optionalAuth (busqueda.routes.ts) solo si había sesión válida; 0 para un
  // visitante, igual que $_SESSION['usuario_id'] ?? 0 en el PHP real.
  if (q.length > 2 && totalServicios === 0 && totalApuntes === 0) {
    await insertBusquedaFallida(q, req.usuarioId ?? 0);
  }

  res.status(200).json({
    data: {
      servicios: serviciosRows.map(mapServicioRow),
      apuntes: apuntesRows.map(mapApunteRow),
      totalServicios,
      totalApuntes,
      categoriasConResultados,
      tab: filtros.tab,
      pagina,
      totalPaginas,
    },
  });
}
