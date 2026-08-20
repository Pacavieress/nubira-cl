import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { searchApuntesPublicos } from "../src/modules/apuntes/apuntes.repository.js";

const SESSION_OWNER = "test-apuntes-detalle-owner";
const SESSION_OTRO = "test-apuntes-detalle-otro";
const SESSION_VENCIDA = "test-apuntes-detalle-vencida";
const USUARIO_OTRO = 888888887; // sintético, no es dueño de ningún apunte real

let apunteId: number;
let alumnoIdDueno: number;
let apuntePendienteId: number | null;
let apunteConIaId: number | null;

before(async () => {
  const { rows } = await searchApuntesPublicos({ page: 1, limit: 1 });
  const first = rows[0];
  if (!first) {
    throw new Error("Se necesita al menos un apunte visible en la BD local para correr este test.");
  }
  apunteId = first.id;

  const [detalleRows] = await pool.query("SELECT id_alumno FROM apuntes WHERE id = ? LIMIT 1", [
    apunteId,
  ]);
  alumnoIdDueno = (detalleRows as unknown as Array<{ id_alumno: number }>)[0]!.id_alumno;

  const [pendienteRows] = await pool.query(
    "SELECT id FROM apuntes WHERE estado = 'pendiente' LIMIT 1",
  );
  apuntePendienteId = ((pendienteRows as unknown as Array<{ id: number }>)[0]?.id) ?? null;

  const [iaRows] = await pool.query(
    "SELECT id FROM apuntes WHERE estado = 'aprobado' AND ia_used = 1 AND ia_keywords IS NOT NULL LIMIT 1",
  );
  apunteConIaId = ((iaRows as unknown as Array<{ id: number }>)[0]?.id) ?? null;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_OWNER, alumnoIdDueno],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_OTRO, USUARIO_OTRO],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() - INTERVAL 1 HOUR)",
    [SESSION_VENCIDA, USUARIO_OTRO],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [
    SESSION_OWNER,
    SESSION_OTRO,
    SESSION_VENCIDA,
  ]);
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

test("GET /api/apuntes/:id inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/999999999`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/apuntes/:id inválido (no numérico) devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/no-es-un-id`);
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("GET /api/apuntes/:id sin cookie devuelve 200 con viewer anónimo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteId}`);
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: false, isOwner: false });
  } finally {
    await close();
  }
});

test("GET /api/apuntes/:id con cookie del dueño real devuelve isOwner=true", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_OWNER}` },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: true, isOwner: true });
  } finally {
    await close();
  }
});

test("GET /api/apuntes/:id con cookie de OTRO usuario devuelve isAuthenticated=true pero isOwner=false", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: true, isOwner: false });
  } finally {
    await close();
  }
});

test("optionalAuth NUNCA bloquea: cookie vencida devuelve 200 (no 401), viewer anónimo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteId}`, {
      headers: { Cookie: `PHPSESSID=${SESSION_VENCIDA}` },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: false, isOwner: false });
  } finally {
    await close();
  }
});

test("optionalAuth NUNCA bloquea: cookie que no existe en sesiones_api devuelve 200 (no 401), viewer anónimo", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteId}`, {
      headers: { Cookie: "PHPSESSID=esto-no-existe-en-sesiones-api" },
    });
    const body = (await res.json()) as { viewer: { isAuthenticated: boolean; isOwner: boolean } };
    assert.equal(res.status, 200);
    assert.deepEqual(body.viewer, { isAuthenticated: false, isOwner: false });
  } finally {
    await close();
  }
});

// Decisión documentada en apuntes.repository.ts: a diferencia de ver_apunte.php (que deja
// al dueño ver su propio apunte pendiente), acá el gate es estado='aprobado' sin excepción
// — misma simetría que servicios.repository.ts. Verificado incluso con la cookie del dueño.
test("GET /api/apuntes/:id de un apunte pendiente devuelve 404 incluso para su dueño (sin excepción, a diferencia de ver_apunte.php)", async (t) => {
  if (apuntePendienteId === null) {
    t.skip("no hay ningún apunte con estado=pendiente en la BD local ahora mismo");
    return;
  }
  const [rows] = await pool.query("SELECT id_alumno FROM apuntes WHERE id = ? LIMIT 1", [
    apuntePendienteId,
  ]);
  const duenoPendiente = (rows as unknown as Array<{ id_alumno: number }>)[0]!.id_alumno;
  const sessionDuenoPendiente = "test-apuntes-detalle-dueno-pendiente";
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [sessionDuenoPendiente, duenoPendiente],
  );

  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apuntePendienteId}`, {
      headers: { Cookie: `PHPSESSID=${sessionDuenoPendiente}` },
    });
    assert.equal(res.status, 404);
  } finally {
    await close();
    await pool.query("DELETE FROM sesiones_api WHERE session_id = ?", [sessionDuenoPendiente]);
  }
});

test("GET /api/apuntes/:id existente: portadaUrl absoluta, descripción SIN truncar (a diferencia del listado)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteId}`);
    const body = (await res.json()) as {
      portadaUrl: string;
      descripcion: string | null;
      publicador: { fotoUrl: string; verificado: boolean };
    };
    assert.equal(res.status, 200);
    assert.ok(body.portadaUrl.startsWith("http"));
    assert.equal("descripcionCorta" in body, false);
    assert.ok(body.publicador.fotoUrl.length > 0);
  } finally {
    await close();
  }
});

test("GET /api/apuntes/:id: iaTags solo aparece poblado cuando ia_used=1 y hay ia_keywords", async (t) => {
  if (apunteConIaId === null) {
    t.skip("no hay ningún apunte con ia_used=1 e ia_keywords en la BD local ahora mismo");
    return;
  }
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${apunteConIaId}`);
    const body = (await res.json()) as { iaTags: string[] };
    assert.equal(res.status, 200);
    assert.ok(Array.isArray(body.iaTags));
    assert.ok(body.iaTags.length > 0);
    assert.ok(body.iaTags.length <= 6);
  } finally {
    await close();
  }
});

// Regresión de bug real (encontrado en vivo: cards de apuntes en la home sin imagen,
// 404 en /upload/portadas/{id}.webp). portadaUrl debe apuntar a /upload/preview/ cuando
// portada sigue el patrón automático "{id}.webp" (el caso común: 61 de 62 apuntes con
// portada poblada hoy), y a /upload/portadas/ solo cuando el nombre es realmente custom
// (el único caso real hoy: apunte 291, "apt_...webp" — el único archivo que existe en esa
// carpeta). "Empieza con http" no alcanza para atrapar este bug: la URL rota también
// empezaba con http, solo apuntaba a la carpeta equivocada.
test("GET /api/apuntes/:id: portadaUrl usa /upload/preview/ para portada='{id}.webp' (patrón automático)", async (t) => {
  const [rows] = await pool.query(
    "SELECT id FROM apuntes WHERE portada = CONCAT(id, '.webp') AND estado = 'aprobado' LIMIT 1",
  );
  const fixture = (rows as unknown as Array<{ id: number }>)[0];
  if (!fixture) {
    t.skip("no hay ningún apunte con portada='{id}.webp' aprobado en la BD local ahora mismo");
    return;
  }
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${fixture.id}`);
    const body = (await res.json()) as { portadaUrl: string };
    assert.equal(res.status, 200);
    assert.ok(
      body.portadaUrl.includes(`/upload/preview/${fixture.id}.webp`),
      `esperaba /upload/preview/${fixture.id}.webp, llegó ${body.portadaUrl}`,
    );
    assert.ok(!body.portadaUrl.includes("/upload/portadas/"));
  } finally {
    await close();
  }
});

test("GET /api/apuntes/:id: portadaUrl usa /upload/portadas/ para una portada con nombre custom", async (t) => {
  const [rows] = await pool.query(
    "SELECT id, portada FROM apuntes WHERE portada IS NOT NULL AND portada != '' AND portada != CONCAT(id, '.webp') AND estado = 'aprobado' LIMIT 1",
  );
  const fixture = (rows as unknown as Array<{ id: number; portada: string }>)[0];
  if (!fixture) {
    t.skip("no hay ningún apunte con portada de nombre custom aprobado en la BD local ahora mismo");
    return;
  }
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/apuntes/${fixture.id}`);
    const body = (await res.json()) as { portadaUrl: string };
    assert.equal(res.status, 200);
    assert.ok(body.portadaUrl.includes(`/upload/portadas/${fixture.portada}`));
  } finally {
    await close();
  }
});
