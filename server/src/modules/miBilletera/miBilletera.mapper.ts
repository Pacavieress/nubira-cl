import type { DatosBancariosRow, SolicitudRetiro, SolicitudRetiroRow } from "./miBilletera.types.js";

// Puerto exacto de datos_bancarios.php:223 — últimos 4 dígitos, o '••••' si el número
// registrado tiene menos de 4 caracteres.
export function enmascararCuenta(numeroCuenta: string | null): string {
  if (!numeroCuenta || numeroCuenta.length < 4) return "••••";
  return numeroCuenta.slice(-4);
}

export function mapDatosBancarios(row: DatosBancariosRow | null): { banco: string; numeroCuentaEnmascarado: string } | null {
  if (!row) return null;
  return { banco: row.banco, numeroCuentaEnmascarado: enmascararCuenta(row.numero_cuenta) };
}

export function mapSolicitudRetiroRow(row: SolicitudRetiroRow): SolicitudRetiro {
  return { monto: row.monto, fechaSolicitud: row.fecha_solicitud, estado: row.estado };
}
