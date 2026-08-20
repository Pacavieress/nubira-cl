import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchServiciosAprobados } from "../src/modules/servicios/servicios.repository.js";

const SESSION_VALID = "test-mis-contratos-session";
const COMPRADOR_ID = 888888896; // sintético — contratos.comprador_id no tiene FK real
const VENDEDOR_REAL_ID = 1; // "Soporte Nubira", usado como vendedor real del fixture

let servicioId: number;
let contratoId: number;

before(async () => {
  const { rows } = await searchServiciosAprobados({ page: 1, limit: 1 });
  const servicio = rows[0];
  if (!servicio) throw new Error("Se necesita al menos un servicio aprobado en la BD local para correr este test.");
  servicioId = servicio.id;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, COMPRADOR_ID],
  );

  const [ins] = await pool.query(
    `INSERT INTO contratos (servicio_id, comprador_id, vendedor_id, monto, estado, fecha_creacion, fecha_estimada)
     VALUES (?, ?, ?, 15000, 'en_progreso', NOW(), NOW() + INTERVAL 3 DAY)`,
    [servicioId, COMPRADOR_ID, VENDEDOR_REAL_ID],
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

test("GET /api/me/mis-contratos sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-contratos`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/mis-contratos: el contrato del fixture aparece en comoComprador con el nombre real del vendedor", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/mis-contratos`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);

    const body = (await res.json()) as {
      comoComprador: { id: number; monto: number; estado: string; otraPersonaNombre: string; imagenUrl: string }[];
      comoVendedor: unknown[];
    };
    const contrato = body.comoComprador.find((c) => c.id === contratoId);
    assert.ok(contrato, "el contrato del fixture debe aparecer en comoComprador");
    assert.equal(contrato!.monto, 15000);
    assert.equal(contrato!.estado, "en_progreso");
    assert.equal(contrato!.otraPersonaNombre, "Soporte Nubira");
    assert.ok(contrato!.imagenUrl.startsWith("http"), "imagenUrl debe ser absoluta");

    assert.ok(
      !body.comoVendedor.some((c) => (c as { id: number }).id === contratoId),
      "el contrato de este comprador sintético no debe aparecer en comoVendedor",
    );
  } finally {
    await close();
  }
});
