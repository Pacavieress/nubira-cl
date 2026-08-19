import { test, after } from "node:test";
import assert from "node:assert/strict";
import { pool } from "../src/db/pool.js";
import { getServicioById, searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

after(async () => {
  await pool.end();
});

test("searchServiciosAprobados devuelve filas bien tipadas respetando el limit", async () => {
  const { rows, hayMas } = await searchServiciosAprobados({ page: 1, limit: 5 });
  assert.ok(Array.isArray(rows));
  assert.ok(rows.length <= 5);
  assert.equal(typeof hayMas, "boolean");

  for (const row of rows) {
    assert.equal(typeof row.id, "number");
    assert.equal(typeof row.titulo, "string");
    assert.equal(typeof row.total_votos, "number");
  }
});

test("searchServiciosAprobados: el mismo filtro devuelve el mismo orden en llamadas repetidas (sin RAND)", async () => {
  const a = await searchServiciosAprobados({ page: 1, limit: 10 });
  const b = await searchServiciosAprobados({ page: 1, limit: 10 });
  assert.deepEqual(
    a.rows.map((r) => r.id),
    b.rows.map((r) => r.id),
  );
});

test("getServicioById devuelve null para un ID inexistente", async () => {
  const row = await getServicioById(999999999);
  assert.equal(row, null);
});

test("getServicioById devuelve la misma fila que aparece en el listado", async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const firstRow = rows[0];
  if (!firstRow) {
    // BD local sin servicios aprobados/visibles hoy — nada que comparar.
    return;
  }

  const row = await getServicioById(firstRow.id);
  assert.ok(row !== null);
  assert.equal(row?.id, firstRow.id);
  assert.equal(row?.titulo, firstRow.titulo);
});
