import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-reportes-servicios-session";
const SESSION_NO_ADMIN = "test-admin-reportes-servicios-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let usuarioReportadoFixtureId: number;
let bloqueadoOriginal: number;
let reporteFixtureId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Reportes Servicios", `test-no-admin-reportes-servicios-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  // Hallazgo real (no introducido por este test): las 4 filas reales de reportes_servicio
  // referencian servicios ya eliminados (43, 44, 70) — el INNER JOIN del PHP real (y de
  // este puerto, que lo replica fielmente) las excluye siempre, así que admin_reportes_
  // servicios.php hoy muestra "Sin reportes" en cualquier tab pese a tener 4 filas reales
  // en la tabla. Fuera de alcance de esta pieza (es un gap de datos preexistente, no un bug
  // de esta migración) — documentado, no arreglado. Por eso el fixture de este test usa un
  // reporte DESECHABLE contra un servicio real que sí existe, para poder validar el query.
  const [servicioReal] = await pool.query("SELECT id, alumno_id FROM servicios ORDER BY id DESC LIMIT 1");
  const s = (servicioReal as unknown as { id: number; alumno_id: number }[])[0];
  if (!s) throw new Error("Se necesita al menos un servicio real en la BD local para correr este test.");

  const [alumnoRow] = await pool.query("SELECT bloqueado FROM alumnos WHERE id = ?", [s.alumno_id]);
  usuarioReportadoFixtureId = s.alumno_id;
  bloqueadoOriginal = (alumnoRow as unknown as { bloqueado: number }[])[0]?.bloqueado ?? 0;

  const [insReporte] = await pool.query(
    "INSERT INTO reportes_servicio (servicio_id, usuario_id, motivo, mensaje, revisado) VALUES (?, ?, 'Test', 'Reporte de prueba', 1)",
    [s.id, ADMIN_ID],
  );
  reporteFixtureId = (insReporte as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM reportes_servicio WHERE id = ?", [reporteFixtureId]);
  await pool.query("UPDATE alumnos SET bloqueado = ? WHERE id = ?", [bloqueadoOriginal, usuarioReportadoFixtureId]);
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

test("GET /api/admin/reportes-servicios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reportes-servicios`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/reportes-servicios con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reportes-servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/reportes-servicios?estado=todos trae reportes reales con estructura completa", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reportes-servicios?estado=todos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "todos");
    assert.ok(Array.isArray(body.reportes));
    assert.ok(body.reportes.length > 0, "debe haber reportes reales en la BD local");
    const primero = body.reportes[0];
    assert.equal(typeof primero.motivo, "string");
    assert.equal(typeof primero.usuarioReporta.nombre, "string");
    assert.equal(typeof primero.usuarioReportado.bloqueado, "boolean");
    assert.equal(typeof body.countPendientes, "number");
  } finally {
    await close();
  }
});

test("GET /api/admin/reportes-servicios?estado=revisados: todos los reportes devueltos están revisados", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reportes-servicios?estado=revisados`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.reportes.length > 0, "debe haber al menos un reporte revisado real para validar el filtro");
    for (const r of body.reportes) assert.equal(r.revisado, true);
  } finally {
    await close();
  }
});

test("GET /api/admin/reportes-servicios (default, pendientes): ninguno debe estar revisado (0 resultados — hallazgo: todo lo real hoy está orphaned o revisado)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reportes-servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "pendientes");
    for (const r of body.reportes) assert.equal(r.revisado, false);
  } finally {
    await close();
  }
});

test("PUT /api/admin/reportes-servicios/usuarios/:id/bloqueo: bloquea y luego desbloquea un usuario real", async () => {
  const { url, close } = listen();
  try {
    const resBloquear = await fetch(`${url}/api/admin/reportes-servicios/usuarios/${usuarioReportadoFixtureId}/bloqueo`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ bloqueado: true }),
    });
    assert.equal(resBloquear.status, 200);

    const [rowsBloq] = await pool.query("SELECT bloqueado FROM alumnos WHERE id = ?", [usuarioReportadoFixtureId]);
    assert.equal((rowsBloq as unknown as { bloqueado: number }[])[0]?.bloqueado, 1);

    const resDesbloquear = await fetch(`${url}/api/admin/reportes-servicios/usuarios/${usuarioReportadoFixtureId}/bloqueo`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ bloqueado: false }),
    });
    assert.equal(resDesbloquear.status, 200);

    const [rowsDesbloq] = await pool.query("SELECT bloqueado FROM alumnos WHERE id = ?", [usuarioReportadoFixtureId]);
    assert.equal((rowsDesbloq as unknown as { bloqueado: number }[])[0]?.bloqueado, 0);
  } finally {
    await close();
  }
});

test("PUT /api/admin/reportes-servicios/usuarios/:id/bloqueo inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/reportes-servicios/usuarios/999999999/bloqueo`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ bloqueado: true }),
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});
