import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { resolverPortadaGuia } from "../../lib/media.js";
import {
  mapApunteRelacionadoRow,
  mapArticuloListadoRow,
  mapArticuloRelacionadoRow,
  mapCategoriaHubRow,
  mapTutorRelacionadoRow,
  slugSeoParaCategoria,
} from "./guias.mapper.js";
import {
  esTutorActivo,
  getApuntesRelacionados,
  getArticuloPublicado,
  getArticulosPublicadosPorCategoria,
  getArticulosRelacionados,
  getCategoriaPorSlug,
  getCategoriasHubGeneral,
  getFaqsPorArticulo,
  getIndexableSeo,
  getTutoresRelacionados,
  registrarArticuloVisto,
} from "./guias.repository.js";
import type { GuiaArticuloDetalle, GuiasHubCategoria, GuiasHubGeneral } from "./guias.types.js";

// Puerto de guias.php MODO 1 (líneas 20-40) — sin gate de sesión, público.
export async function getHubGeneral(_req: Request, res: Response): Promise<void> {
  const rows = await getCategoriasHubGeneral();
  const body: GuiasHubGeneral = { modo: "general", categorias: rows.map(mapCategoriaHubRow) };
  res.status(200).json(body);
}

// Puerto de guias.php MODO 2 (líneas 42-83) — gate de "Para Tutores" idéntico:
// solo_tutores=1 sin sesión -> equivalente de header("Location: /login?redir=...") (acá:
// 401 sin_sesion, web/ decide el redirect); solo_tutores=1 con sesión pero sin ser tutor
// activo -> equivalente de header("Location: /publicar-servicio") (acá: 403 no_tutor).
// Requiere optionalAuth (ver guias.routes.ts), no requireAuth: la mayoría de las
// categorías son públicas, la sesión solo importa para decidir el gate de "Para Tutores".
export async function getHubCategoria(req: Request, res: Response): Promise<void> {
  const slug = String(req.params.slug ?? "").toLowerCase();
  const categoria = await getCategoriaPorSlug(slug);
  if (!categoria) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  if (categoria.solo_tutores === 1) {
    if (!req.usuarioId) {
      res.status(401).json({ error: "sin_sesion" });
      return;
    }
    const esTutor = await esTutorActivo(req.usuarioId);
    if (!esTutor) {
      res.status(403).json({ error: "no_tutor" });
      return;
    }
  }

  const articulosRows = await getArticulosPublicadosPorCategoria(categoria.id);
  const body: GuiasHubCategoria = {
    modo: "categoria",
    categoria: {
      nombre: categoria.nombre,
      slug: categoria.slug,
      descripcionCorta: categoria.descripcion_corta,
      soloTutores: categoria.solo_tutores === 1,
    },
    articulos: articulosRows.map(mapArticuloListadoRow),
    // Puerto exacto de guias.php:76 — mismo umbral anti-thin-content que landing_categoria.php.
    noindex: articulosRows.length < 3,
  };
  res.status(200).json(body);
}

// Puerto de guia_post.php completo — mismo gate de "Para Tutores" que getHubCategoria
// (ver ese handler), + tracking de "visto" (solo si el gate de tutor pasó), + contenido
// relacionado (tutores/apuntes/artículos) cuando la categoría tiene categoria_relacionada
// o filtro_relacionado configurado.
export async function getArticuloDetalle(req: Request, res: Response): Promise<void> {
  const catSlug = String(req.params.cat ?? "").toLowerCase();
  const artSlug = String(req.params.slug ?? "").toLowerCase();

  const categoria = await getCategoriaPorSlug(catSlug);
  if (!categoria) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  let esTutor = false;
  if (categoria.solo_tutores === 1) {
    if (!req.usuarioId) {
      res.status(401).json({ error: "sin_sesion" });
      return;
    }
    esTutor = await esTutorActivo(req.usuarioId);
    if (!esTutor) {
      res.status(403).json({ error: "no_tutor" });
      return;
    }
  }

  const articulo = await getArticuloPublicado(categoria.id, artSlug);
  if (!articulo) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  if (categoria.solo_tutores === 1 && esTutor) {
    await registrarArticuloVisto(req.usuarioId!, articulo.id);
  }

  const faqsRows = await getFaqsPorArticulo(articulo.id);

  let tutoresRelacionados: ReturnType<typeof mapTutorRelacionadoRow>[] = [];
  let apuntesRelacionados: ReturnType<typeof mapApunteRelacionadoRow>[] = [];
  let linkVerClases: string | null = null;
  let linkVerApuntes: string | null = null;

  if (categoria.categoria_relacionada || categoria.filtro_relacionado) {
    const [tutoresRows, apuntesRows, indexableClases, indexableApuntes] = await Promise.all([
      getTutoresRelacionados(categoria),
      getApuntesRelacionados(categoria),
      getIndexableSeo(categoria.nombre, "clases"),
      getIndexableSeo(categoria.nombre, "apuntes"),
    ]);
    tutoresRelacionados = tutoresRows.map(mapTutorRelacionadoRow);
    apuntesRelacionados = apuntesRows.map(mapApunteRelacionadoRow);
    if (indexableClases) linkVerClases = slugSeoParaCategoria(categoria.nombre);
    if (indexableApuntes) linkVerApuntes = slugSeoParaCategoria(categoria.nombre);
  }

  const articulosRelacionadosRows = await getArticulosRelacionados(categoria.id, articulo.id);

  const body: GuiaArticuloDetalle = {
    modo: "articulo",
    categoria: { nombre: categoria.nombre, slug: categoria.slug, soloTutores: categoria.solo_tutores === 1 },
    articulo: {
      titulo: articulo.titulo,
      resumen: articulo.resumen,
      cuerpoHtml: articulo.cuerpo,
      autorNombre: articulo.autor_nombre,
      fechaPublicacion: articulo.fecha_publicacion,
      portadaMainUrl: resolverPortadaGuia(articulo.imagen_portada, "main", env.assetsBaseUrl),
      metaDescription: articulo.meta_description,
    },
    faqs: faqsRows,
    tutoresRelacionados,
    apuntesRelacionados,
    linkVerClases,
    linkVerApuntes,
    articulosRelacionados: articulosRelacionadosRows.map(mapArticuloRelacionadoRow),
    // Puerto exacto de guia_post.php:90.
    mostrarBreadcrumb: categoria.solo_tutores !== 1,
  };
  res.status(200).json(body);
}
