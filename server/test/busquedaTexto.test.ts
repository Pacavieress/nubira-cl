import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { esBusquedaPaes, raicesBusqueda } from "../src/lib/busquedaTexto.js";

after(async () => {
  await pool.end();
});

function listen(): { url: string; close: () => Promise<void> } {
  const app = createApp();
  const server = app.listen(0);
  const address = server.address();
  if (address === null || typeof address === "string") {
    throw new Error("No se pudo obtener el puerto efímero del servidor de prueba");
  }
  return {
    url: `http://127.0.0.1:${address.port}`,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}

// ---- raicesBusqueda: puerto exacto de busqueda.php:262-301 ----

test("raicesBusqueda: palabra terminada en 'es' con largo>4 recorta 2 -> 'clases' -> 'clas'", () => {
  assert.deepEqual(raicesBusqueda("clases"), ["clas"]);
});

test("raicesBusqueda: 'matemáticas' (termina en 's', no 'es' tras las 2 últimas) -> recorta 1 -> 'matemática'", () => {
  assert.deepEqual(raicesBusqueda("matemáticas"), ["matemática"]);
});

test("raicesBusqueda: 'peces' (largo=5>4, termina en 'es') -> 'pec'", () => {
  assert.deepEqual(raicesBusqueda("peces"), ["pec"]);
});

test("raicesBusqueda: 'paes' (largo=4, NO >4, pero SÍ termina en 's' y largo>3) -> raíz 'pae' (largo 3, no se re-expande)", () => {
  assert.deepEqual(raicesBusqueda("paes"), ["pae"]);
});

test("raicesBusqueda: raíz que quedaría <3 chars vuelve a la palabra original ('mes' -> 'mes', no 'm')", () => {
  // 'mes': largo=3, no >3, así que ni siquiera entra a la rama de recorte de 's' -> queda 'mes' tal cual.
  assert.deepEqual(raicesBusqueda("mes"), ["mes"]);
});

test("raicesBusqueda: multi-palabra devuelve una raíz por palabra, en el mismo orden", () => {
  assert.deepEqual(raicesBusqueda("clases matemáticas"), ["clas", "matemática"]);
});

test("raicesBusqueda: palabras <3 chars se descartan salvo que TODAS lo sean (fallback a las originales)", () => {
  assert.deepEqual(raicesBusqueda("de la clases"), ["clas"]); // "de"/"la" descartadas, queda solo "clases"->"clas"
  assert.deepEqual(raicesBusqueda("de la"), ["de", "la"]); // todas cortas -> fallback: se usan tal cual
});

// ---- esBusquedaPaes: puerto exacto de busqueda.php:252 ----

test("esBusquedaPaes: case-insensitive, coincide en cualquier posición del término", () => {
  assert.equal(esBusquedaPaes("paes"), true);
  assert.equal(esBusquedaPaes("PAES"), true);
  assert.equal(esBusquedaPaes("preparación PAES matemática"), true);
  assert.equal(esBusquedaPaes("matemáticas"), false);
});

// ---- Integración: el refuerzo de PAES encuentra servicios con es_paes=1 aunque el
// título NO contenga "pae" en ningún lado (si lo contuviera, el match tokenizado normal
// ya lo encontraría solo, y el test no distinguiría el refuerzo real) ----

test("GET /api/servicios?q=paes: el refuerzo de PAES encuentra servicios es_paes=1 sin 'pae' en ningún campo buscado", async () => {
  const [rows] = await pool.query(
    `SELECT s.id FROM servicios s
     JOIN alumnos a ON a.id = s.alumno_id
     WHERE s.estado = 'aprobado' AND s.visible = 1 AND a.bloqueado = 0 AND s.es_paes = 1
       AND s.titulo NOT LIKE '%pae%' AND (s.descripcion IS NULL OR s.descripcion NOT LIKE '%pae%')
       AND s.categoria NOT LIKE '%pae%'
       AND (s.materia IS NULL OR s.materia NOT LIKE '%pae%')
       AND (s.asignatura IS NULL OR s.asignatura NOT LIKE '%pae%')
       AND (s.area IS NULL OR s.area NOT LIKE '%pae%')
     LIMIT 1`,
  );
  const fixture = (rows as unknown as Array<{ id: number }>)[0];
  if (!fixture) {
    return; // BD local sin ningún servicio es_paes=1 "limpio" hoy — nada que comparar.
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios?q=paes&limit=50`);
    const body = (await res.json()) as { data: { id: number }[] };
    assert.equal(res.status, 200);
    assert.ok(
      body.data.some((s) => s.id === fixture.id),
      "un servicio es_paes=1 sin 'pae' en ningún campo debería aparecer igual gracias al refuerzo PAES",
    );
  } finally {
    await close();
  }
});

// ---- Integración: multi-palabra es AND, no OR — agregar una segunda palabra nunca
// puede devolver un resultado que la primera palabra sola no devolvía ----

test("GET /api/servicios?q=<2 palabras>: el resultado es subconjunto del de la primera palabra sola (AND, no OR)", async () => {
  const { url, close } = listen();
  try {
    const resUna = await fetch(`${url}/api/servicios?q=${encodeURIComponent("clases")}&limit=50`);
    const bodyUna = (await resUna.json()) as { data: { id: number }[] };
    const idsUna = new Set(bodyUna.data.map((s) => s.id));

    const resDos = await fetch(`${url}/api/servicios?q=${encodeURIComponent("clases zzzznoexiste")}&limit=50`);
    const bodyDos = (await resDos.json()) as { data: { id: number }[] };

    assert.equal(bodyDos.data.length, 0, "una segunda palabra que no matchea nada debe dejar el AND en 0 resultados");
    for (const s of bodyDos.data) {
      assert.ok(idsUna.has(s.id), "todo resultado de 2 palabras debe también matchear la primera palabra sola");
    }
  } finally {
    await close();
  }
});

test("GET /api/apuntes?q=paes: el refuerzo de PAES encuentra apuntes con nivel_academico='paes'", async () => {
  const [rows] = await pool.query(
    `SELECT ap.id FROM apuntes ap
     JOIN alumnos al ON al.id = ap.id_alumno
     WHERE ap.publico = 1 AND ap.visible = 1 AND al.visible = 1 AND al.bloqueado = 0
       AND ap.nivel_academico = 'paes'
     LIMIT 1`,
  );
  const fixture = (rows as unknown as Array<{ id: number }>)[0];
  if (!fixture) {
    return; // BD local sin apuntes nivel_academico='paes' hoy — nada que comparar.
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes?q=paes&limit=50`);
    const body = (await res.json()) as { data: { id: number }[] };
    assert.equal(res.status, 200);
    assert.ok(body.data.some((a) => a.id === fixture.id));
  } finally {
    await close();
  }
});
