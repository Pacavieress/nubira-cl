import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-ofertas-apuntes-session";
const SESSION_NO_ADMIN = "test-admin-ofertas-apuntes-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let apunteFixtureId: number;
let original: { precio: number; promo_gratis: number; promo_limite: number; promo_contador: number };

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Ofertas Apuntes", `test-no-admin-ofertas-apuntes-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  // Fixture de escritura: un apunte APROBADO real cualquiera, snapshoteando sus campos
  // originales para restaurarlos exacto en after() — mismo criterio que adminServicios.test.ts.
  const [rows] = await pool.query(
    "SELECT id, precio, promo_gratis, promo_limite, promo_contador FROM apuntes WHERE estado = 'aprobado' ORDER BY id DESC LIMIT 1",
  );
  const fila = (rows as unknown as { id: number; precio: number; promo_gratis: number; promo_limite: number; promo_contador: number }[])[0];
  if (!fila) throw new Error("Se necesita al menos un apunte aprobado real en la BD local para correr este test.");
  apunteFixtureId = fila.id;
  original = { precio: fila.precio, promo_gratis: fila.promo_gratis, promo_limite: fila.promo_limite, promo_contador: fila.promo_contador };
});

after(async () => {
  await pool.query("UPDATE apuntes SET precio = ?, promo_gratis = ?, promo_limite = ?, promo_contador = ? WHERE id = ?", [
    original.precio,
    original.promo_gratis,
    original.promo_limite,
    original.promo_contador,
    apunteFixtureId,
  ]);
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

test("GET /api/admin/ofertas-apuntes sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas-apuntes`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/ofertas-apuntes con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas-apuntes`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/ofertas-apuntes con sesión admin: trae apuntes aprobados reales", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas-apuntes`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length > 0, "debe haber apuntes aprobados reales en la BD local");
    const primero = body[0];
    assert.equal(typeof primero.titulo, "string");
    assert.equal(typeof primero.precio, "number");
    assert.equal(typeof primero.promoGratis, "boolean");
  } finally {
    await close();
  }
});

test("GET /api/admin/ofertas-apuntes?tutor=zzz_no_existe_zzz: sin resultados", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas-apuntes?tutor=zzz_no_existe_zzz`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.deepEqual(body, []);
  } finally {
    await close();
  }
});

test("PUT /api/admin/ofertas-apuntes/:id/precio: actualiza el precio real y persiste en BD", async () => {
  const { url, close } = listen();
  try {
    const nuevoPrecio = original.precio + 777;
    const res = await fetch(`${url}/api/admin/ofertas-apuntes/${apunteFixtureId}/precio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ precio: nuevoPrecio }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query("SELECT precio FROM apuntes WHERE id = ?", [apunteFixtureId]);
    assert.equal((rows as unknown as { precio: number }[])[0]?.precio, nuevoPrecio);
  } finally {
    await close();
  }
});

test("POST /api/admin/ofertas-apuntes/:id/aplicar-promo y quitar-promo: ciclo completo persiste en BD", async () => {
  const { url, close } = listen();
  try {
    const resAplicar = await fetch(`${url}/api/admin/ofertas-apuntes/${apunteFixtureId}/aplicar-promo`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ cupos: 25 }),
    });
    assert.equal(resAplicar.status, 200);

    const [rowsAplicar] = await pool.query("SELECT promo_gratis, promo_limite, promo_contador FROM apuntes WHERE id = ?", [apunteFixtureId]);
    const filaAplicar = (rowsAplicar as unknown as { promo_gratis: number; promo_limite: number; promo_contador: number }[])[0];
    assert.equal(filaAplicar?.promo_gratis, 1);
    assert.equal(filaAplicar?.promo_limite, 25);
    assert.equal(filaAplicar?.promo_contador, 0);

    const resQuitar = await fetch(`${url}/api/admin/ofertas-apuntes/${apunteFixtureId}/quitar-promo`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(resQuitar.status, 200);

    const [rowsQuitar] = await pool.query("SELECT promo_gratis, promo_limite, promo_contador FROM apuntes WHERE id = ?", [apunteFixtureId]);
    const filaQuitar = (rowsQuitar as unknown as { promo_gratis: number; promo_limite: number; promo_contador: number }[])[0];
    assert.equal(filaQuitar?.promo_gratis, 0);
    assert.equal(filaQuitar?.promo_limite, 0);
    assert.equal(filaQuitar?.promo_contador, 0);
  } finally {
    await close();
  }
});

test("PUT /api/admin/ofertas-apuntes/:id/precio con precio negativo devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas-apuntes/${apunteFixtureId}/precio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ precio: -5 }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/admin/ofertas-apuntes/:id/aplicar-promo inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/ofertas-apuntes/999999999/aplicar-promo`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ cupos: 10 }),
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});
