import type { Request, Response } from "express";
import { esDobleSubmit } from "../../lib/idempotencyGuard.js";
import { crearNovedad, listarCategoriasDisponibles, listarInstitucionesDisponibles, listarNovedades, listarServiciosMarketing } from "./adminMarketingCards.repository.js";
import type { FiltrosServiciosMarketing, NovedadMarketing, ServicioMarketing, ServiciosMarketingResumen } from "./adminMarketingCards.types.js";

const FECHA_RE = /^\d{4}-\d{2}-\d{2}$/;

function parsearFiltros(req: Request): FiltrosServiciosMarketing {
  const categoria = typeof req.query.categoria === "string" ? req.query.categoria.trim() : "";
  const institucion = typeof req.query.institucion === "string" ? req.query.institucion.trim() : "";
  const conVideo = req.query.conVideo === "1";
  const fechaDesdeRaw = typeof req.query.fechaDesde === "string" ? req.query.fechaDesde.trim() : "";
  const fechaHastaRaw = typeof req.query.fechaHasta === "string" ? req.query.fechaHasta.trim() : "";

  return {
    categoria,
    institucion,
    conVideo,
    fechaDesde: FECHA_RE.test(fechaDesdeRaw) ? fechaDesdeRaw : "",
    fechaHasta: FECHA_RE.test(fechaHastaRaw) ? fechaHastaRaw : "",
  };
}

// Puerto de admin_marketing_cards.php:41-111 (tab servicios) — listado filtrado + opciones
// de filtro. Sin `imgUrl` en la respuesta a propósito: el cliente arma
// `/api/servicio/compartir/{id}/post` (proxy de web/ ya existente hacia el endpoint público
// de server/src/modules/compartir, reutilizado tal cual — ver nota de alcance en
// adminMarketingCards.types.ts).
export async function getServiciosMarketing(req: Request, res: Response): Promise<void> {
  const filtros = parsearFiltros(req);

  const [filas, categoriasDisponibles, institucionesDisponibles] = await Promise.all([
    listarServiciosMarketing(filtros),
    listarCategoriasDisponibles(),
    listarInstitucionesDisponibles(),
  ]);

  const servicios: ServicioMarketing[] = filas.map((s) => ({
    id: s.id,
    titulo: s.titulo,
    categoria: s.categoria ?? "",
    institucion: s.institucion,
    fechaPublicacion: new Date(s.fecha_publicacion).toISOString(),
    conVideo: s.video_estado === "aprobado",
    tutorNombre: s.tutor_nombre,
  }));

  const body: ServiciosMarketingResumen = {
    total: servicios.length,
    servicios,
    categoriasDisponibles,
    institucionesDisponibles,
  };
  res.status(200).json(body);
}

// mb_strlen cuenta codepoints Unicode — mismo criterio que longitudUnicode() en
// adminAvisos.controller.ts (Array.from itera por codepoint, no unidad UTF-16).
function longitudUnicode(s: string): number {
  return Array.from(s).length;
}

// Puerto de admin_marketing_cards.php:123-133 (historial, tab novedades) — últimas 50.
export async function getNovedades(_req: Request, res: Response): Promise<void> {
  const filas = await listarNovedades();
  const novedades: NovedadMarketing[] = filas.map((n) => ({
    id: n.id,
    titulo: n.titulo,
    cuerpo: n.cuerpo,
    creadoEn: new Date(n.creado_en).toISOString(),
  }));
  res.status(200).json(novedades);
}

// Puerto de admin_guardar_novedad.php:33-64 — única mutación real de todo el panel. Guard
// anti-doble-submit agregado (el PHP real no lo tenía): mismo admin + mismo título + mismo
// cuerpo dentro de 15s se rechaza con 409 en vez de crear 2 novedades duplicadas (2 imágenes,
// 2 filas de historial) por un doble-click.
export async function postCrearNovedad(req: Request, res: Response): Promise<void> {
  const adminId = req.usuarioId;
  if (!adminId) {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  const body = req.body as { titulo?: unknown; cuerpo?: unknown };
  const titulo = typeof body.titulo === "string" ? body.titulo.trim() : "";
  const cuerpo = typeof body.cuerpo === "string" ? body.cuerpo.trim() : "";

  if (titulo === "" || longitudUnicode(titulo) > 120) {
    res.status(400).json({ error: "titulo_invalido", mensaje: "El título es obligatorio y debe tener máximo 120 caracteres." });
    return;
  }
  if (cuerpo === "" || longitudUnicode(cuerpo) > 280) {
    res.status(400).json({ error: "cuerpo_invalido", mensaje: "El cuerpo es obligatorio y debe tener máximo 280 caracteres." });
    return;
  }

  if (esDobleSubmit(`novedad:${adminId}:${titulo}:${cuerpo}`)) {
    res.status(409).json({ error: "doble_envio", mensaje: "Esta novedad ya se guardó hace unos segundos." });
    return;
  }

  const id = await crearNovedad(titulo, cuerpo);
  res.status(201).json({ id });
}
