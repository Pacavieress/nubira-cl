import type { RowDataPacket } from "mysql2";
import { pool } from "../../db/pool.js";

interface ConfigRow extends RowDataPacket {
  clave: string;
  valor: string | null;
}

// Puerto de admin_config_precios.php:85-90 — trae las 2 claves relevantes de la tabla
// config (genérica, muchas más claves posibles, pero acá solo estas 2).
export async function getConfigRaw(): Promise<{ precioDesbloqueoContacto: string | null; ofertaGratisHasta: string | null }> {
  const [rows] = await pool.query<ConfigRow[]>(
    "SELECT clave, valor FROM config WHERE clave IN ('precio_desbloqueo_contacto', 'oferta_gratis_hasta')",
  );
  let precioDesbloqueoContacto: string | null = null;
  let ofertaGratisHasta: string | null = null;
  for (const row of rows) {
    if (row.clave === "precio_desbloqueo_contacto") precioDesbloqueoContacto = row.valor;
    if (row.clave === "oferta_gratis_hasta") ofertaGratisHasta = row.valor;
  }
  return { precioDesbloqueoContacto, ofertaGratisHasta };
}

export async function actualizarPrecioDesbloqueo(nuevoPrecio: number): Promise<void> {
  await pool.query("UPDATE config SET valor = ? WHERE clave = 'precio_desbloqueo_contacto'", [String(nuevoPrecio)]);
}

// fechaFormateada ya viene como 'Y-m-d H:i:s' (o null para desactivar) — puerto de
// admin_config_precios.php:56-77.
export async function actualizarOfertaGratisHasta(fechaFormateada: string | null): Promise<void> {
  await pool.query("UPDATE config SET valor = ? WHERE clave = 'oferta_gratis_hasta'", [fechaFormateada ?? ""]);
}
