import type { Request, Response } from "express";
import fs from "node:fs/promises";
import path from "node:path";
import { env } from "../../config/env.js";
import {
  fingerprintDesafio,
  fingerprintDesafioPreguntas,
  generarImagenDesafioPost,
  generarImagenDesafioPreguntasHistory,
} from "./compartirDesafio.generador.js";
import { fingerprintApunte, generarImagenApuntePost } from "./compartirApunte.generador.js";
import { fingerprintServicio, generarImagenServicioPost } from "./compartirServicio.generador.js";
import {
  getApunteParaCompartir,
  getMateriaActiva,
  getPreguntasParaCompartir,
  getServicioParaCompartir,
  registrarShareApunte,
  registrarShareDesafio,
  registrarShareServicio,
} from "./compartir.repository.js";
import type { FormatoShare } from "./compartir.types.js";

const FORMATOS_VALIDOS = new Set<FormatoShare>(["post", "caption", "share", "preguntas"]);

// Puerto de nb_obtener_imagen_desafio() (imagen_compartir_desafio.php:127-143) — cache en
// disco keyed por fingerprint de contenido (slug+nombre+versión del generador): si nada
// cambió, sirve el JPEG ya generado antes en vez de rerenderizar. Público a propósito (sin
// requireAuth) — mismo criterio que el endpoint real: es una imagen para compartir, tiene
// que ser accesible sin sesión (incluida por crawlers de redes sociales).
export async function getImagenDesafioPost(req: Request, res: Response): Promise<void> {
  const slug = String(req.params.slug ?? "").trim();
  const materia = slug ? await getMateriaActiva(slug) : null;

  if (!materia) {
    res.status(404).json({ error: "materia_invalida" });
    return;
  }

  const fp = fingerprintDesafio(materia);
  const dir = path.join(env.uploadDir, "compartir");
  await fs.mkdir(dir, { recursive: true });
  const archivo = path.join(dir, `desafio_${materia.slug}_post_${fp}.jpg`);

  let buffer: Buffer;
  try {
    buffer = await fs.readFile(archivo);
  } catch {
    buffer = await generarImagenDesafioPost(materia);
    await fs.writeFile(archivo, buffer);
  }

  res.setHeader("Content-Type", "image/jpeg");
  res.setHeader("Cache-Control", "public, max-age=86400, immutable");
  res.status(200).send(buffer);
}

// Puerto de nb_obtener_imagen_desafio_preguntas() (imagen_compartir_desafio.php:389-404) —
// mismo criterio de cache por fingerprint, pero acá el archivo también incluye los 3 ids
// EN EL ORDEN PEDIDO (no ordenados): el mismo trío en otro orden es una card distinta,
// porque la numeración 1/2/3 en la imagen cambiaría. Público a propósito, igual que
// getImagenDesafioPost.
export async function getImagenDesafioPreguntas(req: Request, res: Response): Promise<void> {
  const idsCrudo = String(req.params.ids ?? "");
  const ids = idsCrudo
    .split("-")
    .map((s) => Number(s))
    .filter((n) => Number.isInteger(n));

  const datos = ids.length === 3 ? await getPreguntasParaCompartir(ids) : null;
  if (!datos) {
    res.status(404).json({ error: "preguntas_invalidas" });
    return;
  }

  const fp = fingerprintDesafioPreguntas(ids, datos);
  const dir = path.join(env.uploadDir, "compartir");
  await fs.mkdir(dir, { recursive: true });
  const archivo = path.join(dir, `desafio_preguntas_${ids.join("-")}_history_${fp}.jpg`);

  let buffer: Buffer;
  try {
    buffer = await fs.readFile(archivo);
  } catch {
    buffer = await generarImagenDesafioPreguntasHistory(datos.materia, datos.preguntas);
    await fs.writeFile(archivo, buffer);
  }

  res.setHeader("Content-Type", "image/jpeg");
  res.setHeader("Cache-Control", "public, max-age=86400, immutable");
  res.status(200).send(buffer);
}

function extraerIpUserAgent(req: Request): { ip: string | null; userAgent: string | null } {
  const ip = (req.headers["x-forwarded-for"] as string | undefined)?.split(",")[0]?.trim() ?? req.socket.remoteAddress ?? null;
  const userAgent = (req.headers["user-agent"] as string | undefined) ?? null;
  return { ip, userAgent };
}

export async function postTrackShareDesafio(req: Request, res: Response): Promise<void> {
  const body = req.body as { materiaSlug?: unknown; formato?: unknown };
  const materiaSlug = typeof body.materiaSlug === "string" ? body.materiaSlug.trim() : "";
  const formato = typeof body.formato === "string" ? body.formato : "";

  if (!materiaSlug || !FORMATOS_VALIDOS.has(formato as FormatoShare)) {
    res.status(400).json({ ok: false });
    return;
  }

  const { ip, userAgent } = extraerIpUserAgent(req);
  await registrarShareDesafio(materiaSlug, formato as FormatoShare, ip, userAgent);
  res.status(200).json({ ok: true });
}

// Puerto de app/img_apunte.php — SOLO formato POST (ver nota de alcance en
// compartirApunte.generador.ts). Mismo gate que el PHP real: solo apuntes con
// estado='aprobado' (fusionado en el propio WHERE de getApunteParaCompartir).
export async function getImagenApuntePost(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const apunte = Number.isInteger(id) && id > 0 ? await getApunteParaCompartir(id) : null;

  if (!apunte) {
    res.status(404).json({ error: "apunte_invalido" });
    return;
  }

  const fp = fingerprintApunte(apunte);
  const dir = path.join(env.uploadDir, "compartir");
  await fs.mkdir(dir, { recursive: true });
  const archivo = path.join(dir, `ap_${apunte.id}_post_${fp}.jpg`);

  let buffer: Buffer;
  try {
    buffer = await fs.readFile(archivo);
  } catch {
    buffer = await generarImagenApuntePost(apunte);
    await fs.writeFile(archivo, buffer);
  }

  res.setHeader("Content-Type", "image/jpeg");
  res.setHeader("Cache-Control", "public, max-age=86400, immutable");
  res.status(200).send(buffer);
}

export async function postTrackShareApunte(req: Request, res: Response): Promise<void> {
  const body = req.body as { apunteId?: unknown; formato?: unknown };
  const apunteId = Number(body.apunteId);
  const formato = typeof body.formato === "string" ? body.formato : "";

  if (!Number.isInteger(apunteId) || apunteId <= 0 || !FORMATOS_VALIDOS.has(formato as FormatoShare)) {
    res.status(400).json({ ok: false });
    return;
  }

  const { ip, userAgent } = extraerIpUserAgent(req);
  await registrarShareApunte(apunteId, formato as FormatoShare, ip, userAgent);
  res.status(200).json({ ok: true });
}

// Puerto de app/img_servicio.php — SOLO formato POST (mismo alcance que Compartir
// Apuntes/Desafío). Mismo gate que el PHP real: estado='aprobado' Y COALESCE(visible,1)=1
// (fusionado en el propio WHERE de getServicioParaCompartir). El PHP real además tiene su
// propio rate-limit (40 req/min/IP, tabla img_servicio_rate_limit) — deliberadamente NO
// portado, mismo criterio que el resto de los endpoints de este módulo.
export async function getImagenServicioPost(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const servicio = Number.isInteger(id) && id > 0 ? await getServicioParaCompartir(id) : null;

  if (!servicio) {
    res.status(404).json({ error: "servicio_invalido" });
    return;
  }

  const fp = fingerprintServicio(servicio);
  const dir = path.join(env.uploadDir, "compartir");
  await fs.mkdir(dir, { recursive: true });
  const archivo = path.join(dir, `sv_${servicio.id}_post_${fp}.jpg`);

  let buffer: Buffer;
  try {
    buffer = await fs.readFile(archivo);
  } catch {
    buffer = await generarImagenServicioPost(servicio);
    await fs.writeFile(archivo, buffer);
  }

  res.setHeader("Content-Type", "image/jpeg");
  res.setHeader("Cache-Control", "public, max-age=86400, immutable");
  res.status(200).send(buffer);
}

export async function postTrackShareServicio(req: Request, res: Response): Promise<void> {
  const body = req.body as { servicioId?: unknown; formato?: unknown };
  const servicioId = Number(body.servicioId);
  const formato = typeof body.formato === "string" ? body.formato : "";

  if (!Number.isInteger(servicioId) || servicioId <= 0 || !FORMATOS_VALIDOS.has(formato as FormatoShare)) {
    res.status(400).json({ ok: false });
    return;
  }

  const { ip, userAgent } = extraerIpUserAgent(req);
  await registrarShareServicio(servicioId, formato as FormatoShare, ip, userAgent);
  res.status(200).json({ ok: true });
}
