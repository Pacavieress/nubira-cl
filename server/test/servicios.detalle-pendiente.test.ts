import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Puerto de detalle_servicio.php:115-121: el dueño (o un admin) puede ver su propio
// servicio no aprobado (banner "En Revisión"/"Publicación Pausada"); cualquier otro
// visitante recibe 404 — bug real encontrado y corregido al portar esta página: la query
// que ya existía (getServicioDetalleById, WHERE_VISIBLE) excluía SIEMPRE estado!='aprobado'
// a nivel SQL, sin excepción para el dueño. Este archivo cubre ese caso + contratoId +
// recomendaciones, que también se agregaron en la misma pasada.
const SESSION_DUENO = "test-detalle-pendiente-dueno";
const SESSION_OTRO = "test-detalle-pendiente-otro";
const SESSION_ADMIN = "test-detalle-pendiente-admin";
const ADMIN_ID = 1; // "Soporte Nubira"

let duenoId: number;
let otroId: number;
let servicioPendienteId: number;
let servicioAprobadoId: number;
let compradorId: number;
let contratoId: number;

before(async () => {
  const [insDueno] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Dueno Pendiente",
    `test-dueno-pendiente-${Date.now()}@example.invalid`,
  ]);
  duenoId = (insDueno as { insertId: number }).insertId;

  const [insOtro] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Otro Pendiente",
    `test-otro-pendiente-${Date.now()}@example.invalid`,
  ]);
  otroId = (insOtro as { insertId: number }).insertId;

  const [insComprador] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')", [
    "Test Comprador Contrato",
    `test-comprador-contrato-${Date.now()}@example.invalid`,
  ]);
  compradorId = (insComprador as { insertId: number }).insertId;

  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_DUENO, duenoId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_OTRO, otroId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_ADMIN, ADMIN_ID]);

  const [insServPendiente] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, categoria, precio, estado, visible) VALUES (?, 'Test servicio pendiente detalle', 'Otros', 5000, 'pendiente', 1)",
    [duenoId],
  );
  servicioPendienteId = (insServPendiente as { insertId: number }).insertId;

  const [insServAprobado] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, categoria, precio, estado, visible) VALUES (?, 'Test servicio aprobado detalle', 'Otros', 5000, 'aprobado', 1)",
    [duenoId],
  );
  servicioAprobadoId = (insServAprobado as { insertId: number }).insertId;

  const [insContrato] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado) VALUES (?, ?, ?, 5000, 'en_progreso')",
    [servicioAprobadoId, compradorId, duenoId],
  );
  contratoId = (insContrato as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM contratos WHERE id = ?", [contratoId]);
  await pool.query("DELETE FROM servicios WHERE id IN (?, ?)", [servicioPendienteId, servicioAprobadoId]);
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [SESSION_DUENO, SESSION_OTRO, SESSION_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [duenoId, otroId, compradorId]);
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

test("GET /api/servicios/:id (pendiente) sin sesión devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioPendienteId}`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id (pendiente) con sesión de OTRO usuario devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioPendienteId}`, { headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` } });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id (pendiente) con sesión del DUEÑO devuelve 200 y estado='pendiente'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioPendienteId}`, { headers: { Cookie: `PHPSESSID=${SESSION_DUENO}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "pendiente");
    assert.equal(body.viewer.isOwner, true);
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id (pendiente) con sesión de ADMIN devuelve 200", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioPendienteId}`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.estado, "pendiente");
    assert.equal(body.viewer.isOwner, false);
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id: viewer.contratoId refleja un contrato en_progreso real", async () => {
  const { url, close } = listen();
  try {
    const resComprador = await fetch(`${url}/api/servicios/${servicioAprobadoId}`, { headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` } });
    assert.equal(resComprador.status, 200);
    const bodyOtro = await resComprador.json();
    assert.equal(bodyOtro.viewer.contratoId, null, "un tercero sin contrato debe ver null");

    const resDueno = await fetch(`${url}/api/servicios/${servicioAprobadoId}`, { headers: { Cookie: `PHPSESSID=${SESSION_DUENO}` } });
    const bodyDueno = await resDueno.json();
    assert.equal(bodyDueno.viewer.contratoId, contratoId, "el vendedor del contrato debe ver su id");
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id trae recomendaciones (array) sin incluirse a sí mismo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioAprobadoId}`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(Array.isArray(body.recomendaciones));
    assert.ok(!body.recomendaciones.some((r: { id: number }) => r.id === servicioAprobadoId));
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id: video es null cuando no hay video_path", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/servicios/${servicioAprobadoId}`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.video, null);
  } finally {
    await close();
  }
});

test("GET /api/servicios/:id: tutorEnClase=true si el vendedor tiene CUALQUIER contrato en_progreso (no ligado a este servicio)", async () => {
  const { url, close } = listen();
  try {
    // servicioAprobadoId y servicioPendienteId son ambos del mismo dueño (duenoId), que
    // tiene un contrato en_progreso en servicioAprobadoId — tutorEnClase debe ser true en
    // AMBOS, porque el chequeo es por vendedor_id, no por servicio_id (detalle_servicio.php:328).
    const res1 = await fetch(`${url}/api/servicios/${servicioAprobadoId}`);
    const body1 = await res1.json();
    assert.equal(body1.tutorEnClase, true);

    const res2 = await fetch(`${url}/api/servicios/${servicioPendienteId}`, { headers: { Cookie: `PHPSESSID=${SESSION_DUENO}` } });
    const body2 = await res2.json();
    assert.equal(body2.tutorEnClase, true, "mismo vendedor, otro servicio suyo — debe seguir siendo true");
  } finally {
    await close();
  }
});
