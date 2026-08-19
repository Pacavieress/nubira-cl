import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const TEST_SESSION_VALID = "test-fase5-session-valida";
const TEST_SESSION_EXPIRED = "test-fase5-session-vencida";
const TEST_USUARIO_ID = 999999999; // ID sintético, no corresponde a ningún usuario real.

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [TEST_SESSION_VALID, TEST_USUARIO_ID],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() - INTERVAL 1 HOUR)",
    [TEST_SESSION_EXPIRED, TEST_USUARIO_ID],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [
    TEST_SESSION_VALID,
    TEST_SESSION_EXPIRED,
  ]);
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

test("GET /api/me sin cookie devuelve 401 (falla cerrado, caso 1)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me`);
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 401);
    assert.equal(body.error, "no_autenticado");
  } finally {
    await close();
  }
});

test("GET /api/me con cookie que no existe en sesiones_api devuelve 401 (falla cerrado, caso 2)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me`, {
      headers: { Cookie: "PHPSESSID=no-existe-esta-sesion" },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 401);
    assert.equal(body.error, "no_autenticado");
  } finally {
    await close();
  }
});

test("GET /api/me con sesión vencida devuelve 401 (falla cerrado, caso 3)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_EXPIRED}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 401);
    assert.equal(body.error, "no_autenticado");
  } finally {
    await close();
  }
});

test("GET /api/me con sesión válida devuelve 200 con el usuarioId correcto (caso 4)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_VALID}` },
    });
    const body = (await res.json()) as { usuarioId: number };
    assert.equal(res.status, 200);
    assert.equal(body.usuarioId, TEST_USUARIO_ID);
  } finally {
    await close();
  }
});
