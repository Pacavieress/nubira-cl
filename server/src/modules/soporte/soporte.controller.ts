import type { Request, Response } from "express";
import { buildTicket } from "./soporte.mapper.js";
import {
  crearTicket,
  eliminarTickets,
  existeTicketDeUsuario,
  getMensajesPorTickets,
  getTicketsMaestros,
  marcarLeido,
  marcarResuelto,
  responderTicket,
} from "./soporte.repository.js";
import { CATEGORIAS_VALIDAS, type MisTicketsPublico } from "./soporte.types.js";

// req.usuarioId existe con certeza en todos los handlers de este archivo: requireAuth
// (ver soporte.routes.ts) ya cortó con 401 si no había sesión.

// Puerto exacto de reclamos_sugerencias.php:215-271 (historial + hilo + contadores).
export async function getMisTickets(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const ticketsRows = await getTicketsMaestros(usuarioId);
  const mensajesRows = await getMensajesPorTickets(ticketsRows.map((t) => t.id));

  const mensajesPorTicket = new Map<number, typeof mensajesRows>();
  for (const m of mensajesRows) {
    if (!mensajesPorTicket.has(m.reclamo_id)) mensajesPorTicket.set(m.reclamo_id, []);
    mensajesPorTicket.get(m.reclamo_id)!.push(m);
  }

  const tickets = ticketsRows.map((row) => buildTicket(row, mensajesPorTicket.get(row.id) ?? []));

  // Puerto exacto de reclamos_sugerencias.php:261-271.
  const contadores = { total: tickets.length, activos: 0, resueltos: 0, noLeidos: 0 };
  for (const t of tickets) {
    if (t.estado === "resuelto" || t.estado === "cerrado") contadores.resueltos++;
    else contadores.activos++;
    if (t.tieneRespuestaNueva) contadores.noLeidos++;
  }

  const body: MisTicketsPublico = { tickets, contadores };
  res.status(200).json(body);
}

// Puerto exacto de reclamos_sugerencias.php:78-107 (sin el push a admin, ver soporte.types.ts).
export async function crearMiTicket(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as { asunto?: unknown; mensaje?: unknown; categoria?: unknown };

  const asunto = typeof body.asunto === "string" ? body.asunto.trim() : "";
  const mensaje = typeof body.mensaje === "string" ? body.mensaje.trim() : "";
  const categoriaInput = typeof body.categoria === "string" ? body.categoria.trim() : "";
  const categoria = (CATEGORIAS_VALIDAS as readonly string[]).includes(categoriaInput) ? categoriaInput : "otro";

  if (!asunto || !mensaje) {
    res.status(400).json({ error: "campos_obligatorios", mensaje: "Debes completar el asunto y el mensaje." });
    return;
  }

  const id = await crearTicket(usuarioId, { asunto, mensaje, categoria });
  res.status(201).json({ id });
}

// Puerto exacto de reclamos_sugerencias.php:111-151.
export async function responderMiTicket(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const ticketId = Number(req.params.id);
  const mensaje = typeof (req.body as { mensaje?: unknown }).mensaje === "string" ? (req.body as { mensaje: string }).mensaje.trim() : "";

  if (!Number.isInteger(ticketId) || ticketId <= 0 || mensaje === "") {
    res.status(400).json({ error: "invalido", mensaje: "Debes escribir un mensaje para responder." });
    return;
  }

  const esPropio = await existeTicketDeUsuario(ticketId, usuarioId);
  if (!esPropio) {
    res.status(404).json({ error: "not_found", mensaje: "No encontramos ese ticket." });
    return;
  }

  await responderTicket(ticketId, mensaje);
  res.status(204).send();
}

// Puerto exacto de reclamos_sugerencias.php:194-213.
export async function resolverMiTicket(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const ticketId = Number(req.params.id);
  if (!Number.isInteger(ticketId) || ticketId <= 0) {
    res.status(400).json({ error: "invalido" });
    return;
  }

  const ok = await marcarResuelto(ticketId, usuarioId);
  if (!ok) {
    res.status(404).json({ error: "not_found", mensaje: "No se pudo cerrar el ticket. Verifica que sea tuyo e intenta de nuevo." });
    return;
  }
  res.status(204).send();
}

// Puerto exacto de reclamos_sugerencias.php:60-75.
export async function marcarMiTicketLeido(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const ticketId = Number(req.params.id);
  if (!Number.isInteger(ticketId) || ticketId <= 0) {
    res.status(400).json({ error: "invalido" });
    return;
  }
  await marcarLeido(ticketId, usuarioId);
  res.status(204).send();
}

// Puerto exacto de reclamos_sugerencias.php:155-191 — mismo endpoint cubre 1 o varios ids
// (el PHP real distingue $_POST['ticket_id'] vs $_POST['tickets_seleccionados'], acá el
// cliente siempre manda un array, aunque sea de 1 elemento).
export async function eliminarMisTickets(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as { ids?: unknown };
  const idsCrudos = Array.isArray(body.ids) ? body.ids : [];
  const ids = idsCrudos.map(Number).filter((n) => Number.isInteger(n) && n > 0);

  if (ids.length === 0) {
    res.status(400).json({ error: "sin_ids", mensaje: "No se seleccionó ningún ticket válido para eliminar." });
    return;
  }

  const afectados = await eliminarTickets(ids, usuarioId);
  res.status(200).json({ afectados });
}
