import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

// Panel Admin Retiros — autorizado por el usuario con alcance completo (aprobar/rechazar
// retiros reales), a diferencia de los paneles admin anteriores. El correo de
// aprobado/rechazado SÍ se manda de verdad (mismo criterio "sin mocks, infraestructura
// real" del resto de esta migración — ya probado en vivo contra Daily.co/MercadoPago) —
// los 2 tests de "happy path" (aprobar/rechazar exitosos) disparan un correo real a
// soporte@nubira.cl (inbox real de Nubira, contenido claramente marcado como prueba). El
// resto de los tests (guards, validación, 403/401) nunca llegan a enviarCorreo().

const SESSION_ADMIN = "test-admin-retiros-session";
const SESSION_NO_ADMIN = "test-admin-retiros-session-no-admin";
const ADMIN_ID = 1; // "Soporte Nubira" — mismo fixture real que el resto de tests admin.
let alumnoNoAdminId: number;
let tutorId: number;
let servicioId: number;

let solicitudAprobarId: number;
let solicitudRechazarId: number;
let solicitudAprobadaId: number;
let solicitudInstitucionId: number;
let solicitudAuditoriaId: number;
let solicitudSinContratosId: number;
let contratoAuditoria1Id: number;
let contratoAuditoria2Id: number;

let configOriginal: { minimoRetiro: number; comisionActual: number };

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
    ["Test No Admin Retiros", `test-no-admin-retiros-${ts}@example.invalid`],
  );
  alumnoNoAdminId = (insAlumno as { insertId: number }).insertId;
  await pool.query("INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [SESSION_NO_ADMIN, alumnoNoAdminId]);

  // Correo REAL (soporte@nubira.cl) a propósito — es el destinatario de los correos de
  // aprobado/rechazado que sí se disparan de verdad en los tests de happy path.
  const [insTutor] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, rol) VALUES (?, 'soporte@nubira.cl', 'x', 1, 0, 'alumno')",
    [`[TEST adminRetiros] Tutor Prueba ${ts}`],
  );
  tutorId = (insTutor as { insertId: number }).insertId;

  await pool.query(
    "INSERT INTO datos_pago_usuario (usuario_id, tipo_cuenta, banco, numero_cuenta, titular_nombre, rut) VALUES (?, 'Cuenta Vista', 'Banco Estado', '11112222', 'Tutor Prueba', '11111111-1')",
    [tutorId],
  );

  const [insServ] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, descripcion, categoria, modalidad, estado, visible, precio, fecha_publicacion) VALUES (?, 'Servicio Test Retiros', 'desc', 'Matemáticas', 'Online', 'aprobado', 1, 10000, NOW())",
    [tutorId],
  );
  servicioId = (insServ as { insertId: number }).insertId;

  const crearSolicitud = async (estado: string, monto: number, institucion: string) => {
    const [ins] = await pool.query("INSERT INTO solicitudes_retiro (usuario_id, monto, institucion, estado, fecha_solicitud) VALUES (?, ?, ?, ?, NOW())", [
      tutorId,
      monto,
      institucion,
      estado,
    ]);
    return (ins as { insertId: number }).insertId;
  };
  solicitudAprobarId = await crearSolicitud("pendiente", 20000, "uc");
  solicitudRechazarId = await crearSolicitud("pendiente", 21000, "uc");
  solicitudAprobadaId = await crearSolicitud("aprobado", 22000, "uc");
  solicitudInstitucionId = await crearSolicitud("pendiente", 23000, "aiep");
  solicitudAuditoriaId = await crearSolicitud("pendiente", 24000, "uc");
  solicitudSinContratosId = await crearSolicitud("pendiente", 25000, "uc");

  // Sin FK real en contratos.servicio_id/comprador_id/vendedor_id (confirmado vía
  // information_schema, mismo criterio que adminContratos.test.ts) — IDs inexistentes son
  // seguros acá, solo importan monto/monto_subsidio/monto_comision/solicitud_retiro_id.
  const [insC1] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision, estado, solicitud_retiro_id, fecha_creacion) VALUES (?, 999999999, ?, 15000, 2000, 1500, 'liberado', ?, NOW())",
    [servicioId, tutorId, solicitudAuditoriaId],
  );
  contratoAuditoria1Id = (insC1 as { insertId: number }).insertId;
  const [insC2] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision, estado, solicitud_retiro_id, fecha_creacion) VALUES (?, 999999999, ?, 9000, 0, 900, 'liberado', ?, NOW())",
    [servicioId, tutorId, solicitudAuditoriaId],
  );
  contratoAuditoria2Id = (insC2 as { insertId: number }).insertId;

  const [rowsConf] = await pool.query("SELECT clave, valor FROM configuracion WHERE clave IN ('monto_minimo_retiro', 'comision_plataforma')");
  configOriginal = { minimoRetiro: 10000, comisionActual: 0 };
  for (const row of rowsConf as { clave: string; valor: string }[]) {
    if (row.clave === "monto_minimo_retiro") configOriginal.minimoRetiro = parseInt(row.valor, 10);
    if (row.clave === "comision_plataforma") configOriginal.comisionActual = parseInt(row.valor, 10);
  }
});

after(async () => {
  // Deja la configuración financiera exactamente como estaba antes de este test (real, la
  // misma que usa admin_retiros.php en producción — no es solo un fixture descartable).
  await pool.query("INSERT INTO configuracion (clave, valor) VALUES ('monto_minimo_retiro', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [
    String(configOriginal.minimoRetiro),
  ]);
  await pool.query("INSERT INTO configuracion (clave, valor) VALUES ('comision_plataforma', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [
    String(configOriginal.comisionActual),
  ]);

  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_ADMIN, SESSION_NO_ADMIN]);
  await pool.query("DELETE FROM contratos WHERE id IN (?, ?)", [contratoAuditoria1Id, contratoAuditoria2Id]);
  await pool.query("DELETE FROM solicitudes_retiro WHERE id IN (?, ?, ?, ?, ?, ?)", [
    solicitudAprobarId,
    solicitudRechazarId,
    solicitudAprobadaId,
    solicitudInstitucionId,
    solicitudAuditoriaId,
    solicitudSinContratosId,
  ]);
  await pool.query("DELETE FROM datos_pago_usuario WHERE usuario_id = ?", [tutorId]);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioId]);
  await pool.query("DELETE FROM alumnos WHERE id IN (?, ?)", [tutorId, alumnoNoAdminId]);
  await pool.end();
});

// ============================================================================
// Listado + filtros
// ============================================================================

test("GET /api/admin/retiros sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/admin/retiros con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros`, { headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}` } });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("GET /api/admin/retiros sin query param filtra por 'pendiente' por default (mismo default que admin_retiros.php:66), trae datos bancarios completos y la configuración financiera", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { solicitudes: { id: number; estado: string; datosBancarios: unknown }[]; configuracion: { minimoRetiro: number; comisionActual: number } };

    assert.ok(body.solicitudes.every((s) => s.estado === "pendiente"));
    assert.ok(body.solicitudes.some((s) => s.id === solicitudAprobarId));
    assert.ok(!body.solicitudes.some((s) => s.id === solicitudAprobadaId), "la aprobada no debe aparecer bajo el filtro default");

    const mia = body.solicitudes.find((s) => s.id === solicitudAprobarId) as { datosBancarios: { banco: string; numeroCuenta: string; rut: string } };
    assert.deepEqual(mia.datosBancarios, { banco: "Banco Estado", tipoCuenta: "Cuenta Vista", numeroCuenta: "11112222", titularNombre: "Tutor Prueba", rut: "11111111-1" });

    assert.equal(typeof body.configuracion.minimoRetiro, "number");
    assert.equal(typeof body.configuracion.comisionActual, "number");
  } finally {
    await close();
  }
});

test("GET /api/admin/retiros?estado=aprobado filtra correctamente", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros?estado=aprobado`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const body = (await res.json()) as { solicitudes: { id: number; estado: string }[] };
    assert.ok(body.solicitudes.some((s) => s.id === solicitudAprobadaId));
    assert.ok(body.solicitudes.every((s) => s.estado === "aprobado"));
  } finally {
    await close();
  }
});

test("GET /api/admin/retiros?estado=todas incluye pendientes y aprobadas juntas", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros?estado=todas`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const body = (await res.json()) as { solicitudes: { id: number }[] };
    assert.ok(body.solicitudes.some((s) => s.id === solicitudAprobarId));
    assert.ok(body.solicitudes.some((s) => s.id === solicitudAprobadaId));
  } finally {
    await close();
  }
});

test("GET /api/admin/retiros?institucion=AIEP filtra case-insensitive (mismo criterio LOWER() que admin_retiros.php:81-83)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros?estado=todas&institucion=AIEP`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const body = (await res.json()) as { solicitudes: { id: number }[] };
    assert.ok(body.solicitudes.some((s) => s.id === solicitudInstitucionId));
    assert.ok(!body.solicitudes.some((s) => s.id === solicitudAprobarId), "institucion='uc' no debe aparecer al filtrar por aiep");
  } finally {
    await close();
  }
});

// ============================================================================
// Configuración financiera
// ============================================================================

test("PUT /api/admin/retiros/configuracion: monto mínimo < 1 devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/configuracion`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ montoMinimo: 0, comision: 5 }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/admin/retiros/configuracion: comisión fuera de 0-100 devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/configuracion`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ montoMinimo: 15000, comision: 101 }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("PUT /api/admin/retiros/configuracion: valores válidos se guardan y reflejan en un GET posterior", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/configuracion`, {
      method: "PUT",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ montoMinimo: 19999, comision: 7 }),
    });
    assert.equal(res.status, 200);

    const resGet = await fetch(`${url}/api/admin/retiros`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    const body = (await resGet.json()) as { configuracion: { minimoRetiro: number; comisionActual: number } };
    assert.equal(body.configuracion.minimoRetiro, 19999);
    assert.equal(body.configuracion.comisionActual, 7);
  } finally {
    await close();
  }
});

// ============================================================================
// Aprobar / Rechazar
// ============================================================================

test("POST /api/admin/retiros/:id/aprobar con sesión NO admin devuelve 403", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/${solicitudAprobarId}/aprobar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_NO_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ transferenciaId: "REF-123" }),
    });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST /api/admin/retiros/:id/aprobar sin transferenciaId devuelve 400 (campo activado a pedido del usuario, ahora requerido)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/${solicitudAprobarId}/aprobar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ transferenciaId: "   " }),
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "transferencia_requerida");
  } finally {
    await close();
  }
});

test("POST /api/admin/retiros/:id/aprobar exitoso: pasa a 'aprobado', guarda transferencia_id/fecha_transferencia/fecha_pago, y envía correo real", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/${solicitudAprobarId}/aprobar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ transferenciaId: "REF-TEST-001" }),
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean; correoEnviado: boolean };
    assert.equal(body.ok, true);
    assert.equal(body.correoEnviado, true);

    const [rows] = await pool.query("SELECT estado, transferencia_id, fecha_pago, fecha_transferencia FROM solicitudes_retiro WHERE id = ?", [solicitudAprobarId]);
    const r = (rows as { estado: string; transferencia_id: string; fecha_pago: Date | null; fecha_transferencia: Date | null }[])[0]!;
    assert.equal(r.estado, "aprobado");
    assert.equal(r.transferencia_id, "REF-TEST-001");
    assert.ok(r.fecha_pago !== null);
    assert.ok(r.fecha_transferencia !== null);
  } finally {
    await close();
  }
});

test("POST /api/admin/retiros/:id/aprobar sobre una solicitud YA procesada devuelve 409 (guard nuevo — el PHP real no lo tenía)", async () => {
  const { url, close } = listen();
  try {
    // solicitudAprobarId ya quedó 'aprobado' en el test anterior.
    const res = await fetch(`${url}/api/admin/retiros/${solicitudAprobarId}/aprobar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}`, "Content-Type": "application/json" },
      body: JSON.stringify({ transferenciaId: "REF-TEST-DOBLE" }),
    });
    assert.equal(res.status, 409);
    const body = (await res.json()) as { ok: boolean };
    assert.equal(body.ok, false);

    const [rows] = await pool.query("SELECT transferencia_id FROM solicitudes_retiro WHERE id = ?", [solicitudAprobarId]);
    const r = (rows as { transferencia_id: string }[])[0]!;
    assert.equal(r.transferencia_id, "REF-TEST-001", "el guard debe impedir que el doble-submit pise la referencia ya guardada");
  } finally {
    await close();
  }
});

test("POST /api/admin/retiros/:id/rechazar exitoso: pasa a 'rechazado' y envía correo real", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/${solicitudRechazarId}/rechazar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean; correoEnviado: boolean };
    assert.equal(body.ok, true);
    assert.equal(body.correoEnviado, true);

    const [rows] = await pool.query("SELECT estado FROM solicitudes_retiro WHERE id = ?", [solicitudRechazarId]);
    assert.equal((rows as { estado: string }[])[0]!.estado, "rechazado");
  } finally {
    await close();
  }
});

test("POST /api/admin/retiros/:id/rechazar sobre id inexistente devuelve 409", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/999999999/rechazar`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` },
    });
    assert.equal(res.status, 409);
  } finally {
    await close();
  }
});

// ============================================================================
// Auditoría
// ============================================================================

test("GET /api/admin/retiros/:id/auditoria: desglosa los contratos vinculados y calcula los totales igual que api_auditoria_retiro.php", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/${solicitudAuditoriaId}/auditoria`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { contratos: { id: number; liquido: number }[]; totales: { alumno: number; subsidio: number; comision: number; liquido: number } };

    assert.equal(body.contratos.length, 2);
    assert.ok(body.contratos.some((c) => c.id === contratoAuditoria1Id && c.liquido === 15000 + 2000 - 1500));
    assert.ok(body.contratos.some((c) => c.id === contratoAuditoria2Id && c.liquido === 9000 + 0 - 900));
    assert.equal(body.totales.alumno, 15000 + 9000);
    assert.equal(body.totales.subsidio, 2000);
    assert.equal(body.totales.comision, 1500 + 900);
    assert.equal(body.totales.liquido, 15000 + 2000 - 1500 + (9000 - 900));
  } finally {
    await close();
  }
});

test("GET /api/admin/retiros/:id/auditoria sin contratos vinculados devuelve arrays/totales vacíos (mismo caso 'solo apuntes' del PHP real)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/admin/retiros/${solicitudSinContratosId}/auditoria`, { headers: { Cookie: `PHPSESSID=${SESSION_ADMIN}` } });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { contratos: unknown[]; totales: { alumno: number; subsidio: number; comision: number; liquido: number } };
    assert.equal(body.contratos.length, 0);
    assert.deepEqual(body.totales, { alumno: 0, subsidio: 0, comision: 0, liquido: 0 });
  } finally {
    await close();
  }
});
