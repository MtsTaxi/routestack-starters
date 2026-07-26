# RouteStack PHP Bridge

A private Node.js bridge service that lets PHP websites call the RouteStack MCP server.
PHP alone cannot maintain the persistent MCP/SSE connection that RouteStack requires, so
this starter sits privately on your server and exposes a simple HTTP API that PHP proxies to.

```
Browser → PHP (public_html) → Node.js Bridge (127.0.0.1:3001) → RouteStack MCP
```

## Prerequisites

| Requirement | Minimum |
|---|---|
| Node.js | 20 |
| PHP | 8.2 |
| cPanel "Node.js Selector" | or SSH access to run Node |
| RouteStack API key + secret | from [routestack.ai](https://routestack.ai) |

## Repository layout

```
typescript/php-bridge/
├── src/
│   ├── index.ts              Express server entry point
│   ├── config.ts             Environment variable validation
│   ├── logger.ts             Structured logger
│   ├── mcp-client.ts         RouteStack MCP connection (singleton)
│   ├── middleware/
│   │   └── auth.ts           BRIDGE_SECRET validation
│   └── handlers/
│       ├── hotel.ts          Hotel endpoints (destinations, search, rooms, revalidate, checkout)
│       ├── flight.ts         Flight endpoints (session, locations, search, revalidate, checkout)
│       └── car.ts            Car hire endpoints (locations, search, checkout)
└── php-files/
    ├── private-config/
    │   └── routestack.php.example   Copy above public_html and rename
    ├── lib/
    │   └── bridge-client.php        Shared cURL + CSRF + sanitiser helpers
    └── ai-travel-api/
        ├── .htaccess
        ├── hotel-destinations.php
        ├── hotel-search.php
        ├── hotel-rooms.php
        ├── hotel-revalidate.php
        ├── hotel-checkout.php
        ├── flight-search.php
        └── car-search.php
```

---

## Deployment guide (cPanel)

### 1 — Back up your existing files first

Before copying anything, back up these files in cPanel File Manager:

```
public_html/ai-travel-widget.php
public_html/ai-travel-widget.js
public_html/ai-travel-api/hotel-search.php
public_html/ai-travel-api/hotel-destinations.php
public_html/.env         ← if it exists, move it above public_html
```

### 2 — Deploy the Node.js bridge

Via SSH or cPanel Terminal:

```bash
# Upload/clone this directory above public_html
cd ~
cp -r routestack-starters/typescript/php-bridge routestack-bridge
cd routestack-bridge

# Create and configure .env (never commit this file)
cp .env.example .env
nano .env
```

Set all values in `.env`:

```dotenv
ROUTESTACK_API_KEY=your-key
ROUTESTACK_API_SECRET=your-secret
ROUTESTACK_MCP_URL=https://mcp.routestack.ai/sse
BRIDGE_SECRET=$(openssl rand -hex 32)   # generate a strong secret
HOST=127.0.0.1
PORT=3001
REQUEST_TIMEOUT_MS=25000
```

Install dependencies and verify TypeScript compiles:

```bash
npm install
npx tsc --noEmit   # must produce no errors
```

### 3 — Start the bridge

**Via cPanel Node.js Selector** (recommended for shared hosting):

1. cPanel → Node.js → Create Application
2. Node.js version: 20
3. Application mode: Production
4. Application root: `/home/<user>/routestack-bridge`
5. Application URL: *(leave blank — internal only)*
6. Application startup file: `node_modules/.bin/tsx src/index.ts`
7. Click **Create** then **Run NPM Install**
8. Click **Start**

**Via SSH (VPS)**:

```bash
# Install pm2 if not already present
npm install -g pm2

cd ~/routestack-bridge
pm2 start "npx tsx src/index.ts" --name routestack-bridge
pm2 save
pm2 startup   # follow the output instruction to enable on reboot
```

### 4 — Verify the bridge is running

```bash
curl http://127.0.0.1:3001/health
# Expected: {"status":"ok","service":"routestack-php-bridge"}
```

### 5 — Deploy the private PHP config

```bash
mkdir -p ~/private-config
cp php-files/private-config/routestack.php.example ~/private-config/routestack.php
nano ~/private-config/routestack.php
```

Edit the file and set:

```php
define('BRIDGE_HOST',   '127.0.0.1');
define('BRIDGE_PORT',   3001);
define('BRIDGE_SECRET', 'the-same-value-as-BRIDGE_SECRET-in-dotenv');
```

Set strict permissions:

```bash
chmod 600 ~/private-config/routestack.php
```

### 6 — Deploy the PHP shared library

```bash
mkdir -p ~/public_html/ai-travel-api/lib
cp php-files/lib/bridge-client.php ~/public_html/ai-travel-api/lib/bridge-client.php
chmod 644 ~/public_html/ai-travel-api/lib/bridge-client.php
```

Create `public_html/ai-travel-api/lib/.htaccess` to deny direct access to the helper:

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

### 7 — Deploy the PHP API endpoints

```bash
# Copy all endpoint files
cp php-files/ai-travel-api/*.php ~/public_html/ai-travel-api/
cp php-files/ai-travel-api/.htaccess ~/public_html/ai-travel-api/.htaccess

# Permissions
chmod 644 ~/public_html/ai-travel-api/*.php
chmod 644 ~/public_html/ai-travel-api/.htaccess
```

### 8 — Deploy the widget

```bash
# Back up existing files first!
cp ~/public_html/ai-travel-widget.php ~/public_html/ai-travel-widget.php.bak
cp ~/public_html/ai-travel-widget.js  ~/public_html/ai-travel-widget.js.bak

cp php-files/ai-travel-widget.php ~/public_html/ai-travel-widget.php
cp php-files/ai-travel-widget.js  ~/public_html/ai-travel-widget.js
chmod 644 ~/public_html/ai-travel-widget.php
chmod 644 ~/public_html/ai-travel-widget.js
```

---

## Test with cURL

All tests must be run from the server itself (the bridge is not publicly accessible).

### Health check

```bash
curl http://127.0.0.1:3001/health
```

Expected:

```json
{"status":"ok","service":"routestack-php-bridge"}
```

### Hotel destination search (direct to bridge)

```bash
curl -s -X POST http://127.0.0.1:3001/hotel/destinations \
  -H "Content-Type: application/json" \
  -H "Authorization: ******" \
  -d '{"query":"London"}' | python3 -m json.tool
```

Expected:

```json
{
  "success": true,
  "destinations": [
    { "id": "...", "name": "London (and vicinity), England, GB" },
    ...
  ]
}
```

### Hotel search (through PHP proxy)

First get a CSRF token (or disable CSRF temporarily for testing by commenting out `rs_csrf_validate()`).

```bash
curl -s -X POST https://ilovevoyage.com/ai-travel-api/hotel-search.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_SESSION_CSRF_TOKEN" \
  -d '{
    "destinationId": "DESTINATION_ID_FROM_AUTOCOMPLETE",
    "checkIn":  "2026-09-01",
    "checkOut": "2026-09-05",
    "adults":   2,
    "children": 0,
    "rooms":    1,
    "currency": "GBP"
  }' | python3 -m json.tool
```

Expected:

```json
{
  "success": true,
  "token": "abc123...",
  "correlationId": "def456...",
  "hotels": [
    {
      "id": "...",
      "name": "The Savoy",
      "starRating": 5,
      "ourprice": 489.00,
      "publishedRate": 550.00,
      "currency": "GBP",
      "heroImage": "https://..."
    }
  ]
}
```

---

## Full booking flow

| Step | PHP endpoint | Bridge route | MCP tool |
|---|---|---|---|
| 1. Destination autocomplete | `hotel-destinations.php` | `POST /hotel/destinations` | `hotel_search_destinations` |
| 2. Hotel search | `hotel-search.php` | `POST /hotel/search` | `hotel_search` |
| 3. Rooms & rates | `hotel-rooms.php` | `POST /hotel/rooms` | `hotel_get_rooms_and_rates` |
| 4. Rate revalidation | `hotel-revalidate.php` | `POST /hotel/revalidate` | `hotel_revalidate_rate` |
| 5. Checkout URL | `hotel-checkout.php` | `POST /hotel/checkout` | `hotel_get_checkout_url` |

The widget JavaScript carries `token` and `correlationId` between steps in memory.
The bridge is stateless — all state is owned by the browser.

---

## Error responses

Every endpoint always returns JSON with a `success` field:

```json
{ "success": false, "message": "Human-readable error" }
```

| HTTP code | Meaning |
|---|---|
| 400 | Invalid or missing input parameter |
| 401 | Wrong or missing BRIDGE_SECRET |
| 403 | CSRF token mismatch |
| 405 | Wrong HTTP method |
| 410 | Offer or fare has expired — search again |
| 422 | No results returned |
| 500 | PHP fatal error or bridge startup failure |
| 502 | RouteStack returned an error |

---

## Rollback procedure

```bash
# 1. Stop the bridge
pm2 stop routestack-bridge         # VPS
# or: cPanel → Node.js → Stop Application

# 2. Restore the original PHP files
cp ~/public_html/ai-travel-widget.php.bak  ~/public_html/ai-travel-widget.php
cp ~/public_html/ai-travel-widget.js.bak   ~/public_html/ai-travel-widget.js

# 3. Restore original API files (if you backed them up)
cp ~/public_html/ai-travel-api/hotel-search.php.bak \
   ~/public_html/ai-travel-api/hotel-search.php
```

The PHPTravels installation and database are **never touched** by this integration.

---

## Security checklist

- [x] `BRIDGE_SECRET` never appears in JavaScript or HTML source
- [x] `routestack.php` lives above `public_html` (not web-accessible), chmod 600
- [x] Bridge binds to `127.0.0.1` only — never `0.0.0.0`
- [x] All PHP inputs validated and sanitised before forwarding
- [x] CSRF token required on every mutating PHP request
- [x] SSL verification enabled (internal loopback excluded)
- [x] `display_errors Off` in `.htaccess` — no PHP internals leak to browser
- [x] HTTP method restricted to POST in `.htaccess`
- [x] `lib/` directory blocked from direct web access
- [x] No RouteStack API key or secret stored in `public_html`
