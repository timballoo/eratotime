/* admin.js — Eratotime admin panel (Phase 6).
   ES module; talks to /api/admin/*. Uses a session CSRF for writes. */

const APP = document.getElementById('admin-app');

async function api(path, method = 'GET', body = null) {
    const opts = { method, headers: {} };
    if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) throw new Error(data.error || `Request failed (${res.status})`);
    return data;
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmtTime(utc) {
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(utc));
}

function nav() {
    return `
      <nav class="admin-nav">
        <a href="#" data-view="dashboard" class="is-active">Dashboard</a>
        <a href="#" data-view="availability">Availability</a>
        <a href="#" data-view="meeting-types">Meeting types</a>
        <a href="#" data-view="requests">Requests</a>
        <a href="#" data-view="calendars">Calendars</a>
        <a href="#" data-view="cron">Cron</a>
        <a href="#" data-view="settings">Settings</a>
      </nav>
      <div id="view" class="admin-view"></div>`;
}

async function boot() {
    APP.innerHTML = nav();
    document.querySelectorAll('.admin-nav a').forEach(a => a.addEventListener('click', async e => {
        e.preventDefault();
        document.querySelectorAll('.admin-nav a').forEach(x => x.classList.toggle('is-active', x === a));
        await switchView(a.dataset.view);
    }));
    await switchView('dashboard');
}

async function switchView(name) {
    const view = document.getElementById('view');
    const map = {
        dashboard: renderDashboard,
        availability: renderAvailability,
        'meeting-types': renderMeetingTypes,
        requests: renderRequests,
        calendars: renderCalendars,
        cron: renderCron,
        settings: renderSettings,
    };
    view.innerHTML = '<p class="slot-empty">Loading…</p>';
    try {
        await (map[name] || renderDashboard)(view);
    } catch (e) {
        view.innerHTML = `<div class="status is-visible status-error">${esc(e.message)}</div>`;
    }
}

// --- Login ------------------------------------------------------------------

const loginForm = document.getElementById('admin-login-form');
if (loginForm) {
    loginForm.addEventListener('submit', async e => {
        e.preventDefault();
        const status = document.getElementById('login-status');
        status.className = 'status is-visible status-info';
        status.textContent = 'Signing in…';
        try {
            const fd = new FormData(loginForm);
            await fetch('api/admin/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: fd.get('username'), password: fd.get('password') }),
            }).then(async r => {
                const d = await r.json().catch(() => ({}));
                if (!r.ok || !d.ok) throw new Error(d.error || 'Login failed');
            });
            location.reload();
        } catch (err) {
            status.className = 'status is-visible status-error';
            status.textContent = err.message;
        }
    });
}

// --- Password reset ----------------------------------------------------------

const resetForm = document.getElementById('admin-reset-form');
if (resetForm) {
    resetForm.addEventListener('submit', async e => {
        e.preventDefault();
        const status = document.getElementById('reset-status');
        const fd = new FormData(resetForm);
        const password = fd.get('password');
        const password2 = fd.get('password2');
        if (password !== password2) {
            status.className = 'status is-visible status-error';
            status.textContent = 'Passwords do not match.';
            return;
        }
        if (password.length < 8) {
            status.className = 'status is-visible status-error';
            status.textContent = 'Password must be at least 8 characters.';
            return;
        }
        status.className = 'status is-visible status-info';
        status.textContent = 'Resetting password…';
        try {
            const res = await fetch('api/admin/reset.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ secret: fd.get('secret'), password }),
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok || !d.ok) throw new Error(d.error || 'Reset failed');
            status.className = 'status is-visible status-info';
            status.textContent = 'Password updated. Redirecting…';
            setTimeout(() => { location.href = 'admin.php'; }, 1500);
        } catch (err) {
            status.className = 'status is-visible status-error';
            status.textContent = err.message;
        }
    });
}

// --- Dashboard ----------------------------------------------------------------

async function renderDashboard(view) {
    const d = await api('api/admin/dashboard.php');
    const u = d.usage || {};
    const counts = u.by_status || d.counts || {};
    const max = Math.max(1, ...(u.daily || []).map(x => x.count));
    const bars = (u.daily || []).map(x => `<div class="usage-bar" title="${esc(x.date)} — ${x.count}">
        <div class="usage-bar-fill" style="height:${Math.max(2, Math.round((x.count / max) * 100))}%"></div>
        <span class="usage-bar-day">${Number(x.date.slice(8, 10))}</span></div>`).join('');

    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Dashboard</p>
        <h1 class="booking-heading">Eratotime</h1>
        ${d.warnings && d.warnings.length ? d.warnings.map(w => `<div class="status is-visible status-error">${esc(w)}</div>`).join('') : '<div class="status is-visible status-info">All systems nominal.</div>'}

        <div class="usage-cards">
          <div class="usage-card"><p class="usage-num">${u.total ?? 0}</p><p class="usage-label">Requests all time</p></div>
          <div class="usage-card"><p class="usage-num">${u.upcoming ?? 0}</p><p class="usage-label">Upcoming pending</p></div>
          <div class="usage-card"><p class="usage-num">${counts.pending ?? 0}</p><p class="usage-label">Pending</p></div>
          <div class="usage-card"><p class="usage-num">${counts.fulfilled ?? 0}</p><p class="usage-label">Fulfilled</p></div>
          <div class="usage-card"><p class="usage-num">${(counts.cancelled ?? 0) + (counts.expired ?? 0)}</p><p class="usage-label">Cancelled / expired</p></div>
        </div>

        <p class="booking-step">Requests — last 30 days</p>
        <div class="usage-bars">${bars}</div>

        ${(u.by_type || []).length ? `
        <p class="booking-step">By meeting type</p>
        ${u.by_type.map(t => `
          <div class="usage-type-row">
            <span>${esc(t.type_name)}</span>
            <span class="usage-type-count">${t.n}</span>
          </div>`).join('')}` : ''}

        <p class="booking-step">Actions</p>
        <p><a class="btn btn-primary" href="#" data-goto="availability">Edit availability</a></p>
      </div>`;
    view.querySelector('[data-goto]').addEventListener('click', async e => { e.preventDefault(); await switchView('availability'); });
}

// --- Availability grid ---------------------------------------------------------

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

async function renderAvailability(view) {
    let week = startOfWeek(new Date());
    let mode = 'template';

    const body = `
      <div class="booking-card">
        <p class="eyebrow">Availability</p>
        <h1 class="booking-heading">Weekly grid</h1>
        <div class="grid-toolbar">
          <div class="seg">
            <button type="button" class="seg-btn is-active" data-mode="template">Recurring template</button>
            <button type="button" class="seg-btn" data-mode="override">This week's overrides</button>
          </div>
          <div class="grid-nav">
            <button type="button" class="month-nav" id="grid-prev">‹</button>
            <span id="grid-week-label" class="grid-week-label"></span>
            <button type="button" class="month-nav" id="grid-next">›</button>
            <button type="button" class="month-nav" id="grid-today">This week</button>
          </div>
        </div>
        <p class="field-hint">Click or drag cells to open/block. Grey = blocked, brass = open, hatched = synced busy, dot = pending request hold.</p>
        <div id="grid" class="grid-wrap"></div>
        <button type="button" class="btn btn-primary" id="grid-save">Save</button>
        <div id="grid-status" class="status"></div>
      </div>`;
    view.innerHTML = body;

    const label = document.getElementById('grid-week-label');
    const seg = document.querySelectorAll('.seg-btn');
    seg.forEach(b => b.addEventListener('click', () => { seg.forEach(x => x.classList.toggle('is-active', x === b)); mode = b.dataset.mode; load(); }));
    document.getElementById('grid-prev').addEventListener('click', () => { week = new Date(week.getTime() - 7 * 864e5); load(); });
    document.getElementById('grid-next').addEventListener('click', () => { week = new Date(week.getTime() + 7 * 864e5); load(); });
    document.getElementById('grid-today').addEventListener('click', () => { week = startOfWeek(new Date()); load(); });
    document.getElementById('grid-save').addEventListener('click', save);
    view.addEventListener('click', async e => { if (e.target.closest('#grid-today')) { week = startOfWeek(new Date()); await load(); } });

    async function load() {
        const fmt = w => `${w.getFullYear()}-${String(w.getMonth() + 1).padStart(2, '0')}-${String(w.getDate()).padStart(2, '0')}`;
        label.textContent = `Week of ${fmt(week)}`;
        const d = await api(`api/admin/availability_grid.php?week=${fmt(week)}`);
        renderGrid(d.grid);
    }

    function renderGrid(grid) {
        const wrap = document.getElementById('grid');
        const times = [];
        for (let i = 0; i < grid.cell_count; i++) {
            const m = toMin(grid.cell_start) + i * grid.cell_step;
            times.push(`${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`);
        }
        const head = ['<div class="grid-corner"></div>', ...DAY_LABELS.map(d => `<div class="grid-dow">${d}</div>`)].join('');
        const rows = times.map((t, i) => {
            const cells = grid.days.map(day => {
                const st = day.cells[i];
                const cls = st === 'open' ? 'cell-open' : st === 'busy' ? 'cell-busy' : st === 'hold' ? 'cell-hold' : 'cell-blocked';
                const ro = st === 'busy' || st === 'hold';
                return `<div class="grid-cell ${cls}" data-day="${day.date}" data-idx="${i}"${ro ? ' data-ro="1"' : ''}></div>`;
            }).join('');
            return `<div class="grid-time">${t}</div>${cells}`;
        }).join('');
        wrap.innerHTML = `<div class="grid-head">${head}</div><div class="grid-rows">${rows}</div>`;
        wireDrag();
    }

    function wireDrag() {
        const grid = document.getElementById('grid');
        let painting = null; // {state:boolean(open), day, idx}
        grid.addEventListener('mousedown', e => {
            const cell = e.target.closest('.grid-cell');
            if (!cell || cell.dataset.ro) return;
            e.preventDefault();
            const day = cell.dataset.day;
            const idx = parseInt(cell.dataset.idx, 10);
            const now = cell.classList.contains('cell-open');
            painting = { open: !now, day, idx };
            apply(cell, day, idx, painting.open);
        });
        grid.addEventListener('mousemove', e => {
            if (!painting) return;
            const cell = e.target.closest('.grid-cell');
            if (!cell || cell.dataset.ro) return;
            const day = cell.dataset.day;
            const idx = parseInt(cell.dataset.idx, 10);
            if (day === painting.day) apply(cell, day, idx, painting.open);
        });
        window.addEventListener('mouseup', () => { painting = null; });
    }

    function apply(cell, day, idx, open) {
        cell.classList.toggle('cell-open', open);
        cell.classList.toggle('cell-blocked', !open);
    }

    async function save() {
        const grid = document.getElementById('grid');
        const openByDay = {};
        const dayOrder = [];
        grid.querySelectorAll('.grid-cell').forEach(cell => {
            if (cell.dataset.ro === '1') return;
            const day = cell.dataset.day;
            if (!dayOrder.includes(day)) dayOrder.push(day);
            const idx = parseInt(cell.dataset.idx, 10);
            if (cell.classList.contains('cell-open')) {
                (openByDay[day] = openByDay[day] || []).push(idx);
            }
        });
        const days = {};
        dayOrder.forEach(day => {
            const open = (openByDay[day] || []).sort((a, b) => a - b);
            if (mode === 'template') {
                const monIdx = (new Date(day).getDay() + 6) % 7; // Mon=0
                days[monIdx] = open;
            } else {
                days[day] = open.length ? open : 'blocked'; // all-blocked -> full-day override
            }
        });
        const weekFmt = `${week.getFullYear()}-${String(week.getMonth() + 1).padStart(2, '0')}-${String(week.getDate()).padStart(2, '0')}`;
        const status = document.getElementById('grid-status');
        try {
            await api('api/admin/availability_grid.php', 'POST', { csrf: APP.dataset.csrf, week: weekFmt, mode, days });
            status.className = 'status is-visible status-info';
            status.textContent = 'Saved.';
            await load();
        } catch (e) {
            status.className = 'status is-visible status-error';
            status.textContent = e.message;
        }
    }

    await load();
}

function toMin(hhmm) {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
}

function startOfWeek(d) {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    const dow = (x.getDay() + 6) % 7; // Monday-first
    x.setDate(x.getDate() - dow);
    return x;
}

// --- Meeting types ------------------------------------------------------------

async function renderMeetingTypes(view) {
    const d = await api('api/admin/meeting_types.php');
    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Meeting types</p>
        <h1 class="booking-heading">Meeting types</h1>
        <div id="mt-list"></div>
        <button type="button" class="btn btn-primary" id="mt-new">New meeting type</button>
      </div>`;
    document.getElementById('mt-new').addEventListener('click', () => renderMtEditor(view, null));
    const list = document.getElementById('mt-list');
    list.innerHTML = d.meeting_types.map(t => `
      <div class="admin-row">
        <div>
          <strong>${esc(t.name)}</strong> <span class="muted">/${esc(t.slug)}</span>
          <span class="badge-dash">${t.duration_min} min</span>
          ${t.active ? '<span class="badge-dash badge-on">active</span>' : '<span class="badge-dash">inactive</span>'}
        </div>
        <div class="admin-row-actions">
          <button type="button" class="btn btn-ghost btn-small" data-edit="${t.id}">Edit</button>
          <button type="button" class="btn btn-ghost btn-small" data-del="${t.id}">Delete</button>
        </div>
      </div>`).join('');
    list.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => renderMtEditor(view, d.meeting_types.find(x => x.id == b.dataset.edit))));
    list.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
        if (!confirm('Delete this meeting type?')) return;
        await api('api/admin/meeting_types.php', 'POST', { csrf: APP.dataset.csrf, id: Number(b.dataset.del), _delete: 1 }).catch(() => {});
        await switchView('meeting-types');
    }));
}

async function renderMtEditor(view, t) {
    const v = t || { slug: '', name: '', duration_min: 30, description: '', location_details: '', video_link: '', buffer_before_min: 0, buffer_after_min: 0, min_notice_hours: 24, max_horizon_days: 14, daily_cap: '', active: 1, questions: [] };
    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Meeting type</p>
        <h1 class="booking-heading">${t ? 'Edit' : 'New'} meeting type</h1>
        <form id="mt-form" class="booking-form">
          <div class="field"><label class="field-label">Name</label><input class="field-input" name="name" value="${esc(v.name)}" required></div>
          <div class="field"><label class="field-label">Slug</label><input class="field-input" name="slug" value="${esc(v.slug)}" required></div>
          <div class="field"><label class="field-label">Duration (minutes)</label><input class="field-input" name="duration_min" type="number" min="5" value="${v.duration_min}" required></div>
          <div class="field"><label class="field-label">Description</label><textarea class="field-textarea" name="description" rows="2">${esc(v.description || '')}</textarea></div>
          <div class="field"><label class="field-label">Location / meeting details (phone, Meet link, address)</label><input class="field-input" name="location_details" value="${esc(v.location_details || '')}"></div>
          <div class="field"><label class="field-label">Video call link (Google Meet) — optional, enables the 'Video call' option</label><input class="field-input" name="video_link" value="${esc(v.video_link || '')}" placeholder="https://meet.google.com/..."></div>
          <div class="field"><label class="field-label">Event message template <span class="field-hint">goes in the calendar event + confirmation email. Placeholders: {name} {type} {date} {location} {meet_link} {answers} {guests}</span></label><textarea class="field-textarea" name="message_template" rows="3" placeholder="e.g. Looking forward to our {type}. Please be ready with any questions on {date}.">${esc(v.message_template || '')}</textarea></div>
          <div class="grid2">
            <div class="field"><label class="field-label">Buffer before (min)</label><input class="field-input" name="buffer_before_min" type="number" min="0" value="${v.buffer_before_min}"></div>
            <div class="field"><label class="field-label">Buffer after (min)</label><input class="field-input" name="buffer_after_min" type="number" min="0" value="${v.buffer_after_min}"></div>
            <div class="field"><label class="field-label">Min notice (hours)</label><input class="field-input" name="min_notice_hours" type="number" min="0" value="${v.min_notice_hours}"></div>
            <div class="field"><label class="field-label">Max horizon (days)</label><input class="field-input" name="max_horizon_days" type="number" min="1" value="${v.max_horizon_days}"></div>
            <div class="field"><label class="field-label">Daily cap (blank = none)</label><input class="field-input" name="daily_cap" type="number" min="1" value="${esc(v.daily_cap ?? '')}"></div>
            <div class="field"><label class="field-label">Active</label><input type="checkbox" name="active" ${v.active ? 'checked' : ''}></div>
          </div>
          <div class="field"><label class="field-label">Booking questions</label><div id="q-list"></div><button type="button" class="guest-add" id="q-add">+ Add question</button></div>
          <button type="submit" class="btn btn-primary">Save</button>
          <button type="button" class="btn btn-ghost" id="mt-back">Back</button>
          <div id="mt-status" class="status"></div>
        </form>
      </div>`;
    const qList = document.getElementById('q-list');
    v.questions.forEach((q, i) => addQuestionRow(q, i));
    document.getElementById('q-add').addEventListener('click', () => addQuestionRow(null, qList.children.length));
    document.getElementById('mt-back').addEventListener('click', () => switchView('meeting-types'));
    document.getElementById('mt-form').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const questions = Array.from(qList.querySelectorAll('.q-row')).map((row, i) => ({
            label: row.querySelector('input[name="q_label"]').value.trim(),
            type: row.querySelector('select[name="q_type"]').value,
            required: row.querySelector('input[name="q_req"]').checked,
            sort_order: i,
        })).filter(q => q.label !== '');
        const payload = { csrf: APP.dataset.csrf, id: t ? t.id : 0, questions,
            name: fd.get('name'), slug: fd.get('slug'), duration_min: Number(fd.get('duration_min')),
            description: fd.get('description'), location_details: fd.get('location_details'),
            video_link: fd.get('video_link'), message_template: fd.get('message_template'),
            buffer_before_min: Number(fd.get('buffer_before_min')), buffer_after_min: Number(fd.get('buffer_after_min')),
            min_notice_hours: Number(fd.get('min_notice_hours')), max_horizon_days: Number(fd.get('max_horizon_days')),
            daily_cap: fd.get('daily_cap'), active: fd.get('active') ? 1 : 0 };
        const status = document.getElementById('mt-status');
        try {
            await api('api/admin/meeting_types.php', 'POST', payload);
            await switchView('meeting-types');
        } catch (err) {
            status.className = 'status is-visible status-error';
            status.textContent = err.message;
        }
    });

    function addQuestionRow(q, i) {
        const div = document.createElement('div');
        div.className = 'q-row guest-row';
        div.innerHTML = `<input class="field-input" name="q_label" placeholder="Question label" value="${esc((q && q.label) || '')}">
          <select class="field-input tz-select" name="q_type">
            <option value="text"${q && q.type === 'text' ? ' selected' : ''}>text</option>
            <option value="textarea"${q && q.type === 'textarea' ? ' selected' : ''}>textarea</option>
            <option value="select"${q && q.type === 'select' ? ' selected' : ''}>select</option>
          </select>
          <label class="q-req"><input type="checkbox" name="q_req"${q && q.required ? ' checked' : ''}> req</label>
          <button type="button" class="guest-remove" aria-label="Remove">×</button>`;
        div.querySelector('.guest-remove').addEventListener('click', () => div.remove());
        qList.appendChild(div);
    }
}

// --- Requests ------------------------------------------------------------------

async function renderRequests(view) {
    const d = await api('api/admin/requests.php');
    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Request log</p>
        <h1 class="booking-heading">Requests</h1>
        ${d.requests.length === 0 ? '<p class="slot-empty">No requests yet.</p>' : d.requests.map(r => `
          <div class="admin-row">
            <div>
              <strong>${esc(r.invitee_name)}</strong> &lt;${esc(r.invitee_email)}&gt;
              <span class="badge-dash badge-${esc(r.status)}">${esc(r.status)}</span>
              <div class="muted">${esc(r.type_name)} · ${esc(fmtTime(r.requested_start_utc))}${r.video_call ? ' · Video call' : ''}</div>
              ${r.guest_emails && r.guest_emails.length ? `<div class="muted">Guests: ${esc(r.guest_emails.join(', '))}</div>` : ''}
              ${r.custom_answers && r.custom_answers.length ? `<div class="muted">${esc(r.custom_answers.map(a => `${a.label}: ${a.answer}`).join(' · '))}</div>` : ''}
            </div>
            <div class="admin-row-actions">
              ${r.status === 'pending' ? `<button type="button" class="btn btn-primary btn-small" data-act="fulfilled" data-id="${r.id}">Mark fulfilled</button>
              <button type="button" class="btn btn-ghost btn-small" data-act="cancelled" data-id="${r.id}">Cancel</button>` : ''}
            </div>
          </div>`).join('')}
      </div>`;
    view.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => {
        const action = b.dataset.act === 'fulfilled' ? 'mark_fulfilled' : 'mark_cancelled';
        await api('api/admin/requests.php', 'POST', { csrf: APP.dataset.csrf, id: Number(b.dataset.id), action });
        await switchView('requests');
    }));
}

// --- Calendars -------------------------------------------------------------------

async function renderCalendars(view) {
    const d = await api('api/admin/sources.php');
    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Connected calendars</p>
        <h1 class="booking-heading">Calendars</h1>
        ${d.sources.map(s => `
          <div class="admin-row">
            <div>
              <strong>${esc(s.label)}</strong> <span class="badge-dash badge-${esc(s.active ? 'on' : '')}">${s.active ? 'active' : 'inactive'}</span>
              <div class="muted">${esc(s.provider)} · ${esc(s.calendar_identifier || '')}</div>
              <div class="muted">last sync: ${esc(s.last_synced_at || 'never')} · ${esc(s.last_sync_status)}${s.last_sync_error ? ' · ' + esc(s.last_sync_error) : ''}</div>
            </div>
            <div class="admin-row-actions">
              ${s.active ? `<button type="button" class="btn btn-ghost btn-small" data-sync="${s.id}">Sync now</button>` : ''}
            </div>
          </div>`).join('')}
        <div id="sync-status" class="status"></div>
      </div>`;
    view.querySelectorAll('[data-sync]').forEach(b => b.addEventListener('click', async () => {
        const status = document.getElementById('sync-status');
        status.className = 'status is-visible status-info';
        status.textContent = 'Syncing…';
        try {
            const r = await api('api/admin/sources.php', 'POST', { csrf: APP.dataset.csrf, action: 'sync_now', source_id: Number(b.dataset.sync) });
            const line = (r.results || []).map(x => x.ok ? `ok (${x.blocks} blocks)` : `failed: ${x.error}`).join('; ');
            status.className = 'status is-visible status-info';
            status.textContent = line;
        } catch (e) {
            status.className = 'status is-visible status-error';
            status.textContent = e.message;
        }
    }));
}

// --- Cron ---------------------------------------------------------------------

async function renderCron(view) {
    const d = await api('api/admin/cron.php');
    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Scheduled jobs</p>
        <h1 class="booking-heading">Cron</h1>
        <p class="field-hint">One system cron calls <code>cron_dispatcher.php</code> every 5 minutes; jobs below run when due. Schedules and tracking live here.</p>
        <div id="cron-list"></div>
      </div>`;
    renderCronList(view, d.jobs || []);
}

function renderCronList(view, jobs) {
    const list = view.querySelector('#cron-list');
    list.innerHTML = jobs.map(j => `
      <div class="admin-row">
        <div>
          <strong>${esc(j.title)}</strong> <code>${esc(j.job_key)}</code>
          <span class="badge-dash badge-${j.enabled ? 'on' : ''}">${j.enabled ? 'enabled' : 'disabled'}</span>
          <div class="muted">${esc(j.description || '')}</div>
          <div class="muted">handler: ${esc(j.handler)}</div>
          <div class="muted">last run: ${esc(j.last_run_at || 'never')} · ${esc(j.last_status)} · ran ${j.run_count}×</div>
          ${j.last_output ? `<details><summary class="muted">last output</summary><pre class="cron-output">${esc(j.last_output)}</pre></details>` : ''}
        </div>
        <div class="admin-row-actions">
          <input class="field-input cron-sched" type="number" min="1" max="10080" value="${j.schedule_min}" data-key="${esc(j.job_key)}" title="Minutes between runs" style="width:74px">
          <button type="button" class="btn btn-ghost btn-small" data-act="update" data-key="${esc(j.job_key)}">Save</button>
          <button type="button" class="btn btn-ghost btn-small" data-act="run" data-key="${esc(j.job_key)}">Run now</button>
          <button type="button" class="btn btn-ghost btn-small" data-act="toggle" data-key="${esc(j.job_key)}">${j.enabled ? 'Disable' : 'Enable'}</button>
        </div>
      </div>`).join('');

    list.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => {
        const payload = { csrf: APP.dataset.csrf, job_key: b.dataset.key, action: b.dataset.act };
        if (b.dataset.act === 'update') {
            const input = list.querySelector(`.cron-sched[data-key="${b.dataset.key}"]`);
            payload.schedule_min = Number(input.value);
        }
        try {
            const r = await api('api/admin/cron.php', 'POST', payload);
            renderCronList(view, r.jobs || []);
        } catch (e) {
            alert(e.message);
        }
    }));
}

// --- Settings ---------------------------------------------------------------------

async function renderSettings(view) {
    const d = await api('api/admin/settings.php');
    const s = d.settings;
    view.innerHTML = `
      <div class="booking-card">
        <p class="eyebrow">Settings</p>
        <h1 class="booking-heading">Organizer profile &amp; settings</h1>
        <form id="set-form" class="booking-form">
          <div class="field"><label class="field-label">Organizer bio</label><textarea class="field-textarea" name="organizer_bio" rows="3">${esc(s.organizer_bio || '')}</textarea></div>
          <div class="field"><label class="field-label">Organizer photo</label>
            ${s.organizer_photo_path ? `<p class="muted">Current: ${esc(s.organizer_photo_path)}</p>` : ''}
            <input class="field-input" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            <p class="field-hint">JPG/PNG/WebP, under 2MB. Upload replaces the current photo.</p>
          </div>
          <div class="grid2">
            <div class="field"><label class="field-label">Global daily cap</label><input class="field-input" name="global_daily_cap" type="number" min="1" value="${esc(s.global_daily_cap ?? '')}"></div>
            <div class="field"><label class="field-label">Global weekly cap</label><input class="field-input" name="global_weekly_cap" type="number" min="1" value="${esc(s.global_weekly_cap ?? '')}"></div>
            <div class="field"><label class="field-label">Request hold (hours)</label><input class="field-input" name="request_hold_hours" type="number" min="1" value="${esc(s.request_hold_hours ?? 24)}"></div>
            <div class="field"><label class="field-label">Retention (days)</label><input class="field-input" name="request_log_retention_days" type="number" min="7" value="${esc(s.request_log_retention_days ?? 30)}"></div>
            <div class="field"><label class="field-label">Organizer timezone</label><input class="field-input" name="organizer_timezone" value="${esc(s.organizer_timezone || 'Europe/London')}"></div>
            <div class="field"><label class="field-label">WhatsApp enabled</label><input type="checkbox" name="whatsapp_enabled" ${s.whatsapp_enabled ? 'checked' : ''}></div>
            <div class="field"><label class="field-label">WhatsApp destination number</label><input class="field-input" name="whatsapp_destination_number" value="${esc(s.whatsapp_destination_number || '')}"></div>
            <div class="field"><label class="field-label">Default Google Meet link</label><input class="field-input" name="meet_link" value="${esc(s.meet_link || '')}" placeholder="https://meet.google.com/..."></div>
            <div class="field"><label class="field-label">Dynamic Meet links</label><input type="checkbox" name="dynamic_meet_links" ${s.dynamic_meet_links ? 'checked' : ''}></div>
            <div class="field"><label class="field-label">Delete temp Meet events</label><input type="checkbox" name="delete_meet_events" ${s.delete_meet_events ? 'checked' : ''}></div>
          </div>
          <button type="submit" class="btn btn-primary">Save</button>
          <div id="set-status" class="status"></div>
        </form>
      </div>`;
    const status = document.getElementById('set-status');
    document.getElementById('set-form').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const payload = { csrf: APP.dataset.csrf,
            organizer_bio: fd.get('organizer_bio'), global_daily_cap: fd.get('global_daily_cap'),
            global_weekly_cap: fd.get('global_weekly_cap'), whatsapp_enabled: fd.get('whatsapp_enabled') ? 1 : 0,
            whatsapp_destination_number: fd.get('whatsapp_destination_number'), organizer_timezone: fd.get('organizer_timezone'),
            request_hold_hours: Number(fd.get('request_hold_hours') || 24), request_log_retention_days: Number(fd.get('request_log_retention_days') || 30),
            meet_link: fd.get('meet_link'), dynamic_meet_links: fd.get('dynamic_meet_links') ? 1 : 0,
            delete_meet_events: fd.get('delete_meet_events') ? 1 : 0 };
        status.className = 'status is-visible status-info';
        status.textContent = 'Saving…';
        try {
            await api('api/admin/settings.php', 'POST', payload);
            const file = fd.get('photo');
            if (file && file.size) {
                const fd2 = new FormData();
                fd2.append('photo', file);
                fd2.append('csrf', APP.dataset.csrf);
                const res = await fetch('api/admin/settings.php', { method: 'POST', body: fd2 });
                const r = await res.json().catch(() => ({}));
                if (!res.ok || !r.ok) throw new Error(r.error || 'Photo upload failed');
            }
            status.className = 'status is-visible status-info';
            status.textContent = 'Saved.';
        } catch (err) {
            status.className = 'status is-visible status-error';
            status.textContent = err.message;
        }
    });
}

// --- Logout -------------------------------------------------------------------------

const logoutBtn = document.getElementById('admin-logout');
if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
        await fetch('api/admin/login.php', { method: 'DELETE' });
        location.reload();
    });
}

if (APP) boot();
