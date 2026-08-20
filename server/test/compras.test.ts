import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchApuntesPublicos } from "../src/modules/apuntes/apuntes.repository.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_VALID = "test-compras-session";
const USUARIO_ID = 888888888; // sintético, no corresponde a ningún usuario real

let compraId: number;
let contratoId: number;
let apunteId: number;
let servicioId: number;
let vendedorId: number;

before(async () => {
  const { rows: apuntes } = await searchApuntesPublicos({ page: 1, limit: 1 });
  const apunte = apuntes[0];
  if (!apunte) throw new Error("Se necesita al menos un apunte publicado en la BD local para correr este test.");
  apunteId = apunte.id;

  const { rows: servicios } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const servicio = servicios[0];
  if (!servicio) throw new Error("Se necesita al menos un servicio aprobado en la BD local para correr este test.");
  servicioId = servicio.id;
  vendedorId = servicio.alumno_id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );

  // servicio_id=0: mismo placeholder que usa el flujo real de compra de apuntes
  // (app/pago_exitoso.php:66-67, `$servicio_cero`) — compras.servicio_id es NOT NULL
  // aunque la compra sea de un apunte, no de un servicio.
  const [insCompra] = await pool.query(
    "INSERT INTO compras (usuario_id, id_apunte, servicio_id, monto, estado_pago, fecha) VALUES (?, ?, 0, ?, 'pagado', NOW())",
    [USUARIO_ID, apunteId, 12345],
  );
  compraId = (insCompra as { insertId: number }).insertId;

  const [insContrato] = await pool.query(
    "INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_pago) VALUES (?, ?, ?, ?, 'liberado', NOW())",
    [servicioId, USUARIO_ID, vendedorId, 54321],
  );
  contratoId = (insContrato as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM compras WHERE id = ?", [compraId]);
  await pool.query("DELETE FROM contratos WHERE id = ?", [contratoId]);
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

test("GET /api/me/compras sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/compras`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/compras con sesión válida devuelve el apunte y el servicio del fixture", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/compras`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      apuntes: { id: number; monto: number; estadoPago: string; titulo: string }[];
      servicios: { id: number; monto: number; estado: string; vendedorNombre: string; titulo: string }[];
    };

    // apunte.id es el id de la fila `compras` (c.id en el SELECT, igual que el PHP real),
    // no el id del apunte — apunteId solo se usó para poblar el fixture (c.id_apunte).
    const apunte = body.apuntes.find((a) => a.id === compraId);
    assert.ok(apunte, "el apunte comprado en el fixture debe aparecer en apuntes[]");
    assert.equal(apunte!.monto, 12345);
    assert.equal(apunte!.estadoPago, "pagado");

    const servicioContratado = body.servicios.find((s) => s.id === contratoId);
    assert.ok(servicioContratado, "el contrato del fixture debe aparecer en servicios[]");
    assert.equal(servicioContratado!.monto, 54321);
    assert.equal(servicioContratado!.estado, "liberado");
  } finally {
    await close();
  }
});

test("GET /api/me/compras: un usuario sin compras reales devuelve arrays vacíos, no error", async () => {
  const sessionVacia = "test-compras-session-vacia";
  const usuarioVacio = 888888889; // sintético, distinto del fixture con compras

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionVacia, usuarioVacio],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/compras`, {
      headers: { Cookie: `PHPSESSID=${sessionVacia}` },
    });
    const body = (await res.json()) as { apuntes: unknown[]; servicios: unknown[] };
    assert.equal(res.status, 200);
    assert.deepEqual(body.apuntes, []);
    assert.deepEqual(body.servicios, []);
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionVacia]);
  }
});
