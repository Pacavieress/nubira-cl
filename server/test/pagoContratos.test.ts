import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { parsearContratoId } from "../src/lib/mercadoPago.js";
import { procesarResultadoPago } from "../src/modules/pagoContratos/pagoContratos.repository.js";
import type { PagoVerificado } from "../src/lib/mercadoPago.js";

// Puerto del Checkpoint 2 "Pago" (26/08/2026) — iniciar_pago_servicio.php +
// iniciar_pago_contrato.php (unificados) + notificaciones_mp.php (webhook) +
// pago_exitoso_contrato.php + pago_error_contrato.php + pago_pendiente_contrato.php
// (unificados en /pago/retorno). Los tests que necesitarían llamar a la API REAL de
// MercadoPago (crear una preferencia real, o verificar un payment_id real aprobado) se
// dejan fuera a propósito — MP_ACCESS_TOKEN es el token real de producción (live_mode=true,
// confirmado contra la API), y correr eso en cada `npm test` crearía objetos reales en la
// cuenta de Mercado Pago de Nubira. Esa verificación se hizo en vivo, una sola vez, de forma
// manual (ver reporte al usuario) — acá se prueba todo lo que SÍ es seguro y determinístico:
// el parseo de contrato_id (con el bug real del prefijo "CONTRATO_" que rompe al webhook
// real) y el motor de mutación procesarResultadoPago con datos sintéticos, incluida la
// carrera real concurrente.

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

const SESSION_COMPRADOR = "test-pago-contratos-session-comprador";
const SESSION_OTRO = "test-pago-contratos-session-otro";
let compradorId: number;
let otroId: number;
let vendedorId: number;
let servicioId: number; // sin oferta
let servicioOfertaId: number; // con oferta, cupos_oferta=3

let contratoPendienteId: number;
let contratoEnProgresoId: number;
let contratoCanceladoId: number;
let contratoConOfertaId: number;
let contratoParaCarreraId: number;

before(async () => {
  const ts = Date.now();
  const [insVend] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Vendedor Pago', ?, 'x', 1, 0, '')", [
    `test-vend-pago-${ts}@example.invalid`,
  ]);
  vendedorId = (insVend as { insertId: number }).insertId;

  const [insComp] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Comprador Pago', ?, 'x', 1, 0, '')", [
    `test-comp-pago-${ts}@example.invalid`,
  ]);
  compradorId = (insComp as { insertId: number }).insertId;

  const [insOtro] = await pool.query("INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES ('Test Otro Pago', ?, 'x', 1, 0, '')", [
    `test-otro-pago-${ts}@example.invalid`,
  ]);
  otroId = (insOtro as { insertId: number }).insertId;

  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_COMPRADOR, compradorId]);
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_OTRO, otroId]);

  const [insServ] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Test Pago', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 15000, NOW())",
    [vendedorId],
  );
  servicioId = (insServ as { insertId: number }).insertId;

  const [insServOferta] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, precio_oferta, cupos_oferta, is_subvencionado, fecha_publicacion) VALUES (?, 'Servicio Test Pago Oferta', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 20000, 12000, 3, 1, NOW())",
    [vendedorId],
  );
  servicioOfertaId = (insServOferta as { insertId: number }).insertId;

  const crearContrato = async (servicio: number, estado: string, monto = 15000) => {
    const [ins] = await pool.query(
      "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, ?, ?, NOW())",
      [servicio, compradorId, vendedorId, monto, estado],
    );
    return (ins as { insertId: number }).insertId;
  };

  contratoPendienteId = await crearContrato(servicioId, "pendiente_pago");
  contratoEnProgresoId = await crearContrato(servicioId, "en_progreso");
  contratoCanceladoId = await crearContrato(servicioId, "cancelado");
  contratoConOfertaId = await crearContrato(servicioOfertaId, "pendiente_pago", 12000);
  contratoParaCarreraId = await crearContrato(servicioOfertaId, "pendiente_pago", 12000);
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_COMPRADOR, SESSION_OTRO]);
  await pool.query("DELETE FROM contrato_eventos WHERE contrato_id IN (?, ?, ?, ?, ?)", [
    contratoPendienteId,
    contratoEnProgresoId,
    contratoCanceladoId,
    contratoConOfertaId,
    contratoParaCarreraId,
  ]);
  await pool.query("DELETE FROM mp_eventos_log WHERE contrato_id IN (?, ?, ?, ?, ?)", [
    contratoPendienteId,
    contratoEnProgresoId,
    contratoCanceladoId,
    contratoConOfertaId,
    contratoParaCarreraId,
  ]);
  await pool.query("DELETE FROM contratos WHERE id IN (?, ?, ?, ?, ?)", [
    contratoPendienteId,
    contratoEnProgresoId,
    contratoCanceladoId,
    contratoConOfertaId,
    contratoParaCarreraId,
  ]);
  await pool.query("DELETE FROM servicios WHERE id IN (?, ?)", [servicioId, servicioOfertaId]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?, ?)", [vendedorId, compradorId, otroId]);
  await pool.end();
});

function pago(overrides: Partial<PagoVerificado>): PagoVerificado {
  return { paymentId: "TEST-" + Math.random().toString(36).slice(2), status: "approved", statusDetail: "accredited", contratoId: null, monto: 15000, ...overrides };
}

// ============================================================================
// parsearContratoId — el corazón del Hallazgo A (bug real del prefijo CONTRATO_)
// ============================================================================

test("parsearContratoId: metadata.contrato_id válido tiene prioridad sobre external_reference", () => {
  const id = parsearContratoId({ metadata: { contrato_id: 42 }, external_reference: "999" });
  assert.equal(id, 42);
});

test("parsearContratoId: external_reference numérico crudo ('123') se parsea bien — camino de iniciar_pago_contrato.php", () => {
  const id = parsearContratoId({ metadata: {}, external_reference: "123" });
  assert.equal(id, 123);
});

test("parsearContratoId: external_reference con prefijo 'CONTRATO_123' — el bug real de (int) en PHP, acá SÍ se resuelve", () => {
  const id = parsearContratoId({ metadata: {}, external_reference: "CONTRATO_123" });
  assert.equal(id, 123, "en PHP real, (int)'CONTRATO_123' da 0 — este es exactamente el bug que se corrige acá");
});

test("parsearContratoId: metadata.contrato_id inválido (0) cae al external_reference", () => {
  const id = parsearContratoId({ metadata: { contrato_id: 0 }, external_reference: "77" });
  assert.equal(id, 77);
});

test("parsearContratoId: sin metadata ni external_reference válido devuelve null", () => {
  assert.equal(parsearContratoId({ metadata: null, external_reference: "" }), null);
  assert.equal(parsearContratoId({ metadata: undefined, external_reference: undefined }), null);
});

// ============================================================================
// procesarResultadoPago — el motor de mutación compartido por webhook y retorno
// ============================================================================

test("procesarResultadoPago: contratoId null devuelve contrato_no_identificado", async () => {
  const r = await procesarResultadoPago(pago({ contratoId: null }));
  assert.equal(r.ok, false);
  if (!r.ok) assert.equal(r.error, "contrato_no_identificado");
});

test("procesarResultadoPago: contrato inexistente devuelve contrato_no_encontrado", async () => {
  const r = await procesarResultadoPago(pago({ contratoId: 999999999 }));
  assert.equal(r.ok, false);
  if (!r.ok) assert.equal(r.error, "contrato_no_encontrado");
});

test("procesarResultadoPago: approved sobre contrato pendiente_pago (sin oferta) lo pasa a en_progreso", async () => {
  const r = await procesarResultadoPago(pago({ contratoId: contratoPendienteId, status: "approved" }));
  assert.equal(r.ok, true);
  if (r.ok) assert.equal(r.accion, "aprobado");

  const [rows] = await pool.query("SELECT estado, fecha_pago FROM contratos WHERE id = ?", [contratoPendienteId]);
  const c = (rows as { estado: string; fecha_pago: Date | null }[])[0]!;
  assert.equal(c.estado, "en_progreso");
  assert.ok(c.fecha_pago !== null);
});

test("procesarResultadoPago: approved sobre contrato CON oferta descuenta 1 cupo", async () => {
  const [antes] = await pool.query("SELECT cupos_oferta FROM servicios WHERE id = ?", [servicioOfertaId]);
  const cuposAntes = (antes as { cupos_oferta: number }[])[0]!.cupos_oferta;

  const r = await procesarResultadoPago(pago({ contratoId: contratoConOfertaId, status: "approved", monto: 12000 }));
  assert.equal(r.ok, true);

  const [despues] = await pool.query("SELECT cupos_oferta FROM servicios WHERE id = ?", [servicioOfertaId]);
  const cuposDespues = (despues as { cupos_oferta: number }[])[0]!.cupos_oferta;
  assert.equal(cuposDespues, cuposAntes - 1);
});

test("procesarResultadoPago: approved sobre contrato YA en_progreso es idempotente (aprobado_ya_procesado, sin re-mutar)", async () => {
  const r = await procesarResultadoPago(pago({ contratoId: contratoEnProgresoId, status: "approved" }));
  assert.equal(r.ok, true);
  if (r.ok) assert.equal(r.accion, "aprobado_ya_procesado");
});

test("procesarResultadoPago: approved sobre un contrato en estado terminal no válido (cancelado) devuelve no_aplicable", async () => {
  const r = await procesarResultadoPago(pago({ contratoId: contratoCanceladoId, status: "approved" }));
  assert.equal(r.ok, false);
  if (!r.ok) assert.equal(r.error, "no_aplicable");

  const [rows] = await pool.query("SELECT estado FROM contratos WHERE id = ?", [contratoCanceladoId]);
  assert.equal((rows as { estado: string }[])[0]!.estado, "cancelado", "un contrato cancelado nunca debe reabrirse por un webhook tardío");
});

test("procesarResultadoPago: rejected sobre contrato pendiente_pago lo deja en pendiente_pago (NUNCA escribe 'rechazado', valor ENUM inválido)", async () => {
  const [ins] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, 15000, 'pendiente_pago', NOW())",
    [servicioId, compradorId, vendedorId],
  );
  const contratoId = (ins as { insertId: number }).insertId;
  try {
    const r = await procesarResultadoPago(pago({ contratoId, status: "rejected" }));
    assert.equal(r.ok, true);
    if (r.ok) assert.equal(r.accion, "rechazado");

    const [rows] = await pool.query("SELECT estado FROM contratos WHERE id = ?", [contratoId]);
    assert.equal((rows as { estado: string }[])[0]!.estado, "pendiente_pago");
  } finally {
    await pool.query("DELETE FROM contrato_eventos WHERE contrato_id = ?", [contratoId]);
    await pool.query("DELETE FROM mp_eventos_log WHERE contrato_id = ?", [contratoId]);
    await pool.query("DELETE FROM contratos WHERE id = ?", [contratoId]);
  }
});

test("procesarResultadoPago: pending sobre un contrato YA en_progreso NO lo revierte (guard de estados terminales)", async () => {
  const r = await procesarResultadoPago(pago({ contratoId: contratoEnProgresoId, status: "pending" }));
  assert.equal(r.ok, true);

  const [rows] = await pool.query("SELECT estado FROM contratos WHERE id = ?", [contratoEnProgresoId]);
  assert.equal((rows as { estado: string }[])[0]!.estado, "en_progreso", "un pending tardío nunca debe pisar un pago ya aprobado");
});

test("procesarResultadoPago: CARRERA REAL — 2 confirmaciones 'approved' simultáneas del mismo contrato con oferta, el cupo se descuenta UNA sola vez", async () => {
  const [antes] = await pool.query("SELECT cupos_oferta FROM servicios WHERE id = ?", [servicioOfertaId]);
  const cuposAntes = (antes as { cupos_oferta: number }[])[0]!.cupos_oferta;

  const [rA, rB] = await Promise.all([
    procesarResultadoPago(pago({ contratoId: contratoParaCarreraId, status: "approved", paymentId: "RACE-A", monto: 12000 })),
    procesarResultadoPago(pago({ contratoId: contratoParaCarreraId, status: "approved", paymentId: "RACE-B", monto: 12000 })),
  ]);

  assert.equal(rA.ok, true);
  assert.equal(rB.ok, true);
  const acciones = [rA, rB].map((r) => (r.ok ? r.accion : null)).sort();
  assert.deepEqual(acciones, ["aprobado", "aprobado_ya_procesado"], "exactamente UNA de las 2 debe hacer la mutación real, la otra debe verla ya hecha");

  const [despues] = await pool.query("SELECT s.cupos_oferta FROM servicios s JOIN contratos c ON c.servicio_id = s.id WHERE c.id = ?", [
    contratoParaCarreraId,
  ]);
  const fila = (despues as { cupos_oferta: number }[])[0]!;
  assert.equal(fila.cupos_oferta, cuposAntes - 1, "el cupo debe descontarse UNA sola vez pese a los 2 webhooks/retornos simultáneos (doble pago simulado)");

  const [contratoRows] = await pool.query("SELECT estado FROM contratos WHERE id = ?", [contratoParaCarreraId]);
  assert.equal((contratoRows as { estado: string }[])[0]!.estado, "en_progreso");
});

// ============================================================================
// HTTP — postCrearPreferencia (validaciones y atajos que NO llaman a MercadoPago real)
// ============================================================================

test("POST crear preferencia sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/pago-contratos/${contratoPendienteId}/preferencia`, { method: "POST" });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST crear preferencia sobre un contrato de OTRO usuario devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/pago-contratos/${contratoPendienteId}/preferencia`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` },
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("POST crear preferencia sobre un contrato YA en_progreso devuelve yaProcesado sin llamar a MercadoPago", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/pago-contratos/${contratoEnProgresoId}/preferencia`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const body = (await res.json()) as { ok: boolean; yaProcesado: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.yaProcesado, true);
  } finally {
    await close();
  }
});

test("POST crear preferencia sobre un contrato de monto $0 lo activa directo (en_progreso), sin pasar por MercadoPago", async () => {
  const [ins] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion) VALUES (?, ?, ?, 0, 'pendiente_pago', NOW())",
    [servicioId, compradorId, vendedorId],
  );
  const contratoId = (ins as { insertId: number }).insertId;
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/pago-contratos/${contratoId}/preferencia`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` },
    });
    const body = (await res.json()) as { ok: boolean; yaProcesado: boolean; gratis?: boolean };
    assert.equal(res.status, 200);
    assert.equal(body.yaProcesado, true);
    assert.equal(body.gratis, true);

    const [rows] = await pool.query("SELECT estado FROM contratos WHERE id = ?", [contratoId]);
    assert.equal((rows as { estado: string }[])[0]!.estado, "en_progreso");
  } finally {
    await pool.query("DELETE FROM contratos WHERE id = ?", [contratoId]);
    await close();
  }
});

// ============================================================================
// HTTP — getConfirmarRetorno (solo validaciones que no requieren un payment_id real)
// ============================================================================

test("GET confirmar retorno sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/pago-contratos/retorno?paymentId=123`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET confirmar retorno sin paymentId devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/pago-contratos/retorno`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

// ============================================================================
// HTTP — webhook público (sin sesión, siempre 200 — MP reintenta agresivamente si no)
// ============================================================================

test("POST webhook sin data.id responde 200 sin hacer nada (mismo comportamiento que notificaciones_mp.php)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/pago-contratos/webhook`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ type: "payment" }),
    });
    assert.equal(res.status, 200);
  } finally {
    await close();
  }
});
