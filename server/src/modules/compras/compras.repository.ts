import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ApunteCompradoRow, ServicioContratadoRow } from "./compras.types.js";

interface ApunteCompradoDbRow extends ApunteCompradoRow, RowDataPacket {}
interface ServicioContratadoDbRow extends ServicioContratadoRow, RowDataPacket {}

// Puerto exacto de perfil.php:392 ($ha_comprado_algo — SOLO tabla `compras`, es decir
// compra de apuntes; NO incluye contratos de servicios, a diferencia de mis_compras.php
// que sí junta ambos). Usado para el gating de la tile "Mis Compras" del panel de gestión
// — ver server/src/modules/perfil/perfil.mapper.ts::construirAccesos.
export async function existeAlgunaCompraDeApunte(usuarioId: number): Promise<boolean> {
  const [rows] = await pool.query<RowDataPacket[]>("SELECT 1 FROM compras WHERE usuario_id = ? LIMIT 1", [usuarioId]);
  return rows.length > 0;
}

// Puerto exacto de app/mis_compras.php:43-56 (mismo JOIN, mismo ORDER BY).
export async function getApuntesCompradosByUsuario(usuarioId: number): Promise<ApunteCompradoRow[]> {
  const [rows] = await pool.query<ApunteCompradoDbRow[]>(
    `SELECT
       c.id, c.monto, c.fecha, c.estado_pago,
       a.titulo, a.asignatura, a.archivo, a.institucion
     FROM compras c
     JOIN apuntes a ON c.id_apunte = a.id
     WHERE c.usuario_id = ?
     ORDER BY c.fecha DESC`,
    [usuarioId],
  );
  return rows;
}

// Puerto exacto de app/mis_compras.php:67-79 (mismo JOIN, mismo ORDER BY).
export async function getServiciosContratadosByUsuario(usuarioId: number): Promise<ServicioContratadoRow[]> {
  const [rows] = await pool.query<ServicioContratadoDbRow[]>(
    `SELECT c.id, s.titulo, al.nombre AS vendedor_nombre, c.monto, c.fecha_pago, c.estado
     FROM contratos c
     JOIN servicios s ON s.id = c.servicio_id
     JOIN alumnos al ON al.id = c.vendedor_id
     WHERE c.comprador_id = ?
     ORDER BY c.fecha_pago DESC`,
    [usuarioId],
  );
  return rows;
}
