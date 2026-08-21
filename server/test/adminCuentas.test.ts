import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-cuentas-session";
const SESSION_NO_ADMIN = "test-admin-cuentas-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Cuentas", `test-no-admin-cuentas-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id = ?", [alumnoNoAdminId]);
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

test("GET /api/admin/cuentas sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cuentas`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/cuentas con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cuentas`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/cuentas con sesión admin: trae cuentas reales, sin usuarios bloqueados/invisibles por defecto", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/cuentas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length > 0, "debe haber cuentas bancarias reales en la BD local");
    assert.ok(body.every((c: { bloqueado: boolean; visible: boolean }) => c.bloqueado === false && c.visible === true));
    const primero = body[0];
    assert.equal(typeof primero.numeroCuenta, "string");
    assert.equal(typeof primero.rut, "string");
    assert.equal(typeof primero.banco, "string");
  } finally {
    await close();
  }
});

test("GET /api/admin/cuentas?mostrarTodos=1: puede incluir más filas que la vista filtrada", async () => {
  const { url, close } = listen();
  try {
    const resFiltrado = await fetch(`${url}/api/admin/cuentas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const filtrado = await resFiltrado.json();

    const resTodos = await fetch(`${url}/api/admin/cuentas?mostrarTodos=1`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(resTodos.status, 200);
    const todos = await resTodos.json();

    assert.ok(todos.length >= filtrado.length, "mostrarTodos=1 nunca debe traer MENOS filas que la vista filtrada");
  } finally {
    await close();
  }
});
