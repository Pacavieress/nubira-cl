import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { resolverPortadaApunte } from "../../lib/media.js";
import * as repo from "./adminApuntes.repository.js";
import type { ApunteListado, ApuntesResumen } from "./adminApuntes.types.js";

export async function getApuntes(req: Request, res: Response): Promise<void> {
  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";
  const filas = await repo.listarApuntes(q);

  const apuntes: ApunteListado[] = filas.map((a) => ({
    id: a.id,
    titulo: a.titulo,
    autor: a.autor,
    asignatura: a.asignatura,
    fechaSubida: new Date(a.fecha_subida).toISOString(),
    publico: a.publico === 1,
    estado: a.estado ?? "pendiente",
    totalVentas: a.total_ventas,
    miniaturaUrl: resolverPortadaApunte(a.id, a.portada, a.archivo, env.assetsBaseUrl),
  }));

  const resumen: ApuntesResumen = { q, apuntes };
  res.status(200).json(resumen);
}

export async function postAlternar(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  const actualizado = await repo.alternarVisibilidad(id);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}
