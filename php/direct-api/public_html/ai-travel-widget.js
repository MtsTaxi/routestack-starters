/**
 * ai-travel-widget.js — I LOVE VOYAGE
 * RouteStack Travel Booking Widget (PHP direct-api integration)
 *
 * Handles: Hotels · Flights · Car hire
 * State machine: search → results → rooms/revalidate → checkout redirect
 */

/* global RS */
'use strict';

// ============================================================
// 1. Shared helpers
// ============================================================

/** Typed JSON POST to an endpoint in /ai-travel-api/ */
async function rsPost(endpoint, body) {
  const resp = await fetch(`${RS.apiBase}/${endpoint}`, {
    method:  'POST',
    headers: {
      'Content-Type':  'application/json',
      'X-CSRF-Token':  RS.csrfToken,
    },
    body: JSON.stringify(body),
  });
  const json = await resp.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
  if (!resp.ok) {
    const msg  = json.message || json.error || `HTTP ${resp.status}`;
    const err  = new Error(msg);
    err.status = resp.status;
    err.json   = json;
    throw err;
  }
  return json;
}

function fmt(amount, currency = RS.currency) {
  return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(amount);
}

function stars(n) {
  return '★'.repeat(Math.min(5, Math.max(0, Math.round(n))));
}

function setLoading(btnEl, spinnerEl, loading, defaultText) {
  btnEl.disabled      = loading;
  spinnerEl.hidden    = !loading;
  btnEl.firstChild.textContent = loading ? ' ' : defaultText;
}

function showMsg(el, msg, isError = false) {
  el.textContent = msg;
  el.style.color = isError ? '#c00' : '#333';
}

function clearEl(el) { el.innerHTML = ''; }

// ============================================================
// 2. Child-age helpers
// ============================================================

function renderChildAges(containerEl, count) {
  clearEl(containerEl);
  if (count < 1) return;

  const row = document.createElement('div');
  row.className = 'child-ages-row';
  row.setAttribute('aria-label', 'Child ages');

  for (let i = 0; i < count; i++) {
    const lbl = document.createElement('label');
    lbl.innerHTML = `Child ${i + 1} age:`;

    const sel = document.createElement('select');
    sel.dataset.childIndex = String(i);
    sel.setAttribute('aria-label', `Age of child ${i + 1}`);
    for (let age = 0; age <= 17; age++) {
      const opt = document.createElement('option');
      opt.value = String(age);
      opt.textContent = age === 0 ? '< 1' : String(age);
      if (age === 8) opt.selected = true;
      sel.appendChild(opt);
    }

    lbl.appendChild(sel);
    row.appendChild(lbl);
  }

  containerEl.appendChild(row);
}

function collectChildAges(containerEl) {
  const selects = containerEl.querySelectorAll('select[data-child-index]');
  return Array.from(selects).map(s => parseInt(s.value, 10));
}

// ============================================================
// 3. Tab switching
// ============================================================

(function initTabs() {
  const buttons = document.querySelectorAll('.tw-tabs button[data-tab]');
  const panels  = document.querySelectorAll('.tw-panel');

  buttons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      buttons.forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
      panels.forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
      document.getElementById(`tab-${target}`)?.classList.add('active');
    });
  });
})();

// ============================================================
// 4. Autocomplete helper
// ============================================================

function makeAutocomplete({ inputEl, listEl, hiddenEl, fetch: fetchFn, formatItem }) {
  let timer = null;
  let active = false;

  inputEl.addEventListener('input', () => {
    clearTimeout(timer);
    const q = inputEl.value.trim();
    if (q.length < 2) { listEl.hidden = true; return; }
    timer = setTimeout(async () => {
      try {
        const items = await fetchFn(q);
        listEl.innerHTML = '';
        if (!items.length) { listEl.hidden = true; return; }
        items.slice(0, 8).forEach(item => {
          const d = document.createElement('div');
          d.textContent = formatItem(item);
          d.addEventListener('mousedown', e => {
            e.preventDefault();
            inputEl.value    = formatItem(item);
            if (hiddenEl) hiddenEl.value = item.id ?? item.code ?? '';
            listEl.hidden = true;
            active = false;
          });
          listEl.appendChild(d);
        });
        listEl.hidden = false;
        active = true;
      } catch (_) {
        listEl.hidden = true;
      }
    }, 300);
  });

  inputEl.addEventListener('blur', () => {
    setTimeout(() => { listEl.hidden = true; active = false; }, 150);
  });
}

// ============================================================
// 5. HOTELS
// ============================================================

const hotel = {
  token:         null,
  correlationId: null,
  hotelId:       null,
  hotelName:     null,
};

// 5a. Destination autocomplete
makeAutocomplete({
  inputEl:   document.getElementById('hotel-destination'),
  listEl:    document.getElementById('hotel-dest-list'),
  hiddenEl:  document.getElementById('hotel-destination-id'),
  fetch: async q => {
    const r = await rsPost('hotel-destinations.php', { query: q });
    return r.destinations ?? [];
  },
  formatItem: d => d.name ?? d.cityName ?? d.label ?? d,
});

// 5b. Child age rendering
(function () {
  const sel       = document.getElementById('hotel-children');
  const container = document.getElementById('hotel-child-ages-container');
  sel.addEventListener('change', () => renderChildAges(container, parseInt(sel.value, 10)));
})();

// 5c. Default dates (today + 7 days)
(function setDefaultHotelDates() {
  const checkin  = document.getElementById('hotel-checkin');
  const checkout = document.getElementById('hotel-checkout');
  const today    = new Date();
  const d7       = new Date(today.getTime() + 7 * 86400000);
  const d14      = new Date(today.getTime() + 14 * 86400000);
  checkin.min    = today.toISOString().slice(0, 10);
  checkin.value  = d7.toISOString().slice(0, 10);
  checkout.min   = checkin.value;
  checkout.value = d14.toISOString().slice(0, 10);
  checkin.addEventListener('change', () => {
    checkout.min = checkin.value;
    if (checkout.value <= checkin.value) {
      const co = new Date(checkin.value);
      co.setDate(co.getDate() + 1);
      checkout.value = co.toISOString().slice(0, 10);
    }
  });
})();

// 5d. Hotel search
document.getElementById('hotel-search-form').addEventListener('submit', async e => {
  e.preventDefault();

  const btn        = document.getElementById('hotel-search-btn');
  const spinner    = document.getElementById('hotel-spinner');
  const msg        = document.getElementById('hotel-search-msg');
  const results    = document.getElementById('tw-hotel-results');
  const childCount = parseInt(document.getElementById('hotel-children').value, 10);
  const childAges  = collectChildAges(document.getElementById('hotel-child-ages-container'));

  clearEl(results);
  showMsg(msg, 'Searching…');
  setLoading(btn, spinner, true, 'Search Hotels');

  const body = {
    destination: document.getElementById('hotel-destination').value.trim(),
    destinationId: document.getElementById('hotel-destination-id').value,
    checkin:     document.getElementById('hotel-checkin').value,
    checkout:    document.getElementById('hotel-checkout').value,
    adults:      parseInt(document.getElementById('hotel-adults').value, 10),
    children:    childCount,
    rooms:       parseInt(document.getElementById('hotel-rooms').value, 10),
    currency:    RS.currency,
  };

  if (childCount > 0) {
    body.childAges = childAges.length === childCount
      ? childAges
      : Array.from({ length: childCount }, (_, i) => childAges[i] ?? 8);
  }

  try {
    const data = await rsPost('hotel-search.php', body);
    hotel.token         = data.token;
    hotel.correlationId = data.correlationId;
    showMsg(msg, `${data.hotels.length} hotel${data.hotels.length !== 1 ? 's' : ''} found`);
    renderHotelResults(results, data.hotels, body.checkin, body.checkout, body.adults, childCount, childAges);
  } catch (err) {
    showMsg(msg, err.message, true);
    results.innerHTML = `<div class="tw-error">${escHtml(err.message)}</div>`;
  } finally {
    setLoading(btn, spinner, false, 'Search Hotels');
  }
});

function renderHotelResults(container, hotels, checkin, checkout, adults, children, childAges) {
  if (!hotels.length) {
    container.innerHTML = '<div class="tw-info">No hotels found for your search. Try different dates or a broader destination.</div>';
    return;
  }

  const nights = Math.round((new Date(checkout) - new Date(checkin)) / 86400000);

  container.innerHTML = hotels.map(h => `
    <div class="hotel-card">
      <div class="hotel-card-info">
        <h3>${escHtml(h.hotelName ?? h.name ?? 'Hotel')}</h3>
        <div class="stars">${stars(h.starRating ?? h.stars ?? h.rating ?? 0)}</div>
        <div class="address">${escHtml(h.address ?? h.location ?? '')}</div>
      </div>
      <div class="hotel-card-price">
        <div class="amount">${fmt(h.ourprice ?? h.price ?? 0, h.currency ?? RS.currency)}</div>
        <div class="per-night">for ${nights} night${nights !== 1 ? 's' : ''}</div>
        <button class="tw-btn" style="margin-top:10px;"
          data-hotel-id="${escHtml(h.hotelId ?? h.id ?? '')}"
          data-hotel-name="${escHtml(h.hotelName ?? h.name ?? '')}"
          data-checkin="${escHtml(checkin)}"
          data-checkout="${escHtml(checkout)}"
          data-adults="${adults}"
          data-children="${children}"
          data-child-ages="${escHtml(JSON.stringify(childAges))}"
          onclick="viewHotelRooms(this)">
          View Rooms
        </button>
      </div>
    </div>`).join('');
}

// 5e. View Rooms → hotel-details.php
window.viewHotelRooms = async function(btn) {
  const hotelId   = btn.dataset.hotelId;
  const hotelName = btn.dataset.hotelName;
  const modal     = document.getElementById('tw-room-modal');
  const roomList  = document.getElementById('tw-room-list');
  const title     = document.getElementById('room-modal-title');

  title.textContent = hotelName || 'Select a Room';
  roomList.innerHTML = '<div style="text-align:center;padding:30px"><span class="tw-spinner"></span> Loading rooms…</div>';
  modal.classList.add('open');

  hotel.hotelId   = hotelId;
  hotel.hotelName = hotelName;

  const childAges = (() => {
    try { return JSON.parse(btn.dataset.childAges || '[]'); } catch (_) { return []; }
  })();
  const children = parseInt(btn.dataset.children, 10);

  try {
    const data = await rsPost('hotel-details.php', {
      token:         hotel.token,
      correlationId: hotel.correlationId,
      hotelId:       hotelId,
      checkin:       btn.dataset.checkin,
      checkout:      btn.dataset.checkout,
      adults:        parseInt(btn.dataset.adults, 10),
      children:      children,
      childAges:     children > 0 ? childAges : undefined,
      currency:      RS.currency,
    });

    hotel.token         = data.token         ?? hotel.token;
    hotel.correlationId = data.correlationId ?? hotel.correlationId;

    renderRoomList(roomList, data.rooms ?? []);
  } catch (err) {
    roomList.innerHTML = `<div class="tw-error">${escHtml(err.message)}</div>`;
  }
};

function renderRoomList(container, rooms) {
  if (!rooms.length) {
    container.innerHTML = '<div class="tw-info">No rooms are available for the selected dates. Please adjust your search.</div>';
    return;
  }
  container.innerHTML = rooms.map(r => `
    <div class="room-card">
      <div>
        <h4>${escHtml(r.roomName ?? r.name ?? 'Room')}</h4>
        <div class="board">${escHtml(r.boardBasis ?? r.mealPlan ?? r.board ?? '')}</div>
        ${r.refundable === true ? '<div style="font-size:12px;color:#060">✓ Refundable</div>' : ''}
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
        <div class="room-card-price">${fmt(r.ourprice ?? r.price ?? 0, r.currency ?? RS.currency)}</div>
        <button class="tw-btn"
          data-recommendation-id="${escHtml(r.recommendationId ?? r.id ?? '')}"
          data-fare-source="${escHtml(r.fareSourceCode ?? r.sourceCode ?? '')}"
          onclick="bookHotelRoom(this)">
          Book
        </button>
      </div>
    </div>`).join('');
}

// 5f. Book room → revalidate → payment URL → redirect
window.bookHotelRoom = async function(btn) {
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  const recId  = btn.dataset.recommendationId;
  const source = btn.dataset.fareSource;

  try {
    // Revalidate
    const rev = await rsPost('hotel-revalidate.php', {
      token:            hotel.token,
      correlationId:    hotel.correlationId,
      hotelId:          hotel.hotelId,
      recommendationId: recId,
      fareSourceCode:   source,
    });

    if (!rev.success) {
      throw new Error(rev.message || 'Revalidation failed.');
    }

    // Payment URL
    const pay = await rsPost('hotel-payment.php', {
      token:            hotel.token,
      correlationId:    hotel.correlationId,
      hotelId:          hotel.hotelId,
      recommendationId: recId,
      fareSourceCode:   source,
    });

    if (!pay.checkoutUrl) throw new Error('No checkout URL returned.');

    // Redirect to RouteStack hosted checkout
    window.location.href = pay.checkoutUrl;

  } catch (err) {
    btn.disabled    = false;
    btn.textContent = 'Book';
    const roomList  = document.getElementById('tw-room-list');
    const prev = roomList.querySelector('.tw-error');
    if (prev) prev.remove();
    const errDiv = document.createElement('div');
    errDiv.className = 'tw-error';
    errDiv.style.marginTop = '12px';
    errDiv.textContent = err.status === 410
      ? 'This room is no longer available. Please search again.'
      : err.message;
    roomList.prepend(errDiv);
  }
};

// Close room modal
document.getElementById('tw-room-close').addEventListener('click', () => {
  document.getElementById('tw-room-modal').classList.remove('open');
});
document.getElementById('tw-room-modal').addEventListener('click', e => {
  if (e.target === document.getElementById('tw-room-modal')) {
    document.getElementById('tw-room-modal').classList.remove('open');
  }
});

// ============================================================
// 6. FLIGHTS
// ============================================================

const flightState = {
  sessionId:       null,
  correlationId:   null,
  searchFilterObj: null,
};

// 6a. Flight location autocomplete helper
async function fetchFlightLocations(sessionId, q) {
  const r = await rsPost('flight-locations.php', { sessionId, query: q });
  return r.locations ?? [];
}

// 6b. One-way toggle
(function() {
  document.querySelectorAll('[name="triptype"]').forEach(radio => {
    radio.addEventListener('change', () => {
      const isReturn = document.querySelector('[name="triptype"]:checked')?.value === 'return';
      const wrap     = document.getElementById('return-date-wrap');
      wrap.style.opacity  = isReturn ? '1' : '.4';
      document.getElementById('flight-return').required = isReturn;
    });
  });
})();

// 6c. Initialise flight session then wire autocompetes
(async function initFlightSession() {
  try {
    const r = await rsPost('flight-session.php', {});
    flightState.sessionId = r.sessionId;

    const wires = [
      { inputId: 'flight-origin', listId: 'flight-origin-list', hiddenId: 'flight-origin-code' },
      { inputId: 'flight-dest',   listId: 'flight-dest-list',   hiddenId: 'flight-dest-code'   },
    ];
    wires.forEach(({ inputId, listId, hiddenId }) => {
      makeAutocomplete({
        inputEl:  document.getElementById(inputId),
        listEl:   document.getElementById(listId),
        hiddenEl: document.getElementById(hiddenId),
        fetch:    q => fetchFlightLocations(flightState.sessionId, q),
        formatItem: loc => `${loc.code ? `[${loc.code}] ` : ''}${loc.name ?? loc.label ?? loc}`,
      });
    });
  } catch (_) {
    // Flight session failed — autocompete won't work but manual IATA entry still does
  }
})();

// 6d. Default flight dates
(function() {
  const d  = document.getElementById('flight-depart');
  const r  = document.getElementById('flight-return');
  const t  = new Date();
  const d1 = new Date(t.getTime() + 30 * 86400000);
  const d2 = new Date(t.getTime() + 37 * 86400000);
  d.min = t.toISOString().slice(0, 10);
  d.value = d1.toISOString().slice(0, 10);
  r.min   = d.value;
  r.value = d2.toISOString().slice(0, 10);
  d.addEventListener('change', () => {
    r.min = d.value;
    if (r.value <= d.value) {
      const rd = new Date(d.value);
      rd.setDate(rd.getDate() + 7);
      r.value = rd.toISOString().slice(0, 10);
    }
  });
})();

// 6e. Flight search
document.getElementById('flight-search-form').addEventListener('submit', async e => {
  e.preventDefault();

  const btn     = document.getElementById('flight-search-btn');
  const spinner = document.getElementById('flight-spinner');
  const msg     = document.getElementById('flight-search-msg');
  const results = document.getElementById('tw-flight-results');

  clearEl(results);
  showMsg(msg, 'Searching…');
  setLoading(btn, spinner, true, 'Search Flights');

  const isReturn  = document.querySelector('[name="triptype"]:checked')?.value === 'return';
  const originRaw = document.getElementById('flight-origin').value.trim().toUpperCase();
  const destRaw   = document.getElementById('flight-dest').value.trim().toUpperCase();

  // Accept both hidden (autocomplete) or manual 3-letter entry
  const origin = document.getElementById('flight-origin-code').value || (originRaw.length === 3 ? originRaw : '');
  const dest   = document.getElementById('flight-dest-code').value   || (destRaw.length   === 3 ? destRaw   : '');

  if (!origin || !dest) {
    showMsg(msg, 'Please select origin and destination airports.', true);
    setLoading(btn, spinner, false, 'Search Flights');
    return;
  }

  const body = {
    sessionId:       flightState.sessionId,
    originCode:      origin,
    destinationCode: dest,
    departureDate:   document.getElementById('flight-depart').value,
    adults:          parseInt(document.getElementById('flight-adults').value, 10),
    children:        parseInt(document.getElementById('flight-children').value, 10),
    infants:         parseInt(document.getElementById('flight-infants').value, 10),
    cabinClass:      document.getElementById('flight-cabin').value,
    currency:        RS.currency,
  };
  if (isReturn) {
    body.returnDate = document.getElementById('flight-return').value;
  }

  try {
    const data = await rsPost('flight-search.php', body);
    flightState.sessionId       = data.sessionId       ?? flightState.sessionId;
    flightState.correlationId   = data.correlationId;
    flightState.searchFilterObj = data.searchFilterObj;
    showMsg(msg, `${data.flights.length} result${data.flights.length !== 1 ? 's' : ''} found`);
    renderFlightResults(results, data.flights);
  } catch (err) {
    showMsg(msg, err.message, true);
    results.innerHTML = `<div class="tw-error">${escHtml(err.message)}</div>`;
  } finally {
    setLoading(btn, spinner, false, 'Search Flights');
  }
});

function renderFlightResults(container, flights) {
  if (!flights.length) {
    container.innerHTML = '<div class="tw-info">No flights found. Try different dates or airports.</div>';
    return;
  }
  container.innerHTML = flights.map(f => {
    const leg   = f.flights?.[0] ?? {};
    const dep   = leg.departureDateTime ?? leg.departure ?? '';
    const arr   = leg.arrivalDateTime   ?? leg.arrival   ?? '';
    const stops = f.stops === 0 ? 'Direct' : `${f.stops} stop${f.stops !== 1 ? 's' : ''}`;
    return `
    <div class="flight-card">
      <div class="flight-card-header">
        <div>
          <div class="flight-route">
            ${escHtml(leg.originCode ?? '')} → ${escHtml(leg.destinationCode ?? '')}
          </div>
          <div class="flight-meta">
            ${escHtml(dep ? dep.slice(0,16).replace('T',' ') : '')}
            ${arr  ? '→ ' + arr.slice(0,16).replace('T',' ')  : ''}
            &nbsp;·&nbsp; ${stops}
            &nbsp;·&nbsp; ${escHtml(f.cabinClass ?? '')}
          </div>
        </div>
        <div>
          <div class="flight-price">${fmt(f.ourprice ?? f.price ?? 0, f.currency ?? RS.currency)}</div>
          <button class="tw-btn" style="margin-top:8px;"
            data-fare-source="${escHtml(f.fareSourceCode ?? '')}"
            onclick="bookFlight(this)">
            Select
          </button>
        </div>
      </div>
    </div>`;
  }).join('');
}

// 6f. Book flight → revalidate → checkout
window.bookFlight = async function(btn) {
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  const fareSource = btn.dataset.fareSource;

  try {
    await rsPost('flight-revalidate.php', {
      sessionId:       flightState.sessionId,
      correlationId:   flightState.correlationId,
      fareSourceCode:  fareSource,
      searchFilterObj: flightState.searchFilterObj,
    });

    const pay = await rsPost('flight-payment.php', {
      sessionId:      flightState.sessionId,
      fareSourceCode: fareSource,
    });

    if (!pay.checkoutUrl) throw new Error('No checkout URL returned.');
    window.location.href = pay.checkoutUrl;

  } catch (err) {
    btn.disabled    = false;
    btn.textContent = 'Select';
    const results   = document.getElementById('tw-flight-results');
    const errDiv    = document.createElement('div');
    errDiv.className = 'tw-error';
    errDiv.style.marginBottom = '12px';
    errDiv.textContent = err.status === 410
      ? 'This fare has expired. Please search again.'
      : err.message;
    results.prepend(errDiv);
  }
};

// ============================================================
// 7. CAR HIRE
// ============================================================

// 7a. Location autocomplete
function makeCarAutocomplete(inputId, listId, hiddenId) {
  makeAutocomplete({
    inputEl:  document.getElementById(inputId),
    listEl:   document.getElementById(listId),
    hiddenEl: document.getElementById(hiddenId),
    fetch: async q => {
      const r = await rsPost('car-locations.php', { query: q });
      return r.locations ?? [];
    },
    formatItem: loc => loc.name ?? loc.label ?? String(loc),
  });
}

makeCarAutocomplete('car-pickup-input',  'car-pickup-list',  'car-pickup-id');
makeCarAutocomplete('car-dropoff-input', 'car-dropoff-list', 'car-dropoff-id');

// 7b. Default car dates
(function() {
  const p  = document.getElementById('car-pickup-date');
  const d  = document.getElementById('car-dropoff-date');
  const t  = new Date();
  const d1 = new Date(t.getTime() + 14 * 86400000);
  const d2 = new Date(t.getTime() + 21 * 86400000);
  p.min = t.toISOString().slice(0, 10);
  p.value = d1.toISOString().slice(0, 10);
  d.min   = p.value;
  d.value = d2.toISOString().slice(0, 10);
  p.addEventListener('change', () => {
    d.min = p.value;
    if (d.value <= p.value) {
      const dd = new Date(p.value);
      dd.setDate(dd.getDate() + 3);
      d.value = dd.toISOString().slice(0, 10);
    }
  });
})();

// 7c. Car search
document.getElementById('car-search-form').addEventListener('submit', async e => {
  e.preventDefault();

  const btn     = document.getElementById('car-search-btn');
  const spinner = document.getElementById('car-spinner');
  const msg     = document.getElementById('car-search-msg');
  const results = document.getElementById('tw-car-results');

  const pickupId   = document.getElementById('car-pickup-id').value;
  const dropoffId  = document.getElementById('car-dropoff-id').value;

  if (!pickupId) {
    showMsg(msg, 'Please select a pick-up location from the suggestions.', true);
    return;
  }

  clearEl(results);
  showMsg(msg, 'Searching…');
  setLoading(btn, spinner, true, 'Search Car Hire');

  const body = {
    pickupLocationId: pickupId,
    pickupDate:       document.getElementById('car-pickup-date').value,
    dropoffDate:      document.getElementById('car-dropoff-date').value,
    driverAge:        parseInt(document.getElementById('car-driver-age').value, 10),
    currency:         RS.currency,
  };
  if (dropoffId) body.dropoffLocationId = dropoffId;

  try {
    const data = await rsPost('car-search.php', body);
    showMsg(msg, `${data.cars.length} vehicle${data.cars.length !== 1 ? 's' : ''} found`);
    renderCarResults(results, data.cars, body.pickupLocationId, body.pickupDate, body.dropoffDate);
  } catch (err) {
    showMsg(msg, err.message, true);
    results.innerHTML = `<div class="tw-error">${escHtml(err.message)}</div>`;
  } finally {
    setLoading(btn, spinner, false, 'Search Car Hire');
  }
});

function renderCarResults(container, cars, pickupId, pickupDate, dropoffDate) {
  if (!cars.length) {
    container.innerHTML = '<div class="tw-info">No cars available for those dates and location.</div>';
    return;
  }
  const days = Math.round((new Date(dropoffDate) - new Date(pickupDate)) / 86400000);

  container.innerHTML = cars.map(c => `
    <div class="car-card">
      <div>
        <h4>${escHtml(c.name ?? c.vehicleName ?? 'Car')}</h4>
        <div class="cat">${escHtml(c.category ?? c.carType ?? '')}${c.doors ? ` · ${c.doors} doors` : ''}${c.seats ? ` · ${c.seats} seats` : ''}</div>
        ${c.transmission ? `<div style="font-size:12px;color:#555;margin-top:2px;">${escHtml(c.transmission)}</div>` : ''}
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
        <div class="car-card-price">${fmt(c.totalPrice ?? c.price ?? 0, c.currency ?? RS.currency)}</div>
        <div style="font-size:12px;color:#666;">for ${days} day${days !== 1 ? 's' : ''}</div>
        <button class="tw-btn"
          data-car="${escHtml(JSON.stringify({ vehicleId: c.id ?? c.vehicleId, pickupLocationId: pickupId, pickupDate, dropoffDate }))}"
          onclick="bookCar(this)">
          Book
        </button>
      </div>
    </div>`).join('');
}

// 7d. Book car → checkout
window.bookCar = async function(btn) {
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  let carData;
  try { carData = JSON.parse(btn.dataset.car); } catch (_) { carData = {}; }

  try {
    const pay = await rsPost('car-payment.php', carData);
    if (!pay.checkoutUrl) throw new Error('No checkout URL returned.');
    window.location.href = pay.checkoutUrl;
  } catch (err) {
    btn.disabled    = false;
    btn.textContent = 'Book';
    const results   = document.getElementById('tw-car-results');
    const errDiv    = document.createElement('div');
    errDiv.className = 'tw-error';
    errDiv.style.marginBottom = '12px';
    errDiv.textContent = err.message;
    results.prepend(errDiv);
  }
};

// ============================================================
// 8. XSS helpers
// ============================================================

function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g,  '&amp;')
    .replace(/</g,  '&lt;')
    .replace(/>/g,  '&gt;')
    .replace(/"/g,  '&quot;')
    .replace(/'/g,  '&#39;');
}
