import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-recordatorios-session";
const SESSION_NO_ADMIN = "test-admin-recordatorios-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Recordatorios", `test-no-admin-recordatorios-${Date.now()}@example.invalid`],
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

test("GET /api/admin/recordatorios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/recordatorios`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/recordatorios con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/recordatorios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/recordatorios con sesión admin: trae registros reales con tipo ya traducido a label", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/recordatorios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(typeof body.enviadosHoy, "number");
    assert.equal(typeof body.pendientesHoy, "number");
    assert.ok(Array.isArray(body.registros));
    assert.ok(body.registros.length > 0, "debe haber registros reales en acciones_pendientes");
    const primero = body.registros[0];
    assert.equal(typeof primero.id, "number");
    assert.ok(!primero.tipo.startsWith("recordatorio_"), "el tipo debe venir ya traducido a label, no el valor crudo");
  } finally {
    await close();
  }
});

test("GET /api/admin/recordatorios?estado=enviado: filtra correctamente (todos los registros devueltos tienen ese estado)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/recordatorios?estado=enviado`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.registros.length > 0, "debe haber al menos un registro enviado real para validar el filtro");
    assert.ok(body.registros.every((r: { estado: string }) => r.estado === "enviado"));
  } finally {
    await close();
  }
});

test("GET /api/admin/recordatorios?tipo=recordatorio_3dias: filtra por tipo crudo (valor del <select>, no el label)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/recordatorios?tipo=recordatorio_3dias`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    if (body.registros.length > 0) {
      assert.ok(body.registros.every((r: { tipo: string }) => r.tipo === "3 días – Publicar"));
    }
  } finally {
    await close();
  }
});
