import type { Request, Response } from "express";
import { env } from "../../config/env.js";
import { resolverFotoTutor } from "../../lib/media.js";
import { getInfoContrato, listarAulas, listarMensajesAula, listarMensajesPrevios } from "./adminAulas.repository.js";
import type { AulaDetalle, AulaListado, AulaMensaje } from "./adminAulas.types.js";

const ESTADOS_CERRADOS = new Set(["finalizado", "cancelado", "disputa"]);
const VENTANA_EN_VIVO_MS = 120_000; // admin_chats_aula.php:81 — 120 segundos

export async function getAulas(req: Request, res: Response): Promise<void> {
  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";
  const orden = req.query.orden === "asc" ? "asc" : "desc";

  const filas = await listarAulas(q || undefined, orden);

  const body: AulaListado[] = filas.map((c) => {
    const fechaReferencia = c.fecha_aula ?? c.fecha_creacion;
    const enVivo = fechaReferencia ? Date.now() - fechaReferencia.getTime() <= VENTANA_EN_VIVO_MS : false;
    const cerrado = ESTADOS_CERRADOS.has(c.estado);

    return {
      id: c.id,
      estado: c.estado,
      fechaReferencia: fechaReferencia ? fechaReferencia.toISOString() : null,
      enVivo: enVivo && !cerrado,
      cerrado,
      compradorNombre: c.n1 ?? "Usuario",
      compradorFotoUrl: resolverFotoTutor(c.f1, c.n1, env.assetsBaseUrl),
      vendedorNombre: c.n2 ?? "Usuario",
      vendedorFotoUrl: resolverFotoTutor(c.f2, c.n2, env.assetsBaseUrl),
      ultimoMensaje: c.msg_aula,
    };
  });

  res.status(200).json(body);
}

// Puerto de admin_chats_aula.php:327-397 — historial combinado (pre-venta + aula virtual)
// de un contrato. A diferencia del PHP (que separa "carga inicial" de "smart polling
// ajax_messages", este último devolviendo SOLO los mensajes de aula para diffear el largo
// en el cliente), esta pieza expone un único endpoint que siempre devuelve el hilo
// completo — simplificación deliberada: es una herramienta de auditoría de bajo tráfico,
// no vale la pena el endpoint incremental separado. El Client Component hace el polling
// completo y diferencia por longitud igual que el JS original.
export async function getAulaMensajes(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }

  const info = await getInfoContrato(id);
  if (!info) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  const [previos, aula] = await Promise.all([listarMensajesPrevios(id), listarMensajesAula(id)]);

  const mensajes: AulaMensaje[] = [
    ...previos.map((m): AulaMensaje => ({ remitenteId: m.remitente_id, mensaje: m.mensaje, enviadoEn: m.enviado_en.toISOString(), origen: "previo" })),
    ...aula.map((m): AulaMensaje => ({ remitenteId: m.remitente_id, mensaje: m.mensaje, enviadoEn: m.enviado_en.toISOString(), origen: "aula" })),
  ];

  const body: AulaDetalle = {
    compradorId: info.comprador_id,
    compradorNombre: info.n1 ?? "Usuario",
    vendedorNombre: info.n2 ?? "Usuario",
    estado: info.estado,
    mensajes,
  };

  res.status(200).json(body);
}
