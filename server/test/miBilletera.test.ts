import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_VALID = "test-mi-billetera-session";
// id=1 ("Soporte Nubira") — a diferencia de contratos.vendedor_id (sin FK), tanto
// solicitudes_retiro.usuario_id como datos_pago_usuario.usuario_id sí tienen FK real hacia
// alumnos.id (confirmado en vivo: el INSERT sintético fallaba con ER_NO_REFERENCED_ROW_2).
const VENDEDOR_ID = 1;
let servicioId: number;
let contratoId: number;
let solicitudId: number;
let datosBancariosId: number;

// [26/08/2026] Fixtures propios para solicitar-retiro/datos-bancarios (Grupo B) — 2 alumnos
// 100% desechables (creados y eliminados en este archivo), no se reutiliza VENDEDOR_ID=1
// para estos casos porque los 3 escenarios pedidos (sin datos bancarios / con datos
// completos / con solicitud pendiente) son secuenciales sobre el MISMO saldo, y mezclarlos
// con el saldo ya usado por los tests de arriba habría sido confuso de razonar.
const SESSION_SIN_DATOS = "test-mi-billetera-session-sin-datos";
const SESSION_COMPLETO = "test-mi-billetera-session-completo";
let alumnoSinDatosId: number;
let alumnoCompletoId: number;
let contratoSinDatosId: number;
let contratoCompletoId: number;
let solicitudNuevaId: number | null = null;

before(async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const servicio = rows[0];
  if (!servicio) throw new Error("Se necesita al menos un servicio aprobado en la BD local para correr este test.");
  servicioId = servicio.id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, VENDEDOR_ID],
  );

  // Ganancia real: contrato liberado, monto=50000, sin subsidio/comisión -> neto 50000
  const [insContrato] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, 1, ?, 50000, 'liberado', NOW())",
    [servicioId, VENDEDOR_ID],
  );
  contratoId = (insContrato as { insertId: number }).insertId;

  // Retiro pendiente: descuenta del saldo disponible (mismo criterio que el PHP real)
  const [insSolicitud] = await pool.query(
    "INSERT INTO solicitudes_retiro (usuario_id, tipo, monto, estado, fecha_solicitud) VALUES (?, 'banco', 12000, 'pendiente', NOW())",
    [VENDEDOR_ID],
  );
  solicitudId = (insSolicitud as { insertId: number }).insertId;

  const [insDatos] = await pool.query(
    "INSERT INTO datos_pago_usuario (usuario_id, tipo_cuenta, banco, numero_cuenta, titular_nombre, rut) VALUES (?, 'Cuenta Corriente', 'Banco de Chile', '1234567890', 'Test Vendedor', '11111111-1')",
    [VENDEDOR_ID],
  );
  datosBancariosId = (insDatos as { insertId: number }).insertId;

  // --- Fixtures del Grupo B (solicitar-retiro / datos-bancarios) ---
  const [insAlumnoSinDatos] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Sin Datos Bancarios', ?, 'x', 1, 0, '')",
    [`test-sin-datos-${Date.now()}@example.invalid`],
  );
  alumnoSinDatosId = (insAlumnoSinDatos as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_SIN_DATOS, alumnoSinDatosId],
  );
  const [insContratoSinDatos] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, 1, ?, 20000, 'liberado', NOW())",
    [servicioId, alumnoSinDatosId],
  );
  contratoSinDatosId = (insContratoSinDatos as { insertId: number }).insertId;

  const [insAlumnoCompleto] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Datos Completos', ?, 'x', 1, 0, '')",
    [`test-datos-completos-${Date.now()}@example.invalid`],
  );
  alumnoCompletoId = (insAlumnoCompleto as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_COMPLETO, alumnoCompletoId],
  );
  const [insContratoCompleto] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, 1, ?, 20000, 'liberado', NOW())",
    [servicioId, alumnoCompletoId],
  );
  contratoCompletoId = (insContratoCompleto as { insertId: number }).insertId;
  await pool.query(
    "INSERT INTO datos_pago_usuario (usuario_id, tipo_cuenta, banco, numero_cuenta, titular_nombre, rut) VALUES (?, 'Cuenta Vista', 'BancoEstado', '99887766', 'Test Datos Completos', '12345678-9')",
    [alumnoCompletoId],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [SESSION_VALID, SESSION_SIN_DATOS, SESSION_COMPLETO]);
  await pool.query("DELETE FROM contratos WHERE id = ?", [contratoId]);
  await pool.query("DELETE FROM solicitudes_retiro WHERE id = ?", [solicitudId]);
  await pool.query("DELETE FROM datos_pago_usuario WHERE id = ?", [datosBancariosId]);

  await pool.query("DELETE FROM contratos WHERE id IN (?, ?)", [contratoSinDatosId, contratoCompletoId]);
  if (solicitudNuevaId !== null) await pool.query("DELETE FROM solicitudes_retiro WHERE id = ?", [solicitudNuevaId]);
  await pool.query("DELETE FROM datos_pago_usuario WHERE usuario_id IN (?, ?)", [alumnoSinDatosId, alumnoCompletoId]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?)", [alumnoSinDatosId, alumnoCompletoId]);

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

test("GET /api/me/mi-billetera sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/mi-billetera: saldo = ganancias (50000) - retirado (12000) = 38000, cuenta enmascarada a los últimos 4 dígitos", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      saldoDisponible: number;
      saldoParaMostrar: number;
      gananciasServicios: number;
      totalRetirado: number;
      datosBancarios: { banco: string; numeroCuentaEnmascarado: string } | null;
      historialRetiros: { monto: number; estado: string }[];
    };

    assert.equal(body.gananciasServicios, 50000);
    assert.equal(body.totalRetirado, 12000);
    assert.equal(body.saldoDisponible, 38000);
    assert.equal(body.saldoParaMostrar, 38000);

    assert.ok(body.datosBancarios, "datosBancarios no debe ser null");
    assert.equal(body.datosBancarios!.banco, "Banco de Chile");
    assert.equal(body.datosBancarios!.numeroCuentaEnmascarado, "7890");
    // El número completo (1234567890) NUNCA debe cruzar la red hacia web/.
    assert.ok(!JSON.stringify(body).includes("1234567890"), "el número de cuenta completo no debe aparecer en la respuesta");

    assert.ok(body.historialRetiros.some((h) => h.monto === 12000 && h.estado === "pendiente"));
  } finally {
    await close();
  }
});

test("GET /api/me/mi-billetera: usuario sin datos bancarios ni ganancias devuelve saldo 0 y datosBancarios null", async () => {
  const sessionVacia = "test-mi-billetera-session-vacia";
  const usuarioVacio = 888888898;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionVacia, usuarioVacio],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera`, {
      headers: { Cookie: `PHPSESSID=${sessionVacia}` },
    });
    const body = (await res.json()) as { saldoDisponible: number; saldoParaMostrar: number; datosBancarios: unknown };
    assert.equal(res.status, 200);
    assert.equal(body.saldoDisponible, 0);
    assert.equal(body.saldoParaMostrar, 0);
    assert.equal(body.datosBancarios, null);
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionVacia]);
  }
});

// ============================================================================
// Grupo B (26/08/2026) — PUT /api/me/mi-billetera/datos-bancarios y
// POST /api/me/mi-billetera/solicitar-retiro. Tests secuenciales a propósito (node:test
// corre los tests de un mismo archivo en orden de declaración): cada uno depende del
// estado que dejó el anterior sobre el MISMO fixture, igual que un usuario real
// completando el flujo completo (configurar banco -> pedir retiro -> intentar pedir de
// nuevo mientras el primero sigue pendiente).
// ============================================================================

test("PUT /api/me/mi-billetera/datos-bancarios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, { method: "PUT" });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("PUT /api/me/mi-billetera/datos-bancarios: campos faltantes devuelve 400 campos_obligatorios", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}`, "Content-Type": "application/json" },
      body: JSON.stringify({ banco: "BancoEstado", tipoCuenta: "", numeroCuenta: "123", titularNombre: "X", rut: "12345678-9" }),
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "campos_obligatorios");
  } finally {
    await close();
  }
});

test("PUT /api/me/mi-billetera/datos-bancarios: número de cuenta con letras devuelve 400 cuenta_invalida", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}`, "Content-Type": "application/json" },
      body: JSON.stringify({ banco: "BancoEstado", tipoCuenta: "Cuenta Vista", numeroCuenta: "123ABC", titularNombre: "X", rut: "12345678-9" }),
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "cuenta_invalida");
  } finally {
    await close();
  }
});

test("PUT /api/me/mi-billetera/datos-bancarios: RUT mal formado devuelve 400 rut_invalido", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}`, "Content-Type": "application/json" },
      body: JSON.stringify({ banco: "BancoEstado", tipoCuenta: "Cuenta Vista", numeroCuenta: "123456", titularNombre: "X", rut: "12345678" }),
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "rut_invalido");
  } finally {
    await close();
  }
});

// --- Caso 1: usuario SIN datos bancarios configurados ---

test("POST /api/me/mi-billetera/solicitar-retiro: sin datos bancarios devuelve 400 sin_datos_bancarios (Caso 1)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/solicitar-retiro`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_SIN_DATOS}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "sin_datos_bancarios");
  } finally {
    await close();
  }
});

test("GET /api/me/mi-billetera/datos-bancarios: sin datos configurados trae la lista de bancos y datos=null", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      headers: { Cookie: `PHPSESSID=${SESSION_SIN_DATOS}` },
    });
    const body = (await res.json()) as { bancos: string[]; datos: unknown };
    assert.equal(res.status, 200);
    assert.ok(body.bancos.length > 0, "la lista de bancos real no debe venir vacía");
    assert.equal(body.datos, null);
  } finally {
    await close();
  }
});

test("PUT /api/me/mi-billetera/datos-bancarios: guarda por primera vez (INSERT) — el usuario que no tenía datos ahora sí", async () => {
  const { url, close } = listen();
  try {
    const resPut = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_SIN_DATOS}`, "Content-Type": "application/json" },
      body: JSON.stringify({
        banco: "Banco Santander",
        tipoCuenta: "Cuenta Rut",
        numeroCuenta: "555444333",
        titularNombre: "Test Sin Datos Bancarios",
        rut: "12.345.678-9",
      }),
    });
    assert.equal(resPut.status, 204);

    const resGet = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      headers: { Cookie: `PHPSESSID=${SESSION_SIN_DATOS}` },
    });
    const body = (await resGet.json()) as { datos: { banco: string; numeroCuenta: string; rut: string } | null };
    assert.ok(body.datos, "datos no debe ser null tras guardar");
    assert.equal(body.datos!.banco, "Banco Santander");
    assert.equal(body.datos!.numeroCuenta, "555444333");
    // El RUT se limpia de puntos antes de guardar (editar_datos_bancarios.php:56).
    assert.equal(body.datos!.rut, "12345678-9");
  } finally {
    await close();
  }
});

// --- Caso 2: usuario CON datos bancarios completos, sin solicitud previa ---

test("GET /api/me/mi-billetera/datos-bancarios: con datos ya configurados trae la fila completa sin enmascarar (Caso 2)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}` },
    });
    const body = (await res.json()) as { datos: { banco: string; numeroCuenta: string; tipoCuenta: string; titularNombre: string; rut: string } | null };
    assert.equal(res.status, 200);
    assert.ok(body.datos);
    assert.equal(body.datos!.banco, "BancoEstado");
    assert.equal(body.datos!.numeroCuenta, "99887766");
    assert.equal(body.datos!.rut, "12345678-9");
  } finally {
    await close();
  }
});

test("PUT /api/me/mi-billetera/datos-bancarios: actualiza datos existentes (UPDATE, no INSERT duplicado)", async () => {
  const { url, close } = listen();
  try {
    const resPut = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}`, "Content-Type": "application/json" },
      body: JSON.stringify({
        banco: "Banco de Chile",
        tipoCuenta: "Cuenta Corriente",
        numeroCuenta: "99887766",
        titularNombre: "Test Datos Completos",
        rut: "12345678-9",
      }),
    });
    assert.equal(resPut.status, 204);

    const [rows] = await pool.query("SELECT COUNT(*) AS total FROM datos_pago_usuario WHERE usuario_id = ?", [alumnoCompletoId]);
    assert.equal((rows as { total: number }[])[0].total, 1, "debe seguir habiendo exactamente 1 fila (UPDATE, no un 2do INSERT)");

    const resGet = await fetch(`${url}/api/me/mi-billetera/datos-bancarios`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}` } });
    const body = (await resGet.json()) as { datos: { banco: string } | null };
    assert.equal(body.datos!.banco, "Banco de Chile");
  } finally {
    await close();
  }
});

test("POST /api/me/mi-billetera/solicitar-retiro: con datos bancarios y saldo suficiente crea la solicitud (Caso 2, éxito)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/solicitar-retiro`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}` },
    });
    const body = (await res.json()) as { ok: boolean; monto: number };
    assert.equal(res.status, 200);
    assert.equal(body.ok, true);
    assert.equal(body.monto, 20000, "monto = saldo disponible completo, nunca elegido por el cliente");

    const [rows] = await pool.query(
      "SELECT id, monto, estado FROM solicitudes_retiro WHERE usuario_id = ? ORDER BY id DESC LIMIT 1",
      [alumnoCompletoId],
    );
    const fila = (rows as { id: number; monto: number; estado: string }[])[0];
    assert.ok(fila, "debe existir la solicitud recién creada");
    assert.equal(fila.monto, 20000);
    assert.equal(fila.estado, "pendiente");
    solicitudNuevaId = fila.id;

    const [contratoRows] = await pool.query("SELECT solicitud_retiro_id FROM contratos WHERE id = ?", [contratoCompletoId]);
    assert.equal(
      (contratoRows as { solicitud_retiro_id: number | null }[])[0].solicitud_retiro_id,
      solicitudNuevaId,
      "el contrato liberado debe quedar vinculado a la solicitud recién creada (trazabilidad admin)",
    );
  } finally {
    await close();
  }
});

// --- Caso 3: usuario CON una solicitud ya pendiente (la que acaba de crear el test anterior) ---

test("POST /api/me/mi-billetera/solicitar-retiro: con una solicitud pendiente ya cubriendo todo el saldo devuelve 400 monto_invalido (Caso 3)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mi-billetera/solicitar-retiro`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPLETO}` },
    });
    const body = (await res.json()) as { error: string };
    assert.equal(res.status, 400);
    assert.equal(body.error, "monto_invalido");
  } finally {
    await close();
  }
});
