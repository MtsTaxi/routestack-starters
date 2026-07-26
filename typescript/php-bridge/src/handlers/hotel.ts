import { Router, type Request, type Response } from "express";
import { callTool, type McpToolResult } from "../mcp-client.js";
import { logger } from "../logger.js";

export const hotelRouter = Router();

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
// POST /hotel/destinations
// Body: { query: string }
// Calls: hotel_search_destinations
// ---------------------------------------------------------------------------

hotelRouter.post("/destinations", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const query = typeof body.query === "string" ? body.query.trim() : "";

  if (!query) {
    res.status(400).json({ success: false, message: "query is required" });
    return;
  }

  try {
    const result = await callTool("hotel_search_destinations", { query });
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      logger.warn("hotel_search_destinations returned error", json);
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    // Normalise: the SDK returns { result: [...] } or the array directly
    const destinations =
      (json as any)?.result ??
      (Array.isArray(json) ? json : []);

    res.json({ success: true, destinations });
  } catch (err) {
    logger.error("hotel/destinations", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /hotel/search
// Body: { destinationId, checkIn, checkOut, adults, children?, rooms?,
//         currency?, nationality? }
// Calls: hotel_search
// Returns: { hotels[], token, correlationId }
// ---------------------------------------------------------------------------

hotelRouter.post("/search", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, ["destinationId", "checkIn", "checkOut", "adults"]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const result = await callTool("hotel_search", {
      destinationId: body.destinationId,
      checkIn: body.checkIn,
      checkOut: body.checkOut,
      adults: num(body.adults, 1),
      children: num(body.children, 0),
      rooms: num(body.rooms, 1),
      currency: body.currency ?? "GBP",
      nationality: body.nationality ?? "GB",
    });

    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      logger.warn("hotel_search returned error", json);
      res
        .status(502)
        .json({ success: false, message: "RouteStack error", details: json });
      return;
    }

    const resultData = (json as any)?.result;
    const hotels: unknown[] = resultData?.result ?? [];

    if (!hotels.length) {
      res
        .status(422)
        .json({ success: false, message: "No hotel results were returned." });
      return;
    }

    res.json({
      success: true,
      token: resultData?.token ?? null,
      correlationId: resultData?.correlationId ?? null,
      hotels: hotels.map((h: any) => ({
        id: h.id,
        name: h.name,
        starRating: h.starRating,
        ourprice: h.ourprice,
        publishedRate: h.publishedRate,
        currency: h.currency ?? body.currency ?? "GBP",
        saving: h.saving,
        distance: h.distance,
        heroImage: h.heroImage,
        address: h.address ?? null,
        providerName: h.providerName ?? null,
      })),
    });
  } catch (err) {
    logger.error("hotel/search", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /hotel/rooms
// Body: { hotelId, token, correlationId, checkIn, checkOut, adults,
//         children?, rooms?, currency? }
// Calls: hotel_get_rooms_and_rates
// Returns: { rooms[], token, correlationId, hotelId }
// ---------------------------------------------------------------------------

hotelRouter.post("/rooms", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, ["hotelId", "token", "correlationId"]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const args: Record<string, unknown> = {
      hotelId: body.hotelId,
      token: body.token,
      correlationId: body.correlationId,
      adults: num(body.adults, 1),
      children: num(body.children, 0),
      rooms: num(body.rooms, 1),
      currency: body.currency ?? "GBP",
    };
    if (body.checkIn) args.checkIn = body.checkIn;
    if (body.checkOut) args.checkOut = body.checkOut;

    const result = await callTool("hotel_get_rooms_and_rates", args);
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      logger.warn("hotel_get_rooms_and_rates returned error", json);
      res.status(502).json({
        success: false,
        message: "RouteStack error fetching rooms",
        details: json,
      });
      return;
    }

    const resultData = (json as any)?.result;
    const groups: unknown[] = resultData?.groups ?? [];

    const rooms = (groups as any[])
      .flatMap((g: any) => g.rooms ?? [])
      .map((r: any) => ({
        id: r.id,
        name: r.name,
        description: r.description ?? null,
        recommendationId: r.recommendationId,
        rateid: r.rateid ?? null,
        ourprice: r.ourprice,
        publishedRate: r.publishedRate,
        refundable: r.refundable ?? false,
        boardBasis: r.boardBasis ?? null,
      }));

    res.json({
      success: true,
      token: resultData?.token ?? body.token,
      correlationId: resultData?.correlationId ?? body.correlationId,
      hotelId: resultData?.id ?? body.hotelId,
      rooms,
    });
  } catch (err) {
    logger.error("hotel/rooms", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /hotel/revalidate
// Body: { hotelId, token, correlationId, recommendationId, roomId?,
//         publishedRate? }
// Calls: hotel_revalidate_rate
// Returns: revalidated rate + room, or 410 if expired
// ---------------------------------------------------------------------------

hotelRouter.post("/revalidate", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, [
    "hotelId",
    "token",
    "correlationId",
    "recommendationId",
  ]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const args: Record<string, unknown> = {
      hotelId: body.hotelId,
      token: body.token,
      correlationId: body.correlationId,
      recommendationId: body.recommendationId,
    };
    if (body.roomId) args.roomId = body.roomId;
    if (body.publishedRate !== undefined)
      args.publishedRate = num(body.publishedRate);

    const result = await callTool("hotel_revalidate_rate", args);
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      logger.warn("hotel_revalidate_rate returned error", json);
      res.status(502).json({
        success: false,
        message: "Rate revalidation failed",
        details: json,
      });
      return;
    }

    const resultData = (json as any)?.result ?? json;

    // Detect expired offer
    if (resultData?.expired === true || (json as any)?.expired === true) {
      res.status(410).json({
        success: false,
        message: "This offer has expired. Please search again.",
      });
      return;
    }

    res.json({ success: true, data: resultData });
  } catch (err) {
    logger.error("hotel/revalidate", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});

// ---------------------------------------------------------------------------
// POST /hotel/checkout
// Body: { token, correlationId, hotelId, recommendationId, roomId?,
//         publishedRate? }
// Calls: hotel_get_checkout_url
// Returns: { checkoutUrl }
// ---------------------------------------------------------------------------

hotelRouter.post("/checkout", async (req: Request, res: Response) => {
  const body = req.body as Record<string, unknown>;
  const error = requireFields(body, [
    "token",
    "correlationId",
    "hotelId",
    "recommendationId",
  ]);
  if (error) {
    res.status(400).json({ success: false, message: error });
    return;
  }

  try {
    const args: Record<string, unknown> = {
      token: body.token,
      correlationId: body.correlationId,
      hotelId: body.hotelId,
      recommendationId: body.recommendationId,
    };
    if (body.roomId) args.roomId = body.roomId;
    if (body.publishedRate !== undefined)
      args.publishedRate = num(body.publishedRate);

    const result = await callTool("hotel_get_checkout_url", args);
    const json = extractJson(result) as Record<string, unknown> | null;

    if (result.isError) {
      logger.warn("hotel_get_checkout_url returned error", json);
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
    logger.error("hotel/checkout", err);
    res.status(500).json({ success: false, message: toMessage(err) });
  }
});
