<?php
/**
 * AI Travel Widget — I LOVE VOYAGE
 *
 * Public entry page for the RouteStack travel booking widget.
 * Loads private client, issues a CSRF token, outputs HTML shell.
 *
 * Place at: public_html/ai-travel-widget.php
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// Private-client bootstrap (above public_html)
// ------------------------------------------------------------------
$_privDir  = dirname($_SERVER['DOCUMENT_ROOT']) . '/routestack_private';
$_cfgFile  = $_privDir . '/config.php';
$_clientFile = $_privDir . '/client.php';

if (!is_file($_cfgFile) || !is_file($_clientFile)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Service Temporarily Unavailable</h1>';
    echo '<p>The travel booking service is not yet configured. Please check back later.</p>';
    exit;
}

require_once $_cfgFile;
require_once $_clientFile;

// ------------------------------------------------------------------
// CSRF token for JavaScript API calls
// ------------------------------------------------------------------
session_start();
if (empty($_SESSION['_rs_csrf'])) {
    $_SESSION['_rs_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_rs_csrf'];

// ------------------------------------------------------------------
// Canonical hostname
// ------------------------------------------------------------------
$siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'ilovevoyage.com');
$apiBase  = $siteUrl . '/ai-travel-api';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Search &amp; Book Travel — I LOVE VOYAGE</title>
<meta name="description" content="Search hotels, flights and car hire worldwide. Book in seconds with I LOVE VOYAGE." />

<style>
/* ------------------------------------------------------------------ */
/* Base                                                                 */
/* ------------------------------------------------------------------ */
:root {
  --brand:        #0070c0;
  --brand-dark:   #005493;
  --accent:       #e8f4fd;
  --text:         #222;
  --muted:        #666;
  --border:       #ddd;
  --radius:       8px;
  --shadow:       0 2px 12px rgba(0,0,0,.10);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
       background: #f4f7fb; color: var(--text); }
a { color: var(--brand); text-decoration: none; }

/* ------------------------------------------------------------------ */
/* Widget wrapper                                                        */
/* ------------------------------------------------------------------ */
#travel-widget-app {
  max-width: 1100px;
  margin: 30px auto;
  background: #fff;
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  overflow: hidden;
}

/* Tabs */
.tw-tabs { display: flex; background: var(--brand); }
.tw-tabs button {
  flex: 1;
  padding: 14px 10px;
  background: transparent;
  border: none;
  color: rgba(255,255,255,.75);
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  border-bottom: 3px solid transparent;
  transition: .2s;
}
.tw-tabs button.active, .tw-tabs button:hover {
  color: #fff;
  background: rgba(255,255,255,.12);
  border-bottom-color: #fff;
}

/* Panels */
.tw-panel { display: none; padding: 28px; }
.tw-panel.active { display: block; }

/* Form grid */
.tw-grid { display: grid; gap: 16px; }
@media (min-width: 600px) { .tw-grid-2 { grid-template-columns: 1fr 1fr; } }
@media (min-width: 900px) { .tw-grid-3 { grid-template-columns: 1fr 1fr 1fr; } }

label { display: flex; flex-direction: column; gap: 5px; font-size: 14px; font-weight: 500; }
input, select {
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: 6px;
  font-size: 15px;
  color: var(--text);
  width: 100%;
}
input:focus, select:focus { outline: 2px solid var(--brand); border-color: var(--brand); }

/* Child ages row */
.child-ages-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
.child-ages-row select { width: 90px; }
.child-ages-row label { flex-direction: row; align-items: center; gap: 6px; font-weight: 400; }

/* Search button */
.tw-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 11px 28px;
  background: var(--brand);
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: background .2s;
}
.tw-btn:hover { background: var(--brand-dark); }
.tw-btn.secondary { background: #fff; color: var(--brand); border: 2px solid var(--brand); }
.tw-btn.secondary:hover { background: var(--accent); }
.tw-btn:disabled { opacity: .55; cursor: not-allowed; }

/* Results */
#tw-hotel-results, #tw-flight-results, #tw-car-results {
  margin-top: 24px;
}
.tw-error {
  padding: 12px 16px;
  background: #fff0f0;
  border-left: 4px solid #c00;
  border-radius: 4px;
  font-size: 14px;
  color: #c00;
}
.tw-info {
  padding: 12px 16px;
  background: var(--accent);
  border-left: 4px solid var(--brand);
  border-radius: 4px;
  font-size: 14px;
  color: var(--brand-dark);
}
.tw-spinner {
  display: inline-block;
  width: 20px; height: 20px;
  border: 3px solid #cce4f7;
  border-top-color: var(--brand);
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Hotel cards */
.hotel-card {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px;
  margin-bottom: 14px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}
.hotel-card-info h3 { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.hotel-card-info .stars { color: #f5a623; font-size: 14px; }
.hotel-card-info .address { font-size: 13px; color: var(--muted); margin-top: 3px; }
.hotel-card-price { text-align: right; }
.hotel-card-price .amount { font-size: 22px; font-weight: 700; color: var(--brand); }
.hotel-card-price .per-night { font-size: 12px; color: var(--muted); }

/* Room cards */
.room-card {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px;
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.room-card h4 { font-size: 15px; font-weight: 600; }
.room-card .board { font-size: 13px; color: var(--muted); margin-top: 2px; }
.room-card-price { font-size: 20px; font-weight: 700; color: var(--brand); }

/* Flight cards */
.flight-card {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px;
  margin-bottom: 10px;
}
.flight-card-header { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.flight-route { font-size: 16px; font-weight: 700; }
.flight-meta { font-size: 13px; color: var(--muted); margin-top: 3px; }
.flight-price { font-size: 20px; font-weight: 700; color: var(--brand); }

/* Car cards */
.car-card {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px;
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.car-card h4 { font-size: 15px; font-weight: 600; }
.car-card .cat { font-size: 13px; color: var(--muted); }
.car-card-price { font-size: 20px; font-weight: 700; color: var(--brand); }

/* Destination autocomplete */
.tw-autocomplete-wrap { position: relative; }
.tw-autocomplete-list {
  position: absolute; top: 100%; left: 0; right: 0;
  background: #fff;
  border: 1px solid var(--border);
  border-top: none;
  border-radius: 0 0 6px 6px;
  z-index: 100;
  max-height: 220px;
  overflow-y: auto;
  box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.tw-autocomplete-list div {
  padding: 9px 12px;
  cursor: pointer;
  font-size: 14px;
}
.tw-autocomplete-list div:hover { background: var(--accent); }

/* Modal overlay for rooms */
.tw-modal-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,.55);
  z-index: 200;
  align-items: center;
  justify-content: center;
}
.tw-modal-backdrop.open { display: flex; }
.tw-modal {
  background: #fff;
  border-radius: var(--radius);
  padding: 28px;
  width: min(640px, 95vw);
  max-height: 80vh;
  overflow-y: auto;
  position: relative;
}
.tw-modal h2 { font-size: 18px; margin-bottom: 16px; }
.tw-modal-close {
  position: absolute; top: 14px; right: 18px;
  background: none; border: none;
  font-size: 24px; cursor: pointer; color: var(--muted);
}
</style>
</head>
<body>

<div id="travel-widget-app">

  <!-- Tabs -->
  <div class="tw-tabs" role="tablist">
    <button class="active" data-tab="hotels" role="tab" aria-selected="true">🏨 Hotels</button>
    <button data-tab="flights" role="tab" aria-selected="false">✈️ Flights</button>
    <button data-tab="cars"    role="tab" aria-selected="false">🚗 Car Hire</button>
  </div>

  <!-- ========================== HOTELS PANEL ========================== -->
  <div id="tab-hotels" class="tw-panel active" role="tabpanel">
    <form id="hotel-search-form" autocomplete="off" novalidate>
      <div class="tw-grid tw-grid-3">

        <label>Destination
          <div class="tw-autocomplete-wrap">
            <input id="hotel-destination" name="destination" type="text"
                   placeholder="City, resort or property…" autocomplete="off" required />
            <div id="hotel-dest-list" class="tw-autocomplete-list" hidden></div>
          </div>
          <input type="hidden" id="hotel-destination-id" />
        </label>

        <label>Check-in
          <input type="date" id="hotel-checkin" name="checkin" required />
        </label>

        <label>Check-out
          <input type="date" id="hotel-checkout" name="checkout" required />
        </label>

        <label>Adults
          <select id="hotel-adults" name="adults">
            <?php for ($i = 1; $i <= 8; $i++): ?>
            <option value="<?= $i ?>"<?= $i === 2 ? ' selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </label>

        <label>Children (under 18)
          <select id="hotel-children" name="children">
            <?php for ($i = 0; $i <= 6; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </label>

        <label>Rooms
          <select id="hotel-rooms" name="rooms">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </label>

      </div>

      <!-- Child age inputs (injected by JS) -->
      <div id="hotel-child-ages-container" aria-live="polite"></div>

      <div style="margin-top:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <button type="submit" class="tw-btn" id="hotel-search-btn">
          <span class="tw-spinner" id="hotel-spinner" hidden></span>
          Search Hotels
        </button>
        <span id="hotel-search-msg" aria-live="polite"></span>
      </div>
    </form>

    <div id="tw-hotel-results"></div>
  </div>

  <!-- ========================== FLIGHTS PANEL ========================== -->
  <div id="tab-flights" class="tw-panel" role="tabpanel">
    <form id="flight-search-form" autocomplete="off" novalidate>

      <div style="margin-bottom:14px;">
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
          <input type="radio" name="triptype" value="return" checked /> Return
        </label>
        &nbsp;&nbsp;
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
          <input type="radio" name="triptype" value="oneway" /> One-way
        </label>
      </div>

      <div class="tw-grid tw-grid-2">

        <label>From (IATA code or city)
          <div class="tw-autocomplete-wrap">
            <input id="flight-origin" type="text" placeholder="e.g. LHR or London" required />
            <div id="flight-origin-list" class="tw-autocomplete-list" hidden></div>
          </div>
          <input type="hidden" id="flight-origin-code" />
        </label>

        <label>To (IATA code or city)
          <div class="tw-autocomplete-wrap">
            <input id="flight-dest" type="text" placeholder="e.g. JFK or New York" required />
            <div id="flight-dest-list" class="tw-autocomplete-list" hidden></div>
          </div>
          <input type="hidden" id="flight-dest-code" />
        </label>

        <label>Departure date
          <input type="date" id="flight-depart" required />
        </label>

        <label id="return-date-wrap">Return date
          <input type="date" id="flight-return" />
        </label>

        <label>Adults
          <select id="flight-adults">
            <?php for ($i = 1; $i <= 9; $i++): ?>
            <option value="<?= $i ?>"<?= $i === 1 ? ' selected' : '' ?>><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </label>

        <label>Children (2–11)
          <select id="flight-children">
            <?php for ($i = 0; $i <= 8; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </label>

        <label>Infants (under 2)
          <select id="flight-infants">
            <?php for ($i = 0; $i <= 4; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
            <?php endfor; ?>
          </select>
        </label>

        <label>Cabin class
          <select id="flight-cabin">
            <option value="Economy" selected>Economy</option>
            <option value="Premium Economy">Premium Economy</option>
            <option value="Business">Business</option>
            <option value="First">First</option>
          </select>
        </label>

      </div>

      <div style="margin-top:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <button type="submit" class="tw-btn" id="flight-search-btn">
          <span class="tw-spinner" id="flight-spinner" hidden></span>
          Search Flights
        </button>
        <span id="flight-search-msg" aria-live="polite"></span>
      </div>
    </form>

    <div id="tw-flight-results"></div>
  </div>

  <!-- ========================== CARS PANEL ========================== -->
  <div id="tab-cars" class="tw-panel" role="tabpanel">
    <form id="car-search-form" autocomplete="off" novalidate>
      <div class="tw-grid tw-grid-2">

        <label>Pick-up location
          <div class="tw-autocomplete-wrap">
            <input id="car-pickup-input" type="text"
                   placeholder="Airport, city or address…" autocomplete="off" required />
            <div id="car-pickup-list" class="tw-autocomplete-list" hidden></div>
          </div>
          <input type="hidden" id="car-pickup-id" />
        </label>

        <label>Drop-off location (leave blank for same)
          <div class="tw-autocomplete-wrap">
            <input id="car-dropoff-input" type="text"
                   placeholder="Same as pick-up" autocomplete="off" />
            <div id="car-dropoff-list" class="tw-autocomplete-list" hidden></div>
          </div>
          <input type="hidden" id="car-dropoff-id" />
        </label>

        <label>Pick-up date
          <input type="date" id="car-pickup-date" required />
        </label>

        <label>Drop-off date
          <input type="date" id="car-dropoff-date" required />
        </label>

        <label>Driver age
          <input type="number" id="car-driver-age" value="30" min="18" max="99" required />
        </label>

      </div>

      <div style="margin-top:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <button type="submit" class="tw-btn" id="car-search-btn">
          <span class="tw-spinner" id="car-spinner" hidden></span>
          Search Car Hire
        </button>
        <span id="car-search-msg" aria-live="polite"></span>
      </div>
    </form>

    <div id="tw-car-results"></div>
  </div>

</div><!-- /#travel-widget-app -->

<!-- Room selection modal -->
<div id="tw-room-modal" class="tw-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="room-modal-title">
  <div class="tw-modal">
    <button class="tw-modal-close" id="tw-room-close" aria-label="Close">&times;</button>
    <h2 id="room-modal-title">Select a Room</h2>
    <div id="tw-room-list"></div>
  </div>
</div>

<script>
const RS = {
  apiBase:   <?= json_encode($apiBase, JSON_UNESCAPED_SLASHES) ?>,
  csrfToken: <?= json_encode($csrfToken) ?>,
  currency:  'GBP'
};
</script>
<script src="/ai-travel-widget.js" defer></script>

</body>
</html>
