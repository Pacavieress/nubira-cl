import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import type { ResultSetHeader, RowDataPacket } from "mysql2";
import { createApp } from "../src/app.js";
import { pool } from "../src/db/pool.js";
import { env } from "../src/config/env.js";

const SESSION_VALID = "test-publicar-session";
const SESSION_OTRO = "test-publicar-otro-session";
const USUARIO_ID = 1; // "Soporte Nubira" — único usuario admin real, estable entre sesiones de test.
const OTRO_USUARIO_ID = 888888895; // sintético — servicios.alumno_id no tiene FK real (confirmado con SHOW CREATE TABLE).

let servicioIdsCreados: number[] = [];
let apunteIdsCreados: number[] = [];
let archivosApuntesCreados: string[] = [];
let archivosPreviewCreados: string[] = [];
let archivosVideosCreados: string[] = [];
let archivosThumbsCreados: string[] = [];
let cupoOriginal: number;

before(async () => {
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_VALID, USUARIO_ID],
  );
  await pool.query(
    "INSERT INTO sesiones_api (session_id, usuario_id, expira_en) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)",
    [SESSION_OTRO, OTRO_USUARIO_ID],
  );

  const [rows] = await pool.query<RowDataPacket[]>("SELECT servicios_publicados_total FROM alumnos WHERE id = ?", [USUARIO_ID]);
  cupoOriginal = (rows[0] as { servicios_publicados_total: number }).servicios_publicados_total;
});

after(async () => {
  await pool.query("DELETE FROM sesiones_api WHERE session_id IN (?, ?)", [SESSION_VALID, SESSION_OTRO]);
  if (servicioIdsCreados.length > 0) {
    await pool.query("DELETE FROM servicios WHERE id IN (?)", [servicioIdsCreados]);
  }
  if (apunteIdsCreados.length > 0) {
    await pool.query("DELETE FROM apuntes WHERE id IN (?)", [apunteIdsCreados]);
  }
  await pool.query("UPDATE alumnos SET servicios_publicados_total = ? WHERE id = ?", [cupoOriginal, USUARIO_ID]);

  for (const nombre of [...archivosApuntesCreados]) {
    await fs.rm(path.join(env.uploadDir, "apuntes", nombre), { force: true });
  }
  for (const nombre of [...archivosPreviewCreados]) {
    await fs.rm(path.join(env.uploadDir, "preview", nombre), { force: true });
  }
  for (const nombre of [...archivosVideosCreados]) {
    await fs.rm(path.join(env.uploadDir, "videos_servicios", nombre), { force: true });
  }
  for (const nombre of [...archivosThumbsCreados]) {
    await fs.rm(path.join(env.uploadDir, "servicios", nombre), { force: true });
  }

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

async function setCupo(valor: number) {
  await pool.query("UPDATE alumnos SET servicios_publicados_total = ? WHERE id = ?", [valor, USUARIO_ID]);
}

const SERVICIO_BASE = {
  titulo: "Clases de prueba automatizada",
  descripcion: "Descripción de prueba lo suficientemente larga para pasar validación, sin datos de contacto.",
  categoria: "Asesoría",
  modalidad: "Online",
  ubicacion: "",
  precio: 15000,
  esPaes: false,
};

test("POST /api/me/publicar/servicios sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(SERVICIO_BASE),
    });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios con campos obligatorios faltantes devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ ...SERVICIO_BASE, titulo: "" }),
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "campos_obligatorios_faltantes");
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios con precio bajo el mínimo devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ ...SERVICIO_BASE, precio: 5000 }),
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "precio_bajo_minimo");
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios con datos de contacto en la descripción devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ ...SERVICIO_BASE, descripcion: "Escríbeme a mi Instagram para coordinar la clase." }),
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "contiene_contacto");
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios con cupo gratis agotado devuelve 403 (pago excluido de esta pieza)", async () => {
  await setCupo(1);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify(SERVICIO_BASE),
    });
    assert.equal(res.status, 403);
    const body = (await res.json()) as { error: string };
    assert.equal(body.error, "cupo_gratis_agotado");
  } finally {
    await close();
  }
});

let servicioIdCreado: number;

test("POST /api/me/publicar/servicios con cupo disponible: crea el servicio real (201), asigna imagen del banco, genera slug, incrementa el cupo", async () => {
  await setCupo(0);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify(SERVICIO_BASE),
    });
    assert.equal(res.status, 201);
    const body = (await res.json()) as { ok: boolean; servicioId: number };
    assert.equal(body.ok, true);
    assert.ok(body.servicioId > 0);
    servicioIdCreado = body.servicioId;
    servicioIdsCreados.push(servicioIdCreado);

    const [rows] = await pool.query<RowDataPacket[]>(
      "SELECT titulo, categoria, estado, imagen_banco_id, slug, alumno_id FROM servicios WHERE id = ?",
      [servicioIdCreado],
    );
    const fila = rows[0] as { titulo: string; categoria: string; estado: string; imagen_banco_id: number; slug: string | null; alumno_id: number };
    assert.equal(fila.titulo, SERVICIO_BASE.titulo);
    assert.equal(fila.categoria, SERVICIO_BASE.categoria);
    assert.equal(fila.estado, "pendiente");
    assert.equal(fila.alumno_id, USUARIO_ID);
    assert.ok(fila.imagen_banco_id > 0, "debe traer una imagen real del banco por categoría");
    assert.ok(fila.slug && fila.slug.startsWith("clases-de-prueba-automatizada"), `slug generado: ${fila.slug}`);

    const [cupoRows] = await pool.query<RowDataPacket[]>("SELECT servicios_publicados_total FROM alumnos WHERE id = ?", [USUARIO_ID]);
    assert.equal((cupoRows[0] as { servicios_publicados_total: number }).servicios_publicados_total, 1);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/horario sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdCreado}/horario`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ horariosJson: "{}" }),
    });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/horario con JSON sin las 7 claves válidas devuelve 400", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdCreado}/horario`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ horariosJson: JSON.stringify({ Lunes: [] }) }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/horario sin ningún bloque marcado devuelve 400", async () => {
  const vacio = { Lunes: [], Martes: [], Miércoles: [], Jueves: [], Viernes: [], Sábado: [], Domingo: [] };
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdCreado}/horario`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ horariosJson: JSON.stringify(vacio) }),
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/horario de OTRO usuario (no dueño) devuelve 403", async () => {
  const conBloques = { Lunes: ["09:00 - 11:00"], Martes: [], Miércoles: [], Jueves: [], Viernes: [], Sábado: [], Domingo: [] };
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdCreado}/horario`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_OTRO}` },
      body: JSON.stringify({ horariosJson: JSON.stringify(conBloques) }),
    });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/horario con datos válidos: guarda el horario real (200)", async () => {
  const conBloques = { Lunes: ["09:00 - 11:00"], Martes: ["14:00 - 16:00"], Miércoles: [], Jueves: [], Viernes: [], Sábado: [], Domingo: [] };
  const jsonEnviado = JSON.stringify(conBloques);
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdCreado}/horario`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ horariosJson: jsonEnviado }),
    });
    assert.equal(res.status, 200);

    const [rows] = await pool.query<RowDataPacket[]>("SELECT horarios_json FROM servicios WHERE id = ?", [servicioIdCreado]);
    assert.equal((rows[0] as { horarios_json: string }).horarios_json, jsonEnviado);
  } finally {
    await close();
  }
});

test("DELETE /api/me/publicar/servicios/:id/incompleto NO borra un servicio que YA tiene horario guardado", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdCreado}/incompleto`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean };
    assert.equal(body.ok, false, "no debe reportar borrado: el servicio ya tiene horarios_json, deja de calificar como incompleto");

    const [rows] = await pool.query<RowDataPacket[]>("SELECT id FROM servicios WHERE id = ?", [servicioIdCreado]);
    assert.equal(rows.length, 1, "el servicio debe seguir existiendo");
  } finally {
    await close();
  }
});

test("DELETE /api/me/publicar/servicios/:id/incompleto SÍ borra un servicio recién creado sin horario", async () => {
  await setCupo(0);
  const { url, close } = listen();
  try {
    const resCrear = await fetch(`${url}/api/me/publicar/servicios`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: JSON.stringify({ ...SERVICIO_BASE, titulo: "Servicio para probar rollback" }),
    });
    const { servicioId } = (await resCrear.json()) as { servicioId: number };

    const resDelete = await fetch(`${url}/api/me/publicar/servicios/${servicioId}/incompleto`, {
      method: "DELETE",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
    });
    const body = (await resDelete.json()) as { ok: boolean };
    assert.equal(body.ok, true);

    const [rows] = await pool.query<RowDataPacket[]>("SELECT id FROM servicios WHERE id = ?", [servicioId]);
    assert.equal(rows.length, 0, "el servicio incompleto debe haber sido borrado de verdad");
  } finally {
    await close();
  }
});

// ---- Apuntes: subida real de archivo ----

// PNG 4x4 real y válido, generado con `sharp({create:...}).png().toBuffer()` (no
// hand-typed): sharp necesita poder decodificarlo de verdad para el test de preview, no
// solo pasar el sniffing de magic bytes.
const PNG_1X1 = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEUlEQVQImWMIWXYDjhiI4wAA7VIdIen/5p4AAAAASUVORK5CYII=",
  "base64",
);
const PDF_MINIMO = Buffer.from(
  "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n" +
    "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>",
);

test("POST /api/me/publicar/apuntes sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/apuntes`, { method: "POST", body: new FormData() });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/apuntes sin archivo devuelve 400", async () => {
  const fd = new FormData();
  fd.append("titulo", "Apunte de prueba");
  fd.append("descripcion", "Descripción de prueba");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/apuntes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/apuntes con contenido que NO coincide con la extensión declarada devuelve 400 (sniffing real, no confía en el nombre del archivo)", async () => {
  const fd = new FormData();
  fd.append("titulo", "Apunte con archivo falso");
  fd.append("descripcion", "Descripción de prueba");
  fd.append("archivo", new Blob([Buffer.from("esto no es un pdf real, es texto plano")], { type: "application/pdf" }), "falso.pdf");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/apuntes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.match(body.error, /no coincide con su extensión/);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/apuntes con una imagen PNG real: crea el apunte (201) y genera preview real en disco vía sharp", async () => {
  const fd = new FormData();
  fd.append("titulo", "Apunte de prueba con imagen");
  fd.append("descripcion", "Descripción de prueba para el apunte con imagen real.");
  fd.append("materia", "calculo");
  fd.append("nivelAcademico", "universitario");
  fd.append("archivo", new Blob([PNG_1X1], { type: "image/png" }), "portada.png");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/apuntes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 201);
    const body = (await res.json()) as { success: boolean; id: number };
    assert.equal(body.success, true);
    apunteIdsCreados.push(body.id);

    const [rows] = await pool.query<RowDataPacket[]>("SELECT archivo, preview, portada, materia, id_alumno FROM apuntes WHERE id = ?", [body.id]);
    const fila = rows[0] as { archivo: string; preview: string | null; portada: string | null; materia: string; id_alumno: number };
    assert.equal(fila.id_alumno, USUARIO_ID);
    assert.equal(fila.materia, "calculo");
    assert.ok(fila.archivo.endsWith(".png"));
    archivosApuntesCreados.push(fila.archivo);
    assert.equal(fila.preview, `${body.id}.webp`);
    assert.equal(fila.portada, `${body.id}.webp`);
    archivosPreviewCreados.push(`${body.id}.webp`);

    const statArchivo = await fs.stat(path.join(env.uploadDir, "apuntes", fila.archivo));
    assert.ok(statArchivo.isFile());
    const statPreview = await fs.stat(path.join(env.uploadDir, "preview", `${body.id}.webp`));
    assert.ok(statPreview.isFile(), "el preview real debe existir en disco, generado por sharp");
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/apuntes con un PDF SIN blob de preview adjunto: crea el apunte (201) sin portada (pdf.js pudo no haber renderizado nada)", async () => {
  const fd = new FormData();
  fd.append("titulo", "Apunte de prueba con PDF sin preview");
  fd.append("descripcion", "Descripción de prueba para el apunte en PDF sin preview adjunto.");
  fd.append("archivo", new Blob([PDF_MINIMO], { type: "application/pdf" }), "documento.pdf");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/apuntes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 201);
    const body = (await res.json()) as { success: boolean; id: number };
    apunteIdsCreados.push(body.id);

    const [rows] = await pool.query<RowDataPacket[]>("SELECT archivo, preview, portada FROM apuntes WHERE id = ?", [body.id]);
    const fila = rows[0] as { archivo: string; preview: string | null; portada: string | null };
    assert.ok(fila.archivo.endsWith(".pdf"));
    archivosApuntesCreados.push(fila.archivo);
    assert.equal(fila.preview, null);
    assert.equal(fila.portada, null);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/apuntes con un PDF Y un blob de preview (render client-side de pdf.js): genera portada real vía sharp", async () => {
  const fd = new FormData();
  fd.append("titulo", "Apunte de prueba con PDF y preview");
  fd.append("descripcion", "Descripción de prueba para el apunte en PDF con preview real adjunto.");
  fd.append("archivo", new Blob([PDF_MINIMO], { type: "application/pdf" }), "documento.pdf");
  fd.append("preview", new Blob([PNG_1X1], { type: "image/jpeg" }), "preview.jpg");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/apuntes`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 201);
    const body = (await res.json()) as { success: boolean; id: number };
    apunteIdsCreados.push(body.id);

    const [rows] = await pool.query<RowDataPacket[]>("SELECT archivo, preview, portada FROM apuntes WHERE id = ?", [body.id]);
    const fila = rows[0] as { archivo: string; preview: string | null; portada: string | null };
    assert.ok(fila.archivo.endsWith(".pdf"));
    archivosApuntesCreados.push(fila.archivo);
    assert.equal(fila.preview, `${body.id}.webp`);
    assert.equal(fila.portada, `${body.id}.webp`);
    archivosPreviewCreados.push(`${body.id}.webp`);

    const statPreview = await fs.stat(path.join(env.uploadDir, "preview", `${body.id}.webp`));
    assert.ok(statPreview.isFile(), "el preview del PDF (a partir del blob subido) debe existir en disco, normalizado a webp por sharp");
  } finally {
    await close();
  }
});

// ---- Servicios: video de presentación ----

let servicioIdVideo: number;

const MP4_MINIMO = Buffer.concat([
  Buffer.from([0x00, 0x00, 0x00, 0x18]),
  Buffer.from("ftyp", "ascii"),
  Buffer.from("isom", "ascii"),
  Buffer.from([0x00, 0x00, 0x02, 0x00]),
  Buffer.from("isomiso2mp41", "ascii"),
]);
const WEBM_MINIMO = Buffer.from([0x1a, 0x45, 0xdf, 0xa3, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08]);
const JPEG_MINIMO = Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46]);

test("(setup video) crea un servicio real de prueba, directo por SQL", async () => {
  const [result] = await pool.query<ResultSetHeader>(
    `INSERT INTO servicios (alumno_id, institucion, titulo, descripcion, nombre_oferente, categoria, modalidad, precio, correo, imagen, estado, fecha_publicacion)
     VALUES (?, 'Test', 'Servicio para prueba de video', 'Descripción de prueba', 'Test', 'Asesoría', 'Online', 15000, 'test@test.cl', '', 'pendiente', NOW())`,
    [USUARIO_ID],
  );
  servicioIdVideo = result.insertId;
  servicioIdsCreados.push(servicioIdVideo);
});

test("POST /api/me/publicar/servicios/:id/video sin sesión devuelve 401", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, { method: "POST", body: new FormData() });
    assert.equal(res.status, 401);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/video sin consentimiento devuelve 400", async () => {
  const fd = new FormData();
  fd.append("video", new Blob([MP4_MINIMO], { type: "video/mp4" }), "video.mp4");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.match(body.error, /consentimiento/);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/video con extensión no permitida devuelve 400", async () => {
  const fd = new FormData();
  fd.append("consentimientoRrss", "1");
  fd.append("video", new Blob([MP4_MINIMO], { type: "video/mp4" }), "video.avi");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 400);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/video con contenido que NO es un video real devuelve 400 (sniffing real)", async () => {
  const fd = new FormData();
  fd.append("consentimientoRrss", "1");
  fd.append("video", new Blob([Buffer.from("esto no es un video")], { type: "video/mp4" }), "video.mp4");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 400);
    const body = (await res.json()) as { error: string };
    assert.match(body.error, /no es un video válido/);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/video de un servicio que NO es del usuario devuelve 403", async () => {
  const fd = new FormData();
  fd.append("consentimientoRrss", "1");
  fd.append("video", new Blob([MP4_MINIMO], { type: "video/mp4" }), "video.mp4");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_OTRO}` },
      body: fd,
    });
    assert.equal(res.status, 403);
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/video con MP4 + thumb JPEG reales: sube el video (200), guarda ambos archivos y actualiza el estado", async () => {
  const fd = new FormData();
  fd.append("consentimientoRrss", "1");
  fd.append("video", new Blob([MP4_MINIMO], { type: "video/mp4" }), "presentacion.mp4");
  fd.append("thumb", new Blob([JPEG_MINIMO], { type: "image/jpeg" }), "thumb.jpg");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { ok: boolean; videoPath: string };
    assert.equal(body.ok, true);
    assert.ok(body.videoPath.endsWith(".mp4"));
    archivosVideosCreados.push(body.videoPath);

    const [rows] = await pool.query<RowDataPacket[]>(
      "SELECT video_path, video_thumb_path, video_estado, video_consentimiento_rrss FROM servicios WHERE id = ?",
      [servicioIdVideo],
    );
    const fila = rows[0] as { video_path: string; video_thumb_path: string; video_estado: string; video_consentimiento_rrss: number };
    assert.equal(fila.video_path, body.videoPath);
    assert.equal(fila.video_estado, "pendiente");
    assert.equal(fila.video_consentimiento_rrss, 1);
    assert.ok(fila.video_thumb_path?.endsWith("_thumb.jpg"));
    archivosThumbsCreados.push(fila.video_thumb_path);

    const statVideo = await fs.stat(path.join(env.uploadDir, "videos_servicios", body.videoPath));
    assert.ok(statVideo.isFile());
    const statThumb = await fs.stat(path.join(env.uploadDir, "servicios", fila.video_thumb_path));
    assert.ok(statThumb.isFile());
  } finally {
    await close();
  }
});

test("POST /api/me/publicar/servicios/:id/video reemplaza el video anterior: el archivo viejo se borra del disco", async () => {
  const [antes] = await pool.query<RowDataPacket[]>("SELECT video_path FROM servicios WHERE id = ?", [servicioIdVideo]);
  const videoAnterior = (antes[0] as { video_path: string }).video_path;
  const rutaAnterior = path.join(env.uploadDir, "videos_servicios", videoAnterior);
  assert.ok(
    await fs
      .stat(rutaAnterior)
      .then(() => true)
      .catch(() => false),
    "precondición: el video anterior debe existir antes del reemplazo",
  );

  const fd = new FormData();
  fd.append("consentimientoRrss", "1");
  fd.append("video", new Blob([WEBM_MINIMO], { type: "video/webm" }), "nuevo.webm");
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/me/publicar/servicios/${servicioIdVideo}/video`, {
      method: "POST",
      headers: { Cookie: `PHPSESSID=${SESSION_VALID}` },
      body: fd,
    });
    assert.equal(res.status, 200);
    const body = (await res.json()) as { videoPath: string };
    archivosVideosCreados.push(body.videoPath);
    assert.notEqual(body.videoPath, videoAnterior);

    const existeAnterior = await fs
      .stat(rutaAnterior)
      .then(() => true)
      .catch(() => false);
    assert.equal(existeAnterior, false, "el video reemplazado debe haberse borrado del disco");
  } finally {
    await close();
  }
});
