import { test } from "node:test";
import assert from "node:assert/strict";
import { computeOfertaVigente, computeTier } from "../src/modules/servicios/servicios.mapper.js";
import type { ServicioRow } from "../src/modules/servicios/servicios.types.js";

// ---- computeTier: casos límite exactos de app/componentes/card_servicio_grid.php:76-84 ----

test("computeTier: leyenda requiere las 3 condiciones simultáneas (score>=100, votos>=10, rating>=4.7)", () => {
  assert.equal(computeTier(100, 10, 4.7), "leyenda");
});

test("computeTier: score=99 (un punto bajo el umbral de leyenda) NO da leyenda, cae a elite si corresponde", () => {
  assert.equal(computeTier(99, 10, 4.7), "elite");
});

test("computeTier: votos=9 (uno bajo el umbral de leyenda) NO da leyenda", () => {
  assert.equal(computeTier(100, 9, 4.7), "elite");
});

test("computeTier: rating=4.69 (bajo el umbral de leyenda) NO da leyenda", () => {
  assert.equal(computeTier(100, 10, 4.69), "elite");
});

test("computeTier: elite requiere score>=80, votos>=3, rating>=4.0", () => {
  assert.equal(computeTier(80, 3, 4.0), "elite");
  assert.equal(computeTier(80, 2, 4.0), "pro"); // votos insuficientes -> cae a pro
});

test("computeTier: pro es score>=80 sin cumplir los extras de elite", () => {
  assert.equal(computeTier(80, 0, 0), "pro");
});

test("computeTier: top es score>=60 y <80", () => {
  assert.equal(computeTier(60, 0, 0), "top");
  assert.equal(computeTier(79, 0, 0), "top");
});

test("computeTier: score<60 no tiene tier (null)", () => {
  assert.equal(computeTier(59, 100, 5), null);
  assert.equal(computeTier(0, 0, 0), null);
});

test("computeTier: rating null se trata como 0 (no revienta, no da leyenda/elite)", () => {
  assert.equal(computeTier(100, 10, null), "pro");
});

// ---- computeOfertaVigente: 4 condiciones de app/helpers/ofertas.php ----

function filaBase(overrides: Partial<ServicioRow>): ServicioRow {
  return {
    id: 1,
    slug: null,
    titulo: "Test",
    categoria: "Otros",
    modalidad: "Online",
    precio: "10000",
    precio_oferta: "5000",
    cupos_oferta: 3,
    oferta_termino: null,
    is_subvencionado: 1,
    imagen: null,
    score_nubira: 0,
    video_estado: "sin_video",
    es_paes: 0,
    institucion_maestra: null,
    alumno_id: 1,
    nombre_tutor: null,
    foto_perfil: null,
    banco_archivo: null,
    total_votos: 0,
    rating_promedio: null,
    ...overrides,
  };
}

test("ofertaVigente: true cuando las 4 condiciones se cumplen (sin fecha límite)", () => {
  assert.equal(computeOfertaVigente(filaBase({})), true);
});

test("ofertaVigente: false si is_subvencionado != 1", () => {
  assert.equal(computeOfertaVigente(filaBase({ is_subvencionado: 0 })), false);
});

test("ofertaVigente: false si cupos_oferta <= 0", () => {
  assert.equal(computeOfertaVigente(filaBase({ cupos_oferta: 0 })), false);
});

test("ofertaVigente: false si precio_oferta es null o vacío", () => {
  assert.equal(computeOfertaVigente(filaBase({ precio_oferta: null })), false);
  assert.equal(computeOfertaVigente(filaBase({ precio_oferta: "" })), false);
});

test("ofertaVigente: false si oferta_termino ya pasó (ayer)", () => {
  const ayer = new Date();
  ayer.setDate(ayer.getDate() - 1);
  assert.equal(computeOfertaVigente(filaBase({ oferta_termino: ayer })), false);
});

test("ofertaVigente: true si oferta_termino es hoy", () => {
  const hoy = new Date();
  hoy.setHours(0, 0, 0, 0);
  assert.equal(computeOfertaVigente(filaBase({ oferta_termino: hoy })), true);
});

test("ofertaVigente: true si oferta_termino es mañana", () => {
  const manana = new Date();
  manana.setDate(manana.getDate() + 1);
  assert.equal(computeOfertaVigente(filaBase({ oferta_termino: manana })), true);
});
