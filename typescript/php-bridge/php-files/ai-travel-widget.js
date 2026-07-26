/**
 * ai-travel-widget.js
 * Full booking-flow widget for ilovevoyage.com
 *
 * Flow (hotels):
 *   1. Destination autocomplete  → /ai-travel-api/hotel-destinations.php
 *   2. Hotel search              → /ai-travel-api/hotel-search.php
 *   3. Rooms & rates             → /ai-travel-api/hotel-rooms.php
 *   4. Rate revalidation         → /ai-travel-api/hotel-revalidate.php
 *   5. Checkout URL              → /ai-travel-api/hotel-checkout.php
 *
 * Flow (flights):
 *   All steps through /ai-travel-api/flight-search.php (action param)
 *
 * Flow (car hire):
 *   All steps through /ai-travel-api/car-search.php (action param)
 */

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Configuration
  // ---------------------------------------------------------------------------

  const API_BASE       = '/ai-travel-api';
  const CSRF_TOKEN     = window.__RS_CSRF_TOKEN__ || '';
  const DEBOUNCE_MS    = 350;
  const CURRENCIES     = ['GBP', 'USD', 'EUR', 'AUD', 'CAD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'AED', 'SGD', 'HKD'];
  const CABIN_CLASSES  = ['Economy', 'Premium Economy', 'Business', 'First'];

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------

  const state = {
    activeTab: 'hotels',
    hotel: {
      selectedDestination: null,   // { id, name }
      token: null,
      correlationId: null,
      results: [],
      selectedHotel: null,
      rooms: [],
      selectedRoom: null,
    },
    flight: {
      sessionId: null,
      correlationId: null,
      searchFilterObj: null,
      results: [],
      selectedFlight: null,
    },
    car: {
      pickupLocation: null,
      dropoffLocation: null,
      results: [],
    },
  };

  // ---------------------------------------------------------------------------
  // API helper
  // ---------------------------------------------------------------------------

  async function api(endpoint, body) {
    const res = await fetch(API_BASE + endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF_TOKEN,
      },
      body: JSON.stringify(body),
    });

    // Always parse JSON regardless of HTTP status
    let json;
    try {
      json = await res.json();
    } catch {
      throw new Error('Server returned non-JSON response (HTTP ' + res.status + ')');
    }

    if (!json.success) {
      // Preserve the server's error message
      throw Object.assign(new Error(json.message || 'An error occurred'), {
        httpStatus: res.status,
        details: json.details,
      });
    }

    return json;
  }

  // ---------------------------------------------------------------------------
  // Debounce
  // ---------------------------------------------------------------------------

  function debounce(fn, ms) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  // ---------------------------------------------------------------------------
  // DOM helpers
  // ---------------------------------------------------------------------------

  function el(tag, attrs, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (k === 'className') node.className = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2).toLowerCase(), v);
      else node.setAttribute(k, v);
    }
    for (const child of children.flat()) {
      if (child == null) continue;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    }
    return node;
  }

  function show(node) { node.style.display = ''; }
  function hide(node) { node.style.display = 'none'; }

  function setLoading(btn, busy) {
    btn.disabled = busy;
    btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
    btn.textContent = busy ? 'Searching…' : btn.dataset.originalText;
  }

  function showError(container, message) {
    container.innerHTML = '';
    container.appendChild(
      el('div', { className: 'rs-error', role: 'alert' }, message)
    );
  }

  function clearError(container) {
    container.innerHTML = '';
  }

  // ---------------------------------------------------------------------------
  // Autocomplete widget
  // ---------------------------------------------------------------------------

  function Autocomplete({ input, fetchFn, onSelect, labelKey, idKey }) {
    let dropdown = null;
    let lastQuery = '';

    const doSearch = debounce(async (q) => {
      if (q.length < 2) { closeDropdown(); return; }
      if (q === lastQuery) return;
      lastQuery = q;
      try {
        const items = await fetchFn(q);
        renderDropdown(items);
      } catch {
        closeDropdown();
      }
    }, DEBOUNCE_MS);

    input.addEventListener('input', () => doSearch(input.value.trim()));
    input.addEventListener('keydown', (e) => {
      if (!dropdown) return;
      const items = dropdown.querySelectorAll('[role="option"]');
      const active = dropdown.querySelector('[aria-selected="true"]');
      let idx = Array.from(items).indexOf(active);
      if (e.key === 'ArrowDown') { e.preventDefault(); selectByIndex(items, idx + 1); }
      if (e.key === 'ArrowUp') { e.preventDefault(); selectByIndex(items, idx - 1); }
      if (e.key === 'Enter' && active) { e.preventDefault(); active.click(); }
      if (e.key === 'Escape') closeDropdown();
    });

    document.addEventListener('click', (e) => {
      if (dropdown && !input.contains(e.target) && !dropdown.contains(e.target)) {
        closeDropdown();
      }
    });

    function selectByIndex(items, i) {
      const clamped = Math.max(0, Math.min(i, items.length - 1));
      items.forEach(it => it.setAttribute('aria-selected', 'false'));
      if (items[clamped]) {
        items[clamped].setAttribute('aria-selected', 'true');
        items[clamped].scrollIntoView({ block: 'nearest' });
      }
    }

    function renderDropdown(items) {
      closeDropdown();
      if (!items.length) return;
      dropdown = el('ul', { role: 'listbox', className: 'rs-autocomplete-dropdown' });
      for (const item of items) {
        const opt = el('li', {
          role: 'option',
          'aria-selected': 'false',
          className: 'rs-autocomplete-item',
          onClick: () => {
            input.value = item[labelKey];
            onSelect(item);
            closeDropdown();
          },
        }, item[labelKey]);
        dropdown.appendChild(opt);
      }
      input.parentElement.style.position = 'relative';
      input.parentElement.appendChild(dropdown);
    }

    function closeDropdown() {
      if (dropdown) { dropdown.remove(); dropdown = null; }
      lastQuery = '';
    }
  }

  // ---------------------------------------------------------------------------
  // Build the full widget HTML
  // ---------------------------------------------------------------------------

  function buildWidget(root) {
    root.className = 'rs-widget';
    root.innerHTML = `
      <div class="rs-hero">
        <p class="rs-hero-label">I LOVE VOYAGE AI TRAVEL</p>
        <h1 class="rs-hero-title">Ask AI to Plan Your Trip</h1>
        <p class="rs-hero-sub">Search flights, hotels and car hire from one intelligent travel booking experience for UK and international journeys.</p>
      </div>
      <div class="rs-card">
        <nav class="rs-tabs" role="tablist">
          <button role="tab" id="tab-hotels"  aria-selected="true"  class="rs-tab rs-tab--active" data-tab="hotels">Hotels</button>
          <button role="tab" id="tab-flights" aria-selected="false" class="rs-tab" data-tab="flights">Flights</button>
          <button role="tab" id="tab-cars"    aria-selected="false" class="rs-tab" data-tab="cars">Car Hire</button>
        </nav>

        <!-- HOTELS -->
        <section id="panel-hotels" class="rs-panel" role="tabpanel" aria-labelledby="tab-hotels">
          <h2 class="rs-panel-title">Find Your Hotel</h2>
          <p class="rs-panel-sub">Search worldwide accommodation by destination, dates, rooms and number of guests.</p>
          <div class="rs-form" id="hotel-form">
            <div class="rs-field rs-field--full">
              <label for="hotel-dest">Destination or hotel</label>
              <input id="hotel-dest" type="text" autocomplete="off" placeholder="e.g. London, Paris, New York…">
            </div>
            <div class="rs-field">
              <label for="hotel-checkin">Check-in</label>
              <input id="hotel-checkin" type="date">
            </div>
            <div class="rs-field">
              <label for="hotel-checkout">Check-out</label>
              <input id="hotel-checkout" type="date">
            </div>
            <div class="rs-field">
              <label for="hotel-adults">Adults</label>
              <select id="hotel-adults">${[1,2,3,4,5,6,7,8,9].map(n=>`<option ${n===2?'selected':''} value="${n}">${n} adult${n>1?'s':''}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="hotel-children">Children</label>
              <select id="hotel-children"><option value="0">No children</option>${[1,2,3,4,5,6].map(n=>`<option value="${n}">${n} child${n>1?'ren':''}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="hotel-rooms">Rooms</label>
              <select id="hotel-rooms">${[1,2,3,4,5].map(n=>`<option ${n===1?'selected':''} value="${n}">${n} room${n>1?'s':''}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="hotel-currency">Currency</label>
              <select id="hotel-currency">${CURRENCIES.map(c=>`<option ${c==='GBP'?'selected':''} value="${c}">${c}</option>`).join('')}</select>
            </div>
            <div class="rs-field rs-field--action">
              <button id="hotel-search-btn" class="rs-btn-primary" type="button">Search Hotels</button>
            </div>
          </div>
          <div id="hotel-error" role="alert" aria-live="polite"></div>
          <div id="hotel-results"></div>
        </section>

        <!-- FLIGHTS -->
        <section id="panel-flights" class="rs-panel rs-panel--hidden" role="tabpanel" aria-labelledby="tab-flights">
          <h2 class="rs-panel-title">Find Flights</h2>
          <p class="rs-panel-sub">Search worldwide flights by route, dates and cabin class.</p>
          <div class="rs-form" id="flight-form">
            <div class="rs-field">
              <label for="flight-origin">From (airport code)</label>
              <input id="flight-origin" type="text" placeholder="e.g. LHR" maxlength="3" style="text-transform:uppercase">
            </div>
            <div class="rs-field">
              <label for="flight-dest">To (airport code)</label>
              <input id="flight-dest" type="text" placeholder="e.g. JFK" maxlength="3" style="text-transform:uppercase">
            </div>
            <div class="rs-field">
              <label for="flight-depart">Departure</label>
              <input id="flight-depart" type="date">
            </div>
            <div class="rs-field">
              <label for="flight-return">Return (optional)</label>
              <input id="flight-return" type="date">
            </div>
            <div class="rs-field">
              <label for="flight-adults">Adults</label>
              <select id="flight-adults">${[1,2,3,4,5,6,7,8,9].map(n=>`<option ${n===1?'selected':''} value="${n}">${n} adult${n>1?'s':''}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="flight-children">Children</label>
              <select id="flight-children"><option value="0">No children</option>${[1,2,3,4].map(n=>`<option value="${n}">${n}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="flight-cabin">Cabin</label>
              <select id="flight-cabin">${CABIN_CLASSES.map(c=>`<option value="${c}">${c}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="flight-currency">Currency</label>
              <select id="flight-currency">${CURRENCIES.map(c=>`<option ${c==='GBP'?'selected':''} value="${c}">${c}</option>`).join('')}</select>
            </div>
            <div class="rs-field rs-field--action">
              <button id="flight-search-btn" class="rs-btn-primary" type="button">Search Flights</button>
            </div>
          </div>
          <div id="flight-error" role="alert" aria-live="polite"></div>
          <div id="flight-results"></div>
        </section>

        <!-- CAR HIRE -->
        <section id="panel-cars" class="rs-panel rs-panel--hidden" role="tabpanel" aria-labelledby="tab-cars">
          <h2 class="rs-panel-title">Car Hire</h2>
          <p class="rs-panel-sub">Search car hire worldwide by pickup location and dates.</p>
          <div class="rs-form" id="car-form">
            <div class="rs-field rs-field--full">
              <label for="car-pickup">Pick-up location</label>
              <input id="car-pickup" type="text" autocomplete="off" placeholder="City, airport or station…">
            </div>
            <div class="rs-field rs-field--full">
              <label for="car-dropoff">Drop-off location <small>(leave blank to use same)</small></label>
              <input id="car-dropoff" type="text" autocomplete="off" placeholder="Same as pick-up">
            </div>
            <div class="rs-field">
              <label for="car-pickup-date">Pick-up date</label>
              <input id="car-pickup-date" type="date">
            </div>
            <div class="rs-field">
              <label for="car-dropoff-date">Drop-off date</label>
              <input id="car-dropoff-date" type="date">
            </div>
            <div class="rs-field">
              <label for="car-age">Driver age</label>
              <select id="car-age">${Array.from({length:57},(_,i)=>i+18).map(a=>`<option ${a===30?'selected':''} value="${a}">${a}</option>`).join('')}</select>
            </div>
            <div class="rs-field">
              <label for="car-currency">Currency</label>
              <select id="car-currency">${CURRENCIES.map(c=>`<option ${c==='GBP'?'selected':''} value="${c}">${c}</option>`).join('')}</select>
            </div>
            <div class="rs-field rs-field--action">
              <button id="car-search-btn" class="rs-btn-primary" type="button">Search Cars</button>
            </div>
          </div>
          <div id="car-error" role="alert" aria-live="polite"></div>
          <div id="car-results"></div>
        </section>

        <p class="rs-legal">Prices and availability may change until the booking is confirmed. Please review the final fare, cancellation terms, traveller information and booking conditions before payment.</p>
      </div>
    `;
  }

  // ---------------------------------------------------------------------------
  // Tab switching
  // ---------------------------------------------------------------------------

  function initTabs(root) {
    root.querySelectorAll('.rs-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        root.querySelectorAll('.rs-tab').forEach(b => {
          b.classList.remove('rs-tab--active');
          b.setAttribute('aria-selected', 'false');
        });
        root.querySelectorAll('.rs-panel').forEach(p => p.classList.add('rs-panel--hidden'));
        btn.classList.add('rs-tab--active');
        btn.setAttribute('aria-selected', 'true');
        root.querySelector('#panel-' + btn.dataset.tab).classList.remove('rs-panel--hidden');
        state.activeTab = btn.dataset.tab;
      });
    });
  }

  // ---------------------------------------------------------------------------
  // Set sensible default dates
  // ---------------------------------------------------------------------------

  function initDefaultDates(root) {
    const today      = new Date();
    const checkIn    = new Date(today); checkIn.setDate(today.getDate() + 30);
    const checkOut   = new Date(checkIn); checkOut.setDate(checkIn.getDate() + 3);
    const toISO      = d => d.toISOString().slice(0, 10);

    const hCI  = root.querySelector('#hotel-checkin');
    const hCO  = root.querySelector('#hotel-checkout');
    const fDep = root.querySelector('#flight-depart');
    const fRet = root.querySelector('#flight-return');
    const cPU  = root.querySelector('#car-pickup-date');
    const cDO  = root.querySelector('#car-dropoff-date');

    hCI.value  = toISO(checkIn);
    hCO.value  = toISO(checkOut);
    fDep.value = toISO(checkIn);
    fRet.value = toISO(checkOut);
    cPU.value  = toISO(checkIn);
    cDO.value  = toISO(checkOut);

    hCI.min = hCO.min = fDep.min = cPU.min = toISO(today);
  }

  // ---------------------------------------------------------------------------
  // Hotel flow
  // ---------------------------------------------------------------------------

  function initHotelFlow(root) {
    const destInput  = root.querySelector('#hotel-dest');
    const errorBox   = root.querySelector('#hotel-error');
    const resultsBox = root.querySelector('#hotel-results');
    const searchBtn  = root.querySelector('#hotel-search-btn');

    // Autocomplete
    Autocomplete({
      input:    destInput,
      fetchFn:  async (q) => {
        const data = await api('/hotel-destinations.php', { query: q });
        return data.destinations || [];
      },
      onSelect: (item) => {
        state.hotel.selectedDestination = { id: item.id, name: item.name };
      },
      labelKey: 'name',
      idKey:    'id',
    });

    searchBtn.addEventListener('click', async () => {
      clearError(errorBox);
      resultsBox.innerHTML = '';

      if (!state.hotel.selectedDestination) {
        showError(errorBox, 'Please select a destination from the dropdown suggestions.');
        return;
      }

      const checkIn   = root.querySelector('#hotel-checkin').value;
      const checkOut  = root.querySelector('#hotel-checkout').value;
      const adults    = parseInt(root.querySelector('#hotel-adults').value, 10);
      const children  = parseInt(root.querySelector('#hotel-children').value, 10);
      const rooms     = parseInt(root.querySelector('#hotel-rooms').value, 10);
      const currency  = root.querySelector('#hotel-currency').value;

      if (!checkIn || !checkOut) {
        showError(errorBox, 'Please select check-in and check-out dates.');
        return;
      }

      if (new Date(checkIn) >= new Date(checkOut)) {
        showError(errorBox, 'Check-out must be after check-in.');
        return;
      }

      setLoading(searchBtn, true);

      try {
        const data = await api('/hotel-search.php', {
          destinationId: state.hotel.selectedDestination.id,
          checkIn, checkOut, adults, children, rooms, currency,
        });

        state.hotel.token         = data.token;
        state.hotel.correlationId = data.correlationId;
        state.hotel.results       = data.hotels || [];

        renderHotelResults(root, data.hotels || [], { checkIn, checkOut, adults, children, rooms, currency });
      } catch (err) {
        showError(errorBox, err.message || 'Hotel search failed. Please try again.');
      } finally {
        setLoading(searchBtn, false);
      }
    });
  }

  function renderHotelResults(root, hotels, searchParams) {
    const box = root.querySelector('#hotel-results');
    box.innerHTML = '';

    if (!hotels.length) {
      showError(root.querySelector('#hotel-error'), 'No hotels found for your search. Try adjusting your dates or destination.');
      return;
    }

    const grid = el('div', { className: 'rs-hotel-grid' });

    hotels.forEach(hotel => {
      const card = el('div', { className: 'rs-hotel-card' },
        hotel.heroImage ? el('img', { src: hotel.heroImage, alt: hotel.name, className: 'rs-hotel-img', loading: 'lazy' }) : null,
        el('div', { className: 'rs-hotel-body' },
          el('h3', { className: 'rs-hotel-name' }, hotel.name),
          el('div', { className: 'rs-hotel-stars' }, '★'.repeat(Math.round(hotel.starRating || 0))),
          hotel.distance ? el('p', { className: 'rs-hotel-dist' }, hotel.distance) : null,
          el('div', { className: 'rs-hotel-price' },
            hotel.saving ? el('span', { className: 'rs-hotel-was' }, hotel.currency + ' ' + hotel.publishedRate) : null,
            el('span', { className: 'rs-hotel-now' }, (hotel.currency || searchParams.currency) + ' ' + hotel.ourprice),
            hotel.saving ? el('span', { className: 'rs-hotel-saving' }, 'Save ' + hotel.saving + '%') : null,
          ),
          el('button', {
            className: 'rs-btn-secondary',
            type: 'button',
            onClick: () => openHotelRooms(root, hotel, searchParams),
          }, 'View Rooms'),
        ),
      );
      grid.appendChild(card);
    });

    box.appendChild(grid);
  }

  async function openHotelRooms(root, hotel, searchParams) {
    const resultsBox = root.querySelector('#hotel-results');
    const errorBox   = root.querySelector('#hotel-error');
    clearError(errorBox);
    resultsBox.innerHTML = '<p class="rs-loading">Loading rooms…</p>';

    try {
      const data = await api('/hotel-rooms.php', {
        hotelId:       hotel.id,
        token:         state.hotel.token,
        correlationId: state.hotel.correlationId,
        checkIn:       searchParams.checkIn,
        checkOut:      searchParams.checkOut,
        adults:        searchParams.adults,
        children:      searchParams.children,
        rooms:         searchParams.rooms,
        currency:      searchParams.currency,
      });

      state.hotel.token         = data.token;
      state.hotel.correlationId = data.correlationId;
      state.hotel.selectedHotel = hotel;

      renderRooms(root, hotel, data.rooms || [], searchParams);
    } catch (err) {
      resultsBox.innerHTML = '';
      showError(errorBox, 'Could not load rooms: ' + (err.message || 'Please try again.'));
    }
  }

  function renderRooms(root, hotel, rooms, searchParams) {
    const box = root.querySelector('#hotel-results');
    box.innerHTML = '';

    const back = el('button', {
      className: 'rs-btn-back',
      type: 'button',
      onClick: async () => {
        box.innerHTML = '';
        renderHotelResults(root, state.hotel.results, searchParams);
      },
    }, '← Back to results');

    box.appendChild(back);
    box.appendChild(el('h3', { className: 'rs-rooms-title' }, 'Rooms at ' + hotel.name));

    if (!rooms.length) {
      box.appendChild(el('p', {}, 'No rooms available for the selected dates.'));
      return;
    }

    rooms.forEach(room => {
      const card = el('div', { className: 'rs-room-card' },
        el('div', { className: 'rs-room-body' },
          el('h4', { className: 'rs-room-name' }, room.name),
          room.description ? el('p', { className: 'rs-room-desc' }, room.description) : null,
          el('p', { className: 'rs-room-board' }, room.boardBasis || ''),
          el('p', { className: 'rs-room-refund' }, room.refundable ? '✓ Refundable' : '✗ Non-refundable'),
          el('div', { className: 'rs-room-price' },
            room.publishedRate && room.publishedRate !== room.ourprice
              ? el('span', { className: 'rs-hotel-was' }, searchParams.currency + ' ' + room.publishedRate)
              : null,
            el('span', { className: 'rs-hotel-now' }, searchParams.currency + ' ' + room.ourprice),
          ),
          el('button', {
            className: 'rs-btn-primary',
            type: 'button',
            onClick: () => bookRoom(root, hotel, room, searchParams),
          }, 'Select Room'),
        ),
      );
      box.appendChild(card);
    });
  }

  async function bookRoom(root, hotel, room, searchParams) {
    const errorBox = root.querySelector('#hotel-error');
    clearError(errorBox);

    // Step 1: revalidate
    let revalData;
    try {
      const data = await api('/hotel-revalidate.php', {
        hotelId:          hotel.id,
        token:            state.hotel.token,
        correlationId:    state.hotel.correlationId,
        recommendationId: room.recommendationId,
        roomId:           room.id,
        publishedRate:    room.publishedRate,
      });
      revalData = data.data;
    } catch (err) {
      if (err.httpStatus === 410) {
        showError(errorBox, 'This offer has expired. Please search again.');
      } else {
        showError(errorBox, 'Rate check failed: ' + (err.message || 'Please try again.'));
      }
      return;
    }

    // Step 2: get checkout URL
    try {
      const data = await api('/hotel-checkout.php', {
        token:            state.hotel.token,
        correlationId:    state.hotel.correlationId,
        hotelId:          hotel.id,
        recommendationId: room.recommendationId,
        roomId:           room.id,
        publishedRate:    room.publishedRate,
      });

      window.location.href = data.checkoutUrl;
    } catch (err) {
      showError(errorBox, 'Checkout failed: ' + (err.message || 'Please try again.'));
    }
  }

  // ---------------------------------------------------------------------------
  // Flight flow
  // ---------------------------------------------------------------------------

  function initFlightFlow(root) {
    const btn      = root.querySelector('#flight-search-btn');
    const errorBox = root.querySelector('#flight-error');
    const resultsBox = root.querySelector('#flight-results');

    btn.addEventListener('click', async () => {
      clearError(errorBox);
      resultsBox.innerHTML = '';

      const originCode      = root.querySelector('#flight-origin').value.toUpperCase().trim();
      const destinationCode = root.querySelector('#flight-dest').value.toUpperCase().trim();
      const departureDate   = root.querySelector('#flight-depart').value;
      const returnDate      = root.querySelector('#flight-return').value;
      const adults          = parseInt(root.querySelector('#flight-adults').value, 10);
      const children        = parseInt(root.querySelector('#flight-children').value, 10);
      const cabinClass      = root.querySelector('#flight-cabin').value;
      const currency        = root.querySelector('#flight-currency').value;

      if (!originCode || originCode.length !== 3) {
        showError(errorBox, 'Please enter a valid 3-letter origin airport code (e.g. LHR).');
        return;
      }
      if (!destinationCode || destinationCode.length !== 3) {
        showError(errorBox, 'Please enter a valid 3-letter destination airport code (e.g. JFK).');
        return;
      }
      if (!departureDate) {
        showError(errorBox, 'Please select a departure date.');
        return;
      }

      setLoading(btn, true);

      try {
        // Create session
        let sessionId = state.flight.sessionId;
        if (!sessionId) {
          const sess = await api('/flight-search.php', { action: 'session' });
          sessionId = sess.sessionId;
          state.flight.sessionId = sessionId;
        }

        const body = {
          action: 'search',
          sessionId,
          originCode,
          destinationCode,
          departureDate,
          adults,
          children,
          cabinClass,
          currency,
        };
        if (returnDate) body.returnDate = returnDate;

        const data = await api('/flight-search.php', body);

        state.flight.sessionId      = data.sessionId;
        state.flight.correlationId  = data.correlationId;
        state.flight.searchFilterObj = data.searchFilterObj;
        state.flight.results        = data.flights || [];

        renderFlightResults(root, data.flights || [], currency);
      } catch (err) {
        showError(errorBox, err.message || 'Flight search failed. Please try again.');
        // Reset session on error so next search starts fresh
        state.flight.sessionId = null;
      } finally {
        setLoading(btn, false);
      }
    });
  }

  function renderFlightResults(root, flights, currency) {
    const box = root.querySelector('#flight-results');
    box.innerHTML = '';

    if (!flights.length) {
      showError(root.querySelector('#flight-error'), 'No flights found. Try different dates or airports.');
      return;
    }

    flights.forEach(flight => {
      const legs = (flight.flights || []).map(leg =>
        `${leg.departure} ${leg.departureTime} → ${leg.arrival} ${leg.arrivalTime}`
      ).join(' | ');

      const card = el('div', { className: 'rs-flight-card' },
        el('div', { className: 'rs-flight-route' }, legs || 'Flight details unavailable'),
        el('div', { className: 'rs-flight-info' },
          el('span', {}, `Stops: ${flight.stops ?? 0}`),
          el('span', { className: 'rs-hotel-now' }, `${currency} ${flight.ourprice ?? ''}`),
        ),
        el('button', {
          className: 'rs-btn-primary',
          type: 'button',
          onClick: () => bookFlight(root, flight, currency),
        }, 'Select'),
      );
      box.appendChild(card);
    });
  }

  async function bookFlight(root, flight, currency) {
    const errorBox = root.querySelector('#flight-error');
    clearError(errorBox);

    try {
      const reval = await api('/flight-search.php', {
        action:          'revalidate',
        sessionId:       state.flight.sessionId,
        correlationId:   state.flight.correlationId,
        fareSourceCode:  flight.fareSourceCode,
        searchFilterObj: state.flight.searchFilterObj,
      });

      if (reval.data?.expired) {
        showError(errorBox, 'This fare has expired. Please search again.');
        return;
      }
    } catch (err) {
      if (err.httpStatus === 410) {
        showError(errorBox, 'This fare has expired. Please search again.');
      } else {
        showError(errorBox, 'Price check failed: ' + (err.message || 'Please try again.'));
      }
      return;
    }

    try {
      const data = await api('/flight-search.php', {
        action:         'checkout',
        sessionId:      state.flight.sessionId,
        fareSourceCode: flight.fareSourceCode,
      });
      window.location.href = data.checkoutUrl;
    } catch (err) {
      showError(errorBox, 'Checkout failed: ' + (err.message || 'Please try again.'));
    }
  }

  // ---------------------------------------------------------------------------
  // Car hire flow
  // ---------------------------------------------------------------------------

  function initCarFlow(root) {
    const btn      = root.querySelector('#car-search-btn');
    const errorBox = root.querySelector('#car-error');
    const resultsBox = root.querySelector('#car-results');

    // Autocomplete for pickup location
    const pickupInput = root.querySelector('#car-pickup');
    Autocomplete({
      input:    pickupInput,
      fetchFn:  async (q) => {
        const data = await api('/car-search.php', { action: 'locations', query: q });
        return data.locations || [];
      },
      onSelect: (item) => { state.car.pickupLocation = item; },
      labelKey: 'name',
      idKey:    'id',
    });

    const dropoffInput = root.querySelector('#car-dropoff');
    Autocomplete({
      input:    dropoffInput,
      fetchFn:  async (q) => {
        const data = await api('/car-search.php', { action: 'locations', query: q });
        return data.locations || [];
      },
      onSelect: (item) => { state.car.dropoffLocation = item; },
      labelKey: 'name',
      idKey:    'id',
    });

    btn.addEventListener('click', async () => {
      clearError(errorBox);
      resultsBox.innerHTML = '';

      if (!state.car.pickupLocation) {
        showError(errorBox, 'Please select a pick-up location from the suggestions.');
        return;
      }

      const pickupDate  = root.querySelector('#car-pickup-date').value;
      const dropoffDate = root.querySelector('#car-dropoff-date').value;
      const driverAge   = parseInt(root.querySelector('#car-age').value, 10);
      const currency    = root.querySelector('#car-currency').value;

      if (!pickupDate || !dropoffDate) {
        showError(errorBox, 'Please select pick-up and drop-off dates.');
        return;
      }
      if (new Date(pickupDate) >= new Date(dropoffDate)) {
        showError(errorBox, 'Drop-off must be after pick-up.');
        return;
      }

      setLoading(btn, true);

      try {
        const body = {
          action:            'search',
          pickupLocationId:  state.car.pickupLocation.id,
          pickupDate,
          dropoffDate,
          driverAge,
          currency,
        };
        if (state.car.dropoffLocation) {
          body.dropoffLocationId = state.car.dropoffLocation.id;
        }

        const data = await api('/car-search.php', body);
        state.car.results = data.cars || [];
        renderCarResults(root, data.cars || [], currency);
      } catch (err) {
        showError(errorBox, err.message || 'Car search failed. Please try again.');
      } finally {
        setLoading(btn, false);
      }
    });
  }

  function renderCarResults(root, cars, currency) {
    const box = root.querySelector('#car-results');
    box.innerHTML = '';

    if (!cars.length) {
      showError(root.querySelector('#car-error'), 'No cars available for these dates and location.');
      return;
    }

    cars.forEach(car => {
      const card = el('div', { className: 'rs-car-card' },
        el('h4', {}, car.name || 'Vehicle'),
        el('p', {}, car.category || ''),
        el('span', { className: 'rs-hotel-now' }, `${currency} ${car.price ?? car.ourprice ?? ''}`),
        el('button', {
          className: 'rs-btn-primary',
          type: 'button',
          onClick: async () => {
            try {
              const data = await api('/car-search.php', {
                action: 'checkout',
                vehicleId: car.id,
              });
              window.location.href = data.checkoutUrl;
            } catch (err) {
              showError(root.querySelector('#car-error'), 'Checkout failed: ' + (err.message || 'Please try again.'));
            }
          },
        }, 'Select'),
      );
      box.appendChild(card);
    });
  }

  // ---------------------------------------------------------------------------
  // Styles (injected so widget is self-contained)
  // ---------------------------------------------------------------------------

  function injectStyles() {
    if (document.getElementById('rs-widget-styles')) return;
    const css = `
      .rs-widget { font-family: system-ui, -apple-system, sans-serif; color: #1a1a2e; }
      .rs-hero { background: #1a1a2e; color: #fff; text-align: center; padding: 3rem 1rem; }
      .rs-hero-label { letter-spacing: .15em; font-size: .75rem; margin: 0 0 .5rem; opacity: .7; }
      .rs-hero-title { font-size: clamp(1.75rem, 4vw, 3rem); margin: 0 0 1rem; font-weight: 700; }
      .rs-hero-sub { margin: 0 auto; max-width: 52ch; opacity: .85; line-height: 1.5; }
      .rs-card { background: #fff; max-width: 900px; margin: 1.5rem auto; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12); overflow: hidden; }
      .rs-tabs { display: flex; border-bottom: 2px solid #eee; }
      .rs-tab { flex: 1; padding: .9rem; background: none; border: none; cursor: pointer; font-size: 1rem; font-weight: 600; color: #555; transition: color .15s; }
      .rs-tab--active { color: #c9a227; border-bottom: 2px solid #c9a227; margin-bottom: -2px; }
      .rs-panel { padding: 1.5rem; }
      .rs-panel--hidden { display: none; }
      .rs-panel-title { font-size: 1.25rem; margin: 0 0 .25rem; }
      .rs-panel-sub { color: #666; margin: 0 0 1.25rem; }
      .rs-form { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .75rem; align-items: end; }
      .rs-field { display: flex; flex-direction: column; gap: .3rem; }
      .rs-field--full { grid-column: 1 / -1; }
      .rs-field--action { display: flex; align-items: flex-end; }
      .rs-field label { font-size: .8rem; font-weight: 600; color: #444; }
      .rs-field input, .rs-field select { padding: .6rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: .9rem; width: 100%; }
      .rs-field input:focus, .rs-field select:focus { outline: 2px solid #c9a227; border-color: #c9a227; }
      .rs-btn-primary { background: #c9a227; color: #1a1a2e; border: none; padding: .65rem 1.25rem; border-radius: 4px; font-weight: 700; cursor: pointer; width: 100%; font-size: .95rem; transition: background .15s; }
      .rs-btn-primary:hover:not(:disabled) { background: #b8911f; }
      .rs-btn-primary:disabled { opacity: .65; cursor: not-allowed; }
      .rs-btn-secondary { background: #f4f4f8; border: 1px solid #ddd; padding: .55rem 1rem; border-radius: 4px; cursor: pointer; font-size: .875rem; font-weight: 600; }
      .rs-btn-secondary:hover { background: #e8e8ef; }
      .rs-btn-back { background: none; border: none; color: #c9a227; cursor: pointer; font-size: .875rem; padding: 0 0 .75rem; font-weight: 600; }
      .rs-error { background: #fff5f5; border: 1px solid #fca5a5; color: #b91c1c; padding: .75rem 1rem; border-radius: 4px; margin: .75rem 0; }
      .rs-loading { color: #666; padding: 1rem 0; }
      .rs-hotel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; margin-top: 1rem; }
      .rs-hotel-card { border: 1px solid #eee; border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; }
      .rs-hotel-img { width: 100%; height: 160px; object-fit: cover; }
      .rs-hotel-body { padding: .875rem; flex: 1; display: flex; flex-direction: column; gap: .35rem; }
      .rs-hotel-name { font-size: 1rem; font-weight: 700; margin: 0; }
      .rs-hotel-stars { color: #f59e0b; }
      .rs-hotel-dist { color: #666; font-size: .8rem; margin: 0; }
      .rs-hotel-price { display: flex; align-items: baseline; gap: .5rem; flex-wrap: wrap; margin-top: auto; }
      .rs-hotel-was { text-decoration: line-through; color: #999; font-size: .875rem; }
      .rs-hotel-now { font-size: 1.2rem; font-weight: 700; color: #1a1a2e; }
      .rs-hotel-saving { background: #dcfce7; color: #166534; font-size: .75rem; padding: .15rem .4rem; border-radius: 3px; font-weight: 700; }
      .rs-rooms-title { font-size: 1.1rem; font-weight: 700; margin: .5rem 0 .75rem; }
      .rs-room-card { border: 1px solid #eee; border-radius: 6px; margin-bottom: .75rem; }
      .rs-room-body { padding: .875rem; display: flex; flex-direction: column; gap: .3rem; }
      .rs-room-name { font-weight: 700; font-size: .95rem; margin: 0; }
      .rs-room-desc { color: #555; font-size: .85rem; margin: 0; }
      .rs-room-board { font-size: .8rem; color: #666; }
      .rs-room-refund { font-size: .8rem; }
      .rs-room-price { display: flex; gap: .5rem; align-items: baseline; }
      .rs-flight-card { border: 1px solid #eee; border-radius: 6px; padding: .875rem; margin-bottom: .75rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
      .rs-flight-route { font-size: .9rem; font-weight: 600; }
      .rs-flight-info { display: flex; gap: 1rem; align-items: center; }
      .rs-car-card { border: 1px solid #eee; border-radius: 6px; padding: .875rem; margin-bottom: .75rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
      .rs-autocomplete-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #ccc; border-top: none; border-radius: 0 0 4px 4px; list-style: none; margin: 0; padding: 0; z-index: 1000; max-height: 240px; overflow-y: auto; box-shadow: 0 4px 8px rgba(0,0,0,.1); }
      .rs-autocomplete-item { padding: .6rem .75rem; cursor: pointer; font-size: .875rem; }
      .rs-autocomplete-item:hover, .rs-autocomplete-item[aria-selected="true"] { background: #f4f4f8; }
      .rs-legal { font-size: .75rem; color: #888; padding: 0 1.5rem 1rem; line-height: 1.5; }
    `;
    const style = document.createElement('style');
    style.id = 'rs-widget-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  // ---------------------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------------------

  function init() {
    const root = document.getElementById('ai-travel-widget');
    if (!root) return;

    injectStyles();
    buildWidget(root);
    initTabs(root);
    initDefaultDates(root);
    initHotelFlow(root);
    initFlightFlow(root);
    initCarFlow(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
