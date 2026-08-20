import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import express from "express";
import cookieParser from "cookie-parser";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { requireAdmin } from "../src/modules/auth/auth.middleware.js";

const TEST_SESSION_VALID = "test-fase5-session-valida";
const TEST_SESSION_EXPIRED = "test-fase5-session-vencida";
const TEST_USUARIO_ID = 999999999; // ID sintético, no corresponde a ningún usuario real.

// IDs reales (no sintéticos) para probar requireAdmin/rol-esAdmin contra alumnos.rol de
// verdad — a diferencia de TEST_USUARIO_ID arriba, que sirve para probar el camino
// sesiones_api pero deliberadamente NO tiene fila en alumnos (por eso getUsuarioConRol le
// devuelve null, no un rol). Confirmados por query directa antes de escribir este test:
// id=1 "Soporte Nubira" es el único rol='admin' real de la BD; id=59 es un alumno común
// (rol='alumno', visible=1, bloqueado=0).
const ADMIN_USUARIO_ID = 1;
const ALUMNO_USUARIO_ID = 59;
const TEST_SESSION_ADMIN = "test-fase6-session-admin";
const TEST_SESSION_ALUMNO = "test-fase6-session-alumno";

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [TEST_SESSION_VALID, TEST_USUARIO_ID],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() - INTERVAL 1 HOUR)",
    [TEST_SESSION_EXPIRED, TEST_USUARIO_ID],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [TEST_SESSION_ADMIN, ADMIN_USUARIO_ID],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [TEST_SESSION_ALUMNO, ALUMNO_USUARIO_ID],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?, ?)", [
    TEST_SESSION_VALID,
    TEST_SESSION_EXPIRED,
    TEST_SESSION_ADMIN,
    TEST_SESSION_ALUMNO,
  ]);
  await pool.end();
});

// requireAdmin todavía no está montado en ningún router real (el panel /admin de web/ es
// el siguiente paso, no este). Se prueba acá contra una app Express mínima construida
// solo para el test, en vez de esperar a que exista una ruta real que lo use.
function listenConRequireAdmin(): { url: string; close: () => Promise<void> } {
  const app = express();
  app.use(cookieParser());
  app.get("/test/admin-only", requireAdmin, (req, res) => {
    res.status(200).json({ usuarioId: req.usuarioId });
  });
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
    const body = (await res.json()) as { usuarioId: number; rol: string | null; esAdmin: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.usuarioId, TEST_USUARIO_ID);
    // TEST_USUARIO_ID es sintético: no tiene fila en alumnos, así que getUsuarioConRol le
    // devuelve null -> rol null, esAdmin false. No es un default "asumido", es el reflejo
    // correcto de "no encontré a este usuario".
    assert.equal(body.rol, null);
    assert.equal(body.esAdmin, false);
  } finally {
    await close();
  }
});

test("GET /api/me con sesión de un admin real devuelve rol='admin' y esAdmin=true (caso 5)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { usuarioId: number; rol: string | null; esAdmin: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.usuarioId, ADMIN_USUARIO_ID);
    assert.equal(body.rol, "admin");
    assert.equal(body.esAdmin, true);
  } finally {
    await close();
  }
});

test("GET /api/me con sesión de un alumno común devuelve rol='alumno' y esAdmin=false (caso 6)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_ALUMNO}` },
    });
    const body = (await res.json()) as { usuarioId: number; rol: string | null; esAdmin: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.usuarioId, ALUMNO_USUARIO_ID);
    assert.equal(body.rol, "alumno");
    assert.equal(body.esAdmin, false);
  } finally {
    await close();
  }
});

test("requireAdmin sin cookie devuelve 401 (falla cerrado, caso 1)", async () => {
  const { url, close } = listenConRequireAdmin();
  try {
    const res = await fetch(`${url}/test/admin-only`);
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 401);
    assert.equal(body.error, "no_autenticado");
  } finally {
    await close();
  }
});

test("requireAdmin con cookie que no existe en sesiones_api devuelve 401 (falla cerrado, caso 2)", async () => {
  const { url, close } = listenConRequireAdmin();
  try {
    const res = await fetch(`${url}/test/admin-only`, {
      headers: { Cookie: "PHPSESSID=no-existe-esta-sesion" },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 401);
    assert.equal(body.error, "no_autenticado");
  } finally {
    await close();
  }
});

test("requireAdmin con sesión válida pero usuario sin rol admin devuelve 403 (caso 3)", async () => {
  const { url, close } = listenConRequireAdmin();
  try {
    const res = await fetch(`${url}/test/admin-only`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_ALUMNO}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 403);
    assert.equal(body.error, "no_autorizado");
  } finally {
    await close();
  }
});

test("requireAdmin con sesión válida y sin fila en alumnos (usuario sintético) devuelve 403 (caso 4)", async () => {
  const { url, close } = listenConRequireAdmin();
  try {
    const res = await fetch(`${url}/test/admin-only`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_VALID}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 403);
    assert.equal(body.error, "no_autorizado");
  } finally {
    await close();
  }
});

test("requireAdmin con sesión de un admin real deja pasar y expone req.usuarioId (caso 5)", async () => {
  const { url, close } = listenConRequireAdmin();
  try {
    const res = await fetch(`${url}/test/admin-only`, {
      headers: { Cookie: `PHPSESSID=${TEST_SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { usuarioId: number };
    assert.equal(res.status, 200);
    assert.equal(body.usuarioId, ADMIN_USUARIO_ID);
  } finally {
    await close();
  }
});
