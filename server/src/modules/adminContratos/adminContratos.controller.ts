import type { Request, Response } from "express";
import { ESTADOS_VALIDOS, getStatsPorEstado, listarContratos } from "./adminContratos.repository.js";
import type { ContratosResumen, EstadoContrato } from "./adminContratos.types.js";

function esEstadoValido(valor: unknown): valor is EstadoContrato {
  return typeof valor === "string" && (ESTADOS_VALIDOS as string[]).includes(valor);
}

export async function getContratos(req: Request, res: Response): Promise<void> {
  const estado = esEstadoValido(req.query.estado) ? req.query.estado : undefined;

  const [stats, filas] = await Promise.all([getStatsPorEstado(), listarContratos(estado)]);
  const total = Object.values(stats).reduce((acc, n) => acc + n, 0);

  const body: ContratosResumen = {
    stats,
    total,
    contratos: filas.map((c) => ({
      id: c.id,
      estado: c.estado,
      monto: Number(c.monto),
      fechaCreacion: c.fecha_creacion.toISOString(),
      fechaEstimada: c.fecha_estimada ? c.fecha_estimada.toISOString() : null,
      fechaCierre: c.fecha_cierre ? c.fecha_cierre.toISOString() : null,
      conversacionId: c.conversacion_id,
      servicioTitulo: c.servicio_titulo,
      compradorNombre: c.comprador_nombre,
      vendedorNombre: c.vendedor_nombre,
    })),
  };

  res.status(200).json(body);
}
