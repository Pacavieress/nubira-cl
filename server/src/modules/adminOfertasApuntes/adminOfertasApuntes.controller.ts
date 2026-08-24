import type { Request, Response } from "express";
import { actualizarPrecio, aplicarPromo, listarApuntesConPromo, quitarPromo } from "./adminOfertasApuntes.repository.js";
import type { OfertaApunte } from "./adminOfertasApuntes.types.js";

export async function getOfertasApuntes(req: Request, res: Response): Promise<void> {
  const tutor = typeof req.query.tutor === "string" ? req.query.tutor.trim() : "";
  const filas = await listarApuntesConPromo(tutor || undefined);

  const body: OfertaApunte[] = filas.map((a) => ({
    id: a.id,
    titulo: a.titulo,
    tutorNombre: a.tutor_nombre,
    precio: a.precio,
    promoGratis: a.promo_gratis === 1,
    promoLimite: a.promo_limite,
    promoContador: a.promo_contador,
  }));

  res.status(200).json(body);
}

// Puerto de la rama 'modificar_precio' (admin_ofertas_apuntes.php:41-53).
export async function putPrecio(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const body = req.body as { precio?: unknown };

  if (!Number.isInteger(id) || id <= 0 || typeof body.precio !== "number" || !Number.isInteger(body.precio) || body.precio < 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const actualizado = await actualizarPrecio(id, body.precio);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true, precio: body.precio });
}

// Puerto de la rama 'aplicar_promo' (admin_ofertas_apuntes.php:56-69).
export async function postAplicarPromo(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  const body = req.body as { cupos?: unknown };

  if (!Number.isInteger(id) || id <= 0 || typeof body.cupos !== "number" || !Number.isInteger(body.cupos) || body.cupos <= 0) {
    res.status(400).json({ error: "datos_invalidos" });
    return;
  }

  const actualizado = await aplicarPromo(id, body.cupos);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}

// Puerto de la rama 'quitar_promo' (admin_ofertas_apuntes.php:72-81).
export async function postQuitarPromo(req: Request, res: Response): Promise<void> {
  const id = Number(req.params.id);
  if (!Number.isInteger(id) || id <= 0) {
    res.status(400).json({ error: "id_invalido" });
    return;
  }

  const actualizado = await quitarPromo(id);
  if (!actualizado) {
    res.status(404).json({ error: "not_found" });
    return;
  }
  res.status(200).json({ ok: true });
}
