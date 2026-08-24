import type { Request, Response } from "express";
import {
  autorizarVip,
  contarFallos,
  contarPendientes,
  contarVips,
  limpiarFallos,
  listarFallos,
  listarPendientes,
  listarVips,
  revocarVip,
} from "./adminLoginFallos.repository.js";
import type { MonitoreoResumen, MonitoreoTab } from "./adminLoginFallos.types.js";

const LIMIT = 50; // admin_login_fallos.php:44

function normalizarTab(v: unknown): MonitoreoTab {
  return v === "vips" || v === "pendientes" ? v : "fallos";
}

export async function getMonitoreo(req: Request, res: Response): Promise<void> {
  const tab = normalizarTab(req.query.tab);
  const page = Math.max(1, Number(req.query.page) || 1);
  const offset = (page - 1) * LIMIT;

  const [cntFallos, cntVips, cntPendientes] = await Promise.all([contarFallos(), contarVips(), contarPendientes()]);

  const body: MonitoreoResumen = {
    tab,
    page,
    limit: LIMIT,
    total: tab === "fallos" ? cntFallos : tab === "vips" ? cntVips : cntPendientes,
    contadores: { fallos: cntFallos, vips: cntVips, pendientes: cntPendientes },
  };

  if (tab === "fallos") {
    const filas = await listarFallos(offset, LIMIT);
    body.itemsFallos = filas.map((f) => ({ correo: f.correo, ip: f.ip, fecha: f.fecha.toISOString(), esAlumno: f.es_alumno > 0 }));
  } else if (tab === "vips") {
    const filas = await listarVips(offset, LIMIT);
    body.itemsVips = filas.map((v) => ({ id: v.id, correo: v.correo, fechaCreacion: v.fecha_creacion.toISOString() }));
  } else {
    const filas = await listarPendientes(offset, LIMIT);
    body.itemsPendientes = filas.map((p) => ({ id: p.id, nombre: p.nombre, correo: p.correo, carrera: p.carrera, dominio: p.dominio }));
  }

  res.status(200).json(body);
}

function correoValido(body: unknown): string | null {
  const correo = (body as { correo?: unknown })?.correo;
  return typeof correo === "string" && correo.trim() !== "" ? correo.trim().toLowerCase() : null;
}

// Puerto de la rama 'limpiar_fallos' (admin_login_fallos.php:80-84).
export async function deleteFallos(req: Request, res: Response): Promise<void> {
  const correo = correoValido(req.body);
  if (!correo) {
    res.status(400).json({ error: "correo_invalido" });
    return;
  }
  await limpiarFallos(correo);
  res.status(200).json({ ok: true });
}

// Puerto de la rama 'autorizar_gmail' (admin_login_fallos.php:54-66).
export async function postAutorizarVip(req: Request, res: Response): Promise<void> {
  const correo = correoValido(req.body);
  if (!correo) {
    res.status(400).json({ error: "correo_invalido" });
    return;
  }
  await autorizarVip(correo);
  res.status(200).json({ ok: true });
}

// Puerto de la rama 'revocar_vip' (admin_login_fallos.php:69-77).
export async function postRevocarVip(req: Request, res: Response): Promise<void> {
  const correo = correoValido(req.body);
  if (!correo) {
    res.status(400).json({ error: "correo_invalido" });
    return;
  }
  await revocarVip(correo);
  res.status(200).json({ ok: true });
}
