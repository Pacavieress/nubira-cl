import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchApuntesPublicos } from "../src/modules/apuntes/apuntes.repository.js";

const SESSION_VALID = "test-ventas-apuntes-session";
const VENDEDOR_ID = 888888893; // sintético (vendedor_id), no corresponde a ningún usuario real
const COMPRADOR_ID = 1; // "Soporte Nubira" — admin real; confirmado sin fila previa en ventas_apuntes (uq_ventas_apunte_comprador)

let apunteId: number;
let ventaId: number;

before(async () => {
  const { rows } = await searchApuntesPublicos({ page: 1, limit: 1 });
  const apunte = rows[0];
  if (!apunte) throw new Error("Se necesita al menos un apunte publicado en la BD local para correr este test.");
  apunteId = apunte.id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, VENDEDOR_ID],
  );

  const [ins] = await pool.query(
    "INSERT INTO ventas_apuntes (apunte_id, comprador_id, vendedor_id, precio, fecha, pagado_al_vendedor) VALUES (?, ?, ?, 4500.00, NOW(), 1)",
    [apunteId, COMPRADOR_ID, VENDEDOR_ID],
  );
  ventaId = (ins as { insertId: number }).insertId;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [SESSION_VALID]);
  await pool.query("DELETE FROM ventas_apuntes WHERE id = ?", [ventaId]);
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

test("GET /api/me/ventas-apuntes sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/ventas-apuntes`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/ventas-apuntes: precio DECIMAL llega como number, no string", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/ventas-apuntes`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      data: { id: number; apunteId: number; precio: number; pagadoAlVendedor: boolean; compradorNombre: string }[];
    };
    const venta = body.data.find((v) => v.id === ventaId);
    assert.ok(venta, "la venta del fixture debe aparecer en data[]");
    assert.equal(typeof venta!.precio, "number");
    assert.equal(venta!.precio, 4500);
    assert.equal(venta!.pagadoAlVendedor, true);
    assert.equal(venta!.apunteId, apunteId);
  } finally {
    await close();
  }
});
