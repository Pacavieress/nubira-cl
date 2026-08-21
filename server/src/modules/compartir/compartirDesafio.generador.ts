import crypto from "node:crypto";
import { badgePill, botonGenerico, envolverTexto, PALETA_MARCA, renderizarCardJpeg, textoCentrado } from "../../lib/svgCard.js";
import type { MateriaCompartir } from "./compartir.types.js";

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
