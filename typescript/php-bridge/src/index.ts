import express from "express";
import { config } from "./config.js";
import { connectMcp, disconnectMcp } from "./mcp-client.js";
import { authMiddleware } from "./middleware/auth.js";
import { hotelRouter } from "./handlers/hotel.js";
import { flightRouter } from "./handlers/flight.js";
import { carRouter } from "./handlers/car.js";
import { logger } from "./logger.js";

// ---------------------------------------------------------------------------
// App setup
// ---------------------------------------------------------------------------

const app = express();
app.disable("x-powered-by");
app.use(express.json({ limit: "512kb" }));

// Health check — no authentication so cPanel can probe it
app.get("/health", (_req, res) => {
  res.json({ status: "ok", service: "routestack-php-bridge" });
});

// All travel routes require the shared bridge secret
app.use(authMiddleware);
app.use("/hotel", hotelRouter);
app.use("/flight", flightRouter);
app.use("/car", carRouter);

// Catch-all for unknown routes
app.use((_req, res) => {
  res.status(404).json({ success: false, message: "Route not found" });
});

// ---------------------------------------------------------------------------
// Startup
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
  logger.info("RouteStack PHP Bridge starting");
  logger.info(`MCP endpoint: ${config.routestack.mcpUrl}`);
  logger.info(
    `Listening on ${config.bridge.host}:${config.bridge.port} (internal only)`,
  );

  logger.info("Connecting to RouteStack MCP server...");
  await connectMcp();
  logger.info("MCP connected. Bridge ready.");

  app.listen(config.bridge.port, config.bridge.host, () => {
    logger.info(
      `Bridge running at http://${config.bridge.host}:${config.bridge.port}`,
    );
  });
}

// ---------------------------------------------------------------------------
// Graceful shutdown
// ---------------------------------------------------------------------------

async function shutdown(signal: string): Promise<void> {
  logger.info(`Received ${signal} — shutting down`);
  await disconnectMcp();
  logger.info("Bridge stopped");
  process.exit(0);
}

process.on("SIGINT",  () => void shutdown("SIGINT"));
process.on("SIGTERM", () => void shutdown("SIGTERM"));

process.on("uncaughtException", (err) => {
  logger.error(`Uncaught exception: ${err.message}`);
  void shutdown("uncaughtException");
});

process.on("unhandledRejection", (reason) => {
  const msg = reason instanceof Error ? reason.message : String(reason);
  logger.error(`Unhandled rejection: ${msg}`);
  void shutdown("unhandledRejection");
});

main().catch((err) => {
  logger.error(`Fatal startup error: ${err instanceof Error ? err.message : err}`);
  process.exit(1);
});
