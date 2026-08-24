import type { Request, Response } from "express";
import { crearCupon, eliminarCupon, existeCodigo, listarCupones, listarServiciosAprobados } from "./adminCupones.repository.js";
import type { CuponBeca, CuponesResumen, ServicioParaCupon } from "./adminCupones.types.js";

export async function getCupones(_req: Request, res: Response): Promise<void> {
  const [filasCupones, filasServicios] = await Promise.all([listarCupones(), listarServiciosAprobados()]);

  const cupones: CuponBeca[] = filasCupones.map((c) => ({
    id: c.id,
    codigo: c.codigo,
    porcentajeDescuento: c.porcentaje_descuento,
    usosActuales: c.usos_actuales,
    usosMaximos: c.usos_maximos,
    servicioId: c.servicio_id,
    servicioTitulo: c.servicio_titulo,
    fechaExpiracion: c.fecha_expiracion ? c.fecha_expiracion.toISOString() : null,
  }));

  const servicios: ServicioParaCupon[] = filasServicios.map((s) => ({ id: s.id, titulo: s.titulo, precio: s.precio }));

  const body: CuponesResumen = { cupones, servicios };
  res.status(200).json(body);
}

// Puerto de la lógica de creación (admin_procesar_cupon.php:48-97) — mismo saneo (código en
// mayúsculas), misma validación mínima (código obligatorio).
export async function postCupon(req: Request, res: Response): Promise<void> {
  const body = req.body as {
    codigo?: unknown;
    porcentajeDescuento?: unknown;
    usosMaximos?: unknown;
    servicioId?: unknown;
    fechaExpiracion?: unknown;
  };

  const codigo = typeof body.codigo === "string" ? body.codigo.trim().toUpperCase() : "";
  if (!codigo) {
    res.status(400).json({ error: "codigo_requerido" });
    return;
  }

  const porcentajeDescuento = Number(body.porcentajeDescuento);
  if (!Number.isInteger(porcentajeDescuento) || porcentajeDescuento < 1 || porcentajeDescuento > 100) {
    res.status(400).json({ error: "porcentaje_invalido" });
    return;
  }

  const usosMaximos = Number(body.usosMaximos);
  if (!Number.isInteger(usosMaximos) || usosMaximos < 1) {
    res.status(400).json({ error: "usos_invalidos" });
    return;
  }

  const servicioId = typeof body.servicioId === "number" && Number.isInteger(body.servicioId) && body.servicioId > 0 ? body.servicioId : null;
  const fechaExpiracion = typeof body.fechaExpiracion === "string" && body.fechaExpiracion.trim() !== "" ? body.fechaExpiracion.trim() : null;

  if (await existeCodigo(codigo)) {
    res.status(409).json({ error: "codigo_duplicado", mensaje: `El código '${codigo}' ya está registrado.` });
    return;
  }

  const id = await crearCupon({ codigo, porcentajeDescuento, usosMaximos, servicioId, fechaExpiracion });
  const body200: CuponBeca = {
    id,
    codigo,
    porcentajeDescuento,
    usosActuales: 0,
    usosMaximos,
    servicioId,
    servicioTitulo: null,
    fechaExpiracion,
  };
  res.status(201).json(body200);
}

export async function deleteCupon(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const eliminado = await eliminarCupon(id);
  if (!eliminado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}
