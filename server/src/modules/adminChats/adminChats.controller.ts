import type { Request, Response } from "express";
import * as repo from "./adminChats.repository.js";
import type { FiltroChats } from "./adminChats.repository.js";
import type { ArchivoModeracion, ChatDetalle, ChatListado, ContadoresChats, DlpIntento, MensajeChat } from "./adminChats.types.js";

const FILTROS_VALIDOS: FiltroChats[] = ["activos", "cerrados", "contrato", "cotizacion", "inactivos", "alertas_dlp", "moderacion"];
function normalizarFiltro(v: unknown): FiltroChats {
  return FILTROS_VALIDOS.includes(v as FiltroChats) ? (v as FiltroChats) : "activos";
}
function iso(d: Date | null | undefined): string | null {
  return d ? new Date(d).toISOString() : null;
}

export async function getChats(req: Request, res: Response): Promise<void> {
  const filtro = normalizarFiltro(req.query.estado);
  const orden = req.query.orden === "asc" ? "asc" : "desc";
  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";

  const filas = await repo.listarChats(filtro, orden, q);
  const chats: ChatListado[] = filas.map((c) => ({
    id: c.id,
    contratoId: c.contrato_id,
    fechaOrden: iso(c.fecha_orden),
    eliminado: c.eliminado === 1,
    compradorId: c.uid1,
    compradorNombre: c.n1 ?? "Usuario",
    compradorFoto: c.f1,
    vendedorId: c.uid2,
    vendedorNombre: c.n2 ?? "Usuario",
    vendedorFoto: c.f2,
    servicioTitulo: c.servicio_titulo,
  }));
  res.status(200).json({ estado: filtro, orden, q, chats });
}

export async function getContadores(_req: Request, res: Response): Promise<void> {
  const c = await repo.contarChats();
  const contadores: ContadoresChats = c;
  res.status(200).json(contadores);
}

export async function getModeracion(_req: Request, res: Response): Promise<void> {
  const filas = await repo.listarModeracion();
  const archivos: ArchivoModeracion[] = filas.map((m) => ({
    id: m.id,
    conversacionId: m.conversacion_id,
    archivoRuta: m.archivo_ruta,
    archivoNombre: m.archivo_nombre,
    archivoTipo: m.archivo_tipo,
    archivoPeso: m.archivo_peso,
    enviadoEn: iso(m.enviado_en),
    remitenteNombre: m.remitente_nombre ?? "Desconocido",
  }));
  res.status(200).json({ archivos });
}

function parseId(v: unknown): number | null {
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}

export async function getChatDetalle(req: Request, res: Response): Promise<void> {
  const chatId = parseId(req.params.id);
  if (chatId === null) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }

  const infoRow = await repo.obtenerInfoChat(chatId);
  if (!infoRow) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const [metaRow, mensajesFilas, dlpFilas] = await Promise.all([
    repo.obtenerMetadataChat(chatId),
    repo.listarMensajes(chatId),
    repo.listarDlpDeChat(chatId),
  ]);

  const mensajes: MensajeChat[] = mensajesFilas.map((m) => ({
    id: m.id,
    remitenteId: m.remitente_id,
    mensaje: m.mensaje,
    archivoNombre: m.archivo_nombre,
    archivoRuta: m.archivo_ruta,
    archivoTipo: m.archivo_tipo,
    archivoPeso: m.archivo_peso,
    enviadoEn: iso(m.enviado_en),
  }));

  const dlp: DlpIntento[] = dlpFilas.map((d) => ({
    id: d.id,
    categoria: d.categoria,
    textoIntentado: d.texto_intentado,
    fecha: iso(d.fecha),
    revisadoAdmin: d.revisado_admin === 1,
    remitenteNombre: d.remitente_nombre ?? "Desconocido",
  }));

  const detalle: ChatDetalle = {
    info: {
      id: chatId,
      compradorId: infoRow.uid1,
      compradorNombre: infoRow.n1 ?? "Usuario",
      compradorFoto: infoRow.f1,
      vendedorId: infoRow.uid2,
      vendedorNombre: infoRow.n2 ?? "Usuario",
      vendedorFoto: infoRow.f2,
      servicioTitulo: infoRow.servicio_titulo,
      contratoId: infoRow.contrato_id,
      eliminado: infoRow.eliminado === 1,
    },
    mensajes,
    dlp,
    metadata: {
      totalMensajes: metaRow.total ?? 0,
      archivos: Number(metaRow.archivos ?? 0),
      primero: iso(metaRow.primero),
      ultimo: iso(metaRow.ultimo),
    },
  };
  res.status(200).json(detalle);
}

export async function postEliminarChat(req: Request, res: Response): Promise<void> {
  const chatId = parseId(req.params.id);
  if (chatId === null) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }
  const ok = await repo.alternarEliminadoChat(chatId, true);
  res.status(200).json({ ok });
}

export async function postRestaurarChat(req: Request, res: Response): Promise<void> {
  const chatId = parseId(req.params.id);
  if (chatId === null) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }
  const ok = await repo.alternarEliminadoChat(chatId, false);
  res.status(200).json({ ok });
}

export async function postMarcarRevisadoDlp(req: Request, res: Response): Promise<void> {
  const chatId = parseId(req.params.id);
  if (chatId === null) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }
  const ok = await repo.marcarRevisadoDlp(chatId);
  res.status(200).json({ ok });
}

export async function postAprobarArchivo(req: Request, res: Response): Promise<void> {
  const msgId = parseId(req.params.msgId);
  if (msgId === null) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }
  const ok = await repo.aprobarArchivo(msgId);
  res.status(200).json({ ok });
}
