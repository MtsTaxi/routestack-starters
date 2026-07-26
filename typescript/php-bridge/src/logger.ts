export type LogLevel = "debug" | "info" | "warn" | "error";

const LEVEL_ORDER: Record<LogLevel, number> = {
  debug: 0,
  info: 1,
  warn: 2,
  error: 3,
};

const minLevel: LogLevel = (
  (process.env.LOG_LEVEL?.trim().toLowerCase() as LogLevel | undefined) ?? "info"
) in LEVEL_ORDER
  ? ((process.env.LOG_LEVEL!.trim().toLowerCase()) as LogLevel)
  : "info";

function write(level: LogLevel, message: string, extra?: unknown): void {
  if (LEVEL_ORDER[level] < LEVEL_ORDER[minLevel]) return;
  const line = extra !== undefined
    ? `[${level.toUpperCase()}] ${message} ${JSON.stringify(extra)}`
    : `[${level.toUpperCase()}] ${message}`;
  if (level === "error" || level === "warn") {
    console.error(line);
  } else {
    console.log(line);
  }
}

export const logger = {
  debug: (msg: string, extra?: unknown) => write("debug", msg, extra),
  info:  (msg: string, extra?: unknown) => write("info",  msg, extra),
  warn:  (msg: string, extra?: unknown) => write("warn",  msg, extra),
  error: (msg: string, extra?: unknown) => write("error", msg, extra),
};
