import { NextResponse } from "next/server";
import { getApuntes, getServicios } from "@/lib/api";

// Puerto de cargarRecomendaciones() en desafio.php:368-380 — ahí el navegador pide
// fragmentos HTML directo a cargar_servicios.php/cargar_apuntes.php. Acá, en vez de exponer
// /api/servicios y /api/apuntes de server/ directo al navegador (CORS_ORIGIN cerrado a
// propósito, ver server/src/app.ts), este Route Handler llama esos mismos endpoints
// server-to-server reutilizando getServicios/getApuntes ya existentes en lib/api.ts, y le
// devuelve al Client Component solo el JSON combinado que necesita. Público a propósito
// (sin fetchConSesion) — mismas recomendaciones para cualquier visitante, igual que las
// páginas de listado reales.
export async function GET(req: Request) {
  const { searchParams } = new URL(req.url);
  const categoria = searchParams.get("categoria");
  const materia = searchParams.get("materia");

  const [servicios, apuntes] = await Promise.all([
    categoria ? getServicios({ categoria }) : Promise.resolve({ data: [], meta: { page: 1, limit: 0, hayMas: false } }),
    materia ? getApuntes({ materia }) : Promise.resolve({ data: [], meta: { page: 1, limit: 0, hayMas: false } }),
  ]);

  return NextResponse.json({ servicios: servicios.data, apuntes: apuntes.data });
}
