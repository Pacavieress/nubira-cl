import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

after(async () => {
  // Sin esto, el pool de mysql2 deja una conexión/socket abierto y el proceso
  // de "node --test" nunca sale del event loop (se cuelga aunque el test ya pasó).
  await pool.end();
});

test("GET /health responde 200 con status ok", async () => {
  const app = createApp();
  const server = app.listen(0);
  const address = server.address();
  if (address === null || typeof address === "string") {
    throw new Error("No se pudo obtener el puerto efímero del servidor de prueba");
  }

  try {
    const res = await fetch(`http://127.0.0.1:${address.port}/health`);
    const body = (await res.json()) as { status: string; db: string };

    assert.equal(res.status, 200);
    assert.equal(body.status, "ok");
    assert.ok(body.db === "ok" || body.db === "error");
  } finally {
    server.close();
  }
});
