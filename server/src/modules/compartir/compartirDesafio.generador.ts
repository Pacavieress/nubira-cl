import crypto from "node:crypto";
import { badgePill, botonGenerico, circuloNumerado, envolverTexto, PALETA_MARCA, renderizarCardJpeg, textoCentrado, textoIzquierda } from "../../lib/svgCard.js";
import type { DatosPreguntasCompartir, MateriaCompartir, PreguntaCompartir } from "./compartir.types.js";

// Puerto VISUAL (no pixel-a-pixel, ver nota de alcance en svgCard.ts) de
// nb_generar_imagen_desafio_post() — imagen_compartir_desafio.php:60-124. Mismo contenido y
// misma idea de layout (badge de materia, título envuelto hasta 3 líneas, subtítulo fijo,
// botón CTA, marca), bloque completo centrado verticalmente según su alto real medido —
// pero calculado con la matemática propia de svgCard.ts en vez de replicar cada constante
// GD línea por línea.
const VERSION_IMAGEN = "node-v1";
const W = 1080;
const H = 1350;
const M = 110;

export function fingerprintDesafio(materia: MateriaCompartir): string {
  const base = `${VERSION_IMAGEN}|${materia.slug}|${materia.nombre}`;
  return crypto.createHash("md5").update(base).digest("hex").slice(0, 10);
}

export async function generarImagenDesafioPost(materia: MateriaCompartir): Promise<Buffer> {
  const nombreMateriaMayus = materia.nombre.toUpperCase();
  const tituloTxt = `Desafío Nubira de hoy: ${materia.nombre}`;
  const lineasTitulo = envolverTexto(tituloTxt, "bold", 56, W - M * 2, 3);

  const altoLineaTitulo = 72;
  const gapBadgeTitulo = 50;
  const gapTituloSub = 36;
  const altoSub = 44;
  const gapSubBoton = 70;
  const gapBotonMarca = 45;
  const altoMarca = 40;

  // Medidas reales (badge/botón) antes de calcular el alto total del bloque — mismo
  // criterio "medir todo primero, centrar después" que el comentario del PHP real explica
  // en imagen_compartir_desafio.php:79-82 (títulos cortos vs largos, 1 a 3 líneas).
  const alturaBadge = badgePill(nombreMateriaMayus, W / 2, 0, PALETA_MARCA.acento, PALETA_MARCA.acento).alto;
  const alturaBoton = botonGenerico("Jugar ahora", W / 2, 0, PALETA_MARCA.acento).alto;

  const alturaTitulo = lineasTitulo.length * altoLineaTitulo;
  const altoTotal = alturaBadge + gapBadgeTitulo + alturaTitulo + gapTituloSub + altoSub + gapSubBoton + alturaBoton + gapBotonMarca + altoMarca;

  let y = Math.round((H - altoTotal) / 2);
  const partes: string[] = [];

  const badge = badgePill(nombreMateriaMayus, W / 2, y, PALETA_MARCA.acento, PALETA_MARCA.acento);
  partes.push(badge.svg);
  y += alturaBadge + gapBadgeTitulo;

  for (const linea of lineasTitulo) {
    partes.push(textoCentrado(linea, "bold", 56, PALETA_MARCA.txt, W / 2, y + 45));
    y += altoLineaTitulo;
  }
  y += gapTituloSub;

  partes.push(textoCentrado("3 preguntas rápidas. ¿Cuánto sabes de verdad?", "regular", 28, PALETA_MARCA.txt2, W / 2, y + 22));
  y += altoSub + gapSubBoton;

  const boton = botonGenerico("Jugar ahora", W / 2, y, PALETA_MARCA.acento);
  partes.push(boton.svg);
  y += alturaBoton + gapBotonMarca;

  partes.push(textoCentrado("nubira.cl/desafio", "bold", 28, PALETA_MARCA.acento, W / 2, y + 20));

  return renderizarCardJpeg(partes.join("\n"), W, H, 90);
}

/* ============================================================
   Compartir las 3 preguntas de UNA sesión concreta — HISTORY 9:16 únicamente.
   Puerto VISUAL (ver nota de alcance en svgCard.ts) de
   nb_generar_imagen_desafio_preguntas_history() (imagen_compartir_desafio.php:276-382).
   ============================================================ */

const W_HIST = 1080;
const H_HIST = 1920;
const M_HIST = 100;

export function fingerprintDesafioPreguntas(ids: number[], datos: DatosPreguntasCompartir): string {
  let base = `${VERSION_IMAGEN}|preguntas|${ids.join(",")}|${datos.materia.nombre}`;
  for (const p of datos.preguntas) {
    base += `|${p.tipo}|${p.enunciado}|${Object.values(p.opciones).join("|")}`;
  }
  return crypto.createHash("md5").update(base).digest("hex").slice(0, 10);
}

interface PerfilPreguntas {
  diamCirculo: number;
  gapCirculoTexto: number;
  sizeEnun: number;
  lhEnun: number;
  sizeOp: number;
  lhOp: number;
  gapEnunOp: number;
  gapOpciones: number;
  gapPreguntas: number;
}

const PERFIL_NORMAL: PerfilPreguntas = {
  diamCirculo: 64,
  gapCirculoTexto: 28,
  sizeEnun: 32,
  lhEnun: 40,
  sizeOp: 26,
  lhOp: 34,
  gapEnunOp: 36,
  gapOpciones: 10,
  gapPreguntas: 76,
};
const PERFIL_COMPACTO: PerfilPreguntas = {
  diamCirculo: 52,
  gapCirculoTexto: 24,
  sizeEnun: 26,
  lhEnun: 32,
  sizeOp: 22,
  lhOp: 28,
  gapEnunOp: 28,
  gapOpciones: 7,
  gapPreguntas: 58,
};

// Dibuja (siempre — construir un string SVG que a veces se descarta es barato, a
// diferencia del raster mutable de GD que sí justificaba un modo "soloMedir" aparte en el
// PHP real) las 3 preguntas numeradas con sus opciones neutras. Devuelve el SVG y el alto
// total real consumido, para la decisión normal/compacto de más abajo.
function dibujarBloquePreguntas(preguntas: PreguntaCompartir[], perfil: PerfilPreguntas, yStart: number): { svg: string; alto: number } {
  const xTexto = M_HIST + perfil.diamCirculo + perfil.gapCirculoTexto;
  const maxTxt = W_HIST - M_HIST - xTexto;
  const partes: string[] = [];

  let y = yStart;
  preguntas.forEach((p, i) => {
    const circleTop = y;
    const circleCenterY = circleTop + Math.round(perfil.diamCirculo / 2);

    const lineasEnun = envolverTexto(p.enunciado, "semibold", perfil.sizeEnun, maxTxt, 3);
    const altoEnun = lineasEnun.length * perfil.lhEnun;

    partes.push(circuloNumerado(i + 1, M_HIST + Math.round(perfil.diamCirculo / 2), circleCenterY, perfil.diamCirculo, PALETA_MARCA.acento, PALETA_MARCA.blanco));

    const yTextoBase = circleTop + Math.round(perfil.sizeEnun * 0.8);
    lineasEnun.forEach((linea, li) => {
      partes.push(textoIzquierda(linea, "semibold", perfil.sizeEnun, PALETA_MARCA.txt, xTexto, yTextoBase + li * perfil.lhEnun));
    });

    y = circleTop + altoEnun + perfil.gapEnunOp;

    const letras = Object.keys(p.opciones) as Array<keyof typeof p.opciones>;
    letras.forEach((letra, oi) => {
      const texto = `${String(letra).toUpperCase()}.  ${p.opciones[letra]}`;
      const lineasOp = envolverTexto(texto, "regular", perfil.sizeOp, maxTxt, 2);
      const yOpBase = y + Math.round(perfil.sizeOp * 0.8);
      lineasOp.forEach((linea, li) => {
        partes.push(textoIzquierda(linea, "regular", perfil.sizeOp, PALETA_MARCA.txt2, xTexto, yOpBase + li * perfil.lhOp));
      });
      y += lineasOp.length * perfil.lhOp;
      if (oi < letras.length - 1) y += perfil.gapOpciones;
    });

    if (i < preguntas.length - 1) y += perfil.gapPreguntas;
  });

  return { svg: partes.join("\n"), alto: y - yStart };
}

export async function generarImagenDesafioPreguntasHistory(materia: MateriaCompartir, preguntas: PreguntaCompartir[]): Promise<Buffer> {
  if (preguntas.length !== 3) throw new Error("Se requieren exactamente 3 preguntas");

  // "Zona segura" 4:5 centrada dentro del canvas 9:16 — Instagram recorta arriba/abajo a
  // 1080x1350 cuando esta imagen se publica como feed en vez de historia. Título/subtítulo/
  // 3 preguntas deben caber en [safeTop, safeBottom]; el botón+marca puede quedar fuera
  // (abajo) — se sacrifica a propósito en feed, se ve completo en historia igual. Mismo
  // criterio que imagen_compartir_desafio.php:294-302.
  const safeTop = Math.round((H_HIST - 1350) / 2);
  const safeBottom = safeTop + 1350;

  const partes: string[] = [];
  let y = safeTop + 20;

  const tituloTxt = `Desafío Nubira de hoy · ${materia.nombre}`;
  const lineasTitulo = envolverTexto(tituloTxt, "bold", 38, W_HIST - M_HIST * 2, 2);
  const lhTit = 46;
  lineasTitulo.forEach((linea, i) => {
    partes.push(textoCentrado(linea, "bold", 38, PALETA_MARCA.txt, W_HIST / 2, y + 30 + i * lhTit));
  });
  y += lineasTitulo.length * lhTit + 34;

  partes.push(textoCentrado("¿Cuánto sabes tú de verdad?", "regular", 26, PALETA_MARCA.txt2, W_HIST / 2, y));
  y += 50;

  const contentTop = y;
  const bottomReservado = 260;
  const alturaDisponible = safeBottom - contentTop;

  let perfil = PERFIL_NORMAL;
  let bloque = dibujarBloquePreguntas(preguntas, perfil, contentTop);
  if (bloque.alto > alturaDisponible) {
    perfil = PERFIL_COMPACTO;
    bloque = dibujarBloquePreguntas(preguntas, perfil, contentTop);
  }

  const yInicio = bloque.alto < alturaDisponible ? contentTop + Math.round((alturaDisponible - bloque.alto) / 2) : contentTop;
  const bloqueFinal = yInicio === contentTop ? bloque : dibujarBloquePreguntas(preguntas, perfil, yInicio);
  partes.push(bloqueFinal.svg);

  const yBoton = H_HIST - bottomReservado + 40;
  const boton = botonGenerico("Juega tú mismo", W_HIST / 2, yBoton, PALETA_MARCA.acento);
  partes.push(boton.svg);
  const yMarca = yBoton + boton.alto + 45;
  partes.push(textoCentrado("nubira.cl/desafio", "bold", 28, PALETA_MARCA.acento, W_HIST / 2, yMarca + 20));

  return renderizarCardJpeg(partes.join("\n"), W_HIST, H_HIST, 90);
}
