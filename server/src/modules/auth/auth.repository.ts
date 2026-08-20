import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface SesionApiRow extends RowDataPacket {
  usuario_id: number;
}

export async function findUsuarioIdBySessionId(sessionId: string): Promise<number | null> {
  const [rows] = await pool.query<SesionApiRow[]>(
    "SELECT usuario_id FROM sesiones_api WHERE session_id = ? AND expira_en > NOW() LIMIT 1",
    [sessionId],
  );
  return rows[0]?.usuario_id ?? null;
}

export interface UsuarioConRol {
  id: number;
  rol: string | null;
  visible: number;
  bloqueado: number;
}

interface UsuarioConRolRow extends UsuarioConRol, RowDataPacket {}

// A diferencia de $_SESSION['rol'] en PHP (cacheado en la sesión al momento del login,
// solo se refresca si el usuario vuelve a loguearse), sesiones_api NO guarda rol — así
// que esto consulta alumnos.rol fresco en cada request. Mejora deliberada sobre el
// comportamiento real de PHP (revocar el admin a alguien surte efecto de inmediato acá,
// no recién en su próximo login), documentada como tal, no una simplificación.
//
// visible/bloqueado se agregan como defensa en profundidad — admin_panel.php NO los
// chequea (solo $_SESSION['rol']==='admin'), pero un admin con soft-delete o bloqueado no
// debería poder operar el panel solo porque su sesión PHP vieja seguía viva.
export async function getUsuarioConRol(usuarioId: number): Promise<UsuarioConRol | null> {
  const [rows] = await pool.query<UsuarioConRolRow[]>(
    "SELECT id, rol, visible, bloqueado FROM alumnos WHERE id = ? LIMIT 1",
    [usuarioId],
  );
  return rows[0] ?? null;
}
