import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { DatosBancariosCompletosRow, DatosBancariosRow, GuardarDatosBancariosInput, SolicitudRetiroRow } from "./miBilletera.types.js";

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
interface DatosBancariosCompletosDbRow extends DatosBancariosCompletosRow, RowDataPacket {}
interface BancoRow extends RowDataPacket {
  nombre: string;
}

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

// Puerto exacto de editar_datos_bancarios.php:37 (mismo ORDER BY).
export async function getBancos(): Promise<string[]> {
  const [rows] = await pool.query<BancoRow[]>("SELECT nombre FROM bancos ORDER BY nombre ASC");
  return rows.map((r) => r.nombre);
}

// Fila COMPLETA (con numero_cuenta sin enmascarar) para el propio dueño ver/editar su
// formulario — puerto exacto de editar_datos_bancarios.php:30-35. Distinto de
// getDatosBancarios() (arriba, banco+numero_cuenta) usado para el resumen enmascarado.
export async function getDatosBancariosCompletos(usuarioId: number): Promise<DatosBancariosCompletosRow | null> {
  const [rows] = await pool.query<DatosBancariosCompletosDbRow[]>(
    "SELECT banco, tipo_cuenta, numero_cuenta, titular_nombre, rut FROM datos_pago_usuario WHERE usuario_id = ?",
    [usuarioId],
  );
  return rows[0] ?? null;
}

// Puerto exacto de editar_datos_bancarios.php:62-72 — INSERT si no había fila, UPDATE si
// ya existía (mismo criterio real: 1 fila por usuario en datos_pago_usuario).
export async function upsertDatosBancarios(usuarioId: number, input: GuardarDatosBancariosInput): Promise<void> {
  const existentes = await getDatosBancarios(usuarioId);
  if (existentes) {
    await pool.query("UPDATE datos_pago_usuario SET banco = ?, tipo_cuenta = ?, numero_cuenta = ?, titular_nombre = ?, rut = ? WHERE usuario_id = ?", [
      input.banco,
      input.tipoCuenta,
      input.numeroCuenta,
      input.titularNombre,
      input.rut,
      usuarioId,
    ]);
  } else {
    await pool.query(
      "INSERT INTO datos_pago_usuario (usuario_id, banco, tipo_cuenta, numero_cuenta, titular_nombre, rut) VALUES (?, ?, ?, ?, ?, ?)",
      [usuarioId, input.banco, input.tipoCuenta, input.numeroCuenta, input.titularNombre, input.rut],
    );
  }
}

// Puerto exacto de solicitar_retiro.php:83-107 (INSERT + vincular contratos.
// solicitud_retiro_id, misma transacción — para no dejar contratos sin vincular si la
// vinculación falla a mitad de camino). `estado` arranca siempre en 'pendiente', igual que
// el PHP real (nunca se aprueba sola desde este endpoint).
export async function crearSolicitudRetiro(usuarioId: number, monto: number, institucion: string): Promise<void> {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [result] = await conn.query<ResultSetHeader>(
      "INSERT INTO solicitudes_retiro (usuario_id, monto, institucion, estado, fecha_solicitud) VALUES (?, ?, ?, 'pendiente', NOW())",
      [usuarioId, monto, institucion],
    );
    const solicitudId = result.insertId;
    await conn.query(
      `UPDATE contratos
       SET solicitud_retiro_id = ?
       WHERE vendedor_id = ?
       AND estado IN ('liberado', 'finalizado', 'completado')
       AND solicitud_retiro_id IS NULL`,
      [solicitudId, usuarioId],
    );
    await conn.commit();
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}
