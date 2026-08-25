import type { Request, Response } from "express";
import * as repo from "./adminUsuarios.repository.js";
import type { FiltroRol, FiltroVerificado, UsuarioListado, UsuariosResumen } from "./adminUsuarios.types.js";

function normalizarRol(v: unknown): FiltroRol {
  return v === "admin" || v === "alumno" ? v : "";
}
function normalizarVerificado(v: unknown): FiltroVerificado {
  return v === "si" || v === "no" ? v : "";
}
function aFechaIso(d: Date | null): string | null {
  return d ? new Date(d).toISOString() : null;
}

export async function getUsuarios(req: Request, res: Response): Promise<void> {
  await repo.marcarVistosAdmin();

  const q = typeof req.query.q === "string" ? req.query.q.trim() : "";
  const rol = normalizarRol(req.query.rol);
  const verificado = normalizarVerificado(req.query.verificado);
  const page = Math.max(1, Number(req.query.page) || 1);

  const { usuarios: filas, totalUsers, totalUsersGlobal } = await repo.listarUsuarios({ q, rol, verificado, page });

  const usuarios: UsuarioListado[] = filas.map((u) => ({
    id: u.id,
    nombre: u.nombre,
    correo: u.correo,
    fotoPerfil: u.foto_perfil,
    fechaRegistro: aFechaIso(u.fecha_registro),
    bloqueado: u.bloqueado === 1,
    confirmado: u.confirmado === 1,
    suspendidoHasta: aFechaIso(u.suspendido_hasta),
    ultimoReenvio: aFechaIso(u.ultimo_reenvio),
    rol: u.rol,
    totalServicios: u.total_servicios,
    totalApuntes: u.total_apuntes,
    totalReclamos: u.total_reclamos,
  }));

  const resumen: UsuariosResumen = {
    page,
    totalPages: Math.ceil(totalUsers / 20) || 1,
    totalUsers,
    totalUsersGlobal,
    usuarios,
  };
  res.status(200).json(resumen);
}
