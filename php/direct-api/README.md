# I LOVE VOYAGE — RouteStack PHP Direct-API Starter

A complete, production-ready PHP integration for the [RouteStack](https://routestack.ai) Travel Booking MCP API.

No Node.js required. Connects directly to RouteStack's Streamable HTTP MCP endpoint using secure HMAC partner authentication.

---

## Features

| Feature | Status |
|---|---|
| Hotel search, rooms, revalidation, payment redirect | ✅ Complete |
| Flight session, location autocomplete, search, revalidation, payment redirect | ✅ Complete |
| Car hire location autocomplete, search, payment redirect | ✅ Complete |
| Child age fields (per-child dropdowns, ages 0–17) | ✅ Complete |
| CSRF protection (per-session token, `X-CSRF-Token` header) | ✅ Complete |
| Rate limiting (APCu, 60 req/IP/min, silently skipped if APCu absent) | ✅ Complete |
| Consistent JSON error responses | ✅ Complete |
| Apache `.htaccess` protection | ✅ Complete |
| Safe diagnostic page | ✅ Complete |
| Private credentials above `public_html` | ✅ Complete |
| PHP syntax-checked (PHP 8.2) | ✅ All files pass `php -l` |

---

## Directory layout

```
routestack_private/          ← ABOVE public_html — never web-accessible
  config.php.example         ← Copy → config.php, fill in credentials
  client.php.example         ← Copy → client.php (or use your own)

public_html/
  ai-travel-widget.php       ← Main booking page (include in your site)
  ai-travel-widget.js        ← Frontend widget (hotels · flights · cars)
  diagnostic.php             ← Health check — REMOVE before go-live
  ai-travel-api/
    .htaccess                ← POST-only, no directory listing, security headers
    hotel-destinations.php
    hotel-search.php
    hotel-details.php
    hotel-revalidate.php
    hotel-payment.php
    flight-session.php
    flight-locations.php
    flight-search.php
    flight-revalidate.php
    flight-payment.php
    car-locations.php
    car-search.php
    car-payment.php
    lib/
      .htaccess              ← Blocks direct web access to lib/
      routestack.php         ← Shared helpers (bootstrap, CSRF, rate-limit, sanitisers)
```

---

## Requirements

- PHP 8.2 or 8.4
- Extensions: `curl`, `json`, `openssl`, `hash`, `session`
- `apcu` extension *(optional — rate limiting)*
- cPanel / Apache with `.htaccess` support
- A RouteStack partner account with `apiKey` and `apiSecret`

---

## Installation (cPanel)

### 1. Upload files

Upload everything from `public_html/` into your site's `public_html` (or subdirectory).

Upload the `routestack_private/` folder **one level above** `public_html`:

```
/home/<cpanel-user>/
  public_html/
    ai-travel-widget.php
    ai-travel-widget.js
    diagnostic.php
    ai-travel-api/
      ...
  routestack_private/           ← here — NOT inside public_html
    config.php
    client.php
```

### 2. Configure credentials

```bash
cp routestack_private/config.php.example routestack_private/config.php
cp routestack_private/client.php.example routestack_private/client.php
```

Edit `routestack_private/config.php`:

```php
define('RS_BASE_URL',          'https://mcp.routestack.ai');
define('RS_API_KEY',           'your-api-key-here');
define('RS_API_SECRET',        'your-api-secret-here');
define('RS_TOKEN_CACHE_FILE',  '/home/<cpanel-user>/routestack_private/.token_cache');
define('RS_CONNECT_TIMEOUT',   10);
define('RS_TIMEOUT',           30);
```

Replace `<cpanel-user>` with your actual cPanel username.

### 3. Set file permissions

```bash
chmod 600 routestack_private/config.php
chmod 600 routestack_private/client.php
chmod 700 routestack_private/
```

### 4. Verify installation

Browse to `https://yourdomain.com/diagnostic.php` — all checks should show ✅.

### 5. Remove the diagnostic page before go-live

```bash
rm public_html/diagnostic.php
```

Or restrict it in `.htaccess`:

```apache
<Files "diagnostic.php">
    AuthType Basic
    AuthName "Diagnostics"
    AuthUserFile /home/<cpanel-user>/.htpasswd
    Require valid-user
</Files>
```

### 6. Link the widget page

Include `ai-travel-widget.php` in your existing site (e.g. as a PHP include, or link to it directly).

Update the `RS` config block in `ai-travel-widget.php` if your API folder is at a non-standard path:

```php
$apiBase = $siteUrl . '/ai-travel-api';  // adjust if needed
```

---

## Rollback instructions

If something goes wrong after deployment:

1. **Restore previous files** — upload your backup copies of `ai-travel-widget.php`, `ai-travel-widget.js`, and `ai-travel-api/`.
2. **Remove private config** — delete `routestack_private/config.php` and `routestack_private/client.php` if credentials may have been exposed.
3. **Clear token cache** — delete `routestack_private/.token_cache` (or whatever `RS_TOKEN_CACHE_FILE` points to).
4. **Check server error logs** via cPanel → Logs → Error Log for any PHP fatal errors.
5. **Roll back git** (if using version control):
   ```bash
   git revert HEAD
   ```

---

## Live testing status

The following has been implemented against the RouteStack MCP API specification.
Testing with live production credentials was not possible during development.

| Endpoint | Implementation | Live-tested |
|---|---|---|
| `POST /mcp/auth/partner-token` | HMAC-SHA256 + JWT caching | ❌ No live credentials |
| `hotel_search_destinations` | `hotel-destinations.php` | ❌ |
| `hotel_search` | `hotel-search.php` | ❌ |
| `hotel_get_rooms_and_rates` | `hotel-details.php` | ❌ |
| `hotel_revalidate_rate` | `hotel-revalidate.php` | ❌ |
| `hotel_get_checkout_url` | `hotel-payment.php` | ❌ |
| `flight_session` | `flight-session.php` | ❌ |
| `flight_locations` | `flight-locations.php` | ❌ |
| `flight_search` | `flight-search.php` | ❌ |
| `flight_revalidate` | `flight-revalidate.php` | ❌ |
| `flight_get_checkout_url` | `flight-payment.php` | ❌ |
| Car location (dynamic) | `car-locations.php` | ❌ |
| Car search (dynamic) | `car-search.php` | ❌ |
| Car checkout (dynamic) | `car-payment.php` | ❌ |

**Items to confirm with live credentials:**
- Exact MCP endpoint path (`/mcp` assumed; could be `/mcp/stream` or similar)
- Whether a `tools/initialize` handshake is required before `tools/call`
- Exact tool name for car hire endpoints (discovered dynamically)
- Field name `childAges` (matches TypeScript starter pattern but not confirmed in openapi.yaml)
- JWT field name in auth response (`token` / `accessToken` / `partnerToken` — all three are handled)

---

## Security notes

- Credentials are stored above `public_html` and never exposed via HTTP
- All API endpoints are POST-only (enforced in `.htaccess` and PHP)
- CSRF tokens protect every state-changing request
- Rate limiting (60 req/IP/min) is applied via APCu when available
- `X-Content-Type-Options`, `X-Frame-Options`, and `Referrer-Policy` headers are set on all responses
- The `lib/` folder is blocked from direct web access
- Child ages are validated server-side (0–17 range, count matches `children`)

---

## Customisation

- **Currency**: change `RS.currency = 'GBP'` in `ai-travel-widget.php` and the PHP defaults
- **Branding**: edit the CSS in `ai-travel-widget.php` (`--brand`, `--brand-dark` variables)
- **Rate limit**: edit `RS_RATE_LIMIT` constant in `lib/routestack.php`
- **Results cap**: the `array_slice($hotels, 0, 10)` limits are in each search endpoint
