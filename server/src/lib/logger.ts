type Level = "info" | "warn" | "error";

function line(level: Level, message: string, meta?: Record<string, unknown>): void {
  const entry = {
    time: new Date().toISOString(),
    level,
    message,
    ...(meta ?? {}),
  };
  const out = level === "error" ? console.error : level === "warn" ? console.warn : console.log;
  out(JSON.stringify(entry));
}

export const logger = {
  info: (message: string, meta?: Record<string, unknown>) => line("info", message, meta),
  warn: (message: string, meta?: Record<string, unknown>) => line("warn", message, meta),
  error: (message: string, meta?: Record<string, unknown>) => line("error", message, meta),
};
