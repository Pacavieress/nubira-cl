import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_ADMIN = "test-admin-contratos-session";
const SESSION_NO_ADMIN = "test-admin-contratos-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que los otros tests de admin.
let alumnoNoAdminId: number;
let contratoEnProgresoId: number;
let contratoParaCancelarId: number;
let contratoParaRevertirId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_ADMIN, ADMIN_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, ?, 'x', 1, 0, 'alumno')",
    ["Test No Admin Contratos", `test-no-admin-contratos-${Date.now()}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_NO_ADMIN, alumnoNoAdminId],
  );

  // Contratos sintéticos para probar liberar/cancelar/revertir — servicio_id/comprador_id/
  // vendedor_id sin FK real (confirmado vía information_schema), y el repositorio los lee
  // con LEFT JOIN + COALESCE, así que IDs inexistentes son seguros acá.
  const insertarContrato = async (estado: string) => {
    const [ins] = await pool.query(
      "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (999999999, 999999999, 999999999, 15000, ?, NOW())",
      [estado],
    );
    return (ins as { insertId: number }).insertId;
  };
  contratoEnProgresoId = await insertarContrato("en_progreso");
  contratoParaCancelarId = await insertarContrato("en_progreso");
  contratoParaRevertirId = await insertarContrato("cancelado");
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM alumnos WHERE id = ?", [alumnoNoAdminId]);
  await pool.query("DELETE FROM contratos WHERE id IN (?, ?, ?)", [contratoEnProgresoId, contratoParaCancelarId, contratoParaRevertirId]);
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

test("GET /api/admin/contratos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/contratos con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/contratos con sesión admin: stats reales cuadran con el total y con los contratos sin filtro", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();

    const sumaStats = Object.values(body.stats as Record<string, number>).reduce((a: number, b) => a + (b as number), 0);
    assert.equal(sumaStats, body.total);
    assert.equal(body.contratos.length, body.total, "sin filtro, el listado debe traer TODOS los contratos");
    assert.ok(body.contratos.length > 0, "debe haber contratos reales en la BD local");

    const primero = body.contratos[0];
    assert.equal(typeof primero.monto, "number");
    assert.equal(typeof primero.servicioTitulo, "string");
  } finally {
    await close();
  }
});

test("GET /api/admin/contratos?estado=cancelado: filtra correctamente", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos?estado=cancelado`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.ok(body.contratos.length > 0, "debe haber al menos un contrato cancelado real para validar el filtro");
    assert.ok(body.contratos.every((c: { estado: string }) => c.estado === "cancelado"));
    assert.equal(body.contratos.length, body.stats.cancelado);
  } finally {
    await close();
  }
});

test("GET /api/admin/contratos?estado=no-es-un-estado-valido: ignora el filtro inválido (mismo criterio que in_array estricto del PHP)", async () => {
  const { url, close } = listen();
  try {
    const resFiltrado = await fetch(`${url}/api/admin/contratos?estado=no-es-un-estado-valido`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const filtrado = await resFiltrado.json();
    const resTodos = await fetch(`${url}/api/admin/contratos`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const todos = await resTodos.json();
    assert.equal(filtrado.contratos.length, todos.contratos.length);
  } finally {
    await close();
  }
});

test("POST /api/admin/contratos/:id/liberar sin sesión admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos/${contratoEnProgresoId}/liberar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` },
    });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST /api/admin/contratos/:id/liberar: contrato en_progreso pasa a liberado con fecha_cierre", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos/${contratoEnProgresoId}/liberar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT estado, fecha_cierre FROM contratos WHERE id = ?", [contratoEnProgresoId]);
    const c = (rows as { estado: string; fecha_cierre: Date | null }[])[0]!;
    assert.equal(c.estado, "liberado");
    assert.ok(c.fecha_cierre !== null);
  } finally {
    await close();
  }
});

test("POST /api/admin/contratos/:id/liberar: repetir sobre un contrato ya liberado devuelve ok:false (0 filas)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos/${contratoEnProgresoId}/liberar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false, "ya no está en_progreso -> el WHERE no matchea -> 0 filas, no error");
  } finally {
    await close();
  }
});

test("POST /api/admin/contratos/:id/cancelar: contrato en_progreso pasa a cancelado con fecha_cierre", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos/${contratoParaCancelarId}/cancelar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT estado, fecha_cierre FROM contratos WHERE id = ?", [contratoParaCancelarId]);
    const c = (rows as { estado: string; fecha_cierre: Date | null }[])[0]!;
    assert.equal(c.estado, "cancelado");
    assert.ok(c.fecha_cierre !== null);
  } finally {
    await close();
  }
});

test("POST /api/admin/contratos/:id/revertir: vuelve a en_progreso y limpia fecha_cierre, sin importar el estado de origen", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos/${contratoParaRevertirId}/revertir`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);

    const [rows] = await pool.query("SELECT estado, fecha_cierre FROM contratos WHERE id = ?", [contratoParaRevertirId]);
    const c = (rows as { estado: string; fecha_cierre: Date | null }[])[0]!;
    assert.equal(c.estado, "en_progreso");
    assert.equal(c.fecha_cierre, null);
  } finally {
    await close();
  }
});

test("POST /api/admin/contratos/:id/revertir: contrato inexistente devuelve ok:false", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/contratos/999999999/revertir`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    const body = (await res.json()) as { ok: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.ok, false);
  } finally {
    await close();
  }
});
