import type { Request, Response } from "express";
import { actualizarInstitucion, crearDominio, eliminarDominio, existeDominio, listarDominios } from "./adminDominios.repository.js";

// Puerto de la normalización de admin_dominios.php:58 — quita @/www./http(s):// y baja a
// minúsculas, EN ESE ORDEN (si el usuario pega "https://www.usach.cl" el resultado final
// debe ser "usach.cl").
function normalizarDominio(crudo: string): string {
  return crudo.replace(/@/g, "").replace(/www\./g, "").replace(/https?:\/\//g, "").trim().toLowerCase();
}

function normalizarInstitucion(crudo: string): string {
  return crudo.trim().toUpperCase();
}

export async function getDominios(_req: Request, res: Response): Promise<void> {
  const dominios = await listarDominios();
  res.status(200).json(dominios);
}

// Puerto de la acción 'agregar' (admin_dominios.php:56-70) — mismo chequeo de duplicado
// amigable ANTES del insert (la tabla también tiene UNIQUE(dominio) como respaldo, pero el
// mensaje "ya existe" es mejor UX que dejar reventar el constraint).
export async function postDominio(req: Request, res: Response): Promise<void> {
  const body = req.body as { dominio?: unknown; institucion?: unknown };
  const dominio = normalizarDominio(typeof body.dominio === "string" ? body.dominio : "");
  const institucion = normalizarInstitucion(typeof body.institucion === "string" ? body.institucion : "");

  if (!dominio || !institucion) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  if (await existeDominio(dominio)) {
    res.status(409).json({ error: "dominio_duplicado", mensaje: `El dominio @${dominio} ya existe.` });
    return;
  }

  const id = await crearDominio(dominio, institucion);
  res.status(201).json({ id, dominio, institucion, totalUsuarios: 0 });
}

// Puerto de la acción 'editar' (admin_dominios.php:73-79) — SOLO renombra la institución,
// el dominio en sí no es editable en el PHP real (tiene sentido: cambiar el dominio de un
// registro existente rompería silenciosamente a los usuarios ya asociados a él).
export async function putDominio(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const body = req.body as { institucion?: unknown };
  const institucion = normalizarInstitucion(typeof body.institucion === "string" ? body.institucion : "");

  if (!Number.isInteger(id) || id <= 0 || !institucion) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const actualizado = await actualizarInstitucion(id, institucion);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}

export async function deleteDominio(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const eliminado = await eliminarDominio(id);
  if (!eliminado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}
