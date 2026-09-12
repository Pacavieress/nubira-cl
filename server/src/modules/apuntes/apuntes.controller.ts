import type { Request, Response } from "express";
import { getUsuarioConRol } from "../auth/auth.repository.js";
import { DEMO_TUTOR_USER_ID, viewerCalificaDemo } from "../../lib/demoTutorVisibility.js";
import { enlaceDescargaApunte } from "../../lib/enlaceDescargaApunte.js";
import { mapApunteDetalleRow, mapApunteRow } from "./apuntes.mapper.js";
import { existeCompraPagada, getApunteDetalleById, getCategoriasApuntes, searchApuntesPublicos } from "./apuntes.repository.js";

const DEFAULT_LIMIT = 20;
const MAX_LIMIT = 50;

function parsePage(value: unknown): number {
  const n = Number(value);
  return Number.isInteger(n) && n >= 1 ? n : 1;
}

function parseLimit(value: unknown): number {
  const n = Number(value);
  if (!Number.isInteger(n) || n < 1) return DEFAULT_LIMIT;
  return Math.min(n, MAX_LIMIT);
}

function parseStringFilter(value: unknown): string | undefined {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
}

function parsePrecioFilter(value: unknown): "gratis" | "pagado" | undefined {
  return value === "gratis" || value === "pagado" ? value : undefined;
}

export async function getApuntesList(req: Request, res: Response): Promise<void> {
  const page = parsePage(req.query.page);
  const limit = parseLimit(req.query.limit);
  const viewerCalifica = await viewerCalificaDemo(req.usuarioId);

  const { rows, hayMas } = await searchApuntesPublicos(
    {
      nivel: parseStringFilter(req.query.nivel),
      precio: parsePrecioFilter(req.query.precio),
      orden: parseStringFilter(req.query.orden),
      q: parseStringFilter(req.query.q),
      materia: parseStringFilter(req.query.materia),
      categoria: parseStringFilter(req.query.categoria),
      page,
      limit,
    },
    viewerCalifica,
  );

  res.status(200).json({
    data: rows.map(mapApunteRow),
    meta: { page, limit, hayMas },
  });
}

export async function getApuntesCategoriasList(req: Request, res: Response): Promise<void> {
  const viewerCalifica = await viewerCalificaDemo(req.usuarioId);
  const categorias = await getCategoriasApuntes(viewerCalifica);
  res.status(200).json({ data: categorias });
}

export async function getApunteDetail(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "invalid_id" });
    return;
  }

  const row = await getApunteDetalleById(id);
  if (!row) {
    res.status(404).json({ error: "not_found" });
    return;
  }

  // [DEMO] Cuenta demo (contacto@nubira.cl) — contenido invisible a quien no califica.
  if (row.id_alumno === DEMO_TUTOR_USER_ID && req.usuarioId !== row.id_alumno) {
    const viewerCalifica = await viewerCalificaDemo(req.usuarioId);
    if (!viewerCalifica) {
      res.status(404).json({ error: "not_found" });
      return;
    }
  }

  // req.usuarioId lo pone optionalAuth (apuntes.routes.ts) SOLO si había una sesión
  // válida — undefined para un visitante, nunca un valor asumido por defecto.
  const accesoCompleto = await calcularAccesoCompleto(req.usuarioId, row);

  // Puerto de ver_apunte.php:311 — SOLO se firma el link si hay acceso real (el PHP real lo
  // genera siempre para cualquier logueado, pero acá se restringe a accesoCompleto: no tiene
  // sentido exponer un link firmado a quien de todos modos no podría usarlo, y evita pagar
  // el costo de firmar en el caso común de un visitante/comprador sin acceso).
  // try/catch a propósito: enlaceDescargaApunte() revienta con throw si falta
  // NUBIRA_HMAC_SECRET (ver src/lib/enlaceDescargaApunte.ts) — sin este catch, un VPS sin
  // esa env var tumbaría el endpoint de detalle COMPLETO (título, descripción, precio, todo)
  // por un secreto que ni siquiera hace falta para lo demás. Con el catch: fileUrl queda
  // null, se loguea el error, y el resto del apunte se devuelve igual.
  let fileUrl: string | null = null;
  if (accesoCompleto && req.usuarioId !== undefined && row.archivo) {
    try {
      fileUrl = enlaceDescargaApunte(row.id, row.archivo, req.usuarioId);
    } catch (e) {
      console.error("[apuntes] no se pudo firmar fileUrl (¿falta NUBIRA_HMAC_SECRET?):", e);
      fileUrl = null;
    }
  }

  res.status(200).json(
    mapApunteDetalleRow(
      row,
      {
        isAuthenticated: req.usuarioId !== undefined,
        isOwner: req.usuarioId === row.id_alumno,
        accesoCompleto,
      },
      fileUrl,
    ),
  );
}

// Puerto exacto de las 4 ramas de ver_apunte.php:292-307 (acceso_completo), incluida la
// asimetría real del PHP: TODO el bloque vive dentro de `if ($logueado)` — un invitado sin
// sesión nunca llega a evaluar ninguna rama, ni siquiera la de "gratis". getUsuarioConRol()
// (auth.repository.ts, ya usado por requireAdmin) se reusa tal cual, sin duplicar la query
// — y solo se llama si ni "gratis" ni "dueño" ya resolvieron el acceso, mismo criterio de
// costo-solo-cuando-hace-falta que el resto de este módulo.
async function calcularAccesoCompleto(usuarioId: number | undefined, apunte: { id: number; precio: number; id_alumno: number }): Promise<boolean> {
  if (usuarioId === undefined) return false;

  const esGratis = apunte.precio === 0;
  const esDueno = usuarioId === apunte.id_alumno;
  if (esGratis || esDueno) return true;

  const usuario = await getUsuarioConRol(usuarioId);
  if (usuario?.rol === "admin") return true;

  return existeCompraPagada(usuarioId, apunte.id);
}
