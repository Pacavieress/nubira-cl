import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";
import type { FiltroRol, FiltroVerificado } from "./adminUsuarios.types.js";

interface UsuarioRow extends RowDataPacket {
  id: number;
  nombre: string | null;
  correo: string | null;
  foto_perfil: string | null;
  fecha_registro: Date | null;
  bloqueado: number | null;
  confirmado: number | null;
  suspendido_hasta: Date | null;
  ultimo_reenvio: Date | null;
  rol: string;
  total_servicios: number;
  total_apuntes: number;
  total_reclamos: number;
}

interface ContadorRow extends RowDataPacket {
  c: number;
}

export interface FiltrosListado {
  q: string;
  rol: FiltroRol;
  verificado: FiltroVerificado;
  page: number;
}

const LIMIT = 20;

function construirWhere(f: FiltrosListado): { where: string; params: (string | number)[] } {
  let where = "WHERE u.visible = 1";
  const params: (string | number)[] = [];

  if (f.q) {
    where += " AND (u.nombre LIKE ? OR u.correo LIKE ? OR u.dominio LIKE ?)";
    const like = `%${f.q}%`;
    params.push(like, like, like);
  }
  if (f.rol) {
    where += " AND u.rol = ?";
    params.push(f.rol);
  }
  if (f.verificado === "si") {
    where += " AND u.verificacion_estado = 'aprobado'";
  } else if (f.verificado === "no") {
    where += " AND (u.verificacion_estado IS NULL OR u.verificacion_estado != 'aprobado')";
  }
  return { where, params };
}

// Puerto exacto de admin_usuarios.php:383 — ejecutado en cada carga del listado.
export async function marcarVistosAdmin(): Promise<void> {
  await pool.query("UPDATE alumnos SET visto_admin = 1 WHERE visto_admin = 0");
}

// Puerto exacto de admin_usuarios.php:389-446.
export async function listarUsuarios(f: FiltrosListado): Promise<{ usuarios: UsuarioRow[]; totalUsers: number; totalUsersGlobal: number }> {
  const { where, params } = construirWhere(f);

  const [countRows] = await pool.query<ContadorRow[]>(`SELECT COUNT(*) as c FROM alumnos u ${where}`, params);
  const totalUsers = countRows[0]?.c ?? 0;

  const offset = (f.page - 1) * LIMIT;
  const [rows] = await pool.query<UsuarioRow[]>(
    `SELECT u.id, u.nombre, u.correo, u.foto_perfil, u.fecha_registro, u.bloqueado, u.confirmado,
            u.suspendido_hasta, u.ultimo_reenvio, u.rol,
            (SELECT COUNT(*) FROM servicios WHERE alumno_id = u.id) as total_servicios,
            (SELECT COUNT(*) FROM apuntes WHERE id_alumno = u.id) as total_apuntes,
            (SELECT COUNT(*) FROM reclamos_sugerencias WHERE usuario_id = u.id) as total_reclamos
     FROM alumnos u ${where}
     ORDER BY u.id DESC
     LIMIT ? OFFSET ?`,
    [...params, LIMIT, offset],
  );

  const [globalRows] = await pool.query<ContadorRow[]>("SELECT COUNT(id) as c FROM alumnos WHERE visible = 1");
  const totalUsersGlobal = globalRows[0]?.c ?? 0;

  return { usuarios: rows, totalUsers, totalUsersGlobal };
}
