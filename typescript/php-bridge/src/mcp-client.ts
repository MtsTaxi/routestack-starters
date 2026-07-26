import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";
import { SSEClientTransport } from "@modelcontextprotocol/sdk/client/sse.js";
import crypto from "node:crypto";
import { config } from "./config.js";
import { logger } from "./logger.js";

export interface McpTool {
  name: string;
  description: string;
  inputSchema: Record<string, unknown>;
}

export interface McpToolResult {
  content: Array<{ type: string; text?: string; [key: string]: unknown }>;
  isError?: boolean;
}

// ---------------------------------------------------------------------------
// Module-level singleton — persists for the lifetime of the process
// ---------------------------------------------------------------------------

let client: Client | null = null;
let cachedPartnerToken: string | null = null;
let connecting = false;

// ---------------------------------------------------------------------------
// Partner-token authentication
// ---------------------------------------------------------------------------

async function getPartnerToken(): Promise<string> {
  if (cachedPartnerToken) return cachedPartnerToken;

  const { apiKey, apiSecret, mcpUrl } = config.routestack;

  if (!apiSecret) {
    // Simple API-key-as-bearer fallback (not recommended for production)
    return apiKey;
  }

  const ts = Math.floor(Date.now() / 1000);
  const nonce = crypto.randomUUID();
  const hmac = crypto
    .createHmac("sha256", apiSecret)
    .update(`${apiKey}:${ts}:${nonce}`)
    .digest("base64url");

  const tokenUrl = new URL("/mcp/auth/partner-token", new URL(mcpUrl).origin);

  const res = await fetch(tokenUrl.toString(), {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ apiKey, hmac, timestamp: ts, nonce }),
  });

  const text = await res.text();
  if (!res.ok) {
    throw new Error(`Partner-token request failed (${res.status}): ${text}`);
  }

  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(`Partner-token response was not JSON: ${text}`);
  }

  const token =
    (data as { token?: string }).token ??
    (data as { accessToken?: string }).accessToken ??
    (data as { partnerToken?: string }).partnerToken ??
    (data as { jwt?: string }).jwt;

  if (!token || typeof token !== "string") {
    throw new Error(`Partner-token response missing token field: ${text}`);
  }

  cachedPartnerToken = token;
  return token;
}

// ---------------------------------------------------------------------------
// Connect (called once at startup; reconnects automatically on failure)
// ---------------------------------------------------------------------------

export async function connectMcp(): Promise<void> {
  if (connecting) return;
  connecting = true;

  try {
    const { mcpUrl } = config.routestack;
    const url = new URL(mcpUrl);
    const token = await getPartnerToken();

    const headers: Record<string, string> = {
      Authorization: "Bearer " + token,
    };

    client = new Client({ name: "routestack-php-bridge", version: "0.1.0" });

    try {
      const transport = new StreamableHTTPClientTransport(url, {
        requestInit: { headers },
      });
      await client.connect(transport);
      logger.info(`MCP connected via Streamable HTTP — ${mcpUrl}`);
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      const isTransportMismatch =
        msg.includes("404") ||
        msg.includes("405") ||
        msg.includes("Not Found") ||
        msg.includes("Method Not Allowed");

      if (!isTransportMismatch) throw err;

      // SSE fallback
      await client.close().catch(() => {});
      client = new Client({ name: "routestack-php-bridge", version: "0.1.0" });
      const sseTransport = new SSEClientTransport(url, {
        requestInit: { headers },
      });
      await client.connect(sseTransport);
      logger.info(`MCP connected via SSE fallback — ${mcpUrl}`);
    }
  } finally {
    connecting = false;
  }
}

// ---------------------------------------------------------------------------
// Ensure a live client, reconnecting transparently if the connection dropped
// ---------------------------------------------------------------------------

async function ensureClient(): Promise<Client> {
  if (!client) {
    logger.warn("MCP client not connected — reconnecting");
    await connectMcp();
  }
  return client!;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

export async function listTools(): Promise<McpTool[]> {
  const c = await ensureClient();
  const allTools: McpTool[] = [];
  let cursor: string | undefined;

  do {
    const result = await c.listTools({ cursor });
    allTools.push(
      ...result.tools.map((t) => ({
        name: t.name,
        description: t.description ?? "",
        inputSchema: (t.inputSchema ?? {}) as Record<string, unknown>,
      })),
    );
    cursor = result.nextCursor;
  } while (cursor);

  return allTools;
}

export async function callTool(
  name: string,
  args: Record<string, unknown>,
): Promise<McpToolResult> {
  let c = await ensureClient();

  try {
    const result = await c.callTool({ name, arguments: args });
    return {
      content: (result.content ?? []) as McpToolResult["content"],
      isError: result.isError as boolean | undefined,
    };
  } catch (err) {
    // If the connection dropped mid-request, try once more after reconnecting
    const msg = err instanceof Error ? err.message : String(err);
    if (msg.includes("not connected") || msg.includes("closed")) {
      logger.warn("MCP call failed (connection lost) — reconnecting");
      client = null;
      cachedPartnerToken = null;
      c = await ensureClient();
      const result = await c.callTool({ name, arguments: args });
      return {
        content: (result.content ?? []) as McpToolResult["content"],
        isError: result.isError as boolean | undefined,
      };
    }
    throw err;
  }
}

export async function disconnectMcp(): Promise<void> {
  if (client) {
    await client.close().catch(() => {});
    client = null;
  }
}
