import type { Request, Response } from "express";
import { geoipLookup } from "../../lib/geoip.js";
import { esPrimerPing, upsertVistaDetalle } from "./vistasDetalle.repository.js";
import type { Dispositivo, TipoPublicacion, VistaDetalleInput } from "./vistasDetalle.types.js";

const TIPOS_VALIDOS: TipoPublicacion[] = ["servicio", "apunte"];
const DISPOSITIVOS_VALIDOS: Dispositivo[] = ["movil", "tablet", "desktop"];

// Puerto exacto de track_vista.php:22-45 (validación de input).
export async function postVista(req: Request, res: Response): Promise<void> {
  const body = req.body as Record<string, unknown>;

  const tipo = body.tipo;
  const publicacionId = Number(body.publicacion_id ?? 0);
  const sessionIdRaw = typeof body.session_id === "string" ? body.session_id : "";

  if (!TIPOS_VALIDOS.includes(tipo as TipoPublicacion) || !Number.isInteger(publicacionId) || publicacionId <= 0 || sessionIdRaw.length < 10) {
    res.status(200).json({ ok: false });
    return;
  }

  const sessionId = sessionIdRaw.slice(0, 64);
  const origenRaw = typeof body.origen === "string" ? body.origen : "";
  const origen = origenRaw !== "" ? origenRaw.slice(0, 120) : null;

  const tiempoSegundos = Math.min(Number(body.tiempo_segundos) || 0, 7200);
  const scrollMaxPct = Math.max(0, Math.min(100, Number(body.scroll_max_pct) || 0));
  const leyoCompleto = Boolean(body.leyo_completo);
  const dispRaw = body.dispositivo;
  const dispositivo = DISPOSITIVOS_VALIDOS.includes(dispRaw as Dispositivo) ? (dispRaw as Dispositivo) : null;

  // req.usuarioId lo pone optionalAuth — undefined para un visitante, nunca asumido.
  const usuarioId = req.usuarioId !== undefined && req.usuarioId > 0 ? req.usuarioId : null;

  const ipHeader = req.headers["x-forwarded-for"];
  const ipRaw = (Array.isArray(ipHeader) ? ipHeader[0] : ipHeader) ?? req.socket.remoteAddress ?? "";
  const ip = ipRaw.slice(0, 45);

  const input: VistaDetalleInput = {
    tipo: tipo as TipoPublicacion,
    publicacionId,
    sessionId,
    tiempoSegundos,
    scrollMaxPct,
    leyoCompleto,
    dispositivo,
    origen,
    usuarioId,
    ip,
  };

  const esPrimero = await esPrimerPing(sessionId, input.tipo, publicacionId);
  const geo = esPrimero ? await geoipLookup(ip) : { pais: null, ciudad: null };

  await upsertVistaDetalle(input, geo);
  res.status(200).json({ ok: true });
}
