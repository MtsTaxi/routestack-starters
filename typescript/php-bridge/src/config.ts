import "dotenv/config";

function requireEnv(name: string): string {
  const val = process.env[name]?.trim();
  if (!val) {
    console.error(`Error: ${name} is required. Set it in your .env file.`);
    process.exit(1);
  }
  return val;
}

function optionalInt(name: string, fallback: number): number {
  const raw = process.env[name]?.trim();
  if (!raw) return fallback;
  const n = parseInt(raw, 10);
  if (!Number.isFinite(n) || n < 1) {
    console.error(`Error: ${name} must be a positive integer.`);
    process.exit(1);
  }
  return n;
}

export const config = {
  routestack: {
    apiKey: requireEnv("ROUTESTACK_API_KEY"),
    apiSecret: process.env.ROUTESTACK_API_SECRET?.trim() ?? "",
    mcpUrl:
      process.env.ROUTESTACK_MCP_URL?.trim() || "https://mcp.routestack.ai/sse",
  },
  bridge: {
    secret: requireEnv("BRIDGE_SECRET"),
    host: process.env.HOST?.trim() || "127.0.0.1",
    port: optionalInt("PORT", 3001),
    requestTimeoutMs: optionalInt("REQUEST_TIMEOUT_MS", 25_000),
  },
} as const;
