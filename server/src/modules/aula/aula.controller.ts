import fs from "node:fs";
import path from "node:path";
import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { getUsuarioConRol } from "../auth/auth.repository.js";
import {
  enviarMensajeAula,
  getArchivoContratoInfo,
  getArchivosContrato,
  getAulaDetalle,
  getEstadoAula,
  getEstadoPresenciaSala,
  getMensajesAula,
  registrarPresenciaSala,
  salirDeSala,
  setTypingAula,
  subirArchivoContrato,
} from "./aula.repository.js";

// El bypass de admin (observador de solo lectura) es un rol de la CUENTA, no de la sesión
// PHP como en el original — se consulta fresco por request, mismo criterio que
// pagoContratos.repository.ts usa para el mismo problema.
async function esAdminReq(req: Request): Promise<boolean> {
  const usuario = await getUsuarioConRol(req.usuarioId as number);
  return usuario?.rol === "admin";
}

export async function getAulaDetalleHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const detalle = await getAulaDetalle(contratoId, usuarioId, await esAdminReq(req));
  if (!detalle) {
    res.status(404).json({ error: "sin_acceso" });
    return;
  }
  res.status(200).json(detalle);
}

export async function getMensajesAulaHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const resultado = await getMensajesAula(contratoId, usuarioId, await esAdminReq(req));
  if (!resultado) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }
  res.status(200).json(resultado);
}

export async function postEnviarMensajeAula(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  const body = req.body as { mensaje?: unknown };
  const mensaje = typeof body.mensaje === "string" ? body.mensaje : "";

  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ ok: false, error: "contrato_invalido" });
    return;
  }
  const resultado = await enviarMensajeAula(usuarioId, contratoId, mensaje);
  if (!resultado.ok) {
    const err = resultado.error;
    res.status(200).json({ ok: false, error: "mensaje" in err ? err.mensaje : "Datos inválidos." });
    return;
  }
  res.status(200).json({ ok: true });
}

export async function postTypingAula(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ ok: false, error: "contrato_invalido" });
    return;
  }
  const ok = await setTypingAula(usuarioId, contratoId);
  res.status(ok ? 200 : 403).json({ ok });
}

export async function getEstadoAulaHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const estado = await getEstadoAula(contratoId, usuarioId);
  if (!estado) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }
  res.status(200).json(estado);
}

export async function postPresenciaAula(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ ok: false, error: "contrato_invalido" });
    return;
  }
  const ok = await registrarPresenciaSala(usuarioId, contratoId, await esAdminReq(req));
  res.status(ok ? 200 : 403).json({ ok });
}

export async function deletePresenciaAula(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ ok: false, error: "contrato_invalido" });
    return;
  }
  const ok = await salirDeSala(usuarioId, contratoId, await esAdminReq(req));
  res.status(ok ? 200 : 403).json({ ok });
}

export async function getPresenciaAulaHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const estado = await getEstadoPresenciaSala(contratoId, usuarioId, await esAdminReq(req));
  if (!estado) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }
  res.status(200).json(estado);
}

export async function getArchivosAulaHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ error: "contrato_invalido" });
    return;
  }
  const archivos = await getArchivosContrato(contratoId, usuarioId, await esAdminReq(req));
  if (!archivos) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }
  res.status(200).json({ archivos });
}

const MENSAJES_ERROR_SUBIDA: Record<string, string> = {
  sin_acceso: "Sin acceso al contrato.",
  aula_cerrada: "Esta aula ya está cerrada.",
  peso: "El archivo no debe superar los 50 MB.",
  extension: "Formato de archivo no permitido.",
  contenido: "El contenido del archivo no coincide con su extensión.",
};

export async function postSubirArchivoAula(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const contratoId = Number(req.params.id);
  const archivo = (req as Request & { file?: Express.Multer.File }).file;

  if (!Number.isInteger(contratoId) || contratoId <= 0) {
    res.status(400).json({ ok: false, error: "contrato_invalido" });
    return;
  }
  if (!archivo) {
    res.status(400).json({ ok: false, error: "No llegó ningún archivo." });
    return;
  }

  const resultado = await subirArchivoContrato(usuarioId, contratoId, archivo);
  if (!resultado.ok) {
    res.status(400).json({ ok: false, error: MENSAJES_ERROR_SUBIDA[resultado.error.tipo] });
    return;
  }
  res.status(200).json({ ok: true, archivoId: resultado.archivoId });
}

export async function getArchivoAulaHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const archivoId = Number(req.params.archivoId);
  if (!Number.isInteger(archivoId) || archivoId <= 0) {
    res.status(404).type("text/plain").send("Archivo no encontrado.");
    return;
  }

  const info = await getArchivoContratoInfo(archivoId, usuarioId, await esAdminReq(req));
  if (!info) {
    res.status(403).type("text/plain").send("Acceso denegado.");
    return;
  }

  const dirBase = path.resolve(env.materialesAulaDir);
  const rutaReal = path.resolve(dirBase, info.rutaRelativa);
  if (!rutaReal.startsWith(dirBase + path.sep) || !fs.existsSync(rutaReal) || !fs.statSync(rutaReal).isFile()) {
    res.status(404).type("text/plain").send("Archivo no encontrado.");
    return;
  }

  const nombreHeader = info.nombre.replace(/[\r\n"]/g, "");
  res.setHeader("Content-Type", info.mime);
  res.setHeader("Content-Disposition", `attachment; filename="${nombreHeader}"`);
  res.setHeader("X-Content-Type-Options", "nosniff");
  res.setHeader("Content-Security-Policy", "default-src 'none'");
  fs.createReadStream(rutaReal).pipe(res);
}
