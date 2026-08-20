import { test, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

after(async () => {
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

interface HomeBody {
  serviciosRecomendados: { id: number }[];
  serviciosNuevos: { id: number }[];
  apuntesRecomendados: { id: number }[];
  clasesPaes: { id: number }[];
  ofertas: { id: number; ofertaVigente: boolean }[];
}

test("GET /api/home devuelve 200 con las 5 claves esperadas (sin banner: vitrina.php lo consulta pero nunca lo renderiza)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/home`);
    const body = (await res.json()) as HomeBody & { banner?: unknown };
    assert.equal(res.status, 200);
    assert.ok(Array.isArray(body.serviciosRecomendados));
    assert.ok(Array.isArray(body.serviciosNuevos));
    assert.ok(Array.isArray(body.apuntesRecomendados));
    assert.ok(Array.isArray(body.clasesPaes));
    assert.ok(Array.isArray(body.ofertas));
    assert.equal("banner" in body, false);
  } finally {
    await close();
  }
});

test("GET /api/home: ningún servicio se repite entre recomendadas/nuevas/PAES/ofertas (dedup)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/home`);
    const body = (await res.json()) as HomeBody;
    const todosLosIds = [
      ...body.serviciosRecomendados.map((s) => s.id),
      ...body.serviciosNuevos.map((s) => s.id),
      ...body.clasesPaes.map((s) => s.id),
      ...body.ofertas.map((s) => s.id),
    ];
    const idsUnicos = new Set(todosLosIds);
    assert.equal(idsUnicos.size, todosLosIds.length, "no debería haber IDs repetidos entre secciones de servicios");
  } finally {
    await close();
  }
});

test("GET /api/home: clasesPaes nunca tiene entre 1 y 3 elementos (gate real: 0 o >=4)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/home`);
    const body = (await res.json()) as HomeBody;
    assert.ok(
      body.clasesPaes.length === 0 || body.clasesPaes.length >= 4,
      `clasesPaes.length fue ${body.clasesPaes.length}, debería ser 0 o >=4`,
    );
  } finally {
    await close();
  }
});

// Verifica el relleno silencioso de "Precios de última hora": con al menos 1 oferta real
// activa pero menos de 6, la sección debe rellenarse con servicios normales
// (ofertaVigente=false, sin badge de descuento) hasta completar — puerto de
// vitrina.php:502-534. La BD local no tiene ofertas vigentes hoy (todas expiraron en
// junio), así que se extiende una temporalmente para el test y se revierte siempre.
test("GET /api/home: con 1 oferta real activa, la sección se rellena con servicios normales (ofertaVigente=false)", async (t) => {
  const [rows] = await pool.query(
    "SELECT id, oferta_termino FROM servicios WHERE is_subvencionado = 1 AND cupos_oferta > 0 AND estado = 'aprobado' AND visible = 1 LIMIT 1",
  );
  const fixture = (rows as unknown as Array<{ id: number; oferta_termino: Date | null }>)[0];
  if (!fixture) {
    t.skip("no hay ningún servicio is_subvencionado=1 con cupos>0 en la BD local hoy");
    return;
  }

  await pool.query("UPDATE servicios SET oferta_termino = DATE_ADD(CURDATE(), INTERVAL 7 DAY) WHERE id = ?", [
    fixture.id,
  ]);

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/home`);
    const body = (await res.json()) as HomeBody;
    assert.equal(res.status, 200);
    assert.ok(body.ofertas.length > 0, "con una oferta real vigente, la sección de ofertas no debería estar vacía");
    assert.ok(
      body.ofertas.some((o) => o.id === fixture.id && o.ofertaVigente === true),
      "el servicio con la oferta extendida debería aparecer con ofertaVigente=true",
    );
    if (body.ofertas.length < 6) {
      assert.ok(
        body.ofertas.some((o) => o.ofertaVigente === false),
        "si hay menos de 6 ofertas, el resto debería ser relleno con ofertaVigente=false",
      );
    }
  } finally {
    await close();
    await pool.query("UPDATE servicios SET oferta_termino = ? WHERE id = ?", [
      fixture.oferta_termino,
      fixture.id,
    ]);
  }
});
