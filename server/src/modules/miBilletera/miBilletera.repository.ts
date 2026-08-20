import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { DatosBancariosRow, SolicitudRetiroRow } from "./miBilletera.types.js";

interface ConfigRow extends RowDataPacket {
  clave: string;
  valor: string;
}
// SUM() sobre columnas int(11) igual llega como string desde mysql2 (mismo comportamiento
// que una columna DECIMAL real, ver servicios.types.ts) — se parsea a number en cada
// función de este archivo antes de devolver, nunca se expone el string crudo.
interface TotalRow extends RowDataPacket {
  total: string | number | null;
}
interface DatosBancariosDbRow extends DatosBancariosRow, RowDataPacket {}
interface SolicitudRetiroDbRow extends SolicitudRetiroRow, RowDataPacket {}

// Puerto exacto de datos_bancarios.php:30-34 (misma query, mismos defaults si la fila no
// existe: mínimo 10000, comisión 0).
export async function getConfiguracionFinanciera(): Promise<{ minimoRetiro: number; comisionActual: number }> {
  const [rows] = await pool.query<ConfigRow[]>(
    "SELECT clave, valor FROM configuracion WHERE clave IN ('monto_minimo_retiro', 'comision_plataforma')",
  );
  let minimoRetiro = 10000;
  let comisionActual = 0;
  for (const row of rows) {
    if (row.clave === "monto_minimo_retiro") minimoRetiro = parseInt(row.valor, 10);
    if (row.clave === "comision_plataforma") comisionActual = parseInt(row.valor, 10);
  }
  return { minimoRetiro, comisionActual };
}

// Puerto exacto de datos_bancarios.php:39-44 (mismo filtro de estados, misma fórmula neto).
export async function getGananciasServicios(vendedorId: number): Promise<number> {
  const [rows] = await pool.query<TotalRow[]>(
    `SELECT SUM(monto + COALESCE(monto_subsidio, 0) - COALESCE(monto_comision, 0)) AS total
     FROM contratos
     WHERE vendedor_id = ? AND estado IN ('liberado', 'finalizado', 'completado')`,
    [vendedorId],
  );
  return Number(rows[0]?.total ?? 0);
}

// Puerto exacto de datos_bancarios.php:47-52.
export async function getGananciasApuntes(vendedorId: number): Promise<number> {
  const [rows] = await pool.query<TotalRow[]>(
    "SELECT SUM(precio) AS total FROM ventas_apuntes WHERE vendedor_id = ? AND pagado_al_vendedor = 1",
    [vendedorId],
  );
  return Number(rows[0]?.total ?? 0);
}

// Puerto exacto de datos_bancarios.php:57-62 (mismos 3 estados: no solo lo ya pagado, para
// no dejar "reservar" el mismo saldo dos veces mientras una solicitud está pendiente).
export async function getTotalRetirado(usuarioId: number): Promise<number> {
  const [rows] = await pool.query<TotalRow[]>(
    "SELECT SUM(monto) AS total FROM solicitudes_retiro WHERE usuario_id = ? AND estado IN ('aprobado', 'pendiente', 'pagado')",
    [usuarioId],
  );
  return Number(rows[0]?.total ?? 0);
}

export async function getDatosBancarios(usuarioId: number): Promise<DatosBancariosRow | null> {
  const [rows] = await pool.query<DatosBancariosDbRow[]>(
    "SELECT banco, numero_cuenta FROM datos_pago_usuario WHERE usuario_id = ?",
    [usuarioId],
  );
  return rows[0] ?? null;
}

// Puerto exacto de datos_bancarios.php:240-243 (mismo LIMIT 15, mismo ORDER BY).
export async function getHistorialRetiros(usuarioId: number): Promise<SolicitudRetiroRow[]> {
  const [rows] = await pool.query<SolicitudRetiroDbRow[]>(
    "SELECT monto, fecha_solicitud, estado FROM solicitudes_retiro WHERE usuario_id = ? ORDER BY fecha_solicitud DESC LIMIT 15",
    [usuarioId],
  );
  return rows;
}
