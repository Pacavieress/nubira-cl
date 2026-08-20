import cookieParser from "cookie-parser";
import cors from "cors";
import express, { type Express } from "express";
import { env } from "./config/env.js";
import { errorHandler, notFoundHandler } from "./lib/errors.js";
import { apuntesRouter } from "./modules/apuntes/apuntes.routes.js";
import { authRouter } from "./modules/auth/auth.routes.js";
import { categoriasRouter } from "./modules/categorias/categorias.routes.js";
import { favoritosRouter } from "./modules/favoritos/favoritos.routes.js";
import { healthRouter } from "./modules/health/health.routes.js";
import { homeRouter } from "./modules/home/home.routes.js";
import { landingsRouter } from "./modules/landings/landings.routes.js";
import { serviciosRouter } from "./modules/servicios/servicios.routes.js";
import { tutoresRouter } from "./modules/tutores/tutores.routes.js";

export function createApp(): Express {
  const app = express();

  // CORS_ORIGIN vacío => allowedOrigins=[] => origin:false => deniega todo origin
  // cross-site (el navegador bloquea la respuesta por falta de header ACAO). Nunca "*"
  // como default implícito — hay que configurar CORS_ORIGIN explícitamente para abrir algo.
  const allowedOrigins = env.corsOrigin
    .split(",")
    .map((origin) => origin.trim())
    .filter(Boolean);
  app.use(cors({ origin: allowedOrigins.length > 0 ? allowedOrigins : false }));

  app.use(express.json());
  app.use(cookieParser());
  app.use("/health", healthRouter);
  app.use("/api/categorias", categoriasRouter);
  app.use("/api/home", homeRouter);
  app.use("/api/landings", landingsRouter);
  app.use("/api/apuntes", apuntesRouter);
  app.use("/api/servicios", serviciosRouter);
  app.use("/api/tutores", tutoresRouter);
  app.use("/api/me/favoritos", favoritosRouter);
  app.use("/api", authRouter);

  app.use(notFoundHandler);
  app.use(errorHandler);

  return app;
}
