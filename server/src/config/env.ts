import "dotenv/config";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function required(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === "") {
    throw new Error(`Falta la variable de entorno ${name} (revisa server/.env contra server/.env.example)`);
  }
  return value;
}

export const env = {
  port: Number(process.env.PORT ?? 4000),
  nodeEnv: process.env.NODE_ENV ?? "development",
  // Lista de orígenes permitidos separados por coma. Vacío por defecto = deniega todo
  // origin cross-site (ver src/app.ts) — nunca "*" como default implícito.
  corsOrigin: process.env.CORS_ORIGIN ?? "",
  // Base para resolver rutas de imagen relativas (/upload/..., /app/perfil/fotos/...) que
  // devuelve la API — esas rutas asumen el contexto del sitio PHP, que sirve de un origen
  // distinto al de esta API. Sin esto, un consumidor externo (web/) recibiría <img src>
  // rotos apuntando a su propio origen en vez de al sitio real.
  assetsBaseUrl: required("ASSETS_BASE_URL"),
  // Directorio real donde escribir los archivos subidos (apuntes, previews) — el mismo
  // /upload/ que sirve el sitio PHP. Asume filesystem compartido (cierto hoy en local por
  // coincidencia de entorno, ver nota ya existente en src/lib/media.ts sobre esta misma
  // asunción para LECTURA); la topología de hosting en producción sigue sin decidir.
  // Default: resuelto relativo a este archivo (server/src/config/env.ts -> repo root/upload).
  uploadDir: process.env.UPLOAD_DIR ?? path.resolve(__dirname, "../../../upload"),
  db: {
    host: required("DB_HOST"),
    port: Number(process.env.DB_PORT ?? 3306),
    user: required("DB_USER"),
    // DB_PASSWORD puede ser vacío legítimamente (root local sin clave) — no usar required() acá.
    password: process.env.DB_PASSWORD ?? "",
    database: required("DB_NAME"),
  },
};
