import type { Request, Response } from "express";
import fs from "node:fs/promises";
import path from "node:path";
import { env } from "../../config/env.js";
import { fingerprintDesafio, generarImagenDesafioPost } from "./compartirDesafio.generador.js";
import { getMateriaActiva, registrarShareDesafio } from "./compartir.repository.js";
import type { FormatoShare } from "./compartir.types.js";

const FORMATOS_VALIDOS = new Set<FormatoShare>(["post", "caption", "share"]);

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

export async function postTrackShareDesafio(req: Request, res: Response): Promise<void> {
  const body = req.body as { materiaSlug?: unknown; formato?: unknown };
  const materiaSlug = typeof body.materiaSlug === "string" ? body.materiaSlug.trim() : "";
  const formato = typeof body.formato === "string" ? body.formato : "";

  if (!materiaSlug || !FORMATOS_VALIDOS.has(formato as FormatoShare)) {
    res.status(400).json({ ok: false });
    return;
  }

  const ip = (req.headers["x-forwarded-for"] as string | undefined)?.split(",")[0]?.trim() ?? req.socket.remoteAddress ?? null;
  const userAgent = req.headers["user-agent"] ?? null;

  await registrarShareDesafio(materiaSlug, formato as FormatoShare, ip, userAgent ?? null);
  res.status(200).json({ ok: true });
}
