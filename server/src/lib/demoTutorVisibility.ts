import type { RowDataPacket } from "mysql2";
import { pool } from "../db/pool.js";
import { getUsuarioConRol } from "../modules/auth/auth.repository.js";

// Cuenta demo de tutor (contacto@nubira.cl) usada para publicar ejemplos — mismo id
// hardcodeado que DEMO_TUTOR_USER_ID en app/config.php (PHP). Su contenido solo debe
// verse por viewers que califican (ver viewerCalificaDemo abajo).
export const DEMO_TUTOR_USER_ID = 167;

interface TutorActivoRowPacket extends RowDataPacket {
  tiene_servicio: number;
  tiene_apunte: number;
}

interface ReputacionRowPacket extends RowDataPacket {
  leg_qty: number;
  v_qty: number;
}

// Puerto exacto de nb_es_tutor_activo() en app/helpers/roles.php: 1) ¿tiene ≥1
// servicio o apunte activo publicado?, 2) si no, ¿tiene reputación de vendedor
// (valoraciones o cantidad_votos legacy)?
export async function esTutorActivo(usuarioId: number): Promise<boolean> {
  if (usuarioId <= 0) return false;

  const [rows] = await pool.query<TutorActivoRowPacket[]>(
    `SELECT
        EXISTS(SELECT 1 FROM servicios WHERE alumno_id = ? AND estado = 'aprobado' AND COALESCE(visible,1) = 1) AS tiene_servicio,
        EXISTS(SELECT 1 FROM apuntes WHERE id_alumno = ? AND estado = 'aprobado' AND bloqueado = 0 AND COALESCE(visible,1) = 1) AS tiene_apunte`,
    [usuarioId, usuarioId],
  );
  const row = rows[0];
  if (row && (row.tiene_servicio === 1 || row.tiene_apunte === 1)) {
    return true;
  }

  const [repRows] = await pool.query<ReputacionRowPacket[]>(
    `SELECT
        (SELECT cantidad_votos FROM alumnos WHERE id = ?) AS leg_qty,
        (SELECT COUNT(*) FROM valoraciones WHERE id_evaluado = ? AND rol_evaluado = 'vendedor') AS v_qty`,
    [usuarioId, usuarioId],
  );
  const rep = repRows[0];
  return !!rep && (Number(rep.leg_qty ?? 0) + Number(rep.v_qty ?? 0)) > 0;
}

// Puerto de nb_viewer_ve_demo() en app/helpers/demo_visibility.php: admin siempre
// califica; si no, hace falta estar autenticado Y ser tutor activo.
export async function viewerCalificaDemo(usuarioId: number | undefined): Promise<boolean> {
  if (usuarioId === undefined) return false;

  const usuario = await getUsuarioConRol(usuarioId);
  if (usuario?.rol === "admin") return true;

  return esTutorActivo(usuarioId);
}

// Sentinel 0 (ningún alumno real tiene id 0): permite mantener el texto SQL estático
// ("AND alumno_id != ?") sin importar si el viewer califica o no — solo cambia el
// valor bindeado, nunca la forma de la query.
export function demoExclusionParam(viewerCalifica: boolean): number {
  return viewerCalifica ? 0 : DEMO_TUTOR_USER_ID;
}
