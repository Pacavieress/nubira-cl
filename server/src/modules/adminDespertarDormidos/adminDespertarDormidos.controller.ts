import type { Request, Response } from "express";
import { direccionRemitente, enviarCorreo } from "../../lib/correo.js";
import { esDobleSubmit } from "../../lib/idempotencyGuard.js";
import { buscarCuponGlobal, listarDormidos, registrarEnvio, usuariosElegiblesParaEnvio } from "./adminDespertarDormidos.repository.js";
import { generarHtmlEmailCuponPromocional, generarHtmlEmailDespertarDormidos, generarUnsubUrl, headersUnsubscribe } from "./adminDespertarDormidos.templates.js";
import { TOPE_DESTINATARIOS_POR_ENVIO, type CuponGlobalResultado, type DespertarDormidosResumen, type EnviarDormidosResultado, type EstadoEnvioDormido, type UsuarioDormido } from "./adminDespertarDormidos.types.js";

const ASUNTO_BASE = "¿Todavía necesitas un tutor?";
const DELAY_ENTRE_ENVIOS_MS = 2000; // Mismo throttle que sleep(2) en el PHP real.

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// Puerto exacto del listado GET de enviar_despertar_dormidos.php (modo WEB) — filtros de
// proveedor/orden/paginación del PHP real se resuelven en el cliente (dataset acotado, sin
// necesidad de ida y vuelta al server por cada cambio de filtro).
export async function getListado(_req: Request, res: Response): Promise<void> {
  const filas = await listarDormidos();

  const usuarios: UsuarioDormido[] = filas.map((r) => {
    let estado: EstadoEnvioDormido = "pendiente";
    if (r.estado_envio === 1) estado = "enviado";
    else if (r.estado_envio !== null) estado = "fallo";
    return {
      alumnoId: r.alumno_id,
      nombre: r.nombre,
      correo: r.correo,
      estado,
      fechaEnviado: r.fecha_enviado ? new Date(r.fecha_enviado).toISOString() : null,
    };
  });

  const stats = {
    total: usuarios.length,
    enviados: usuarios.filter((u) => u.estado === "enviado").length,
    pendientes: usuarios.filter((u) => u.estado === "pendiente").length,
    fallidos: usuarios.filter((u) => u.estado === "fallo").length,
  };

  const body: DespertarDormidosResumen = { usuarios, stats };
  res.status(200).json(body);
}

// Puerto exacto de nb_consultar_cupon_global() vía el endpoint ?consultar_cupon=1 /
// ?preview_cupon=1 de enviar_despertar_dormidos.php.
export async function getCupon(req: Request, res: Response): Promise<void> {
  const codigo = typeof req.query.codigo === "string" ? req.query.codigo.trim().toUpperCase() : "";
  const resultado = await resolverCupon(codigo);
  res.status(200).json(resultado);
}

async function resolverCupon(codigo: string): Promise<CuponGlobalResultado> {
  if (codigo === "") return { ok: false, error: "Falta el código." };

  const cupon = await buscarCuponGlobal(codigo);
  if (!cupon) return { ok: false, error: `El código '${codigo}' no existe.` };
  if (cupon.servicio_id !== null) return { ok: false, error: "Este código está restringido a un servicio específico." };

  return {
    ok: true,
    porcentaje: Number(cupon.porcentaje_descuento),
    fechaExpiracion: cupon.fecha_expiracion ? new Date(cupon.fecha_expiracion).toISOString() : null,
  };
}

// Puerto del POST de envío de enviar_despertar_dormidos.php:158-258 — CON 3 correcciones
// deliberadas autorizadas por el usuario: (1) cada correo lleva el link/header de baja real
// (ver adminDespertarDormidos.templates.ts), reutilizando la lógica de
// app/helpers/campanas.php en vez de replicar el hueco del PHP real; (2) tope duro de
// TOPE_DESTINATARIOS_POR_ENVIO destinatarios por request (el PHP real no tenía límite); (3)
// guard anti-doble-submit (esDobleSubmit) — mismo admin + mismo conjunto de ids + mismo
// cupón dentro de 15s se rechaza con 409 en vez de reenviar el correo duplicado.
export async function postEnviar(req: Request, res: Response): Promise<void> {
  const adminId = req.usuarioId;
  if (!adminId) {
    res.status(401).json({ error: "no_autenticado" });
    return;
  }

  const body = req.body as { alumnoIds?: unknown; codigo?: unknown };
  const idsRaw = Array.isArray(body.alumnoIds) ? body.alumnoIds : [];
  const ids = Array.from(new Set(idsRaw.map(Number).filter((n) => Number.isInteger(n) && n > 0))).sort((a, b) => a - b);

  if (ids.length === 0) {
    res.status(400).json({ error: "sin_destinatarios", mensaje: "Sin destinatarios seleccionados." });
    return;
  }
  if (ids.length > TOPE_DESTINATARIOS_POR_ENVIO) {
    res.status(400).json({
      error: "demasiados_destinatarios",
      mensaje: `Máximo ${TOPE_DESTINATARIOS_POR_ENVIO} destinatarios por envío — selecciona menos o envía en varias tandas.`,
    });
    return;
  }

  const codigoCupon = typeof body.codigo === "string" ? body.codigo.trim().toUpperCase() : "";
  let cuponInfo: Extract<CuponGlobalResultado, { ok: true }> | null = null;
  if (codigoCupon !== "") {
    const resultado = await resolverCupon(codigoCupon);
    if (!resultado.ok) {
      res.status(400).json({ error: "cupon_invalido", mensaje: resultado.error });
      return;
    }
    cuponInfo = resultado;
  }

  if (esDobleSubmit(`despertar:${adminId}:${ids.join(",")}:${codigoCupon}`)) {
    res.status(409).json({ error: "doble_envio", mensaje: "Este envío ya se disparó hace unos segundos." });
    return;
  }

  const usuarios = await usuariosElegiblesParaEnvio(ids);
  const omitidos = ids.length - usuarios.length;
  const remitenteNoreply = direccionRemitente("noreply");

  let enviados = 0;
  let fallidos = 0;

  for (const usuario of usuarios) {
    const correo = usuario.correo.toLowerCase().trim();
    const primerNombre = usuario.nombre.trim().split(" ")[0] ?? usuario.nombre;
    const unsubUrl = generarUnsubUrl(correo);
    const headers = headersUnsubscribe(remitenteNoreply, unsubUrl);

    let asuntoFinal: string;
    let html: string;
    if (cuponInfo) {
      asuntoFinal = `Un ${cuponInfo.porcentaje}% de descuento para tu próxima clase en Nubira`;
      html = generarHtmlEmailCuponPromocional(
        primerNombre,
        codigoCupon,
        cuponInfo.porcentaje,
        cuponInfo.fechaExpiracion,
        "Hace un tiempo te registraste en Nubira. Te dejamos un cupón para volver:",
        unsubUrl,
      );
    } else {
      asuntoFinal = ASUNTO_BASE;
      html = generarHtmlEmailDespertarDormidos(primerNombre, unsubUrl);
    }

    const exito = await enviarCorreo(correo, asuntoFinal, html, "noreply", headers);
    await registrarEnvio(adminId, correo, asuntoFinal, html, exito);

    if (exito) enviados++;
    else fallidos++;

    await sleep(DELAY_ENTRE_ENVIOS_MS);
  }

  const resultado: EnviarDormidosResultado = { enviados, fallidos, omitidos };
  res.status(200).json(resultado);
}
