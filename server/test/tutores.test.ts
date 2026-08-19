import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

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

test("GET /api/tutores/:id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/999999999`);
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 404);
    assert.equal(body.error, "not_found");
  } finally {
    await close();
  }
});

test("GET /api/tutores/:id inválido (no numérico) devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/abc`);
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("GET /api/tutores/:id existente devuelve el perfil con su servicio real en la lista", async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const first = rows[0];
  if (!first) {
    // BD local sin servicios aprobados/visibles hoy — nada que comparar.
    return;
  }

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/tutores/${first.alumno_id}`);
    const body = (await res.json()) as {
      id: number;
      rating: { promedio: number | null; votos: number };
      servicios: { id: number }[];
    };
    assert.equal(res.status, 200);
    assert.equal(body.id, first.alumno_id);
    assert.ok(typeof body.rating.votos === "number" && body.rating.votos >= 0);
    assert.ok(body.rating.promedio === null || typeof body.rating.promedio === "number");
    assert.ok(
      body.servicios.some((s) => s.id === first.id),
      "el servicio usado como fixture debería aparecer en la lista de servicios del tutor",
    );
  } finally {
    await close();
  }
});
