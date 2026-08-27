import type { Request, Response } from "express";
import { enviarCorreo } from "../../lib/correo.js";
import { getConfiguracionFinanciera, setConfiguracionFinanciera } from "../miBilletera/miBilletera.repository.js";
import { mapAuditoria, mapSolicitudRetiroAdmin } from "./adminRetiros.mapper.js";
import { aprobarRetiro, getAuditoriaContratos, getInfoParaCorreo, listarSolicitudesRetiro, rechazarRetiro } from "./adminRetiros.repository.js";
import { ESTADOS_RETIRO, type EstadoRetiro } from "./adminRetiros.types.js";

function esEstadoValido(valor: unknown): valor is EstadoRetiro {
  return typeof valor === "string" && (ESTADOS_RETIRO as readonly string[]).includes(valor);
}

// Puerto de admin_retiros.php:66 — mismo default ('pendiente') cuando no viene query param.
export async function getListadoRetiros(req: Request, res: Response): Promise<void> {
  const estadoQuery = req.query.estado;
  const estado: EstadoRetiro | "todas" = estadoQuery === "todas" ? "todas" : esEstadoValido(estadoQuery) ? estadoQuery : "pendiente";
  const institucion = typeof req.query.institucion === "string" ? req.query.institucion : "";

  const [filas, config] = await Promise.all([listarSolicitudesRetiro(estado, institucion), getConfiguracionFinanciera()]);

  res.status(200).json({
    solicitudes: filas.map(mapSolicitudRetiroAdmin),
    configuracion: config,
  });
}

// Puerto exacto de admin_retiros.php:25-53 (mismas 2 validaciones, mismo rango).
export async function putConfiguracionRetiros(req: Request, res: Response): Promise<void> {
  const body = req.body as { montoMinimo?: unknown; comision?: unknown };
  const montoMinimo = Number(body.montoMinimo);
  const comision = Number(body.comision);

  if (!Number.isInteger(montoMinimo) || montoMinimo < 1) {
    res.status(400).json({ error: "monto_invalido", mensaje: "El monto mínimo debe ser al menos 1 peso." });
    return;
  }
  if (!Number.isInteger(comision) || comision < 0 || comision > 100) {
    res.status(400).json({ error: "comision_invalida", mensaje: "La comisión debe estar entre 0% y 100%." });
    return;
  }

  await setConfiguracionFinanciera(montoMinimo, comision);
  res.status(200).json({ ok: true });
}

function idDesdeParams(req: Request): number | null {
  const id = Number(req.params.id);
  return Number.isInteger(id) && id > 0 ? id : null;
}

// Puerto exacto del correo de admin_retiros.php:110 (mismo asunto, mismo cuerpo).
async function enviarCorreoAprobado(correo: string, nombre: string, monto: number): Promise<boolean> {
  const montoF = "$" + monto.toLocaleString("es-CL");
  const msg = `<h3>¡Pago Enviado! 💸</h3><p>Hola <b>${nombre}</b>,</p><p>Te hemos transferido exitosamente los fondos solicitados desde tu billetera hacia tu cuenta bancaria.</p><p>Monto transferido: <b style='color:#059669; font-size:18px;'>${montoF}</b></p><hr><p><small>Equipo Nubira.cl</small></p>`;
  return enviarCorreo(correo, "✅ Pago Transferido a tu Cuenta", msg);
}

// Puerto exacto del correo de admin_retiros.php:124 (mismo asunto, mismo cuerpo).
async function enviarCorreoRechazado(correo: string, nombre: string): Promise<boolean> {
  const msg = `<h3>Retiro Rechazado ❌</h3><p>Hola <b>${nombre}</b>,</p><p>Tu solicitud de retiro ha sido rechazada. Por favor, revisa que tus datos bancarios en la plataforma estén correctos y vuelve a solicitar el retiro desde tu billetera.</p><hr><p><small>Equipo Nubira.cl</small></p>`;
  return enviarCorreo(correo, "❌ Revisa tus Datos Bancarios", msg);
}

// Puerto de admin_retiros.php:100-113, con el guard `estado='pendiente'` agregado (ver
// adminRetiros.repository.ts) y transferenciaId ahora requerido (activación de la columna
// muerta, a pedido del usuario) en vez de opcional.
export async function postAprobarRetiro(req: Request, res: Response): Promise<void> {
  const id = idDesdeParams(req);
  if (!id) {
    res.status(400).json({ ok: false, error: "id_invalido" });
    return;
  }
  const body = req.body as { transferenciaId?: unknown };
  const transferenciaId = typeof body.transferenciaId === "string" ? body.transferenciaId.trim() : "";
  if (!transferenciaId) {
    res.status(400).json({ ok: false, error: "transferencia_requerida", mensaje: "Ingresa la referencia de la transferencia real antes de aprobar." });
    return;
  }

  const aprobado = await aprobarRetiro(id, transferenciaId);
  if (!aprobado) {
    res.status(409).json({ ok: false, error: "ya_procesada", mensaje: "Esta solicitud ya no está pendiente (puede haber sido aprobada o rechazada por otra pestaña)." });
    return;
  }

  const info = await getInfoParaCorreo(id);
  const correoEnviado = info ? await enviarCorreoAprobado(info.correo, info.nombre, info.monto) : false;
  res.status(200).json({ ok: true, correoEnviado });
}

// Puerto de admin_retiros.php:115-127, con el mismo guard agregado.
export async function postRechazarRetiro(req: Request, res: Response): Promise<void> {
  const id = idDesdeParams(req);
  if (!id) {
    res.status(400).json({ ok: false, error: "id_invalido" });
    return;
  }

  const rechazado = await rechazarRetiro(id);
  if (!rechazado) {
    res.status(409).json({ ok: false, error: "ya_procesada", mensaje: "Esta solicitud ya no está pendiente (puede haber sido aprobada o rechazada por otra pestaña)." });
    return;
  }

  const info = await getInfoParaCorreo(id);
  const correoEnviado = info ? await enviarCorreoRechazado(info.correo, info.nombre) : false;
  res.status(200).json({ ok: true, correoEnviado });
}

export async function getAuditoriaRetiro(req: Request, res: Response): Promise<void> {
  const id = idDesdeParams(req);
  if (!id) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }
  const filas = await getAuditoriaContratos(id);
  res.status(200).json(mapAuditoria(filas));
}
