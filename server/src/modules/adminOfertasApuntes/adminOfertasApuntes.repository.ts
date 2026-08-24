import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface ApunteRow extends RowDataPacket {
  id: number;
  titulo: string;
  precio: number;
  promo_gratis: number;
  promo_limite: number;
  promo_contador: number;
  tutor_nombre: string;
}

// Puerto exacto de admin_ofertas_apuntes.php:94-111 — mismo ORDER BY (promo_gratis DESC,
// id DESC), mismo LIMIT 50 solo cuando no hay filtro de tutor.
export async function listarApuntesConPromo(filtroTutor?: string): Promise<ApunteRow[]> {
  const base = `SELECT ap.id, ap.titulo, ap.precio, ap.promo_gratis, ap.promo_limite, ap.promo_contador, a.nombre AS tutor_nombre
     FROM apuntes ap
     JOIN alumnos a ON ap.id_alumno = a.id
     WHERE ap.estado = 'aprobado'`;

  if (filtroTutor) {
    const [rows] = await pool.query<ApunteRow[]>(
      `${base} AND a.nombre LIKE ? ORDER BY ap.promo_gratis DESC, ap.id DESC`,
      [`%${filtroTutor}%`],
    );
    return rows;
  }

  const [rows] = await pool.query<ApunteRow[]>(`${base} ORDER BY ap.promo_gratis DESC, ap.id DESC LIMIT 50`);
  return rows;
}

// Puerto exacto de la rama 'modificar_precio' (admin_ofertas_apuntes.php:41-53).
export async function actualizarPrecio(apunteId: number, nuevoPrecio: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>("UPDATE apuntes SET precio = ? WHERE id = ?", [nuevoPrecio, apunteId]);
  return res.affectedRows > 0;
}

// Puerto exacto de la rama 'aplicar_promo' (admin_ofertas_apuntes.php:56-69) — prende la
// promo, fija el límite de cupos, reinicia el contador de usados.
export async function aplicarPromo(apunteId: number, cupos: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>(
    "UPDATE apuntes SET promo_gratis = 1, promo_limite = ?, promo_contador = 0 WHERE id = ?",
    [cupos, apunteId],
  );
  return res.affectedRows > 0;
}

// Puerto exacto de la rama 'quitar_promo' (admin_ofertas_apuntes.php:72-81).
export async function quitarPromo(apunteId: number): Promise<boolean> {
  const [res] = await pool.query<ResultSetHeader>(
    "UPDATE apuntes SET promo_gratis = 0, promo_limite = 0, promo_contador = 0 WHERE id = ?",
    [apunteId],
  );
  return res.affectedRows > 0;
}
