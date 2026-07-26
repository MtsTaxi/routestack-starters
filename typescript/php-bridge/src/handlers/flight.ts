import { Router, type Request, type Response } from "express";
import { callTool, type McpToolResult } from "../mcp-client.js";
import { logger } from "../logger.js";

export const flightRouter = Router();

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

function num(value: unknown, fallback = 0): number {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function requireFields(
  body: Record<string, unknown>,
  fields: string[],
): string | null {
  const missing = fields.filter((f) => !body[f] && body[f] !== 0);
  return missing.length ? `Missing required fields: ${missing.join(", ")}` : null;
}

// ---------------------------------------------------------------------------
// POST /flight/session
// No body required.
// Calls: flight_session
// Returns: { sessionId }
// ---------------------------------------------------------------------------

flightRouter.post("/session", async (_req: Request, res: Response) => {
  try {
    const result = await callTool("flight_session", {});
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    const sessionId =
      (json as any)?.sessionId ??
      (json as any)?.result?.sessionId ??
      null;

    if (!sessionId) {
      res
        .status(502)
        .json({ success: false, message: "No sessionId returned", details: json });
      return;
    }

    res.json({ success: true, sessionId });
  } catch (err) {
    logger.error("flight/session", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /flight/locations
// Body: { sessionId, query }
// Calls: flight_locations
// Returns: { locations[] }
// ---------------------------------------------------------------------------

flightRouter.post("/locations", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, ["sessionId", "query"]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const result = await callTool("flight_locations", {
      sessionId: body.sessionId,
      query: body.query,
    });
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    const locations =
      (json as any)?.result ??
      (Array.isArray(json) ? json : []);

    res.json({ success: true, locations });
  } catch (err) {
    logger.error("flight/locations", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /flight/search
// Body: { sessionId, originCode, destinationCode, departureDate,
//         returnDate?, adults, children?, infants?,
//         cabinClass?, currency? }
// Calls: flight_search
// Returns: { flights[], correlationId, sessionId, searchFilterObj }
// ---------------------------------------------------------------------------

flightRouter.post("/search", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, [
    "sessionId",
    "originCode",
    "destinationCode",
    "departureDate",
    "adults",
  ]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const args: Record<string, unknown> = {
      sessionId: body.sessionId,
      originCode: body.originCode,
      destinationCode: body.destinationCode,
      departureDate: body.departureDate,
      adults: num(body.adults, 1),
      children: num(body.children, 0),
      infants: num(body.infants, 0),
      cabinClass: body.cabinClass ?? "Economy",
      currency: body.currency ?? "GBP",
    };
    if (body.returnDate) args.returnDate = body.returnDate;

    const result = await callTool("flight_search", args);
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    const flights: unknown[] = (json as any)?.result?.flights ?? [];

    if (!flights.length) {
      res
        .status(422)
        .json({ success: false, message: "No flight results were returned." });
      return;
    }

    res.json({
      success: true,
      sessionId: (json as any)?.sessionId ?? body.sessionId,
      correlationId: (json as any)?.correlationId ?? null,
      searchFilterObj: (json as any)?.searchFilterObj ?? null,
      flights: flights.slice(0, 10),
    });
  } catch (err) {
    logger.error("flight/search", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /flight/revalidate
// Body: { sessionId, correlationId, fareSourceCode, searchFilterObj }
// Calls: flight_revalidate
// Returns: revalidated flight or 410 if expired
// ---------------------------------------------------------------------------

flightRouter.post("/revalidate", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, [
    "sessionId",
    "correlationId",
    "fareSourceCode",
    "searchFilterObj",
  ]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const result = await callTool("flight_revalidate", {
      sessionId: body.sessionId,
      correlationId: body.correlationId,
      fareSourceCode: body.fareSourceCode,
      searchFilterObj: body.searchFilterObj,
    });
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res.status(502).json({
        success: false,
        message: "Flight revalidation failed",
        details: json,
      });
      return;
    }

    if ((json as any)?.expired === true || (json as any)?.result?.expired === true) {
      res.status(410).json({
        success: false,
        message: "This fare has expired. Please search again.",
      });
      return;
    }

    res.json({ success: true, data: (json as any)?.result ?? json });
  } catch (err) {
    logger.error("flight/revalidate", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /flight/checkout
// Body: { sessionId, fareSourceCode, ... }
// Calls: flight_get_checkout_url
// Returns: { checkoutUrl }
// ---------------------------------------------------------------------------

flightRouter.post("/checkout", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, ["sessionId", "fareSourceCode"]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const result = await callTool("flight_get_checkout_url", {
      sessionId: body.sessionId,
      fareSourceCode: body.fareSourceCode,
    });
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      res.status(502).json({
        success: false,
        message: "Checkout URL generation failed",
        details: json,
      });
      return;
    }

    const url =
      (json as any)?.url ??
      (json as any)?.checkoutUrl ??
      (json as any)?.result?.url ??
      (json as any)?.result?.checkoutUrl;

    if (!url || typeof url !== "string") {
      res.status(502).json({
        success: false,
        message: "No checkout URL returned from RouteStack",
        details: json,
      });
      return;
    }

    res.json({ success: true, checkoutUrl: url });
  } catch (err) {
    logger.error("flight/checkout", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});
