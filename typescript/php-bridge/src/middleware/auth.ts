import type { Request, Response, NextFunction } from "express";
import { config } from "../config.js";

/**
 * Validates the shared BRIDGE_SECRET sent by PHP as an Authorization header.
 * Every route except GET /health passes through this middleware.
 */
export function authMiddleware(
  req: Request,
  res: Response,
  next: NextFunction,
): void {
  const auth = req.headers["authorization"] ?? "";
  const expected = "Bearer " + config.bridge.secret;

  if (!auth || auth !== expected) {
    res.status(401).json({ success: false, message: "Unauthorized" });
    return;
  }

  next();
}
