import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-accesos-session";
const SESSION_NO_ADMIN = "test-admin-accesos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let alumnoRegistradoId: number;
const eventoIds: number[] = [];
const IP_FIXTURE = "203.0.113.77";
const IP_BOT_FIXTURE = "203.0.113.78";

before(async () => {
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insAlumno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test No Admin Accesos",
    `test-no-admin-accesos-${Date.now()}@example.invalid`,
  ]);
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [
    SESSION_NO_ADMIN,
    alumnoNoAdminId,
  ]);

  const [insRegistrado] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Usuario Trafico",
    `test-usuario-trafico-${Date.now()}@example.invalid`,
  ]);
  alumnoRegistradoId = (insRegistrado as { insertId: number }).insertId;

  // Evento de un usuario registrado (dentro de los últimos 14 días).
  const [ev1] = await pool.query(
    "INSERT INTO historial_actividad (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent, fecha) VALUES (?, 'VITRINA', 'detalle test', '/explorar', ?, 0, 'Mozilla/5.0 Test', NOW())",
    [alumnoRegistradoId, IP_FIXTURE],
  );
  eventoIds.push((ev1 as { insertId: number }).insertId);

  // Evento de un invitado (usuario_id NULL) por la misma IP fixture.
  const [ev2] = await pool.query(
    "INSERT INTO historial_actividad (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent, fecha) VALUES (NULL, 'GUEST_VIEW', 'detalle invitado', '/apuntes', ?, 0, 'Mozilla/5.0 Guest', NOW())",
    [IP_FIXTURE],
  );
  eventoIds.push((ev2 as { insertId: number }).insertId);

  // Evento de bot reciente (no debe purgarse — tiene menos de 30 días).
  const [ev3] = await pool.query(
    "INSERT INTO historial_actividad (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent, fecha) VALUES (NULL, 'BOT_HIT', NULL, '/robots.txt', ?, 1, 'TestBot/1.0', NOW())",
    [IP_BOT_FIXTURE],
  );
  eventoIds.push((ev3 as { insertId: number }).insertId);

  // Evento de bot ANTIGUO (más de 30 días) — candidato real a "purgar_bots".
  const [ev4] = await pool.query(
    "INSERT INTO historial_actividad (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent, fecha) VALUES (NULL, 'BOT_HIT', NULL, '/old.txt', ?, 1, 'TestBot/1.0', DATE_SUB(NOW(), INTERVAL 40 DAY))",
    [IP_BOT_FIXTURE],
  );
  eventoIds.push((ev4 as { insertId: number }).insertId);

  // Búsqueda fallida de prueba.
  await pool.query("INSERT INTO busquedas_fallidas (termino, usuario_id, fecha) VALUES ('termino de prueba unico xyz', 0, NOW())");
});

after(async () => {
  await pool.query("DELETE FROM historial_actividad WHERE id IN (?, ?, ?, ?)", eventoIds);
  await pool.query("DELETE FROM busquedas_fallidas WHERE termino = 'termino de prueba unico xyz'");
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?)", [alumnoNoAdminId, alumnoRegistradoId]);
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

test("GET /api/admin/accesos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos (tab trafico) trae al usuario registrado con contadores", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "trafico");
    assert.ok(body.trafico);
    const fila = body.trafico.usuarios.find((u: { usuarioId: number }) => u.usuarioId === alumnoRegistradoId);
    assert.ok(fila, "debe incluir al usuario registrado del fixture");
    assert.equal(fila.nombre, "Test Usuario Trafico");
    assert.ok(body.trafico.contadores.alumnos >= 1);
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos?tab=bots trae el bot reciente con stats", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos?tab=bots`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "bots");
    const fila = body.bots.bots.find((b: { ipUsuario: string }) => b.ipUsuario === IP_BOT_FIXTURE);
    assert.ok(fila, "debe incluir el bot reciente");
    assert.ok(body.bots.stats.totalEventos >= 1);
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos?tab=paginas trae la URL visitada", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos?tab=paginas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "paginas");
    assert.ok(body.paginas.paginas.some((p: { url: string }) => p.url === "/explorar" || p.url === "/apuntes"));
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos?tab=fallidas trae la lista con la estructura esperada", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos?tab=fallidas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.tab, "fallidas");
    assert.ok(Array.isArray(body.fallidas.busquedas));
    // LIMIT 50 por total_intentos DESC: con 131 términos distintos en la BD local, un
    // fixture de 1 solo intento no entra al top 50 — se verifica estructura, no membresía.
    if (body.fallidas.busquedas.length > 0) {
      const b = body.fallidas.busquedas[0];
      assert.equal(typeof b.termino, "string");
      assert.equal(typeof b.totalIntentos, "number");
      assert.equal(typeof b.ultimaBusqueda, "string");
    }
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos/detalle?uid=X trae el detalle del usuario registrado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos/detalle?uid=${alumnoRegistradoId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.usuario.usuarioId, alumnoRegistradoId);
    assert.equal(body.usuario.esGuest, false);
    assert.equal(body.usuario.nombre, "Test Usuario Trafico");
    assert.ok(body.eventos.some((e: { accion: string }) => e.accion === "VITRINA"));
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos/detalle?uid=0&ip=X trae el detalle del invitado con nombre hasheado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos/detalle?uid=0&ip=${encodeURIComponent(IP_FIXTURE)}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.usuario.esGuest, true);
    assert.match(body.usuario.nombre, /^Invitado [0-9A-F]{5}$/);
    assert.equal(body.usuario.correo, `Huella: ${IP_FIXTURE}`);
    assert.ok(body.eventos.some((e: { accion: string }) => e.accion === "GUEST_VIEW"));
  } finally {
    await close();
  }
});

test("POST /api/admin/accesos/purgar-bots borra solo el bot de más de 30 días", async () => {
  const { url, close } = listen();
  try {
    const [antesViejo] = await pool.query("SELECT id FROM historial_actividad WHERE id = ?", [eventoIds[3]]);
    assert.equal((antesViejo as unknown[]).length, 1);

    const res = await fetch(`${url}/api/admin/accesos/purgar-bots`, { method: "POST", headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);

    const [despuesViejo] = await pool.query("SELECT id FROM historial_actividad WHERE id = ?", [eventoIds[3]]);
    assert.equal((despuesViejo as unknown[]).length, 0, "el bot de 40 días debe haberse borrado");

    const [despuesReciente] = await pool.query("SELECT id FROM historial_actividad WHERE id = ?", [eventoIds[2]]);
    assert.equal((despuesReciente as unknown[]).length, 1, "el bot reciente NO debe haberse borrado");

    // Re-insertar el evento viejo para no romper el after() (que espera los 4 ids).
    const [reins] = await pool.query(
      "INSERT INTO historial_actividad (usuario_id, accion, detalle, url, ip_usuario, es_bot, user_agent, fecha) VALUES (NULL, 'BOT_HIT', NULL, '/old.txt', ?, 1, 'TestBot/1.0', DATE_SUB(NOW(), INTERVAL 40 DAY))",
      [IP_BOT_FIXTURE],
    );
    eventoIds[3] = (reins as { insertId: number }).insertId;
  } finally {
    await close();
  }
});

test("POST /api/admin/accesos/eliminar borra los eventos seleccionados", async () => {
  const { url, close } = listen();
  try {
    const [ins] = await pool.query(
      "INSERT INTO historial_actividad (usuario_id, accion, detalle, url, ip_usuario, es_bot, fecha) VALUES (NULL, 'TEST_DELETE', NULL, '/x', '203.0.113.99', 0, NOW())",
    );
    const idBorrar = (ins as { insertId: number }).insertId;

    const res = await fetch(`${url}/api/admin/accesos/eliminar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ ids: [idBorrar] }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.afectados, 1);

    const [rows] = await pool.query("SELECT id FROM historial_actividad WHERE id = ?", [idBorrar]);
    assert.equal((rows as unknown[]).length, 0);
  } finally {
    await close();
  }
});

test("GET /api/admin/accesos/exportar devuelve CSV con encabezado esperado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/accesos/exportar?uid=${alumnoRegistradoId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    assert.match(res.headers.get("content-type") ?? "", /text\/csv/);
    const texto = await res.text();
    assert.ok(texto.startsWith("ID,Usuario ID,Nombre,Accion,Detalle,URL,IP,Es Bot,User Agent,Fecha"));
    assert.ok(texto.includes("VITRINA"));
  } finally {
    await close();
  }
});
