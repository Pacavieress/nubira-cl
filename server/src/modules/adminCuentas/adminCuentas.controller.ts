import type { Request, Response } from "express";
import { listarCuentas } from "./adminCuentas.repository.js";
import type { CuentaBancariaAdmin } from "./adminCuentas.types.js";

export async function getCuentas(req: Request, res: Response): Promise<void> {
  const mostrarTodos = req.query.mostrarTodos === "1";
  const filas = await listarCuentas(mostrarTodos);

  const body: CuentaBancariaAdmin[] = filas.map((f) => ({
    idUsuario: f.id_usuario,
    nombre: f.nombre,
    correo: f.correo,
    bloqueado: f.bloqueado === 1,
    visible: f.visible !== 0,
    banco: f.banco,
    tipoCuenta: f.tipo_cuenta,
    numeroCuenta: f.numero_cuenta,
    titularNombre: f.titular_nombre,
    rut: f.rut,
    fechaConfiguracion: f.fecha_configuracion.toISOString(),
  }));

  res.status(200).json(body);
}
