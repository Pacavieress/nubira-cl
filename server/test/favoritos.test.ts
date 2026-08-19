import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import type { RowDataPacket } from "mysql2";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_VALID = "test-fase7-session";
const USUARIO_ID = 777777777; // sintético, no corresponde a ningún usuario real

let servicioId: number;

before(async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const first = rows[0];
  if (!first) {
    throw new Error(
      "Se necesita al menos un servicio aprobado/visible en la BD local para correr este test.",
    );
  }
  servicioId = first.id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM favoritos_servicios WHERE usuario_id = ?", [USUARIO_ID]);
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

async function contarFilas(servicio: number): Promise<number> {
  const [rows] = await pool.query<RowDataPacket[]>(
    "SELECT COUNT(*) AS total FROM favoritos_servicios WHERE usuario_id = ? AND servicio_id = ?",
    [USUARIO_ID, servicio],
  );
  return Number((rows[0] as { total: number }).total);
}

test("GET /api/me/favoritos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/favoritos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("PUT /api/me/favoritos/:id sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/favoritos/${servicioId}`, { method: "PUT" });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("PUT /api/me/favoritos/:id sobre un servicio inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/favoritos/999999999`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("PUT marca el favorito (204) y aparece en GET /api/me/favoritos", async () => {
  const { url, close } = listen();
  try {
    const putRes = await fetch(`${url}/api/me/favoritos/${servicioId}`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(putRes.status, 204);

    const getRes = await fetch(`${url}/api/me/favoritos`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    const body = (await getRes.json()) as { data: { id: number }[] };
    assert.equal(getRes.status, 200);
    assert.ok(body.data.some((s) => s.id === servicioId));
  } finally {
    await close();
  }
});

test("DELETE sobre un favorito que NO existe devuelve 204, no 404 (idempotente por diseño)", async () => {
  const { url, close } = listen();
  try {
    await pool.query("DELETE FROM favoritos_servicios WHERE usuario_id = ? AND servicio_id = ?", [
      USUARIO_ID,
      servicioId,
    ]);
    const res = await fetch(`${url}/api/me/favoritos/${servicioId}`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 204);
  } finally {
    await close();
  }
});

test("CONCURRENCIA — 5 PUT en paralelo para el mismo (usuario, servicio): todos 204, exactamente 1 fila al final", async () => {
  const { url, close } = listen();
  try {
    await pool.query("DELETE FROM favoritos_servicios WHERE usuario_id = ? AND servicio_id = ?", [
      USUARIO_ID,
      servicioId,
    ]);

    const respuestas = await Promise.all(
      Array.from({ length: 5 }, () =>
        fetch(`${url}/api/me/favoritos/${servicioId}`, {
          method: "PUT",
          headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
        }),
      ),
    );

    for (const res of respuestas) {
      assert.equal(res.status, 204);
    }

    const total = await contarFilas(servicioId);
    assert.equal(total, 1, `esperaba exactamente 1 fila tras 5 PUT paralelos, hay ${total}`);
  } finally {
    await close();
  }
});

test("CONCURRENCIA — 5 DELETE en paralelo para el mismo (usuario, servicio): todos 204, 0 filas al final", async () => {
  const { url, close } = listen();
  try {
    await pool.query(
      "INSERT INTO favoritos_servicios (usuario_id, servicio_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE usuario_id = usuario_id",
      [USUARIO_ID, servicioId],
    );

    const respuestas = await Promise.all(
      Array.from({ length: 5 }, () =>
        fetch(`${url}/api/me/favoritos/${servicioId}`, {
          method: "DELETE",
          headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
        }),
      ),
    );

    for (const res of respuestas) {
      assert.equal(res.status, 204);
    }

    const total = await contarFilas(servicioId);
    assert.equal(total, 0, `esperaba 0 filas tras 5 DELETE paralelos, hay ${total}`);
  } finally {
    await close();
  }
});
