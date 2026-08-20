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

interface LandingBody {
  categoria: string;
  seo: { titulo: string; descripcion: string; h1: string; intro: string | null; noindex: boolean };
  total: number;
  servicios: { id: number; categoria: string }[];
  faqs: { pregunta: string; respuesta: string }[];
}

test("GET /api/landings/clases/:slug inexistente devuelve 404", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/no-existe-esta-categoria`);
    assert.equal(res.status, 404);
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/matematicas: servicios reales, todos con categoria='Matemáticas'", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/matematicas`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "Matemáticas");
    assert.ok(body.total > 0, "debería haber servicios reales de Matemáticas en la BD local");
    assert.equal(body.servicios.length, body.total);
    for (const s of body.servicios) assert.equal(s.categoria, "Matemáticas");
  } finally {
    await close();
  }
});

// Puerto de landing_categoria.php:81-89: "Cálculo" no es un valor real de
// servicios.categoria (no está en CATEGORIAS_VALIDAS) — el match es por filtro_titulo
// (LIKE '%calcul%' desde seo_categorias_contenido), no por categoria exacta.
test("GET /api/landings/clases/calculo: usa filtro_titulo (LIKE), no categoria exacta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/calculo`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "Cálculo");
    assert.ok(body.total > 0, "debería haber servicios reales con 'calcul' en el título");
    for (const s of body.servicios) assert.ok(s.categoria !== "Cálculo", "no existe la categoría literal 'Cálculo' en servicios.categoria");
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/paes: refuerzo amplio (LIKE + es_paes=1), no categoria exacta", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/paes`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.categoria, "PAES");
    assert.ok(body.total > 0, "debería haber servicios reales relacionados con PAES en la BD local");
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/tesis: FAQs solo pobladas para Tesis (único caso real hoy)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/tesis`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.faqs.length, 4);
  } finally {
    await close();
  }
});

test("GET /api/landings/clases/matematicas: sin FAQs (no está en el catálogo curado)", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/matematicas`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.faqs.length, 0);
  } finally {
    await close();
  }
});

// Puerto de landing_categoria.php:122: noindex = (total < 3 || !indexable) — verificado
// con una categoría real que tiene fila indexable=1 en seo_categorias_contenido pero
// menos de 3 servicios reales (Física, confirmado 1 servicio en la BD local hoy).
test("GET /api/landings/clases/fisica: noindex=true por total<3 aunque indexable=1", async (t) => {
  const { url, close } = listen();
  const res = await fetch(`${url}/api/landings/clases/fisica`);
  const body = (await res.json()) as LandingBody;
  try {
    assert.equal(res.status, 200);
    if (body.total >= 3) {
      t.skip("la BD local ya tiene >=3 servicios de Física — este caso límite ya no aplica hoy");
      return;
    }
    assert.equal(body.seo.noindex, true);
  } finally {
    await close();
  }
});

// Puerto del default opt-in: sin fila en seo_categorias_contenido, indexable=false
// siempre, sin importar cuántos resultados reales haya.
test("GET /api/landings/clases/lenguaje: sin fila en seo_categorias_contenido -> noindex=true, copy genérico", async () => {
  const { url, close } = listen();
  try {
    const res = await fetch(`${url}/api/landings/clases/lenguaje`);
    const body = (await res.json()) as LandingBody;
    assert.equal(res.status, 200);
    assert.equal(body.seo.noindex, true);
    assert.equal(body.seo.h1, "Clases de Lenguaje en Chile");
  } finally {
    await close();
  }
});
