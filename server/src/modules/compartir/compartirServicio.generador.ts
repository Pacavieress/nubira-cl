import crypto from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { abreviarInstitucion } from "../../lib/institucion.js";
import {
  avatarCircular,
  badgePillIzquierda,
  botonIzquierdo,
  cargarImagenComoDataUri,
  envolverTexto,
  estrellaConRating,
  medirAnchoTexto,
  PALETA_MARCA,
  pillSolidoIzquierda,
  renderizarCardJpeg,
  textoCentrado,
  textoCentradoDosColores,
  textoDerecha,
  textoIzquierda,
} from "../../lib/svgCard.js";
import type { ServicioCompartir } from "./compartir.types.js";

// Puerto VISUAL (ver nota de alcance en svgCard.ts) de nb_generar_imagen_post()
// (imagen_compartir.php:429-536) — SOLO formato POST 4:5, mismo criterio de slices que
// Compartir Apuntes/Desafío: HISTORY queda para otra pieza si hace falta.
// Incrementar (v1 -> v2 -> ...) invalida automáticamente el cache de /upload/compartir/
// cuando cambia el diseño visual — mismo criterio que NB_IMG_VERSION en imagen_compartir.php
// (PHP), que se bumpeó en el mismo cambio (30/08/2026: badge "Disponible" reubicado, features
// en 1 columna).
const VERSION_IMAGEN_SERVICIO = "node-v2";
const W = 1080;
const H = 1350;
const M = 110;

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// app/perfil/fotos/ — mismo directorio compartido de filesystem que ya usa
// compartirApunte.generador.ts (fuera de /upload/, junto al sitio PHP).
const FOTOS_TUTOR_DIR = path.resolve(__dirname, "../../../../app/perfil/fotos");

export function fingerprintServicio(s: ServicioCompartir): string {
  const base = [
    VERSION_IMAGEN_SERVICIO,
    s.id,
    s.titulo,
    s.precio,
    s.precioOferta ?? "",
    s.isSubvencionado ? 1 : 0,
    s.ofertaTermino ? s.ofertaTermino.toISOString().slice(0, 10) : "",
    s.fotoPerfil ?? "",
    s.categoria ?? "",
    s.institucionMaestra ?? "",
    s.ratingProm,
    s.ratingVotos,
  ].join("|");
  return crypto.createHash("md5").update(base).digest("hex").slice(0, 10);
}

function rutaFotoTutor(s: ServicioCompartir): string | null {
  if (!s.fotoPerfil) return null;
  return path.join(FOTOS_TUTOR_DIR, path.basename(s.fotoPerfil));
}

// Mismas 2 iniciales (nombre + "apellido", tomando la 2da palabra) que nb_dibujar_avatar()
// — duplicado deliberado del mismo helper de 6 líneas ya escrito en
// compartirApunte.generador.ts: no vale la pena forzar un import cruzado entre 2
// generadores por una función tan chica (ver criterio de no-abstracción prematura).
function iniciales(nombre: string | null): string {
  const partes = (nombre?.trim() || "?").split(/\s+/);
  let ini = (partes[0]?.[0] ?? "?").toUpperCase();
  if (partes[1]) ini += partes[1][0]!.toUpperCase();
  return ini;
}

// Puerto de nombre_publico_tutor() (app/helpers/nombre_publico.php) — primera palabra
// completa (capitalizada) + inicial de la ÚLTIMA palabra (no la 2da, a diferencia de
// iniciales() de arriba) + ".". Protege el apellido completo del tutor en esta card
// pública, mismo criterio de privacidad documentado en el PHP real.
function nombrePublicoTutor(nombreCompleto: string | null): string {
  const partes = (nombreCompleto ?? "").trim().split(/\s+/).filter(Boolean);
  if (partes.length === 0) return "Tutor";
  const primera = partes[0]!;
  const out = primera[0]!.toUpperCase() + primera.slice(1).toLowerCase();
  if (partes.length >= 2) {
    const ultima = partes[partes.length - 1]!;
    return `${out} ${ultima[0]!.toUpperCase()}.`;
  }
  return out;
}

function formatoCLP(valor: number): string {
  return `$${Math.round(valor).toLocaleString("es-CL")}`;
}

// Puerto de las 4 "features fijas" (imagen_compartir.php:388-411) — 1 sola columna
// alineada a la izquierda (ANTES: grilla 2x2), texto más grande (18px -> 30px). Cambio de
// diseño pedido explícitamente por el usuario (30/08/2026) y confirmado contra 2 prototipos
// renderizados antes de tocar este archivo — ver el mismo cambio espejado en
// nb_dibujar_features_fijas() (imagen_compartir.php).
function dibujarFeaturesFijas(yTop: number): { svg: string; yFin: number } {
  const features = ["Clase 100% online en Nubira", "Chat anónimo antes de contratar", "Horarios publicados por el tutor", "Garantía Nubira"];
  const padX = M;
  const size = 30;
  const rowGap = 58;
  const dotR = 7;
  const dotGap = 16;

  const partes: string[] = [];
  features.forEach((label, i) => {
    const yBase = yTop + 14 + i * rowGap;
    const cyDot = yBase - size * 0.35;
    partes.push(`<circle cx="${padX + dotR}" cy="${cyDot}" r="${dotR}" fill="${PALETA_MARCA.acento}" />`);
    partes.push(textoIzquierda(label, "semibold", size, PALETA_MARCA.txt, padX + dotR * 2 + dotGap, yBase));
  });

  return { svg: partes.join("\n"), yFin: yTop + 14 + (features.length - 1) * rowGap + 20 };
}

// Puerto de nb_dibujar_precio_centrado() (imagen_compartir.php:128-190) — con oferta
// vigente: badge OFERTA verde + precio oferta grande + original tachado, ambos centrados
// como bloque; sin oferta vigente: precio normal + "CLP" chico, o "Gratis" si no hay precio.
// $ofertaVigente acá usa EXACTAMENTE las mismas 3 condiciones que la función real que se
// está portando (is_subvencionado + oferta_termino sin vencer) — DELIBERADAMENTE distinto
// del computeOfertaVigente() de servicios.mapper.ts (que además exige cupos_oferta>0):
// esa es una inconsistencia preexistente del propio código PHP fuente (el comentario
// original en imagen_compartir.php se declara equivalente a vitrina.php, pero no chequea
// cupos_oferta) — no corresponde "corregirla" en este port, que busca fidelidad visual a
// la función real, no a otra función distinta del sitio.
function dibujarPrecioCentrado(s: ServicioCompartir, yBase: number): string {
  const of = s.precioOferta ?? 0;
  const pr = s.precio;
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  const ofertaVigente = s.isSubvencionado && (s.ofertaTermino === null || s.ofertaTermino >= hoy);

  if (of <= 0 && pr <= 0) {
    return textoCentrado("Gratis", "bold", 48, PALETA_MARCA.txt, W / 2, yBase);
  }

  if (of > 0 && ofertaVigente) {
    const badgeTxt = "OFERTA";
    const bw = medirAnchoTexto(badgeTxt, "bold", 22);
    const bx1 = Math.round((W - bw - 36) / 2);
    const by1 = yBase - 115;
    const badge = pillSolidoIzquierda(badgeTxt, bx1, by1, "#16A34A", "#FFFFFF", 22, 18, 11);

    // Segmentos SIN espacios embebidos + gaps fijos en px entre ellos — no medirAnchoTexto
    // de un string con espacio: esa medición es un bbox de TINTA (ver nota de
    // textoCentradoDosColores en svgCard.ts), un espacio no tiene tinta, así que un gap
    // "medido" así siempre da 0 y los segmentos quedan pegados. Con 3 segmentos de colores
    // Y tamaños DISTINTOS en la misma línea (a diferencia del título, que son solo 2 y
    // podían resolverse con tspans) un gap fijo en px es más simple que forzar tspans con
    // baseline-shift entre tamaños de fuente distintos.
    const szOrig = 28;
    const ofTxt = `$${Math.round(of).toLocaleString("es-CL")}`;
    const clpTxt = "CLP";
    const origTxt = `$${Math.round(pr).toLocaleString("es-CL")}`;
    const gapOfClp = 6;
    const gapClpOrig = 22;

    const wOf = medirAnchoTexto(ofTxt, "bold", 48);
    const wClp = medirAnchoTexto(clpTxt, "semibold", 32);
    const wOrig = medirAnchoTexto(origTxt, "regular", szOrig);
    let x = Math.round((W - wOf - gapOfClp - wClp - gapClpOrig - wOrig) / 2);

    const partes: string[] = [badge.svg];
    partes.push(textoIzquierda(ofTxt, "bold", 48, PALETA_MARCA.txt, x, yBase));
    x += wOf + gapOfClp;
    partes.push(textoIzquierda(clpTxt, "semibold", 32, PALETA_MARCA.txt, x, yBase));
    x += wClp + gapClpOrig;
    const yo = yBase - Math.round((48 - szOrig) * 0.45);
    partes.push(textoIzquierda(origTxt, "regular", szOrig, PALETA_MARCA.txt2, x, yo));
    const lineY = yo - Math.round(szOrig * 0.3);
    partes.push(`<line x1="${x}" y1="${lineY}" x2="${x + wOrig}" y2="${lineY}" stroke="${PALETA_MARCA.txt2}" stroke-width="3" />`);
    return partes.join("\n");
  }

  const mainTxt = formatoCLP(pr);
  const wMain = medirAnchoTexto(mainTxt, "bold", 48);
  const wClp = medirAnchoTexto("CLP", "semibold", 32);
  const gap = 6;
  const x = Math.round((W - wMain - gap - wClp) / 2);
  return `${textoIzquierda(mainTxt, "bold", 48, PALETA_MARCA.txt, x, yBase)}\n${textoIzquierda("CLP", "semibold", 32, PALETA_MARCA.txt, x + wMain + gap, yBase)}`;
}

export async function generarImagenServicioPost(s: ServicioCompartir): Promise<Buffer> {
  const partes: string[] = [];

  // PARTE 1: avatar grande + nombre + institución (el badge "Disponible" ya NO va acá —
  // se movió a PARTE 2, debajo de la línea de rating, cambio pedido explícitamente por el
  // usuario 30/08/2026).
  const diamAv = 400;
  const avTop = 150;
  const avLeft = M;
  const avCx = avLeft + Math.round(diamAv / 2);
  const dataUriFoto = await cargarImagenComoDataUri(rutaFotoTutor(s) ?? "");
  partes.push(avatarCircular(dataUriFoto, avCx, avTop, diamAv, PALETA_MARCA.acento, PALETA_MARCA.blanco, iniciales(s.nombreAlumno)));
  const avBottom = avTop + diamAv;

  const colX = avLeft + diamAv + 40;
  const colMaxW = W - M - colX;

  // envolverTexto(...,1) no trunca con "…" como nb_truncar_una_linea — simplificación
  // deliberada (mismo criterio ya aceptado en Compartir Apuntes): el nombre ya viene
  // abreviado por nombrePublicoTutor() ("Karen A."), el desborde real es poco probable.
  const nombre = envolverTexto(nombrePublicoTutor(s.nombreAlumno), "bold", 40, colMaxW, 1)[0] ?? "Tutor";
  const yNombre = avTop + 90;
  partes.push(textoIzquierda(nombre, "bold", 40, PALETA_MARCA.acento, colX, yNombre));

  const instRaw = (s.institucionMaestra ?? "").trim();
  const inst = instRaw !== "" ? abreviarInstitucion(instRaw, 22).toUpperCase() : "TUTOR PARTICULAR";
  const yInst = yNombre + 45;
  partes.push(textoIzquierda(inst, "regular", 24, PALETA_MARCA.txt2, colX, yInst));

  // PARTE 2: badge categoría + línea de rating + badge "Disponible" — los 3 apilados en la
  // misma columna (colX). "Disponible" vivía antes al lado del nombre (PARTE 1); bajó acá
  // debajo de "★ Nuevo"/rating, cambio pedido explícitamente por el usuario 30/08/2026.
  const cat = (s.categoria ?? "").trim().toUpperCase();
  const yCatBadge = yInst + 30;
  const badgeCat = badgePillIzquierda(cat, colX, yCatBadge, PALETA_MARCA.acento, PALETA_MARCA.acento);
  partes.push(badgeCat.svg);

  const yRating = yCatBadge + badgeCat.alto + 34;
  partes.push(estrellaConRating(s.ratingProm, s.ratingVotos, colX, yRating, 26, PALETA_MARCA.acento));

  const yDisponibleTop = yRating + 24;
  const badgeDisponible = pillSolidoIzquierda("Disponible", colX, yDisponibleTop, "#10B981", PALETA_MARCA.blanco);
  partes.push(badgeDisponible.svg);
  const yDisponibleBottom = yDisponibleTop + badgeDisponible.alto;

  // PARTE 3: título genérico (categoría en acento) — sin bio (mismo criterio de privacidad
  // documentado en el PHP real: el texto libre de la bio puede filtrar el apellido completo).
  let y = Math.max(avBottom, yDisponibleBottom + 20) + 110;

  const categoriaTxt = (s.categoria ?? "").trim();
  const tituloGenerico = `Clases particulares de ${categoriaTxt}`;
  const lineaTit = envolverTexto(tituloGenerico, "semibold", 34, W - M * 2, 1)[0] ?? tituloGenerico;
  if (categoriaTxt !== "" && lineaTit.endsWith(categoriaTxt)) {
    const prefijo = lineaTit.slice(0, lineaTit.length - categoriaTxt.length);
    partes.push(textoCentradoDosColores(prefijo, categoriaTxt, "semibold", 34, PALETA_MARCA.txt, PALETA_MARCA.acento, W / 2, y));
  } else {
    partes.push(textoCentrado(lineaTit, "semibold", 34, PALETA_MARCA.txt, W / 2, y));
  }
  y += 46 + 40;
  // Gap reducido (100 -> 35): con las features ahora en 1 columna más alta (4 filas en vez
  // de 2), había que subirlas para no empujar el precio/botón fuera del canvas — mismo
  // cambio pedido explícitamente por el usuario 30/08/2026, confirmado en el prototipo.
  y += 35;

  // PARTE 4: features en 1 columna + precio + botón (izquierda) + marca (misma fila).
  const featuresResult = dibujarFeaturesFijas(y);
  partes.push(featuresResult.svg);

  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  const ofertaVigenteCard = s.isSubvencionado && (s.ofertaTermino === null || s.ofertaTermino >= hoy) && (s.precioOferta ?? 0) > 0;
  y = featuresResult.yFin + 80;
  if (ofertaVigenteCard) y += 65;

  partes.push(dibujarPrecioCentrado(s, y));
  y += 95;

  const boton = botonIzquierdo("Agendar clase", M, y, PALETA_MARCA.acento);
  partes.push(boton.svg);
  partes.push(textoDerecha("Nubira.cl", "bold", 28, PALETA_MARCA.acento, W - M, y + 42));

  return renderizarCardJpeg(partes.join("\n"), W, H, 90);
}
