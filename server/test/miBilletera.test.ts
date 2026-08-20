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
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM contratos WHERE id = ?", [contratoId]);
  await pool.query("DELETE FROM solicitudes_retiro WHERE id = ?", [solicitudId]);
  await pool.query("DELETE FROM datos_pago_usuario WHERE id = ?", [datosBancariosId]);
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
