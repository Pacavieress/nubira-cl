import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface FalloRow extends RowDataPacket {
  correo: string;
  ip: string;
  fecha: Date;
  es_alumno: number;
}

interface VipRow extends RowDataPacket {
  id: number;
  correo: string;
  fecha_creacion: Date;
}

interface PendienteRow extends RowDataPacket {
  id: number;
  nombre: string;
  correo: string;
  carrera: string | null;
  dominio: string | null;
}

// total viene como STRING desde mysql2 (COUNT(*) sin CAST, mismo gotcha documentado en
// adminComprasApuntes/adminAvisos) — contar() lo convierte con Number().
interface CountRow extends RowDataPacket {
  total: string;
}

async function contar(sql: string): Promise<number> {
  const [rows] = await pool.query<CountRow[]>(sql);
  return Number(rows[0]?.total ?? 0);
}

// Puerto exacto de admin_login_fallos.php:132-133.
export async function contarFallos(): Promise<number> {
  return contar("SELECT COUNT(*) AS total FROM login_fallos");
}

export async function listarFallos(offset: number, limit: number): Promise<FalloRow[]> {
  const [rows] = await pool.query<FalloRow[]>(
    `SELECT lf.correo, lf.ip, lf.fecha, (SELECT COUNT(*) FROM alumnos a WHERE a.correo = lf.correo) as es_alumno
     FROM login_fallos lf ORDER BY lf.fecha DESC LIMIT ?, ?`,
    [offset, limit],
  );
  return rows;
}

// Puerto exacto de admin_login_fallos.php:140-141.
export async function contarVips(): Promise<number> {
  return contar("SELECT COUNT(*) AS total FROM excepciones_email WHERE activo = 1");
}

export async function listarVips(offset: number, limit: number): Promise<VipRow[]> {
  const [rows] = await pool.query<VipRow[]>(
    `SELECT id, correo, fecha_creacion FROM excepciones_email WHERE activo = 1 ORDER BY fecha_creacion DESC LIMIT ?, ?`,
    [offset, limit],
  );
  return rows;
}

// Puerto exacto de admin_login_fallos.php:136-137.
export async function contarPendientes(): Promise<number> {
  return contar("SELECT COUNT(*) AS total FROM alumnos WHERE confirmado = 0");
}

export async function listarPendientes(offset: number, limit: number): Promise<PendienteRow[]> {
  const [rows] = await pool.query<PendienteRow[]>(
    `SELECT id, nombre, correo, carrera, dominio FROM alumnos WHERE confirmado = 0 ORDER BY id DESC LIMIT ?, ?`,
    [offset, limit],
  );
  return rows;
}

// Puerto exacto de la rama 'limpiar_fallos' (admin_login_fallos.php:80-84).
export async function limpiarFallos(correo: string): Promise<void> {
  await pool.query<ResultSetHeader>("DELETE FROM login_fallos WHERE correo = ?", [correo]);
}

// Puerto exacto de la rama 'autorizar_gmail' (admin_login_fallos.php:54-66) — upsert +
// limpieza de `interesados_registro` para ese correo, igual que el PHP.
export async function autorizarVip(correo: string): Promise<void> {
  await pool.query<ResultSetHeader>("INSERT INTO excepciones_email (correo, activo) VALUES (?, 1) ON DUPLICATE KEY UPDATE activo = 1", [correo]);
  await pool.query<ResultSetHeader>("DELETE FROM interesados_registro WHERE correo = ?", [correo]);
}

// Puerto exacto de la rama 'revocar_vip' (admin_login_fallos.php:69-77).
export async function revocarVip(correo: string): Promise<void> {
  await pool.query<ResultSetHeader>("UPDATE excepciones_email SET activo = 0 WHERE correo = ?", [correo]);
}
