import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";

const SESSION_LECTURA = "test-perfil-session-lectura";
const SESSION_BIO = "test-perfil-session-bio";
const SESSION_COMPRADOR = "test-perfil-session-comprador";

// Fixture de LECTURA: alumno REAL con servicios reales, confirmado antes de escribir estos
// tests (4 servicios aprobados/visibles, todos sin horarios_json ni video, foto y banco
// reales, bio real ~58 caracteres) — solo se LEE, nunca se muta, así que es seguro
// reutilizar datos reales en vez de crear un fixture sintético.
const ALUMNO_LECTURA_ID = 10036;

// Fixture de LECTURA #2: alumno REAL comprador puro — confirmado antes de escribir este
// test que tiene >=1 fila en `compras`, CERO servicios/apuntes publicados y CERO
// valoraciones como vendedor (o sea, esCreador debe dar false). Cubre el caso inverso al
// fixture de arriba: "Mis Compras" visible, tiles de tutor ausentes, "Desafío de hoy" visible.
const ALUMNO_COMPRADOR_ID = 10050;

// Fixture de ESCRITURA: alumno de prueba insertado y eliminado en este mismo archivo — el
// endpoint de guardar bio muta alumnos.bio Y recalcula servicios.score_nubira de TODOS los
// servicios del usuario; en vez de restaurar el estado de un usuario real (riesgoso, tabla
// central de auth), se usa un alumno 100% desechable creado acá.
let alumnoBioId: number;
let servicioBioId: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_LECTURA, ALUMNO_LECTURA_ID],
  );

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_COMPRADOR, ALUMNO_COMPRADOR_ID],
  );

  const [insAlumno] = await pool.query(
    "INSERT INTO alumnos (nombre, correo, password, visible, bloqueado, bio) VALUES (?, ?, 'x', 1, 0, '')",
    ["Test Perfil Bio", `test-perfil-bio-${Date.now()}@example.invalid`],
  );
  alumnoBioId = (insAlumno as { insertId: number }).insertId;

  const [insServicio] = await pool.query(
    "INSERT INTO servicios (alumno_id, titulo, categoria, precio, estado, visible, score_nubira, video_estado, fecha_publicacion) VALUES (?, 'Servicio de prueba', 'Otros', 5000, 'aprobado', 1, 0, 'sin_video', NOW())",
    [alumnoBioId],
  );
  servicioBioId = (insServicio as { insertId: number }).insertId;

  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_BIO, alumnoBioId],
  );
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?, ?)", [SESSION_LECTURA, SESSION_BIO, SESSION_COMPRADOR]);
  await pool.query("DELETE FROM servicios WHERE id = ?", [servicioBioId]);
  await pool.query("DELETE FROM alumnos WHERE id = ?", [alumnoBioId]);
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

test("GET /api/me/perfil sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/perfil`);
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("GET /api/me/perfil: alumno real con servicios reales trae completitud/gamificación/accesos coherentes", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/perfil`, { headers: { Cookie: `PHPSESSID=${SESSION_LECTURA}` } });
    assert.equal(res.status, 200);

    const body = await res.json();
    assert.equal(body.id, ALUMNO_LECTURA_ID);
    assert.ok(body.servicios.length > 0, "debe traer los servicios reales del alumno");
    assert.ok(body.esCreador, "tiene servicios/apuntes -> es creador");

    // Completitud: todos los servicios reales de este fixture están sin horarios/video.
    assert.equal(body.completitud.faltaHorarios, true);
    assert.equal(body.completitud.faltaVideo, true);
    assert.ok(body.completitud.servicioFaltaHorariosId, "debe apuntar al primer servicio sin horarios");

    // Gamificación: maxScore debe ser un múltiplo de 20 entre 0 y 100, tier coherente con ese valor.
    assert.equal(body.gamificacion.maxScore % 20, 0);
    assert.ok(body.gamificacion.maxScore >= 0 && body.gamificacion.maxScore <= 100);
    if (body.gamificacion.maxScore >= 60) assert.notEqual(body.gamificacion.tier, "basico");

    // Accesos: al ser creador, debe incluir tiles de tutor y NO "Desafío de hoy".
    const titulos = body.accesos.map((a: { titulo: string }) => a.titulo);
    assert.ok(titulos.includes("Mis Publicaciones"));
    assert.ok(titulos.includes("Mi Billetera"));
    assert.ok(!titulos.includes("Desafío de hoy"));
    // Bug real corregido 26/08/2026: "Mis Compras" solo debe verse si el usuario compró
    // algo alguna vez (tabla `compras`) — este fixture real (10036) no tiene ninguna fila
    // ahí, confirmado con una query directa antes de escribir esta aserción.
    assert.ok(!titulos.includes("Mis Compras"), "este fixture no compró nada -> no debe verse");
    // Cada tile ahora también trae su ícono real (mismo SVG que panel_gestion.php).
    for (const acceso of body.accesos as { titulo: string; iconoSvg: string }[]) {
      assert.ok(acceso.iconoSvg && acceso.iconoSvg.includes("<path"), `"${acceso.titulo}" debe traer iconoSvg`);
    }

    // vistasPerfil es un dato propio (privado) — no debe faltar en la respuesta.
    assert.equal(typeof body.vistasPerfil, "number");
  } finally {
    await close();
  }
});

test("GET /api/me/perfil: alumno real comprador (sin publicaciones) ve 'Mis Compras' pero ninguna tile de tutor", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/perfil`, { headers: { Cookie: `PHPSESSID=${SESSION_COMPRADOR}` } });
    assert.equal(res.status, 200);

    const body = await res.json();
    assert.equal(body.id, ALUMNO_COMPRADOR_ID);
    assert.equal(body.esCreador, false, "sin publicaciones ni reseñas como vendedor -> no es creador");

    const titulos = body.accesos.map((a: { titulo: string }) => a.titulo);
    assert.ok(titulos.includes("Mis Compras"), "este fixture sí compró algo -> debe verse");
    assert.ok(titulos.includes("Desafío de hoy"), "no es tutor -> Desafío de hoy visible");
    assert.ok(!titulos.includes("Mis Publicaciones"), "no es tutor -> sin tiles de tutor");
    assert.ok(!titulos.includes("Mi Billetera"), "no es tutor -> sin tiles de tutor");
    assert.ok(!titulos.includes("Métricas"), "no es tutor -> sin tiles de tutor");
    // Siempre visibles, sin importar el rol.
    assert.ok(titulos.includes("Configurar Cuenta"));
    assert.ok(titulos.includes("Mis Evaluaciones"));
    assert.ok(titulos.includes("Soporte"));
  } finally {
    await close();
  }
});

test("PUT /api/me/perfil/bio sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/perfil/bio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ bio: "cualquier cosa" }),
    });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("PUT /api/me/perfil/bio: bio vacía devuelve 400 con el mensaje real del PHP", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/perfil/bio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_BIO}` },
      body: JSON.stringify({ bio: "" }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.equal(body.ok, false);
    assert.equal(body.mensaje, "LA BIO NO PUEDE ESTAR VACÍA");
  } finally {
    await close();
  }
});

test("PUT /api/me/perfil/bio: bio menor a 60 caracteres devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/perfil/bio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_BIO}` },
      body: JSON.stringify({ bio: "Bio muy corta." }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.match(body.mensaje, /AL MENOS 60 CARACTERES/);
  } finally {
    await close();
  }
});

test("PUT /api/me/perfil/bio: bio con correo electrónico devuelve 400 (DLP)", async () => {
  const { url, close } = listen();
  try {
    const bioConCorreo = "Soy tutor de matemáticas hace varios años, escríbeme a contacto@ejemplo.com para más información.";
    const res = await fetch(`${url}/api/me/perfil/bio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_BIO}` },
      body: JSON.stringify({ bio: bioConCorreo }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.match(body.mensaje, /correo electrónico/);
  } finally {
    await close();
  }
});

test("PUT /api/me/perfil/bio: bio con lenguaje ofensivo devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const bioOfensiva = "Soy un tutor pendejo pero sé mucho de matemáticas y llevo años enseñando con buenos resultados.";
    const res = await fetch(`${url}/api/me/perfil/bio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_BIO}` },
      body: JSON.stringify({ bio: bioOfensiva }),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.equal(body.mensaje, "LENGUAJE NO PERMITIDO");
  } finally {
    await close();
  }
});

test("PUT /api/me/perfil/bio: bio válida se guarda, recalcula el score y refleja la nueva foto/bio en la gamificación", async () => {
  const { url, close } = listen();
  try {
    const bioValida = "Soy tutor de matemáticas y física con varios años de experiencia ayudando a estudiantes universitarios.";
    const res = await fetch(`${url}/api/me/perfil/bio`, {
      method: "PUT",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_BIO}` },
      body: JSON.stringify({ bio: bioValida }),
    });
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.ok, true);
    assert.equal(body.bio, bioValida);
    // Fixture: sin foto real (0), CON bio larga (+20) tras este guardado, sin descripción
    // larga/apunte/reseñas/video (0 c/u) -> score = 20.
    assert.equal(body.gamificacion.maxScore, 20);
    assert.equal(body.gamificacion.misiones.bioLarga, true);
    assert.equal(body.gamificacion.misiones.foto, false);

    const [rows] = await pool.query<{ bio: string }[] & { length: number }>("SELECT bio FROM alumnos WHERE id = ?", [alumnoBioId]);
    assert.equal((rows as unknown as { bio: string }[])[0]?.bio, bioValida);

    const [rowsServicio] = await pool.query("SELECT score_nubira FROM servicios WHERE id = ?", [servicioBioId]);
    assert.equal((rowsServicio as unknown as { score_nubira: number }[])[0]?.score_nubira, 20);
  } finally {
    await close();
  }
});
