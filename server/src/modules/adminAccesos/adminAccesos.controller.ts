import { createHash } from "node:crypto";
import type { Request, Response } from "express";
import * as repo from "./adminAccesos.repository.js";
import type {
  AccesosResumen,
  BotFila,
  BusquedaFallida,
  DetalleResumen,
  EventoHistorial,
  PaginaFila,
  TabAccesos,
  UsuarioTrafico,
} from "./adminAccesos.types.js";

const TABS_VALIDOS: TabAccesos[] = ["trafico", "bots", "paginas", "fallidas"];
function normalizarTab(v: unknown): TabAccesos {
  return TABS_VALIDOS.includes(v as TabAccesos) ? (v as TabAccesos) : "trafico";
}
function iso(d: Date | null | undefined): string | null {
  return d ? new Date(d).toISOString() : null;
}
// Puerto exacto de "$guest_id = 'GST-' . strtoupper(substr(md5($ip_str), 0, 5))"
// (admin_accesos_vitrina.php:982) y del hash equivalente usado en la vista detalle.
function hashCorto(ip: string): string {
  return createHash("md5").update(ip).digest("hex").slice(0, 5).toUpperCase();
}

export async function getAccesos(req: Request, res: Response): Promise<void> {
  const tab = normalizarTab(req.query.tab);

  if (tab === "bots") {
    const { bots: filas, stats } = await repo.listarBots();
    const bots: BotFila[] = filas.map((b) => ({
      ipUsuario: b.ip_usuario,
      userAgent: b.user_agent,
      totalHits: b.total_hits,
      urlsUnicas: b.urls_unicas,
      ultimaVisita: iso(b.ultima_visita)!,
      primeraVisita: iso(b.primera_visita)!,
    }));
    const resumen: AccesosResumen = { tab, bots: { bots, stats: { totalEventos: stats.total_eventos, ipsUnicas: stats.ips_unicas, botsUnicos: stats.bots_unicos } } };
    res.status(200).json(resumen);
    return;
  }

  if (tab === "paginas") {
    const { paginas: filas, totalHits } = await repo.listarPaginas();
    const paginas: PaginaFila[] = filas.map((p) => ({ url: p.url, hits: p.hits, uniques: p.uniques }));
    const resumen: AccesosResumen = { tab, paginas: { totalHits, paginas } };
    res.status(200).json(resumen);
    return;
  }

  if (tab === "fallidas") {
    const filas = await repo.listarFallidas();
    const busquedas: BusquedaFallida[] = filas.map((f) => ({ termino: f.termino, totalIntentos: f.total_intentos, ultimaBusqueda: iso(f.ultima_busqueda)! }));
    const resumen: AccesosResumen = { tab, fallidas: { busquedas } };
    res.status(200).json(resumen);
    return;
  }

  const { usuarios: filas, contadores } = await repo.listarTrafico();
  const usuarios: UsuarioTrafico[] = filas.map((u) => ({
    usuarioId: u.usuario_id,
    ipUsuario: u.ip_usuario,
    ultimaActividad: iso(u.ultima_actividad)!,
    totalAcciones: u.total_acciones,
    ultimaUrl: u.ultima_url,
    ultimaAccionTxt: u.ultima_accion_txt,
    nombre: u.nombre,
    fotoPerfil: u.foto_perfil,
    institucion: u.institucion,
    correo: u.correo,
  }));
  // SUM() en MySQL devuelve DECIMAL — mysql2 lo entrega como string para no perder
  // precisión (a diferencia de COUNT(), que sí llega como number JS) — se fuerza a number acá.
  const resumen: AccesosResumen = {
    tab,
    trafico: {
      contadores: { alumnos: Number(contadores.alumnos ?? 0), invitados: Number(contadores.invitados ?? 0), bots: Number(contadores.bots ?? 0) },
      usuarios,
    },
  };
  res.status(200).json(resumen);
}

export async function getDetalle(req: Request, res: Response): Promise<void> {
  const usuarioId = Number(req.query.uid);
  if (!Number.isInteger(usuarioId) || usuarioId < 0) {
    res.status(400).json({ error: "uid_invalido" });
    return;
  }
  const filtroIp = typeof req.query.ip === "string" && req.query.ip !== "" ? req.query.ip : null;
  const col = typeof req.query.col === "string" ? req.query.col : "id";
  const ord = typeof req.query.ord === "string" ? req.query.ord : "desc";

  const d = await repo.obtenerDetalle({ usuarioId, filtroIp, col, ord });

  // Puerto exacto de admin_accesos_vitrina.php:179-185 (síntesis de nombre/correo para
  // invitados — bot/anónimo/huella de IP con hash corto no reversible para el nombre).
  const fueBot = (d.stats.fue_bot ?? 0) === 1;
  let nombre: string;
  let correo: string | null;
  if (d.isGuest) {
    nombre = filtroIp ? (fueBot ? "Bot/Crawler" : `Invitado ${hashCorto(filtroIp)}`) : "Tráfico Anónimo";
    correo = filtroIp ? `Huella: ${filtroIp}` : "Usuarios sin cuenta registrada";
  } else {
    nombre = d.usuarioTarget.nombre ?? "Usuario Desconocido";
    correo = d.usuarioTarget.correo;
  }

  const eventos: EventoHistorial[] = d.historial.map((h) => ({
    id: h.id,
    accion: h.accion,
    detalle: h.detalle,
    url: h.url,
    ipUsuario: h.ip_usuario,
    fecha: iso(h.fecha)!,
    esBot: h.es_bot === 1,
  }));

  const resumen: DetalleResumen = {
    usuario: {
      usuarioId,
      esGuest: d.isGuest,
      ip: d.targetIp,
      nombre,
      correo,
      fotoPerfil: d.usuarioTarget.fotoPerfil,
      totalEventos: d.stats.total_acciones ?? 0,
      accionFav: d.accionFav,
      primeraVisita: iso(d.stats.min_f),
      ultimaVisita: iso(d.stats.max_f),
      online: d.online,
      fueBot,
      urlsUnicas: d.stats.urls_unicas ?? 0,
      diasDesdePrimera: d.diasDesdePrimera,
      primerReferrer: d.primerReferrer,
      primerUtm: d.primerUtm,
      primerContacto: iso(d.conv.primer_contacto),
      primerApunte: iso(d.conv.primer_apunte),
    },
    eventos,
  };
  res.status(200).json(resumen);
}

export async function postEliminar(req: Request, res: Response): Promise<void> {
  const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(Number).filter((n: number) => Number.isInteger(n) && n > 0) : [];
  if (ids.length === 0) {
    res.status(400).json({ error: "sin_ids" });
    return;
  }
  const afectados = await repo.eliminarEventos(ids);
  res.status(200).json({ ok: true, afectados });
}

export async function postPurgarBots(_req: Request, res: Response): Promise<void> {
  const afectados = await repo.purgarBotsAntiguos();
  res.status(200).json({ ok: true, afectados });
}

function celdaCsv(v: unknown): string {
  const s = v === null || v === undefined ? "" : String(v);
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

// Puerto exacto de admin_accesos_vitrina.php:66-119 (exportación CSV, mismas columnas y
// mismo orden).
export async function getExportar(req: Request, res: Response): Promise<void> {
  const uidParam = req.query.uid;
  const uid = typeof uidParam === "string" && uidParam !== "" ? Number(uidParam) : null;
  const fecha = typeof req.query.fecha === "string" && req.query.fecha !== "" ? req.query.fecha : null;
  const incluirBots = req.query.incluir_bots === "1" || req.query.incluir_bots === "true";

  const filas = await repo.listarParaExportar({ uid, fecha, incluirBots });

  const filename = `nubira_actividad_${new Date().toISOString().slice(0, 16).replace(/[-T:]/g, "-")}.csv`;
  res.setHeader("Content-Type", "text/csv; charset=utf-8");
  res.setHeader("Content-Disposition", `attachment; filename=${filename}`);

  const encabezado = ["ID", "Usuario ID", "Nombre", "Accion", "Detalle", "URL", "IP", "Es Bot", "User Agent", "Fecha"].join(",") + "\n";
  const cuerpo = filas
    .map((row) =>
      [
        row.id,
        row.usuario_id,
        row.nombre ?? "Visitante",
        row.accion,
        row.detalle,
        row.url,
        row.ip_usuario,
        row.es_bot ?? 0,
        row.user_agent ?? "",
        row.fecha instanceof Date ? row.fecha.toISOString().slice(0, 19).replace("T", " ") : row.fecha,
      ]
        .map(celdaCsv)
        .join(","),
    )
    .join("\n");

  res.status(200).send(encabezado + cuerpo);
}
