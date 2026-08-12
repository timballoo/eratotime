/* booking.js — Eratotime booking widget (Phase 4).
   Reads its config from #eratotime-app[data-config], drives availability
   rendering against api/slots.php, and collects the invitee's details.
   Request submission (Phase 5) targets POST /api/requests.php; until that
   endpoint exists a graceful notice is shown. Brand styling via css/eratotime.css.
   Runs as an ES module, standalone (safe inside an iframe). */

const APP = document.getElementById('eratotime-app');
const CONFIG = JSON.parse(APP.getAttribute('data-config'));

const state = {
    monthOffset: 0,
    selectedDate: null,
    selectedUtcSlot: null,
    selectedOrgSlot: null,
    timezone: detectedTimezone(),
    stale: false,
};

const LOCALE = 'en-GB';
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const DOW = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

const COMMON_TZ = [
    'Europe/London', 'Europe/Dublin', 'Europe/Lisbon', 'Europe/Paris', 'Europe/Berlin',
    'Europe/Amsterdam', 'Europe/Madrid', 'Europe/Rome', 'Europe/Vienna', 'Europe/Zurich',
    'Europe/Stockholm', 'Europe/Copenhagen', 'Europe/Warsaw', 'Europe/Prague', 'Europe/Helsinki',
    'Europe/Athens', 'Europe/Istanbul', 'Europe/Moscow',
    'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'America/Toronto', 'America/Vancouver', 'America/Sao_Paulo', 'America/Mexico_City',
    'Africa/Lagos', 'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Nairobi',
    'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Hong_Kong',
    'Asia/Tokyo', 'Asia/Seoul', 'Asia/Shanghai', 'Asia/Bangkok', 'Australia/Sydney',
    'Australia/Melbourne', 'Pacific/Auckland',
];

function detectedTimezone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch (e) {
        return 'UTC';
    }
}

function orgNowDate() {
    // Organizer-local "today" is sent by the API as part of month data; the
    // widget works in organizer dates because availability is organizer-anchored.
    return new Date();
}

function formatTimeInTz(utcIso, tz) {
    try {
        return new Intl.DateTimeFormat(LOCALE, { timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(utcIso));
    } catch (e) {
        return new Date(utcIso).toTimeString().slice(0, 5);
    }
}

function formatDayInTz(utcIso, tz) {
    return new Intl.DateTimeFormat(LOCALE, { timeZone: tz, weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(utcIso));
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// --- Rendering ---------------------------------------------------------------

function render() {
    APP.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Meertec &nbsp;·&nbsp; ${esc(CONFIG.type.name)}</p>
        <h1 class="booking-heading">Book a conversation</h1>

        ${CONFIG.organizer.photo || CONFIG.organizer.bio ? `
        <div class="organizer">
          ${CONFIG.organizer.photo ? `<img class="organizer-photo" src="${esc(CONFIG.organizer.photo)}" alt="">` : ''}
          ${CONFIG.organizer.bio ? `<p class="organizer-bio">${esc(CONFIG.organizer.bio)}</p>` : ''}
        </div>` : ''}

        <div class="meeting-type">
          <h2 class="meeting-type-name">${esc(CONFIG.type.name)}</h2>
          <span class="meeting-type-duration">${CONFIG.type.duration_min} min</span>
        </div>
        ${CONFIG.type.description ? `<p class="meeting-type-desc">${esc(CONFIG.type.description)}</p>` : ''}
        ${CONFIG.type.location_details ? `<p class="meeting-type-location">Where: <a href="${esc(locationHref(CONFIG.type.location_details))}" target="_blank" rel="noopener">${esc(CONFIG.type.location_details)}</a></p>` : ''}

        <div class="tz-row">
          <label class="tz-label" for="tz-select">Your timezone</label>
          <select id="tz-select" class="tz-select"></select>
        </div>

        <div id="status" class="status" role="status"></div>

        <p class="booking-step">1 · Pick a day</p>
        <div id="month-wrap"></div>

        <p class="booking-step">2 · Pick a time</p>
        <div id="slots-wrap"></div>

        <p class="booking-step">3 · Your details</p>
        <form id="booking-form" class="booking-form" novalidate>
          <div class="field">
            <label class="field-label" for="f-name">Name <span class="req">*</span></label>
            <input class="field-input" id="f-name" name="name" type="text" autocomplete="name" required>
          </div>
          <div class="field">
            <label class="field-label" for="f-email">Email <span class="req">*</span></label>
            <input class="field-input" id="f-email" name="email" type="email" autocomplete="email" required>
          </div>
          ${CONFIG.questions.map((q, i) => questionHtml(q, i)).join('')}
          <div class="field">
            <label class="field-label">Guests <span class="field-hint">optional — additional attendees</span></label>
            <div id="guest-list"></div>
            <button type="button" class="guest-add" id="guest-add">+ Add guest</button>
          </div>
          <div class="honeypot" aria-hidden="true">
            <label for="f-website">Leave this field empty</label>
            <input id="f-website" name="website" type="text" tabindex="-1" autocomplete="off">
          </div>
          <div id="summary"></div>
          <button type="submit" class="btn btn-primary" id="submit-btn">Request this time</button>
        </form>
      </div>`;

    populateTzSelect();
    document.getElementById('tz-select').addEventListener('change', e => { state.timezone = e.target.value; refreshSlots(); });
    document.getElementById('guest-add').addEventListener('click', addGuestRow);
    document.getElementById('booking-form').addEventListener('submit', onSubmit);
    setupMonthNav();
    refreshMonth();
}

function locationHref(raw) {
    const t = String(raw || '').trim();
    return /^https?:\/\//i.test(t) ? t : `mailto:${t}`;
}

function questionHtml(q, i) {
    const id = `q-${i}`;
    const req = q.required ? ' <span class="req">*</span>' : '';
    const opt = q.type === 'select' ? '<option value="">Select…</option>' : '';
    if (q.type === 'textarea') {
        return `<div class="field"><label class="field-label" for="${id}">${esc(q.label)}${req}</label><textarea class="field-textarea" id="${id}" name="question_${i}" rows="3"${q.required ? ' required' : ''}></textarea></div>`;
    }
    if (q.type === 'select') {
        return `<div class="field"><label class="field-label" for="${id}">${esc(q.label)}${req}</label><select class="field-input" id="${id}" name="question_${i}"${q.required ? ' required' : ''}>${opt}</select></div>`;
    }
    return `<div class="field"><label class="field-label" for="${id}">${esc(q.label)}${req}</label><input class="field-input" id="${id}" name="question_${i}" type="text"${q.required ? ' required' : ''}></div>`;
}

function populateTzSelect() {
    const sel = document.getElementById('tz-select');
    const options = [state.timezone, ...COMMON_TZ.filter(t => t !== state.timezone)];
    sel.innerHTML = options.map(t => `<option value="${esc(t)}"${t === state.timezone ? ' selected' : ''}>${esc(t)}</option>`).join('');
}

// --- Availability fetching ----------------------------------------------------

async function api(params) {
    const qs = new URLSearchParams({ tenant: CONFIG.tenant_slug, type: CONFIG.type_slug, ...params });
    const res = await fetch(`api/slots.php?${qs}`);
    const body = await res.json().catch(() => ({}));
    if (!res.ok || !body.ok) throw new Error(body.error || `Request failed (${res.status})`);
    return body;
}

function setStatus(msg, kind = 'info') {
    const el = document.getElementById('status');
    el.className = `status is-visible status-${kind}`;
    el.textContent = msg;
}

function clearStatus() {
    const el = document.getElementById('status');
    el.className = 'status';
    el.textContent = '';
}

// --- Month --------------------------------------------------------------------

function monthAnchor() {
    const d = orgNowDate();
    return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + state.monthOffset, 1));
}

function setupMonthNav() {
    const wrap = document.getElementById('month-wrap');
    wrap.innerHTML = `
      <div class="month-head">
        <button type="button" class="month-nav" id="prev-month" aria-label="Previous month">‹</button>
        <p class="month-title" id="month-title"></p>
        <button type="button" class="month-nav" id="next-month" aria-label="Next month">›</button>
      </div>
      <ul class="date-grid" id="date-grid"></ul>`;
    document.getElementById('prev-month').addEventListener('click', () => { if (state.monthOffset > 0) { state.monthOffset--; refreshMonth(); } });
    document.getElementById('next-month').addEventListener('click', () => { state.monthOffset++; refreshMonth(); });
}

async function refreshMonth() {
    const anchor = monthAnchor();
    const ym = `${anchor.getUTCFullYear()}-${String(anchor.getUTCMonth() + 1).padStart(2, '0')}`;
    document.getElementById('month-title').textContent = `${MONTH_NAMES[anchor.getUTCMonth()]} ${anchor.getUTCFullYear()}`;
    document.getElementById('prev-month').disabled = state.monthOffset <= 0;
    document.getElementById('slots-wrap').innerHTML = '<p class="slot-empty">Loading…</p>';
    state.selectedDate = null;
    state.selectedUtcSlot = null;
    state.selectedOrgSlot = null;
    clearStatus();

    try {
        const body = await api({ month: ym });
        if (body.stale) {
            setStatus('Availability is temporarily unavailable — please email stephen@meertec.ltd to arrange a time.', 'info');
            document.getElementById('date-grid').innerHTML = '';
            return;
        }
        renderMonth(anchor, new Set(body.dates));
    } catch (e) {
        setStatus(e.message, 'error');
    }
}

function renderMonth(anchor, availableDates) {
    const grid = document.getElementById('date-grid');
    const cells = DOW.map(d => `<li class="date-dow">${d}</li>`);
    const firstDow = (anchor.getUTCDay() + 6) % 7; // Monday-first
    const daysInMonth = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth() + 1, 0)).getUTCDate();
    const today = orgNowDate();
    const todayKey = `${today.getUTCFullYear()}-${String(today.getUTCMonth() + 1).padStart(2, '0')}-${String(today.getUTCDate()).padStart(2, '0')}`;

    for (let i = 0; i < firstDow; i++) cells.push('<li class="date-cell"></li>');
    for (let d = 1; d <= daysInMonth; d++) {
        const key = `${anchor.getUTCFullYear()}-${String(anchor.getUTCMonth() + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const available = availableDates.has(key);
        const isToday = key === todayKey;
        const classes = ['date-cell'];
        if (available) classes.push('is-available');
        if (isToday) classes.push('is-today');
        if (key === state.selectedDate) classes.push('is-selected');
        cells.push(`<li><button type="button" class="${classes.join(' ')}" data-date="${key}"${available ? '' : ' disabled'} tabindex="${available ? '0' : '-1'}">${d}</button></li>`);
    }
    grid.innerHTML = cells.join('');
    grid.querySelectorAll('.date-cell.is-available').forEach(btn => {
        btn.addEventListener('click', () => selectDate(btn.dataset.date));
    });
}

async function selectDate(date) {
    state.selectedDate = date;
    state.selectedUtcSlot = null;
    state.selectedOrgSlot = null;
    renderMonth(monthAnchor(), null); // re-render with selection state; availability from cache
    const wrap = document.getElementById('slots-wrap');
    wrap.innerHTML = '<p class="slot-empty">Loading times…</p>';
    document.getElementById('date-grid').querySelectorAll('.date-cell.is-available').forEach(b => b.classList.toggle('is-selected', b.dataset.date === date));

    try {
        const body = await api({ date });
        const fmt = new Intl.DateTimeFormat(LOCALE, { timeZone: state.timezone, weekday: 'long', day: 'numeric', month: 'long' });
        const localDay = fmt.format(new Date(body.utc_slots[0] || `${date}T12:00:00`));
        if (body.slots.length === 0) {
            wrap.innerHTML = `<p class="slot-empty">No open times on ${esc(localDay)}. Try another day.</p>`;
            return;
        }
        const items = body.utc_slots.map((iso, i) => {
            const time = formatTimeInTz(iso, state.timezone);
            const shownDay = formatDayInTz(iso, state.timezone);
            const crossDay = !iso.startsWith(date);
            return `<li><button type="button" class="slot-btn" data-iso="${esc(iso)}" data-org="${esc(body.slots[i])}">${esc(time)}${crossDay ? ' (' + esc(shownDay) + ')' : ''}</button></li>`;
        });
        wrap.innerHTML = `<p class="slot-date-note">${esc(localDay)} — shown in your timezone (${esc(state.timezone)})</p><ul class="slot-list">${items.join('')}</ul>`;
        wrap.querySelectorAll('.slot-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                state.selectedUtcSlot = btn.dataset.iso;
                state.selectedOrgSlot = btn.dataset.org;
                wrap.querySelectorAll('.slot-btn').forEach(b => b.classList.toggle('is-selected', b === btn));
                renderSummary();
            });
        });
    } catch (e) {
        wrap.innerHTML = `<p class="slot-empty">Couldn't load times: ${esc(e.message)}</p>`;
    }
}

function refreshSlots() {
    if (state.selectedDate) selectDate(state.selectedDate);
}

// --- Form ---------------------------------------------------------------------

function addGuestRow() {
    const list = document.getElementById('guest-list');
    const row = document.createElement('div');
    row.className = 'guest-row';
    row.innerHTML = '<input class="field-input" name="guest" type="email" autocomplete="email" placeholder="guest@example.com">' +
        '<button type="button" class="guest-remove" aria-label="Remove guest">×</button>';
    row.querySelector('.guest-remove').addEventListener('click', () => row.remove());
    list.appendChild(row);
    row.querySelector('input').focus();
}

function renderSummary() {
    const el = document.getElementById('summary');
    if (!state.selectedUtcSlot) {
        el.innerHTML = '';
        return;
    }
    const fmt = new Intl.DateTimeFormat(LOCALE, { timeZone: state.timezone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
    el.innerHTML = `<div class="summary-card"><p class="summary-k">Requested time</p><p>${esc(fmt.format(new Date(state.selectedUtcSlot)))}</p><p class="summary-k">Meeting</p><p>${esc(CONFIG.type.name)} · ${CONFIG.type.duration_min} min</p></div>`;
}

async function onSubmit(e) {
    e.preventDefault();
    if (!state.selectedUtcSlot) {
        setStatus('Pick a day and time first.', 'error');
        return;
    }
    clearStatus();
    const data = {
        tenant: CONFIG.tenant_slug,
        type: CONFIG.type_slug,
        slot_utc: state.selectedUtcSlot,
        name: document.getElementById('f-name').value.trim(),
        email: document.getElementById('f-email').value.trim(),
        timezone: state.timezone,
        questions: CONFIG.questions.map((q, i) => ({
            label: q.label,
            answer: document.getElementById(`q-${i}`).value.trim(),
        })),
        guests: Array.from(document.querySelectorAll('input[name="guest"]')).map(i => i.value.trim()).filter(v => v !== ''),
        website: document.getElementById('f-website').value.trim(),
    };
    if (!data.name || !data.email) {
        setStatus('Please enter your name and email.', 'error');
        return;
    }
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    try {
        const res = await fetch('api/requests.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        const body = await res.json().catch(() => ({}));
        if (res.ok && body.ok) {
            setStatus(`Request received. ${body.message || 'The organizer will be in touch.'}`, 'info');
            btn.textContent = 'Request sent';
        } else {
            setStatus(body.error || `Submission failed (${res.status})`, 'error');
            btn.disabled = false;
        }
    } catch (err) {
        setStatus('Request submission is being wired up in the next phase — meanwhile, please email stephen@meertec.ltd to arrange a time.', 'info');
        btn.disabled = false;
    }
}

render();
