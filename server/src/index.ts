import { createApp } from "./app.js";
import { env } from "./config/env.js";
import { logger } from "./lib/logger.js";

const app = createApp();

app.listen(env.port, () => {
  logger.info("Servidor Nubira API escuchando", { port: env.port, nodeEnv: env.nodeEnv });
});
