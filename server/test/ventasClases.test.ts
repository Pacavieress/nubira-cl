import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_VALID = "test-ventas-clases-session";
const VENDEDOR_ID = 888888892; // sintético (vendedor_id), no corresponde a ningún usuario real
const COMPRADOR_ID = 1; // "Soporte Nubira" — admin real, sirve como comprador real del fixture

let servicioId: number;
let contratoId: number;

before(async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const servicio = rows[0];
  if (!servicio) throw new Error("Se necesita al menos un servicio aprobado en la BD local para correr este test.");
  servicioId = servicio.id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, VENDEDOR_ID],
  );

  const [ins] = await pool.query(
    `INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, monto_subsidio, monto_comision, estado, fecha_creacion, fecha_pago, calificacion_vendedor)
     VALUES (?, ?, ?, 20000, 5000, 3000, 'liberado', NOW(), NOW(), 0)`,
    [servicioId, COMPRADOR_ID, VENDEDOR_ID],
  );
  contratoId = (ins as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
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

test("GET /api/me/ventas-clases sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/ventas-clases`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/ventas-clases: neto = bruto + subsidio - comisión, calcula bien con el fixture (20000+5000-3000=22000)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/ventas-clases`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      data: { idContrato: number; bruto: number; subsidio: number; comision: number; neto: number; estado: string; yaCalificado: boolean }[];
    };
    const venta = body.data.find((v) => v.idContrato === contratoId);
    assert.ok(venta, "el contrato del fixture debe aparecer en data[]");
    assert.equal(venta!.bruto, 20000);
    assert.equal(venta!.subsidio, 5000);
    assert.equal(venta!.comision, 3000);
    assert.equal(venta!.neto, 22000);
    assert.equal(venta!.estado, "liberado");
    assert.equal(venta!.yaCalificado, false);
  } finally {
    await close();
  }
});
