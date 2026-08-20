import type { Request, Response } from "express";
import { mapArticuloListadoRow, mapCategoriaHubRow } from "./guias.mapper.js";
import {
  esTutorActivo,
  getArticulosPublicadosPorCategoria,
  getCategoriaPorSlug,
  getCategoriasHubGeneral,
} from "./guias.repository.js";
import type { GuiasHubCategoria, GuiasHubGeneral } from "./guias.types.js";

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
