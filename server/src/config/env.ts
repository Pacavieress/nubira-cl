import "dotenv/config";

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
  db: {
    host: required("DB_HOST"),
    port: Number(process.env.DB_PORT ?? 3306),
    user: required("DB_USER"),
    // DB_PASSWORD puede ser vacío legítimamente (root local sin clave) — no usar required() acá.
    password: process.env.DB_PASSWORD ?? "",
    database: required("DB_NAME"),
  },
};
