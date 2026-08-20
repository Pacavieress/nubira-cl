import type { Request, Response } from "express";
import { mapPreguntaRow } from "./desafio.mapper.js";
import {
  actualizarProgreso,
  esMateriaValida,
  getCategoriaServicioPorMateria,
  getMateriasActivas,
  getNivelActual,
  getPreguntasCandidatas,
  getPreguntasParaCorregir,
  insertarIntento,
  marcarPreguntasVistas,
  resetVistasParaMateria,
} from "./desafio.repository.js";
import type { PreguntaDesafioRow, ResultadoDesafio } from "./desafio.types.js";
import { TIPOS_OPINION } from "./desafio.types.js";

export async function getMaterias(_req: Request, res: Response): Promise<void> {
  const materias = await getMateriasActivas();
  res.status(200).json({ data: materias });
}

// Puerto exacto de nb_desafio_seleccionar() (cargar_desafio.php:86-98) — cascada de 3
// intentos con dificultad cada vez más amplia (exacta -> vecina -> cualquiera), sin volver
// a tocar la BD si el primer intento ya alcanza las 3.
async function seleccionarPreguntas(materiaSlug: string, usuarioId: number, nivelActual: number): Promise<PreguntaDesafioRow[]> {
  let rows = await getPreguntasCandidatas(materiaSlug, usuarioId, [nivelActual]);
  if (rows.length >= 3) return rows;

  const vecinos = Array.from(new Set([nivelActual - 1, nivelActual, nivelActual + 1].filter((n) => n >= 1 && n <= 3)));
  rows = await getPreguntasCandidatas(materiaSlug, usuarioId, vecinos);
  if (rows.length >= 3) return rows;

  return getPreguntasCandidatas(materiaSlug, usuarioId, null);
}

// Puerto exacto de cargar_desafio.php completo (líneas 18-149, sin el gate de sesión
// duplicado del PHP real: acá ya lo garantiza requireAuth vía desafio.routes.ts).
export async function getPreguntas(req: Request, res: Response): Promise<void> {
  const materia = String(req.query.materia ?? "").trim();
  if (materia === "") {
    res.status(400).json({ ok: false, error: "materia_requerida" });
    return;
  }

  const materiaValida = await esMateriaValida(materia);
  if (!materiaValida) {
    res.status(400).json({ ok: false, error: "materia_invalida" });
    return;
  }

  const usuarioId = req.usuarioId as number;
  const nivelActual = await getNivelActual(usuarioId, materia);

  let rows = await seleccionarPreguntas(materia, usuarioId, nivelActual);
  if (rows.length < 3) {
    // Banco agotado para este usuario en esta materia — reset silencioso de "vistas" y
    // reintenta toda la cascada, igual que cargar_desafio.php:109-120.
    await resetVistasParaMateria(usuarioId, materia);
    rows = await seleccionarPreguntas(materia, usuarioId, nivelActual);
  }

  const preguntas = rows.map(mapPreguntaRow);
  if (preguntas.length < 3) {
    // Banco insuficiente para esta materia (revisado_por_admin=1 filtra preguntas aún no
    // aprobadas) — no es un error del cliente, es falta de contenido.
    res.status(200).json({ ok: false, error: "contenido_insuficiente", disponibles: preguntas.length });
    return;
  }

  res.status(200).json({ ok: true, materia, preguntas });
}

// Puerto exacto de responder_desafio.php completo (líneas 13-157, gate de sesión también
// vía requireAuth).
export async function responder(req: Request, res: Response): Promise<void> {
  const usuarioId = req.usuarioId as number;
  const body = req.body as { materia?: unknown; respuestas?: unknown };

  const materia = typeof body.materia === "string" ? body.materia.trim() : "";
  const respuestasCrudas = Array.isArray(body.respuestas) ? body.respuestas : null;

  if (materia === "" || !respuestasCrudas || respuestasCrudas.length !== 3) {
    res.status(400).json({ ok: false, error: "datos_invalidos" });
    return;
  }

  const OPCIONES_VALIDAS = new Set(["a", "b", "c", "d"]);
  const preguntaIds: number[] = [];
  const elegidas = new Map<number, string>();

  for (const r of respuestasCrudas as Record<string, unknown>[]) {
    const pid = Number(r.preguntaId ?? r.pregunta_id ?? 0);
    const op = typeof r.opcion === "string" ? r.opcion.toLowerCase().trim() : "";
    if (!Number.isInteger(pid) || pid <= 0 || !OPCIONES_VALIDAS.has(op)) {
      res.status(400).json({ ok: false, error: "datos_invalidos" });
      return;
    }
    preguntaIds.push(pid);
    elegidas.set(pid, op);
  }

  if (new Set(preguntaIds).size !== 3) {
    res.status(400).json({ ok: false, error: "datos_invalidos" });
    return;
  }

  // Nunca confía en el cliente: trae la respuesta correcta real desde la BD y exige que
  // las 3 preguntas pertenezcan de verdad a la materia declarada.
  const correccionRows = await getPreguntasParaCorregir(preguntaIds, materia);
  if (correccionRows.length !== 3) {
    res.status(400).json({ ok: false, error: "preguntas_invalidas" });
    return;
  }

  const correctas = new Map(correccionRows.map((r) => [r.id, r.respuesta_correcta]));
  const tipos = new Map(correccionRows.map((r) => [r.id, r.tipo]));

  // Tipos de opinión (sin respuesta única, diseño aprobado "Opción C: auto-acierto
  // neutro"): cuentan siempre como acierto.
  let aciertos = 0;
  for (const [pid, op] of elegidas) {
    const tipo = tipos.get(pid);
    if (tipo && (TIPOS_OPINION as readonly string[]).includes(tipo)) aciertos++;
    else if (correctas.get(pid) === op) aciertos++;
  }

  await insertarIntento(usuarioId, materia, aciertos);
  await marcarPreguntasVistas(usuarioId, preguntaIds as [number, number, number]);

  const resultado: "bien" | "mal" = aciertos >= 2 ? "bien" : "mal";
  const delta = resultado === "bien" ? 1 : -1;
  await actualizarProgreso(usuarioId, materia, delta);

  const categoriaServicio = resultado === "mal" ? await getCategoriaServicioPorMateria(materia) : null;

  const body_: ResultadoDesafio = { materia, aciertos, resultado, categoriaServicio };
  res.status(200).json({ ok: true, ...body_ });
}
