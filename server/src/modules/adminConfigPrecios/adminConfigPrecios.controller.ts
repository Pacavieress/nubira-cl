import type { Request, Response } from "express";
import { actualizarOfertaGratisHasta, actualizarPrecioDesbloqueo, getConfigRaw } from "./adminConfigPrecios.repository.js";
import type { ConfigPrecios } from "./adminConfigPrecios.types.js";

// Puerto de admin_config_precios.php:188 — "vigente" si hoy <= la fecha de término
// (comparación de strings 'Y-m-d H:i:s', igual que el PHP real). Ambos lados asumen la
// misma zona horaria del servidor (Chile, ver conexion.php) — mismo supuesto implícito que
// el propio PHP, no una divergencia nueva.
function esOfertaVigente(ofertaGratisHasta: string | null): boolean {
  if (!ofertaGratisHasta) return false;
  const ahora = new Date().toISOString().slice(0, 19).replace("T", " ");
  return ahora <= ofertaGratisHasta;
}

export async function getConfigPrecios(_req: Request, res: Response): Promise<void> {
  const raw = await getConfigRaw();
  const precioDesbloqueoContacto = Number(raw.precioDesbloqueoContacto ?? 0);
  const ofertaGratisHasta = raw.ofertaGratisHasta || null;
  const body: ConfigPrecios = {
    precioDesbloqueoContacto,
    ofertaGratisHasta,
    ofertaVigente: esOfertaVigente(ofertaGratisHasta),
  };
  res.status(200).json(body);
}

// Puerto de admin_config_precios.php:40-53.
export async function putPrecioDesbloqueo(req: Request, res: Response): Promise<void> {
  const body = req.body as { precio?: unknown };
  const precio = Number(body.precio);

  if (!Number.isInteger(precio) || precio < 100) {
    res.status(400).json({ error: "precio_invalido", mensaje: "El precio base debe ser al menos $100 CLP." });
    return;
  }
  if (precio > 99999) {
    res.status(400).json({ error: "precio_invalido", mensaje: "El precio no puede superar los $99.999 CLP." });
    return;
  }

  await actualizarPrecioDesbloqueo(precio);
  res.status(200).json({ ok: true, precioDesbloqueoContacto: precio });
}

// Puerto de admin_config_precios.php:55-78 — fecha en formato datetime-local del HTML5
// ("YYYY-MM-DDTHH:MM", sin segundos) o cadena vacía/undefined para desactivar la promo.
export async function putOfertaGratisHasta(req: Request, res: Response): Promise<void> {
  const body = req.body as { fecha?: unknown };
  const fechaCruda = typeof body.fecha === "string" ? body.fecha.trim() : "";

  if (fechaCruda === "") {
    await actualizarOfertaGratisHasta(null);
    res.status(200).json({ ok: true, ofertaGratisHasta: null, ofertaVigente: false });
    return;
  }

  const fechaFormateada = `${fechaCruda.replace("T", " ")}:00`;
  const timestamp = Date.parse(fechaFormateada.replace(" ", "T"));
  if (Number.isNaN(timestamp) || timestamp <= Date.now()) {
    res.status(400).json({ error: "fecha_invalida", mensaje: "La fecha de la oferta debe ser futura y válida." });
    return;
  }

  await actualizarOfertaGratisHasta(fechaFormateada);
  res.status(200).json({ ok: true, ofertaGratisHasta: fechaFormateada, ofertaVigente: true });
}
