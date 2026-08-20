import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { ActualizarPerfilInput, PerfilCuentaRow } from "./configurarCuenta.types.js";

interface PerfilCuentaDbRow extends PerfilCuentaRow, RowDataPacket {}

// Puerto exacto de editar_datos.php:61-66.
export async function getPerfilCuenta(usuarioId: number): Promise<PerfilCuentaRow | null> {
  const [rows] = await pool.query<PerfilCuentaDbRow[]>(
    "SELECT nombre, correo, carrera, tipo, bio, universidad, anio_egreso, anios_experiencia FROM alumnos WHERE id = ?",
    [usuarioId],
  );
  return rows[0] ?? null;
}

// Puerto exacto del UPDATE de editar_datos.php:102-103. Sin cláusula de "ownership" extra
// en el WHERE más allá de id=? — igual que el PHP real, usuarioId viene de la sesión
// (requireAuth), nunca del body de la request, así que no hay forma de editar el perfil
// de otro usuario aunque se intente pasar un id distinto.
export async function actualizarPerfilCuenta(usuarioId: number, input: ActualizarPerfilInput): Promise<void> {
  await pool.query(
    "UPDATE alumnos SET nombre=?, carrera=?, tipo=?, bio=?, universidad=?, anio_egreso=?, anios_experiencia=? WHERE id=?",
    [input.nombre, input.carrera, input.tipo || null, input.bio, input.universidad, input.anioEgreso, input.aniosExperiencia, usuarioId],
  );
}
