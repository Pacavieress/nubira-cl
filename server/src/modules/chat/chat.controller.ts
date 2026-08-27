import fs from "node:fs";
import path from "node:path";
import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import {
  eliminarChats,
  enviarMensaje,
  getArchivoChatInfo,
  getBandeja,
  getChatDetalle,
  getMensajes,
  iniciarChat,
  setTyping,
} from "./chat.repository.js";

export async function getBandejaHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const items = await getBandeja(usuarioId);
  res.status(200).json({ items });
}

export async function postEliminarChats(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as { ids?: unknown };
  if (!Array.isArray(body.ids) || body.ids.length === 0) {
    res.status(400).json({ success: false, error: "Datos inválidos" });
    return;
  }

  // Puerto exacto del parseo "tipo_id" de eliminar_conversacion.php:26-30.
  const items: { tipo: "negociacion" | "aula"; id: number }[] = [];
  for (const raw of body.ids) {
    if (typeof raw !== "string") continue;
    const partes = raw.split("_");
    if (partes.length !== 2) continue;
    const [tipo, idStr] = partes;
    if (tipo !== "negociacion" && tipo !== "aula") continue;
    const id = Number(idStr);
    if (!Number.isInteger(id) || id <= 0) continue;
    items.push({ tipo, id });
  }

  const eliminados = await eliminarChats(usuarioId, items);
  res.status(200).json({ success: true, eliminados });
}

const MENSAJES_ERROR_INICIAR_CHAT: Record<string, string> = {
  servicio_no_encontrado: "El servicio solicitado no existe o fue eliminado.",
  propio_servicio: "No puedes iniciar un chat contigo mismo.",
};

export async function postIniciarChat(req: Request, res: Response): Promise<void> {
  const compradorId = req.usuarioId as number;
  const body = req.body as { servicioId?: unknown; mensajeInicial?: unknown };
  const servicioId = Number(body.servicioId);
  const mensajeInicial = typeof body.mensajeInicial === "string" ? body.mensajeInicial.slice(0, 1000) : "";

  if (!Number.isInteger(servicioId) || servicioId <= 0) {
    res.status(400).json({ error: "servicio_invalido" });
    return;
  }

  const resultado = await iniciarChat(compradorId, servicioId, mensajeInicial);
  if (!resultado.ok) {
    res.status(400).json({ error: resultado.error, mensaje: MENSAJES_ERROR_INICIAR_CHAT[resultado.error] });
    return;
  }
  res.status(200).json({ ok: true, chatId: resultado.chatId });
}

export async function getChatDetalleHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const chatId = Number(req.params.id);
  if (!Number.isInteger(chatId) || chatId <= 0) {
    res.status(400).json({ error: "chat_invalido" });
    return;
  }

  const detalle = await getChatDetalle(chatId, usuarioId);
  if (!detalle) {
    res.status(404).json({ error: "chat_no_encontrado" });
    return;
  }
  res.status(200).json(detalle);
}

export async function getMensajesHandler(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const chatId = Number(req.params.id);
  if (!Number.isInteger(chatId) || chatId <= 0) {
    res.status(400).json({ error: "chat_invalido" });
    return;
  }

  const resultado = await getMensajes(chatId, usuarioId);
  if (!resultado) {
    res.status(403).json({ error: "sin_acceso" });
    return;
  }
  res.status(200).json(resultado);
}

const MENSAJES_ERROR_ENVIAR: Record<string, string> = {
  requiere_completar: "Para enviar más mensajes, crea una contraseña y protege tu cuenta.",
};

export async function postEnviarMensaje(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const chatId = Number(req.params.id);
  const body = req.body as { mensaje?: unknown };
  const mensaje = typeof body.mensaje === "string" ? body.mensaje : "";

  if (!Number.isInteger(chatId) || chatId <= 0) {
    res.status(400).json({ ok: false, error: "chat_invalido" });
    return;
  }

  const resultado = await enviarMensaje(usuarioId, chatId, mensaje);
  if (!resultado.ok) {
    const err = resultado.error;
    if (err.tipo === "requiere_completar") {
      res.status(200).json({ ok: false, requiereCompletar: true });
      return;
    }
    if (err.tipo === "limite_alcanzado") {
      res.status(200).json({ ok: false, limiteAlcanzado: true, error: err.mensaje });
      return;
    }
    res.status(200).json({ ok: false, error: err.mensaje });
    return;
  }
  res.status(200).json({ ok: true, mostrarBannerExpress: resultado.mostrarBannerExpress });
}

export async function postTyping(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const chatId = Number(req.params.id);
  if (!Number.isInteger(chatId) || chatId <= 0) {
    res.status(400).json({ ok: false, error: "chat_invalido" });
    return;
  }
  const ok = await setTyping(usuarioId, chatId);
  res.status(ok ? 200 : 403).json({ ok });
}

// Puerto exacto de ver_archivo_chat.php:97-141 — misma defensa anti path-traversal
// (containment check contra el directorio real), mismo whitelist de MIME para servir
// inline vs. forzar descarga.
const INLINE_SEGUROS = new Set(["image/jpeg", "image/png", "image/webp", "application/pdf"]);

export async function getArchivoChat(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const mensajeId = Number(req.params.mensajeId);
  const forzarDescarga = req.query.dl !== undefined;

  if (!Number.isInteger(mensajeId) || mensajeId <= 0) {
    res.status(404).type("text/plain").send("Archivo no encontrado.");
    return;
  }

  const info = await getArchivoChatInfo(mensajeId, usuarioId);
  if (!info) {
    res.status(403).type("text/plain").send("Acceso denegado.");
    return;
  }

  const dirBase = path.resolve(env.chatArchivosDir);
  const rutaReal = path.resolve(dirBase, info.rutaRelativa);
  if (!rutaReal.startsWith(dirBase + path.sep)) {
    res.status(404).type("text/plain").send("Archivo no encontrado.");
    return;
  }
  if (!fs.existsSync(rutaReal) || !fs.statSync(rutaReal).isFile()) {
    res.status(404).type("text/plain").send("Archivo no encontrado.");
    return;
  }

  const disposition = forzarDescarga || !INLINE_SEGUROS.has(info.mime) ? "attachment" : "inline";
  const nombreHeader = info.nombre.replace(/[\r\n"]/g, "");

  res.setHeader("Content-Type", info.mime);
  res.setHeader("Content-Disposition", `${disposition}; filename="${nombreHeader}"`);
  res.setHeader("X-Content-Type-Options", "nosniff");
  res.setHeader("Content-Security-Policy", "default-src 'none'");
  res.setHeader("Cache-Control", "private, max-age=3600");
  fs.createReadStream(rutaReal).pipe(res);
}
