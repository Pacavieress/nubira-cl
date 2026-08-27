import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Panel Marketing/Cards — tab Servicios (Pieza 1). 100% lectura: cero mutaciones, cero
// generador de imagen nuevo (reutiliza /api/compartir/servicio/:id/post, ya existente y
// probado). La tab Novedades (Pieza 2) se testea en un archivo aparte una vez portada.

const SESSION_ADMIN = "test-admin-marketing-cards-session";
const SESSION_NO_ADMIN = "test-admin-marketing-cards-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que el resto de tests admin.
let alumnoNoAdminId: number;
let novedadesCreadasEnTest: number[] = [];

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

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Marketing Cards", `test-no-admin-marketing-cards-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_NO_ADMIN, alumnoNoAdminId]);
});

after(async () => {
  if (novedadesCreadasEnTest.length > 0) {
    await pool.query("DELETE FROM novedades WHERE id IN (?)", [novedadesCreadasEnTest]);
  }
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id = ?", [alumnoNoAdminId]);
  await pool.end();
});

test("GET /api/admin/marketing-cards/servicios sin sesión devuelve 401, con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/marketing-cards/servicios`);
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/marketing-cards/servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(resNoAdmin.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/marketing-cards/servicios sin filtros: estructura + solo aprobados/visibles + orden real", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/marketing-cards/servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();

    assert.equal(typeof body.total, "number");
    assert.ok(Array.isArray(body.servicios));
    assert.equal(body.servicios.length, body.total);
    assert.ok(Array.isArray(body.categoriasDisponibles));
    assert.ok(Array.isArray(body.institucionesDisponibles));
    assert.ok(body.total > 0, "se necesita al menos 1 servicio aprobado real en la BD local para este test");

    const s = body.servicios[0];
    assert.equal(typeof s.id, "number");
    assert.equal(typeof s.titulo, "string");
    assert.equal(typeof s.categoria, "string");
    assert.equal(typeof s.conVideo, "boolean");
    assert.equal(typeof s.tutorNombre, "string");

    // Orden real: fecha_publicacion DESC.
    const fechas = body.servicios.map((x: { fechaPublicacion: string }) => new Date(x.fechaPublicacion).getTime());
    const ordenadas = [...fechas].sort((a, b) => b - a);
    assert.deepEqual(fechas, ordenadas);

    // Contra la BD real: ningún servicio devuelto puede estar fuera de estado='aprobado'/visible=1.
    const ids = body.servicios.map((x: { id: number }) => x.id);
    const [filasReales] = await pool.query(
      `SELECT COUNT(*) c FROM servicios WHERE id IN (${ids.map(() => "?").join(",") || "NULL"}) AND (estado != 'aprobado' OR COALESCE(visible,1) != 1)`,
      ids,
    );
    assert.equal((filasReales as { c: number }[])[0].c, 0);
  } finally {
    await close();
  }
});

test("GET /api/admin/marketing-cards/servicios?categoria=X filtra por categoría real", async () => {
  const { url, close } = listen();
  try {
    const resTodos = await fetch(`${url}/api/admin/marketing-cards/servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyTodos = await resTodos.json();
    assert.ok(bodyTodos.categoriasDisponibles.length > 0, "se necesita al menos 1 categoría real en la BD local");
    const categoria = bodyTodos.categoriasDisponibles[0];

    const res = await fetch(`${url}/api/admin/marketing-cards/servicios?categoria=${encodeURIComponent(categoria)}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.servicios.length > 0);
    assert.ok(body.servicios.every((s: { categoria: string }) => s.categoria === categoria));
  } finally {
    await close();
  }
});

test("GET /api/admin/marketing-cards/servicios?conVideo=1 solo trae video_estado='aprobado'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/marketing-cards/servicios?conVideo=1`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.servicios.every((s: { conVideo: boolean }) => s.conVideo === true));
  } finally {
    await close();
  }
});

test("GET /api/admin/marketing-cards/servicios con fecha inválida la ignora (mismo criterio que el regex del PHP real)", async () => {
  const { url, close } = listen();
  try {
    const resInvalida = await fetch(`${url}/api/admin/marketing-cards/servicios?fechaDesde=no-es-una-fecha`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const resSinFiltro = await fetch(`${url}/api/admin/marketing-cards/servicios`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyInvalida = await resInvalida.json();
    const bodySinFiltro = await resSinFiltro.json();
    assert.equal(bodyInvalida.total, bodySinFiltro.total);
  } finally {
    await close();
  }
});

// ============================================================================
// Tab Novedades (Pieza 2)
// ============================================================================

test("GET /api/admin/marketing-cards/novedades gates + estructura", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/marketing-cards/novedades`);
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/marketing-cards/novedades`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(resNoAdmin.status, 403);

    const res = await fetch(`${url}/api/admin/marketing-cards/novedades`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body));
    assert.ok(body.length <= 50, "el listado respeta el LIMIT 50 del PHP real");
    if (body.length > 0) {
      assert.equal(typeof body[0].id, "number");
      assert.equal(typeof body[0].titulo, "string");
      assert.equal(typeof body[0].cuerpo, "string");
      assert.equal(typeof body[0].creadoEn, "string");

      const fechas = body.map((x: { creadoEn: string }) => new Date(x.creadoEn).getTime());
      const ordenadas = [...fechas].sort((a, b) => b - a);
      assert.deepEqual(fechas, ordenadas, "orden real: creado_en DESC");
    }
  } finally {
    await close();
  }
});

test("POST /api/admin/marketing-cards/novedades sin sesión devuelve 401, con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/marketing-cards/novedades`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ titulo: "x", cuerpo: "y" }),
    });
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/marketing-cards/novedades`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` },
      body: JSON.stringify({ titulo: "x", cuerpo: "y" }),
    });
    assert.equal(resNoAdmin.status, 403);
  } finally {
    await close();
  }
});

test("POST /api/admin/marketing-cards/novedades valida título y cuerpo (obligatorios, máximo 120/280)", async () => {
  const { url, close } = listen();
  try {
    const casos = [
      [{ titulo: "", cuerpo: "cuerpo válido" }, "titulo_invalido"],
      [{ titulo: "a".repeat(121), cuerpo: "cuerpo válido" }, "titulo_invalido"],
      [{ titulo: "título válido", cuerpo: "" }, "cuerpo_invalido"],
      [{ titulo: "título válido", cuerpo: "a".repeat(281) }, "cuerpo_invalido"],
    ] as const;

    for (const [payload, errorEsperado] of casos) {
      const res = await fetch(`${url}/api/admin/marketing-cards/novedades`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
        body: JSON.stringify(payload),
      });
      assert.equal(res.status, 400, `caso ${JSON.stringify(payload)}`);
      const body = await res.json();
      assert.equal(body.error, errorEsperado, `caso ${JSON.stringify(payload)}`);
    }
  } finally {
    await close();
  }
});

test("POST /api/admin/marketing-cards/novedades: crea una novedad real, aparece en el historial, y el doble-submit inmediato se rechaza con 409", async () => {
  const { url, close } = listen();
  try {
    const payload = { titulo: `[TEST] Novedad ${Date.now()}`, cuerpo: "Cuerpo de prueba para el test automatizado de adminMarketingCards." };

    const res1 = await fetch(`${url}/api/admin/marketing-cards/novedades`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify(payload),
    });
    assert.equal(res1.status, 201);
    const body1 = (await res1.json()) as { id: number };
    assert.equal(typeof body1.id, "number");
    novedadesCreadasEnTest.push(body1.id);

    const [filas] = await pool.query("SELECT titulo, cuerpo FROM novedades WHERE id = ?", [body1.id]);
    assert.equal((filas as { titulo: string }[])[0]?.titulo, payload.titulo);

    const resHistorial = await fetch(`${url}/api/admin/marketing-cards/novedades`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const historial = await resHistorial.json();
    assert.ok(historial.some((n: { id: number }) => n.id === body1.id));

    // Mismo admin + mismo título + mismo cuerpo inmediatamente después -> doble-submit.
    const res2 = await fetch(`${url}/api/admin/marketing-cards/novedades`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify(payload),
    });
    assert.equal(res2.status, 409);
    assert.equal((await res2.json()).error, "doble_envio");
  } finally {
    await close();
  }
});
