import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface ServicioIdRow extends RowDataPacket {
  servicio_id: number;
}

// PUT idempotente: "INSERT ... ON DUPLICATE KEY UPDATE" con un no-op a propósito
// (usuario_id = usuario_id). Dos PUT simultáneos para el mismo (usuario_id, servicio_id)
// se serializan a nivel de fila sobre la PRIMARY KEY compuesta — uno inserta, el otro
// hace el update vacío. Ninguno falla, ninguno duplica. A diferencia del patrón
// "SELECT para decidir, después INSERT o DELETE" de app/dar_like.php, acá no hay ninguna
// ventana entre leer y escribir donde dos requests puedan pisarse.
export async function marcarFavorito(usuarioId: number, servicioId: number): Promise<void> {
  await pool.query(
    "INSERT INTO favoritos_servicios (usuario_id, servicio_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE usuario_id = usuario_id",
    [usuarioId, servicioId],
  );
}

// DELETE ya es idempotente por naturaleza en SQL: borrar una fila que no existe afecta
// 0 filas, no es un error. Sin verificación previa de existencia (a propósito — ver
// favoritos.controller.ts: un favorito viejo siempre debe poder quitarse, incluso si el
// servicio que apuntaba ya no existe o fue dado de baja).
export async function desmarcarFavorito(usuarioId: number, servicioId: number): Promise<void> {
  await pool.query("DELETE FROM favoritos_servicios WHERE usuario_id = ? AND servicio_id = ?", [
    usuarioId,
    servicioId,
  ]);
}

export async function listarServicioIdsFavoritos(usuarioId: number): Promise<number[]> {
  const [rows] = await pool.query<ServicioIdRow[]>(
    "SELECT servicio_id FROM favoritos_servicios WHERE usuario_id = ? ORDER BY creado_en DESC",
    [usuarioId],
  );
  return rows.map((row) => row.servicio_id);
}
