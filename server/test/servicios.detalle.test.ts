import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_OWNER = "test-fase6-owner";
const SESSION_OTRO = "test-fase6-otro";
const SESSION_VENCIDA = "test-fase6-vencida";
const USUARIO_OTRO = 888888888; // sintético, no es dueño de ningún servicio real

let servicioId: number;
let alumnoIdDueno: number;

before(async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const first = rows[0];
  if (!first) {
    throw new Error(
      "Se necesita al menos un servicio aprobado/visible en la BD local para correr este test.",
    );
  }
  servicioId = first.id;
  alumnoIdDueno = first.alumno_id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_OWNER, alumnoIdDueno],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_OTRO, USUARIO_OTRO],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() - INTERVAL 1 HOUR)",
    [SESSION_VENCIDA, USUARIO_OTRO],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [
    SESSION_OWNER,
    SESSION_OTRO,
    SESSION_VENCIDA,
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

test("GET /api/servicios/:id sin cookie devuelve 200 con viewer anónimo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioId}`);
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: false, isOwner: false, esFavorito: false });
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id con cookie del dueño real devuelve isOwner=true", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_OWNER}` },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: true, isOwner: true, esFavorito: false });
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id con cookie de OTRO usuario devuelve isAuthenticated=true pero isOwner=false", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: true, isOwner: false, esFavorito: false });
  } finally {
    await close();
  }
});

test("optionalAuth NUNCA bloquea: cookie vencida devuelve 200 (no 401), viewer anónimo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VENCIDA}` },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: false, isOwner: false, esFavorito: false });
  } finally {
    await close();
  }
});

test("optionalAuth NUNCA bloquea: cookie que no existe en sesiones_api devuelve 200 (no 401), viewer anónimo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioId}`, {
      headers: { Cookie: "PHPSESSID=esto-no-existe-en-sesiones-api" },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean; esFavorito: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: false, isOwner: false, esFavorito: false });
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id: esFavorito refleja favoritos_servicios real, no un valor fijo", async () => {
  const { url, close } = listen();
  try {
    await pool.query(
      "INSERT INTO favoritos_servicios (usuario_id, servicio_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE usuario_id = usuario_id",
      [USUARIO_OTRO, servicioId],
    );

    const res = await fetch(`${url}/api/servicios/${servicioId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` },
    });
    const body = (await res.json()) as { viewer: { esFavorito: boolean } };
    assert.equal(res.status, 200);
    assert.equal(body.viewer.esFavorito, true);
  } finally {
    await pool.query("DELETE FROM favoritos_servicios WHERE usuario_id = ? AND servicio_id = ?", [
      USUARIO_OTRO,
      servicioId,
    ]);
    await close();
  }
});

test("la respuesta de detalle NO expone whatsapp ni correo crudos (Decisión A)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioId}`);
    const body = (await res.json()) as Record<string, unknown>;
    assert.equal("whatsapp" in body, false);
    assert.equal("correo" in body, false);
  } finally {
    await close();
  }
});
