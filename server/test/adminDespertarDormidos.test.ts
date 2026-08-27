import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Panel "Despertar Dormidos" — autorizado por el usuario con alcance completo (envío real de
// correo a usuarios inactivos), mismo criterio que adminRetiros.test.ts: el test de happy
// path de envío SÍ dispara un correo real a soporte@nubira.cl (inbox real de Nubira,
// contenido claramente marcado como prueba). El resto de los tests (guards, validación,
// 401/403, cupón inválido) nunca llegan a enviarCorreo().

const SESSION_ADMIN = "test-admin-despertar-dormidos-session";
const SESSION_NO_ADMIN = "test-admin-despertar-dormidos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que el resto de tests admin.

let alumnoNoAdminId: number;
let dormidoId: number; // Elegible: correo real soporte@nubira.cl, usado en el happy path de envío.
let dormidoDadoDeBajaId: number; // Elegible salvo por estar en `unsubscribed`.
let cuponGlobalId: number;
let cuponConServicioId: number;
let servicioIdParaCupon: number;
let maxCorreosAdminIdAntes: number;

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

  const ts = Date.now();
  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Despertar Dormidos", `test-no-admin-despertar-dormidos-${ts}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_NO_ADMIN, alumnoNoAdminId]);

  // Correo REAL (soporte@nubira.cl) a propósito — destinatario del envío real de prueba.
  const [insDormido] = await pool.query(
    `INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, confirmado, recibir_emails, rol, fecha_registro)
     VALUES (?, 'soporte@nubira.cl', 'x', 1, 0, 1, 1, 'alumno', DATE_SUB(NOW(), INTERVAL 40 DAY))`,
    [`[TEST adminDespertarDormidos] Dormido ${ts}`],
  );
  dormidoId = (insDormido as { insertId: number }).insertId;

  const [insBaja] = await pool.query(
    `INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, confirmado, recibir_emails, rol, fecha_registro)
     VALUES (?, ?, 'x', 1, 0, 1, 1, 'alumno', DATE_SUB(NOW(), INTERVAL 40 DAY))`,
    [`[TEST adminDespertarDormidos] DadoDeBaja ${ts}`, `test-dado-de-baja-${ts}@example.invalid`],
  );
  dormidoDadoDeBajaId = (insBaja as { insertId: number }).insertId;
  const [filasBaja] = await pool.query("SELECT correo FROM alumnos WHERE id = ?", [dormidoDadoDeBajaId]);
  const correoBaja = (filasBaja as { correo: string }[])[0].correo;
  await pool.query("INSERT INTO unsubscribed (correo) VALUES (?)", [correoBaja]);

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Test DespertarDormidos', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 10000, NOW())",
    [ADMIN_ID],
  );
  servicioIdParaCupon = (insServicio as { insertId: number }).insertId;

  const [insCuponGlobal] = await pool.query(
    "INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, servicio_id, usos_actuales, creado_en) VALUES (?, 15, 100, NULL, 0, NOW())",
    [`TESTDORMIDOS${ts}`],
  );
  cuponGlobalId = (insCuponGlobal as { insertId: number }).insertId;

  const [insCuponServicio] = await pool.query(
    "INSERT INTO cupones (codigo, porcentaje_descuento, usos_maximos, servicio_id, usos_actuales, creado_en) VALUES (?, 20, 100, ?, 0, NOW())",
    [`TESTDORMIDOSSERV${ts}`, servicioIdParaCupon],
  );
  cuponConServicioId = (insCuponServicio as { insertId: number }).insertId;

  const [filasMax] = await pool.query("SELECT COALESCE(MAX(id), 0) AS maxId FROM correos_admin");
  maxCorreosAdminIdAntes = (filasMax as { maxId: number }[])[0].maxId;
});

after(async () => {
  // Solo borra los correos_admin insertados por ESTE archivo de test (id > el máximo visto
  // antes de correr) — la tabla es real y comparte admin_nombre con envíos reales pasados.
  await pool.query("DELETE FROM correos_admin WHERE id > ? AND admin_nombre = 'despertar_dormidos_jun2026'", [maxCorreosAdminIdAntes]);
  await pool.query("DELETE FROM cupones WHERE id IN (?, ?)", [cuponGlobalId, cuponConServicioId]);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioIdParaCupon]);
  await pool.query("DELETE FROM unsubscribed WHERE correo IN (SELECT correo FROM alumnos WHERE id = ?)", [dormidoDadoDeBajaId]);
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [alumnoNoAdminId, dormidoId, dormidoDadoDeBajaId]);
  await pool.end();
});

test("GET /api/admin/despertar-dormidos sin sesión devuelve 401, con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/despertar-dormidos`);
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/despertar-dormidos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(resNoAdmin.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/despertar-dormidos: incluye al dormido elegible, EXCLUYE al que está en `unsubscribed` (corrección deliberada vs. el PHP real)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/despertar-dormidos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { usuarios: { alumnoId: number; estado: string }[]; stats: { total: number } };

    assert.ok(body.usuarios.some((u) => u.alumnoId === dormidoId), "el dormido elegible debe aparecer");
    assert.ok(!body.usuarios.some((u) => u.alumnoId === dormidoDadoDeBajaId), "el dado de baja NUNCA debe aparecer, aunque cumpla el resto de los criterios");

    const fila = body.usuarios.find((u) => u.alumnoId === dormidoId);
    assert.equal(fila?.estado, "pendiente");
    assert.equal(typeof body.stats.total, "number");
  } finally {
    await close();
  }
});

test("GET /api/admin/despertar-dormidos/cupon: código inexistente, código restringido a servicio, y código global válido", async () => {
  const { url, close } = listen();
  try {
    const resVacio = await fetch(`${url}/api/admin/despertar-dormidos/cupon?codigo=`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal((await resVacio.json()).ok, false);

    const resInexistente = await fetch(`${url}/api/admin/despertar-dormidos/cupon?codigo=NOEXISTE999`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyInexistente = await resInexistente.json();
    assert.equal(bodyInexistente.ok, false);

    const [[{ codigo: codigoServicio }]] = (await pool.query("SELECT codigo FROM cupones WHERE id = ?", [cuponConServicioId])) as [{ codigo: string }[], unknown];
    const resServicio = await fetch(`${url}/api/admin/despertar-dormidos/cupon?codigo=${codigoServicio}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    const bodyServicio = await resServicio.json();
    assert.equal(bodyServicio.ok, false, "un cupón restringido a un servicio no debe usarse en una campaña masiva global");

    const [[{ codigo: codigoGlobal }]] = (await pool.query("SELECT codigo FROM cupones WHERE id = ?", [cuponGlobalId])) as [{ codigo: string }[], unknown];
    const resGlobal = await fetch(`${url}/api/admin/despertar-dormidos/cupon?codigo=${codigoGlobal}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const bodyGlobal = await resGlobal.json();
    assert.equal(bodyGlobal.ok, true);
    assert.equal(bodyGlobal.porcentaje, 15);
  } finally {
    await close();
  }
});

test("POST /api/admin/despertar-dormidos/enviar: gates + validación (sin ids, tope de destinatarios)", async () => {
  const { url, close } = listen();
  try {
    const resNoSesion = await fetch(`${url}/api/admin/despertar-dormidos/enviar`, { method: "POST" });
    assert.equal(resNoSesion.status, 401);

    const resNoAdmin = await fetch(`${url}/api/admin/despertar-dormidos/enviar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` },
      body: JSON.stringify({ alumnoIds: [dormidoId] }),
    });
    assert.equal(resNoAdmin.status, 403);

    const resSinIds = await fetch(`${url}/api/admin/despertar-dormidos/enviar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ alumnoIds: [] }),
    });
    assert.equal(resSinIds.status, 400);
    assert.equal((await resSinIds.json()).error, "sin_destinatarios");

    const idsExcesivos = Array.from({ length: 151 }, (_, i) => i + 1);
    const resTope = await fetch(`${url}/api/admin/despertar-dormidos/enviar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify({ alumnoIds: idsExcesivos }),
    });
    assert.equal(resTope.status, 400);
    assert.equal((await resTope.json()).error, "demasiados_destinatarios");
  } finally {
    await close();
  }
});

test("POST /api/admin/despertar-dormidos/enviar: happy path envía correo real, omite al dado de baja, registra en correos_admin, y el doble-submit inmediato se rechaza con 409", async () => {
  const { url, close } = listen();
  try {
    const payload = { alumnoIds: [dormidoId, dormidoDadoDeBajaId] };

    const res1 = await fetch(`${url}/api/admin/despertar-dormidos/enviar`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_ADMIN}` },
      body: JSON.stringify(payload),
    });
    assert.equal(res1.status, 200);
    const body1 = (await res1.json()) as { enviados: number; fallidos: number; omitidos: number };
    assert.equal(body1.enviados, 1, "solo el dormido elegible recibe el correo");
    assert.equal(body1.omitidos, 1, "el dado de baja se omite por estar en `unsubscribed`, ver adminDespertarDormidos.repository.ts::usuariosElegiblesParaEnvio");

    const [filasLog] = await pool.query("SELECT destinatario, exito FROM correos_admin WHERE admin_nombre = 'despertar_dormidos_jun2026' AND id > ? ORDER BY id DESC LIMIT 1", [
      maxCorreosAdminIdAntes,
    ]);
    const log = (filasLog as { destinatario: string; exito: number }[])[0];
    assert.equal(log?.destinatario, "soporte@nubira.cl");
    assert.equal(log?.exito, 1);

    // Doble-submit: mismo admin + mismos ids + mismo cupón (ninguno) inmediatamente después.
    const res2 = await fetch(`${url}/api/admin/despertar-dormidos/enviar`, {
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
