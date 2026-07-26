import { Router, type Request, type Response } from "express";
import { callTool, listTools, type McpToolResult } from "../mcp-client.js";
import { logger } from "../logger.js";

export const carRouter = Router();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function extractJson(result: McpToolResult): unknown {
  for (const item of result.content) {
    if (typeof item.text === "string" && item.text.trim()) {
      try {
        return JSON.parse(item.text);
      } catch {
        // not JSON — continue
      }
    }
  }
  return null;
}

function toMessage(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function requireFields(
  body: Record<string, unknown>,
  fields: string[],
): string | null {
  const missing = fields.filter((f) => !body[f] && body[f] !== 0);
  return missing.length ? `Missing required fields: ${missing.join(", ")}` : null;
}

// ---------------------------------------------------------------------------
// POST /car/locations
// Body: { query: string }
// Discovers the appropriate tool at runtime from the MCP tool list.
// ---------------------------------------------------------------------------

carRouter.post("/locations", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  if (!body.query || typeof body.query !== "string") {
    res.status(400).json({ success: false, message: "query is required" });
    return;
  }

  try {
    const tool = await findCarTool("location");
    if (!tool) {
      res.status(501).json({
        success: false,
        message: "Car hire location search is not available on this RouteStack plan.",
      });
      return;
    }

    const result = await callTool(tool, { query: body.query });
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    res.json({ success: true, locations: (json as any)?.result ?? json });
  } catch (err) {
    logger.error("car/locations", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /car/search
// Body: { pickupLocationId, dropoffLocationId?, pickupDate, dropoffDate,
//         driverAge?, currency? }
// ---------------------------------------------------------------------------

carRouter.post("/search", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, [
    "pickupLocationId",
    "pickupDate",
    "dropoffDate",
  ]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const tool = await findCarTool("search");
    if (!tool) {
      res.status(501).json({
        success: false,
        message: "Car hire search is not available on this RouteStack plan.",
      });
      return;
    }

    const args: Record<string, unknown> = {
      pickupLocationId: body.pickupLocationId,
      pickupDate: body.pickupDate,
      dropoffDate: body.dropoffDate,
      currency: body.currency ?? "GBP",
    };
    if (body.dropoffLocationId) args.dropoffLocationId = body.dropoffLocationId;
    if (body.driverAge) args.driverAge = Number(body.driverAge);

    const result = await callTool(tool, args);
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    const cars: unknown[] = (json as any)?.result ?? (Array.isArray(json) ? json : []);

    if (!cars.length) {
      res
        .status(422)
        .json({ success: false, message: "No car hire results were returned." });
      return;
    }

    res.json({ success: true, cars });
  } catch (err) {
    logger.error("car/search", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /car/checkout
// Body: { vehicleId, ... } (passthrough)
// ---------------------------------------------------------------------------

carRouter.post("/checkout", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;

  try {
    const tool = await findCarTool("checkout");
    if (!tool) {
      res.status(501).json({
        success: false,
        message: "Car hire checkout is not available on this RouteStack plan.",
      });
      return;
    }

    const result = await callTool(tool, body);
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    const url =
      (json as any)?.url ??
      (json as any)?.checkoutUrl ??
      (json as any)?.result?.url;

    if (!url) {
      res.status(502).json({
        success: false,
        message: "No checkout URL returned",
        details: json,
      });
      return;
    }

    res.json({ success: true, checkoutUrl: url });
  } catch (err) {
    logger.error("car/checkout", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// Internal: discover car tool name at runtime from the MCP tool list
// ---------------------------------------------------------------------------

let cachedTools: string[] | null = null;

async function findCarTool(
  intent: "location" | "search" | "checkout",
): Promise<string | null> {
  if (!cachedTools) {
    const tools = await listTools();
    cachedTools = tools.map((t) => t.name);
  }

  const candidates = cachedTools.filter((n) => n.toLowerCase().includes("car"));

  switch (intent) {
    case "location":
      return candidates.find((n) => n.includes("location")) ?? null;
    case "search":
      return (
        candidates.find((n) => n.includes("search")) ??
        (candidates.length ? candidates[0] : null)
      );
    case "checkout":
      return candidates.find((n) => n.includes("checkout")) ?? null;
  }
}
