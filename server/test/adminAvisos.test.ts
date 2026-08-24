import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-avisos-session";
const SESSION_NO_ADMIN = "test-admin-avisos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Avisos", `test-no-admin-avisos-${Date.now()}@example.invalid`],
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

test("GET /api/admin/avisos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/avisos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/avisos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/avisos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/avisos con sesión admin: estructura y consistencia con datos reales", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/avisos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();

    assert.equal(typeof body.totalCampanas, "number");
    assert.equal(typeof body.totalDestinatarios, "number");
    assert.ok(Array.isArray(body.campanas));
    assert.ok(body.campanas.length <= 50, "el listado respeta el LIMIT 50 del PHP real");
    assert.ok(body.campanas.length <= body.totalCampanas);

    if (body.campanas.length > 0) {
      const c = body.campanas[0];
      assert.equal(typeof c.id, "number");
      assert.equal(typeof c.titulo, "string");
      assert.equal(typeof c.mensaje, "string");
      assert.ok(["info", "novedad", "importante"].includes(c.tipo));
      assert.ok(["todos", "tutores", "no_tutores", "usuario"].includes(c.segmento));
      assert.equal(typeof c.totalDestinatarios, "number");
      assert.equal(typeof c.leidos, "number");
      assert.ok(c.leidos <= c.totalDestinatarios, "no puede haber más lectores que destinatarios");
      assert.ok(Array.isArray(c.imagenes));

      // Orden real: fecha_creacion DESC.
      const fechas = body.campanas.map((x: { fechaCreacion: string }) => new Date(x.fechaCreacion).getTime());
      const ordenadas = [...fechas].sort((a, b) => b - a);
      assert.deepEqual(fechas, ordenadas);
    }
  } finally {
    await close();
  }
});

test("GET /api/admin/avisos/:id/lectores gates + estructura", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/avisos/1/lectores`);
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/avisos/1/lectores`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(resNoAdmin.status, 403);

    const resInvalido = await fetch(`${url}/api/admin/avisos/abc/lectores`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(resInvalido.status, 400);

    // Buscamos una campaña real con al menos un lector para validar el detalle contra datos reales.
    const resAvisos = await fetch(`${url}/api/admin/avisos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const avisosBody = await resAvisos.json();
    const conLectores = avisosBody.campanas.find((c: { leidos: number }) => c.leidos > 0);

    if (conLectores) {
      const res = await fetch(`${url}/api/admin/avisos/${conLectores.id}/lectores`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
      assert.equal(res.status, 200);
      const lectores = await res.json();
      assert.ok(Array.isArray(lectores));
      assert.ok(lectores.length > 0);
      assert.equal(typeof lectores[0].nombre, "string");
      assert.equal(typeof lectores[0].fechaLeido, "string");
    } else {
      const res = await fetch(`${url}/api/admin/avisos/999999999/lectores`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
      assert.equal(res.status, 200);
      const lectores = await res.json();
      assert.deepEqual(lectores, []);
    }
  } finally {
    await close();
  }
});
