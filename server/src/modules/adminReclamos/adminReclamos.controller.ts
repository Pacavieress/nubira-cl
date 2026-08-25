import type { Request, Response } from "express";
import * as repo from "./adminReclamos.repository.js";
import type { AccionLote, EstadoFiltro, MensajeHilo, Ticket } from "./adminReclamos.types.js";

const ESTADOS_VALIDOS: EstadoFiltro[] = ["activos", "resuelto", "todos", "eliminado"];

function normalizarEstado(v: unknown): EstadoFiltro {
  return ESTADOS_VALIDOS.includes(v as EstadoFiltro) ? (v as EstadoFiltro) : "activos";
}

function aFechaIso(d: Date): string {
  return d.toISOString();
}

export async function getReclamos(req: Request, res: Response): Promise<void> {
  const estado = normalizarEstado(req.query.estado);
  const [contadores, filas] = await Promise.all([repo.contarPorEstado(), repo.listarTickets(estado)]);
  const mensajesPorTicket = await repo.listarMensajesPorTicket(filas.map((f) => f.id));

  // Puerto exacto de admin_reclamos.php:201-220 (armado del hilo + flag "urgente").
  const tickets: Ticket[] = filas.map((t) => {
    const mensajesBd = mensajesPorTicket.get(t.id) ?? [];
    const hilo: MensajeHilo[] = [{ remitente: "usuario", mensaje: t.texto, fecha: aFechaIso(t.fecha) }];

    if (t.respuesta_admin) {
      const esDup = mensajesBd.some((mt) => mt.remitente === "admin" && mt.mensaje.trim() === t.respuesta_admin!.trim());
      if (!esDup) hilo.push({ remitente: "admin", mensaje: t.respuesta_admin, fecha: aFechaIso(t.fecha) });
    }

    for (const m of mensajesBd) {
      hilo.push({ remitente: m.remitente, mensaje: m.mensaje, fecha: aFechaIso(m.fecha) });
    }

    hilo.sort((a, b) => new Date(a.fecha).getTime() - new Date(b.fecha).getTime());

    const urgente = t.estado === "pendiente" && Date.now() - t.fecha.getTime() > 86_400_000;

    return {
      id: t.id,
      fecha: aFechaIso(t.fecha),
      texto: t.texto,
      estado: t.estado,
      respuestaAdmin: t.respuesta_admin,
      usuarioNombre: t.usuario_raw,
      fotoPerfil: t.foto_perfil,
      chatThread: hilo,
      urgente,
    };
  });

  res.status(200).json({ estado, contadores, tickets });
}

function parseId(req: Request): number | null {
  const id = Number(req.params.id);
  return Number.isInteger(id) && id > 0 ? id : null;
}

export async function postResponder(req: Request, res: Response): Promise<void> {
  const id = parseId(req);
  const respuesta = typeof req.body?.respuesta === "string" ? req.body.respuesta.trim() : "";
  if (id === null || respuesta === "") {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  await repo.responder(id, respuesta);
  res.status(200).json({ ok: true });
}

export async function postResolver(req: Request, res: Response): Promise<void> {
  const id = parseId(req);
  if (id === null) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  const actualizado = await repo.resolver(id);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}

export async function postPapelera(req: Request, res: Response): Promise<void> {
  const id = parseId(req);
  if (id === null) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  const actualizado = await repo.papelera(id);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}

export async function postRestaurar(req: Request, res: Response): Promise<void> {
  const id = parseId(req);
  if (id === null) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  const actualizado = await repo.restaurar(id);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}

export async function deleteHard(req: Request, res: Response): Promise<void> {
  const id = parseId(req);
  if (id === null) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  const eliminado = await repo.eliminarHard(id);
  if (!eliminado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}

const ACCIONES_VALIDAS: AccionLote[] = ["papelera", "restaurar", "eliminar_hard"];

export async function postAccionLote(req: Request, res: Response): Promise<void> {
  const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(Number).filter((n: number) => Number.isInteger(n) && n > 0) : [];
  const accion = req.body?.accion;
  if (ids.length === 0 || !ACCIONES_VALIDAS.includes(accion)) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }
  const afectados = await repo.accionLote(ids, accion);
  res.status(200).json({ ok: true, afectados });
}
