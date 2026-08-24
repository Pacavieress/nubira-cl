import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-login-fallos-session";
const SESSION_NO_ADMIN = "test-admin-login-fallos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
const CORREO_TEST_VIP = `test-vip-${Date.now()}@example.invalid`;
const CORREO_TEST_FALLO = `test-fallo-${Date.now()}@example.invalid`;
let alumnoNoAdminId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Login Fallos", `test-no-admin-login-fallos-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  // Fixture desechable: una fila real en login_fallos para probar limpiar_fallos sin tocar
  // datos reales existentes.
  await pool.query("INSERT INTO login_fallos (correo, ip, fecha) VALUES (?, '127.0.0.1', NOW())", [CORREO_TEST_FALLO]);
});

after(async () => {
  await pool.query("DELETE FROM login_fallos WHERE correo IN (?, ?)", [CORREO_TEST_FALLO, CORREO_TEST_VIP]);
  await pool.query("DELETE FROM excepciones_email WHERE correo = ?", [CORREO_TEST_VIP]);
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

test("GET /api/admin/login-fallos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/login-fallos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/login-fallos (tab fallos, default) trae intentos reales y contadores de las 3 pestañas", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "fallos");
    assert.ok(Array.isArray(body.itemsFallos));
    assert.ok(body.itemsFallos.length > 0, "debe haber intentos reales en la BD local (fixture incluido)");
    assert.ok(body.contadores.fallos >= body.itemsFallos.length);
    assert.equal(typeof body.contadores.vips, "number");
    assert.equal(typeof body.contadores.pendientes, "number");

    const filaFixture = body.itemsFallos.find((f: { correo: string }) => f.correo === CORREO_TEST_FALLO);
    assert.ok(filaFixture, "el fixture insertado debe aparecer en la primera página (ORDER BY fecha DESC)");
    assert.equal(typeof filaFixture.esAlumno, "boolean");
  } finally {
    await close();
  }
});

test("GET /api/admin/login-fallos?tab=vips trae VIPs reales", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos?tab=vips`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "vips");
    assert.ok(Array.isArray(body.itemsVips));
    assert.ok(body.itemsVips.length > 0, "debe haber al menos un VIP activo real en la BD local");
  } finally {
    await close();
  }
});

test("GET /api/admin/login-fallos?tab=pendientes trae usuarios sin confirmar reales", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos?tab=pendientes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "pendientes");
    assert.ok(Array.isArray(body.itemsPendientes));
    assert.ok(body.itemsPendientes.length > 0, "debe haber usuarios sin confirmar reales en la BD local");
  } finally {
    await close();
  }
});

test("POST /api/admin/login-fallos/vips y /vips/revocar: ciclo completo persiste en BD", async () => {
  const { url, close } = listen();
  try {
    const resAutorizar = await fetch(`${url}/api/admin/login-fallos/vips`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ correo: CORREO_TEST_VIP }),
    });
    assert.equal(resAutorizar.status, 200);

    const [rowsActivo] = await pool.query("SELECT activo FROM excepciones_email WHERE correo = ?", [CORREO_TEST_VIP]);
    assert.equal((rowsActivo as unknown as { activo: number }[])[0]?.activo, 1);

    const resRevocar = await fetch(`${url}/api/admin/login-fallos/vips/revocar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ correo: CORREO_TEST_VIP }),
    });
    assert.equal(resRevocar.status, 200);

    const [rowsRevocado] = await pool.query("SELECT activo FROM excepciones_email WHERE correo = ?", [CORREO_TEST_VIP]);
    assert.equal((rowsRevocado as unknown as { activo: number }[])[0]?.activo, 0);
  } finally {
    await close();
  }
});

test("DELETE /api/admin/login-fallos/fallos limpia el historial real de un correo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos/fallos`, {
      method: "DELETE",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ correo: CORREO_TEST_FALLO }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT COUNT(*) as n FROM login_fallos WHERE correo = ?", [CORREO_TEST_FALLO]);
    assert.equal(Number((rows as unknown as { n: number }[])[0]?.n), 0);
  } finally {
    await close();
  }
});

test("POST /api/admin/login-fallos/vips sin correo devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/login-fallos/vips`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({}),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});
