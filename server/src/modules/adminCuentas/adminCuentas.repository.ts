import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface CuentaRow extends RowDataPacket {
  id_usuario: number;
  nombre: string;
  correo: string;
  bloqueado: number;
  visible: number | null;
  banco: string;
  tipo_cuenta: string;
  numero_cuenta: string;
  titular_nombre: string;
  rut: string;
  fecha_configuracion: Date;
}

// Puerto exacto de admin_cuentas.php:36-43 — mostrarTodos=false replica el WHERE real
// (a.bloqueado=0 AND a.visible=1); true trae todo, incluidos suspendidos/eliminados.
export async function listarCuentas(mostrarTodos: boolean): Promise<CuentaRow[]> {
  const where = mostrarTodos ? "" : "WHERE a.bloqueado = 0 AND a.visible = 1";
  const [rows] = await pool.query<CuentaRow[]>(
    `SELECT a.id AS id_usuario, a.nombre, a.correo, a.bloqueado, a.visible,
            d.banco, d.tipo_cuenta, d.numero_cuenta, d.titular_nombre, d.rut, d.fecha_registro AS fecha_configuracion
     FROM alumnos a
     INNER JOIN datos_pago_usuario d ON a.id = d.usuario_id
     ${where}
     ORDER BY d.id DESC`,
  );
  return rows;
}
