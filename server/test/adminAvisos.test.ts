import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-avisos-session";
const SESSION_NO_ADMIN = "test-admin-avisos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let campanasCreadasEnTest: number[] = [];

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
  if (campanasCreadasEnTest.length > 0) {
    await pool.query("DELETE FROM avisos_admin WHERE campana_id IN (?)", [campanasCreadasEnTest]);
    await pool.query("DELETE FROM avisos_campanas WHERE id IN (?)", [campanasCreadasEnTest]);
  }
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

// ============================================================================
// POST /api/admin/avisos — crear + enviar campaña
//
// Solo se ejercita el segmento 'usuario' (destinatario único y controlado) en los tests de
// happy path. 'todos'/'tutores'/'no_tutores' NUNCA se disparan acá a propósito — resolverían
// contra TODOS los alumnos reales de la base local e insertarían un avisos_admin por cada
// uno, un efecto real de gran escala que no corresponde a un test automatizado.
// ============================================================================

function nuevaCampanaValida(overrides: Record<string, unknown> = {}) {
  return {
    titulo: `[TEST] Campaña ${Date.now()}`,
    mensaje: "Mensaje de prueba para el test automatizado de adminAvisos.",
    tipo: "info",
    segmento: "usuario",
    usuarioId: alumnoNoAdminId,
    ...overrides,
  };
}

test("POST /api/admin/avisos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/avisos`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(nuevaCampanaValida()),
    });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST /api/admin/avisos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/avisos`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` },
      body: JSON.stringify(nuevaCampanaValida()),
    });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST /api/admin/avisos valida título, mensaje, tipo y segmento", async () => {
  const { url, close } = listen();
  try {
    const casos = [
      [{ titulo: "a" }, "titulo_invalido"],
      [{ mensaje: "hi" }, "mensaje_invalido"],
      [{ tipo: "spam" }, "tipo_invalido"],
      [{ segmento: "marte" }, "segmento_invalido"],
      [{ segmento: "usuario", usuarioId: undefined }, "usuario_requerido"],
      [{ segmento: "usuario", usuarioId: 999999999 }, "usuario_invalido"],
    ] as const;

    for (const [overrides, errorEsperado] of casos) {
      const res = await fetch(`${url}/api/admin/avisos`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
        body: JSON.stringify(nuevaCampanaValida(overrides)),
      });
      assert.equal(res.status, 400, `caso ${JSON.stringify(overrides)}`);
      const body = await res.json();
      assert.equal(body.error, errorEsperado, `caso ${JSON.stringify(overrides)}`);
    }
  } finally {
    await close();
  }
});

test("POST /api/admin/avisos segmento='usuario': crea campaña + 1 aviso real, y el doble-submit inmediato se rechaza con 409", async () => {
  const { url, close } = listen();
  try {
    const payload = nuevaCampanaValida();

    const res1 = await fetch(`${url}/api/admin/avisos`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify(payload),
    });
    assert.equal(res1.status, 201);
    const body1 = (await res1.json()) as { campanaId: number; enviados: number };
    assert.equal(typeof body1.campanaId, "number");
    assert.equal(body1.enviados, 1);
    campanasCreadasEnTest.push(body1.campanaId);

    const [filasCampana] = await pool.query("SELECT titulo, segmento, total_destinatarios FROM avisos_campanas WHERE id = ?", [body1.campanaId]);
    assert.equal((filasCampana as { titulo: string }[])[0]?.titulo, payload.titulo);
    assert.equal((filasCampana as { total_destinatarios: number }[])[0]?.total_destinatarios, 1);

    const [filasAviso] = await pool.query("SELECT destino_id FROM avisos_admin WHERE campana_id = ?", [body1.campanaId]);
    assert.deepEqual((filasAviso as { destino_id: number }[]).map((f) => f.destino_id), [alumnoNoAdminId]);

    // Mismo admin + mismo contenido + mismo segmento inmediatamente después -> doble-submit.
    const res2 = await fetch(`${url}/api/admin/avisos`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify(payload),
    });
    assert.equal(res2.status, 409);
    const body2 = await res2.json();
    assert.equal(body2.error, "doble_envio");
  } finally {
    await close();
  }
});

// ============================================================================
// GET /api/admin/avisos/buscar-usuarios
// ============================================================================

test("GET /api/admin/avisos/buscar-usuarios gates + comportamiento", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/avisos/buscar-usuarios?q=ab`);
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/avisos/buscar-usuarios?q=ab`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(resNoAdmin.status, 403);

    const resCorto = await fetch(`${url}/api/admin/avisos/buscar-usuarios?q=a`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(resCorto.status, 200);
    assert.deepEqual(await resCorto.json(), [], "menos de 2 caracteres devuelve vacío, mismo criterio que admin_buscar_usuarios.php");

    const resReal = await fetch(`${url}/api/admin/avisos/buscar-usuarios?q=Test No Admin Avisos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(resReal.status, 200);
    const usuarios = await resReal.json();
    assert.ok(Array.isArray(usuarios));
    assert.ok(usuarios.some((u: { id: number }) => u.id === alumnoNoAdminId));
  } finally {
    await close();
  }
});
