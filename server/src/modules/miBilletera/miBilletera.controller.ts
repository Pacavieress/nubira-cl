import type { Request, Response } from "express";
import { mapDatosBancarios, mapDatosBancariosCompletos, mapSolicitudRetiroRow } from "./miBilletera.mapper.js";
import {
  crearSolicitudRetiro,
  getBancos,
  getConfiguracionFinanciera,
  getDatosBancarios,
  getDatosBancariosCompletos,
  getGananciasApuntes,
  getGananciasServicios,
  getHistorialRetiros,
  getTotalRetirado,
  upsertDatosBancarios,
} from "./miBilletera.repository.js";
import type { DatosBancariosParaEditar, GuardarDatosBancariosInput, MiBilleteraPublico } from "./miBilletera.types.js";

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

// Puerto de editar_datos_bancarios.php:30-37 — lista de bancos + los datos completos del
// usuario (sin enmascarar, es el propio dueño viendo su formulario para editarlo).
export async function getMiDatosBancariosParaEditar(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const [bancos, datosRow] = await Promise.all([getBancos(), getDatosBancariosCompletos(usuarioId)]);
  const body: DatosBancariosParaEditar = { bancos, datos: datosRow ? mapDatosBancariosCompletos(datosRow) : null };
  res.status(200).json(body);
}

function aString(v: unknown): string {
  return typeof v === "string" ? v.trim() : "";
}

// Puerto EXACTO de editar_datos_bancarios.php:39-72 — mismas 5 validaciones, mismo orden,
// mismos mensajes: campos obligatorios -> número de cuenta solo dígitos -> formato de RUT
// (7-8 dígitos + guión + dígito verificador, puntos del RUT se limpian antes de validar).
// Sin CSRF de sesión PHP que validar acá (ver nota de alcance en miBilletera.types.ts) —
// requireAuth ya cubre ese mismo problema.
export async function putMiDatosBancarios(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as Record<string, unknown>;

  const banco = aString(body.banco);
  const tipoCuenta = aString(body.tipoCuenta);
  const numeroCuenta = aString(body.numeroCuenta);
  const titularNombre = aString(body.titularNombre);
  const rutCrudo = aString(body.rut);

  if (!banco || !tipoCuenta || !numeroCuenta || !titularNombre || !rutCrudo) {
    res.status(400).json({ error: "campos_obligatorios", mensaje: "Todos los campos son obligatorios." });
    return;
  }
  if (!/^\d+$/.test(numeroCuenta)) {
    res.status(400).json({ error: "cuenta_invalida", mensaje: "El número de cuenta debe contener solo números." });
    return;
  }

  const rutLimpio = rutCrudo.replace(/\./g, "");
  if (!/^\d{7,8}-[\dkK]$/.test(rutLimpio)) {
    res.status(400).json({ error: "rut_invalido", mensaje: "El RUT debe tener el formato correcto (ejemplo: 12345678-9)." });
    return;
  }

  const input: GuardarDatosBancariosInput = { banco, tipoCuenta, numeroCuenta, titularNombre, rut: rutLimpio };
  await upsertDatosBancarios(usuarioId, input);
  res.status(204).send();
}

// Puerto EXACTO de solicitar_retiro.php:1-118 — mismas 3 validaciones tras verificar datos
// bancarios (monto<=0, monto<mínimo, sin necesidad de "saldo_insuficiente" separado: acá el
// monto NUNCA lo manda el cliente, siempre es floor(saldoDisponible) recién calculado
// server-side, igual que el hidden input `value="<?= floor($saldo_disponible) ?>"` del PHP
// real — no existe un camino para que el usuario pida MÁS de lo que tiene, a diferencia
// del PHP que sí confía en el POST del cliente y solo lo valida después. Sin el push a
// admin (ver nota de alcance en miBilletera.types.ts).
export async function postSolicitarRetiro(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;

  const [datosBancarios, gananciasApuntes, gananciasServicios, totalRetirado, config] = await Promise.all([
    getDatosBancarios(usuarioId),
    getGananciasApuntes(usuarioId),
    getGananciasServicios(usuarioId),
    getTotalRetirado(usuarioId),
    getConfiguracionFinanciera(),
  ]);

  if (!datosBancarios) {
    res.status(400).json({ error: "sin_datos_bancarios", mensaje: "Configura tu cuenta bancaria antes de solicitar un retiro." });
    return;
  }

  const saldoDisponible = gananciasApuntes + gananciasServicios - totalRetirado;
  const montoSolicitado = Math.floor(saldoDisponible);

  if (montoSolicitado <= 0) {
    res.status(400).json({ error: "monto_invalido", mensaje: "No tienes saldo disponible para retirar." });
    return;
  }
  if (montoSolicitado < config.minimoRetiro) {
    res.status(400).json({ error: "monto_minimo", mensaje: `El monto mínimo de retiro es $${config.minimoRetiro.toLocaleString("es-CL")}.` });
    return;
  }

  await crearSolicitudRetiro(usuarioId, montoSolicitado, datosBancarios.banco);
  res.status(200).json({ ok: true, monto: montoSolicitado });
}
