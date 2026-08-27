import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

const ADMIN_NOMBRE = "despertar_dormidos_jun2026";

interface UsuarioDormidoRow extends RowDataPacket {
  alumno_id: number;
  nombre: string;
  correo: string;
  fecha_enviado: Date | null;
  estado_envio: number | null;
}

// Puerto exacto del WHERE de enviar_despertar_dormidos.php:321-332 (modo WEB, listado), CON
// la exclusión de `unsubscribed` agregada (ver nota de corrección deliberada en
// adminDespertarDormidos.types.ts) — el PHP real no la tenía.
export async function listarDormidos(): Promise<UsuarioDormidoRow[]> {
  const [rows] = await pool.query<UsuarioDormidoRow[]>(
    `SELECT
        a.id AS alumno_id,
        a.nombre,
        LOWER(TRIM(a.correo)) AS correo,
        (SELECT MAX(ca.fecha_envio) FROM correos_admin ca
            WHERE LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(a.correo))
              AND ca.admin_nombre = ?
              AND ca.exito = 1) AS fecha_enviado,
        (SELECT MAX(ca.exito) FROM correos_admin ca
            WHERE LOWER(TRIM(ca.destinatario)) = LOWER(TRIM(a.correo))
              AND ca.admin_nombre = ?) AS estado_envio
     FROM alumnos a
     WHERE a.visible = 1
       AND a.bloqueado = 0
       AND a.confirmado = 1
       AND a.recibir_emails = 1
       AND a.id != 1
       AND a.correo NOT LIKE 'testpablo%'
       AND DATEDIFF(NOW(), a.fecha_registro) >= 31
       AND NOT EXISTS (SELECT 1 FROM servicios s WHERE s.alumno_id = a.id)
       AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.comprador_id = a.id)
       AND NOT EXISTS (SELECT 1 FROM apuntes ap WHERE ap.id_alumno = a.id)
       AND a.correo NOT IN (SELECT correo FROM unsubscribed)
     GROUP BY a.id
     ORDER BY a.id ASC`,
    [ADMIN_NOMBRE, ADMIN_NOMBRE],
  );
  return rows;
}

interface UsuarioEnvioRow extends RowDataPacket {
  id: number;
  nombre: string;
  correo: string;
}

// Puerto exacto de la re-verificación de enviar_despertar_dormidos.php:196-209 (modo WEB,
// justo antes de enviar), CON la exclusión de `unsubscribed` agregada — mismo criterio que
// listarDormidos. Se re-verifica en vez de confiar en los ids que mandó el cliente porque
// el estado del usuario pudo cambiar entre que se cargó el listado y que el admin envió
// (publicó un servicio, compró algo, etc.) — mismo espíritu que el PHP real.
export async function usuariosElegiblesParaEnvio(ids: number[]): Promise<UsuarioEnvioRow[]> {
  if (ids.length === 0) return [];
  const [rows] = await pool.query<UsuarioEnvioRow[]>(
    `SELECT a.id, a.nombre, LOWER(TRIM(a.correo)) AS correo
     FROM alumnos a
     WHERE a.id IN (?)
       AND a.visible = 1
       AND a.confirmado = 1
       AND NOT EXISTS (SELECT 1 FROM servicios s WHERE s.alumno_id = a.id)
       AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.comprador_id = a.id)
       AND NOT EXISTS (SELECT 1 FROM apuntes ap WHERE ap.id_alumno = a.id)
       AND a.correo NOT IN (SELECT correo FROM unsubscribed)
     ORDER BY a.id ASC`,
    [ids],
  );
  return rows;
}

interface CuponRow extends RowDataPacket {
  porcentaje_descuento: number;
  fecha_expiracion: Date | null;
  servicio_id: number | null;
}

// Puerto exacto de nb_consultar_cupon_global() (app/helpers/campanas.php:193-207).
export async function buscarCuponGlobal(codigo: string): Promise<CuponRow | null> {
  const [rows] = await pool.query<CuponRow[]>("SELECT porcentaje_descuento, fecha_expiracion, servicio_id FROM cupones WHERE codigo = ? LIMIT 1", [codigo]);
  return rows[0] ?? null;
}

// Puerto exacto del INSERT de correos_admin que hace cada iteración del loop de envío
// (enviar_despertar_dormidos.php:215-218 / 245-246).
export async function registrarEnvio(adminId: number, destinatario: string, asunto: string, mensajeHtml: string, exito: boolean): Promise<void> {
  await pool.query<ResultSetHeader>(
    `INSERT INTO correos_admin (admin_id, admin_nombre, destinatario, asunto, mensaje, exito)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [adminId, ADMIN_NOMBRE, destinatario, asunto, mensajeHtml, exito ? 1 : 0],
  );
}
