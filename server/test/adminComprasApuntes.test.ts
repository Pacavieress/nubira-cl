import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-compras-apuntes-session";
const SESSION_NO_ADMIN = "test-admin-compras-apuntes-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Compras Apuntes", `test-no-admin-compras-apuntes-${Date.now()}@example.invalid`],
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

test("GET /api/admin/compras-apuntes sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/compras-apuntes`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/compras-apuntes con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/compras-apuntes`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/compras-apuntes con sesión admin: KPIs cuadran con la suma de tutores agrupados", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/compras-apuntes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();

    assert.ok(body.tutores.length > 0, "debe haber tutores con ventas reales en la BD local");
    assert.equal(body.kpis.totalTutores, body.tutores.length);

    const sumaVentas = body.tutores.reduce((acc: number, t: { totalVentas: number }) => acc + t.totalVentas, 0);
    assert.equal(sumaVentas, body.kpis.totalCompras);

    const sumaMonto = body.tutores.reduce((acc: number, t: { totalMonto: number }) => acc + t.totalMonto, 0);
    assert.equal(sumaMonto, body.kpis.totalMonto);

    // Cada tutor trae su detalle real, y pagadas+pendientes debe cuadrar con totalVentas.
    const primero = body.tutores[0];
    assert.ok(Array.isArray(primero.detalle));
    assert.equal(primero.detalle.length, primero.totalVentas);
    assert.equal(primero.pagadas + primero.pendientes, primero.totalVentas);
  } finally {
    await close();
  }
});

test("GET /api/admin/compras-apuntes?estado_pago=1: todas las ventas del detalle están pagadas", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/compras-apuntes?estado_pago=1`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.tutores.length > 0, "debe haber al menos una venta pagada real para validar el filtro");
    for (const tutor of body.tutores) {
      for (const venta of tutor.detalle) {
        assert.equal(venta.pagadoAlVendedor, true);
      }
    }
  } finally {
    await close();
  }
});

test("GET /api/admin/compras-apuntes?orden=alfabetico: tutores ordenados alfabéticamente por nombre", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/compras-apuntes?orden=alfabetico`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    const nombres = body.tutores.map((t: { vendedorNombre: string }) => t.vendedorNombre);
    const ordenados = [...nombres].sort((a, b) => a.localeCompare(b));
    assert.deepEqual(nombres, ordenados);
  } finally {
    await close();
  }
});

test("GET /api/admin/compras-apuntes?q_vendedor=zzz_no_existe_zzz: sin resultados", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/compras-apuntes?q_vendedor=zzz_no_existe_zzz`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tutores.length, 0);
    assert.equal(body.kpis.totalCompras, 0);
  } finally {
    await close();
  }
});
