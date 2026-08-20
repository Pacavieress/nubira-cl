import type { Request, Response } from "express";
import { mapDatosBancarios, mapSolicitudRetiroRow } from "./miBilletera.mapper.js";
import {
  getConfiguracionFinanciera,
  getDatosBancarios,
  getGananciasApuntes,
  getGananciasServicios,
  getHistorialRetiros,
  getTotalRetirado,
} from "./miBilletera.repository.js";
import type { MiBilleteraPublico } from "./miBilletera.types.js";

export async function getMiBilletera(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;

  const [config, gananciasServicios, gananciasApuntes, totalRetirado, datosBancariosRow, historialRows] =
    await Promise.all([
      getConfiguracionFinanciera(),
      getGananciasServicios(usuarioId),
      getGananciasApuntes(usuarioId),
      getTotalRetirado(usuarioId),
      getDatosBancarios(usuarioId),
      getHistorialRetiros(usuarioId),
    ]);

  const totalGanancias = gananciasApuntes + gananciasServicios;
  const saldoDisponible = totalGanancias - totalRetirado;

  const body: MiBilleteraPublico = {
    saldoDisponible,
    // Puerto exacto de datos_bancarios.php:67 — nunca se muestra saldo negativo al
    // usuario; saldoDisponible (sin recortar) sigue siendo la fuente de verdad real.
    saldoParaMostrar: Math.max(0, saldoDisponible),
    minimoRetiro: config.minimoRetiro,
    comisionActual: config.comisionActual,
    gananciasApuntes,
    gananciasServicios,
    totalRetirado,
    datosBancarios: mapDatosBancarios(datosBancariosRow),
    historialRetiros: historialRows.map(mapSolicitudRetiroRow),
  };
  res.status(200).json(body);
}
