<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

daluyan_session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$currentUser = daluyan_current_user();
if ($currentUser === null) {
    header('Location: login.html', true, 302);
    exit;
}
if ($currentUser['role'] === 'executive') {
    header('Location: executive-dashboard.html', true, 302);
    exit;
}

$csrfToken = htmlspecialchars((string) $_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#060a12">
  <title>My Dashboard | DALUYAN SSC-UIM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="brand.css">
  <style>
    :root {
      color-scheme: dark;
      --cyan: #55CFF5;
      --teal: #0088B3;
      --orange: #FF6F32;
      --amber: #EEA61C;
      --base: #060a12;
      --surface: #0b1322;
      --muted: #94a3b8;
      --muted-dim: #64748b;
      --line: rgba(255,255,255,.12);
      --line-soft: rgba(255,255,255,.08);
      --glass: rgba(255,255,255,.05);
      --glass-hover: rgba(255,255,255,.09);
      font-family: Poppins, sans-serif;
      background: var(--base);
      color: #f3f4f6;
    }
    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body { min-height: 100vh; margin: 0; background: var(--base); line-height: 1.6; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
    a { color: inherit; text-decoration: none; }
    button, select, input, textarea { font: inherit; color: inherit; }
    button { cursor: pointer; }
    :focus-visible { outline: 2px solid var(--cyan); outline-offset: 3px; }
    .ambient { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
    .ambient span { position: absolute; display: block; border-radius: 50%; filter: blur(130px); opacity: .34; }
    .ambient span:nth-child(1) { top: -12rem; left: -10rem; width: 30rem; height: 30rem; background: rgba(85,207,245,.35); }
    .ambient span:nth-child(2) { top: 24%; right: -14rem; width: 32rem; height: 32rem; background: rgba(0,136,179,.32); }
    .ambient span:nth-child(3) { bottom: -16rem; left: 28%; width: 28rem; height: 28rem; background: rgba(255,111,50,.2); }
    .dashboard-surface { position: relative; z-index: 1; min-height: 100vh; background: linear-gradient(135deg,rgba(85,207,245,.045),transparent 46%); }
    .wrap { width: min(100% - 2.5rem, 1200px); margin-inline: auto; }
    .header { position: sticky; top: 0; z-index: 30; border-bottom: 1px solid var(--line-soft); background: rgba(6,10,18,.84); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
    .nav { min-height: 4.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .user-tools { display: flex; align-items: center; gap: .8rem; color: var(--muted); font-size: .68rem; }
    .user-name { max-width: 24rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; padding: .65rem .95rem; border: 1px solid rgba(255,255,255,.18); border-radius: .8rem; background: rgba(255,255,255,.07); color: #e2e8f0; font-size: .7rem; font-weight: 600; backdrop-filter: blur(12px); transition: transform .2s ease, border-color .2s ease, background-color .2s ease, box-shadow .2s ease; }
    .button:hover { transform: translateY(-2px); border-color: rgba(85,207,245,.55); background: rgba(255,255,255,.12); box-shadow: 0 0 20px rgba(85,207,245,.15); }
    main { position: relative; padding: clamp(2.4rem, 6vw, 4.2rem) 0; }
    .stack { display: grid; gap: clamp(1.1rem, 2.4vw, 1.7rem); }
    .hero { display: flex; align-items: end; justify-content: space-between; gap: 1.4rem; flex-wrap: wrap; padding-bottom: 1.3rem; }
    .eyebrow { display: inline-flex; align-items: center; gap: .5rem; color: var(--cyan); font-size: .62rem; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; }
    .eyebrow::before { content: ''; width: .4rem; height: .4rem; border-radius: 50%; background: var(--cyan); box-shadow: 0 0 10px rgba(85,207,245,.8); }
    h1 { margin: .7rem 0 .45rem; font-size: clamp(2rem, 4.5vw, 3.2rem); font-weight: 800; letter-spacing: -.035em; line-height: 1.08; }
    .grad-brand { background: linear-gradient(120deg,var(--cyan),var(--orange) 58%,var(--amber)); background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .muted { color: var(--muted); font-size: .76rem; line-height: 1.75; }
    .scope { display: inline-flex; align-items: center; gap: .45rem; padding: .38rem .72rem; border: 1px solid rgba(85,207,245,.32); border-radius: 999px; background: rgba(85,207,245,.09); color: var(--cyan); font-size: .6rem; font-weight: 600; letter-spacing: .04em; }
    .period-control { display: grid; gap: .4rem; min-width: 10rem; color: var(--muted); font-size: .64rem; }
    .panel, .stat { border: 1px solid var(--line); background: rgba(11,19,34,.62); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); box-shadow: 0 14px 36px rgba(0,0,0,.24), inset 0 1px 1px rgba(255,255,255,.08); }
    .panel { overflow: hidden; border-radius: 1.1rem; }
    .panel-head { display: flex; align-items: center; gap: .85rem; padding: 1rem 1.2rem; border-bottom: 1px solid var(--line-soft); }
    .panel-icon, .stat-icon { display: grid; flex: 0 0 auto; place-items: center; width: 2.4rem; height: 2.4rem; border: 1px solid rgba(85,207,245,.3); border-radius: .75rem; background: rgba(85,207,245,.1); color: var(--cyan); }
    .panel-head h2 { margin: 0; font-size: .88rem; font-weight: 600; }
    .panel-head p { margin: .25rem 0 0; color: var(--muted-dim); font-size: .64rem; line-height: 1.6; }
    .panel-body { padding: 1.1rem 1.2rem; }
    .stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(190px,1fr)); gap: .9rem; }
    .stat { position: relative; display: flex; align-items: center; gap: .9rem; min-height: 6rem; padding: 1rem 1.15rem; overflow: hidden; border-radius: 1rem; transition: background-color .2s ease, border-color .2s ease, transform .2s ease; }
    .stat:hover { transform: translateY(-2px); border-color: rgba(85,207,245,.36); background: rgba(255,255,255,.07); }
    .stat:nth-child(2) .stat-icon { border-color: rgba(255,111,50,.3); background: rgba(255,111,50,.1); color: var(--orange); }
    .stat:nth-child(3) .stat-icon { border-color: rgba(238,166,28,.3); background: rgba(238,166,28,.1); color: var(--amber); }
    .stat-label { color: var(--muted); font-size: .65rem; }
    .stat-value { margin-top: .12rem; font-size: 1.55rem; font-weight: 700; line-height: 1.2; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: .67rem; }
    th, td { padding: .8rem 1rem; border-bottom: 1px solid rgba(255,255,255,.055); vertical-align: top; }
    th { background: rgba(255,255,255,.025); color: var(--muted-dim); font-size: .56rem; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; white-space: nowrap; }
    td { color: #cbd5e1; }
    tbody tr:hover { background: rgba(255,255,255,.025); }
    tbody tr:last-child td { border-bottom: 0; }
    .empty { display: flex; align-items: center; gap: .6rem; padding: 1.3rem 1.2rem; color: var(--muted-dim); font-size: .7rem; }
    .empty i { color: var(--cyan); }
    .subtle { color: var(--muted-dim); font-size: .59rem; }
    .status-tag { display: inline-flex; align-items: center; padding: .2rem .48rem; border: 1px solid rgba(85,207,245,.2); border-radius: 999px; background: rgba(85,207,245,.07); color: #9de6fb; font-size: .56rem; white-space: nowrap; }
    .status-tag.is-pending { border-color: rgba(238,166,28,.25); background: rgba(238,166,28,.08); color: #f5cd7a; }
    .status-tag.is-complete, .status-tag.is-approved { border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.08); color: #86efac; }
    .forms { display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,330px),1fr)); gap: 1rem; }
    .form { display: grid; align-content: start; gap: .8rem; padding: 1rem; border: 1px solid var(--line-soft); border-radius: .9rem; background: rgba(255,255,255,.025); }
    .form-title { display: flex; align-items: center; gap: .55rem; font-size: .78rem; font-weight: 600; }
    .form-title i { color: var(--cyan); }
    .form label { display: grid; gap: .38rem; color: #cbd5e1; font-size: .64rem; }
    .field { width: 100%; padding: .68rem .78rem; border: 1px solid rgba(255,255,255,.16); border-radius: .65rem; outline: none; background: rgba(255,255,255,.06); color: #f3f4f6; font-size: .7rem; transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease; }
    .field:focus { border-color: var(--cyan); background: rgba(255,255,255,.09); box-shadow: 0 0 16px rgba(85,207,245,.13); }
    .field option { background: var(--surface); color: #f3f4f6; }
    textarea.field { resize: vertical; }
    .submit { border: 1px solid transparent; background: linear-gradient(135deg,var(--teal),var(--cyan)); color: #06121a; }
    .submit-warm { border: 1px solid transparent; background: linear-gradient(135deg,var(--orange),var(--amber)); color: #111827; }
    .submit:hover, .submit-warm:hover { filter: brightness(1.08); }
    .notice { min-height: 1rem; margin: 0; color: #a7f3d0; font-size: .65rem; }
    .page-status { min-height: 1.2rem; margin: 0; color: #fca5a5; }
    .adviser-link { margin: 1.1rem 1.2rem; }
    footer { padding: 0 0 1.5rem; color: var(--muted-dim); font-size: .58rem; letter-spacing: .06em; text-align: center; }
    @media (max-width: 620px) {
      .wrap { width: min(100% - 1.5rem, 1200px); }
      .user-name { max-width: 9rem; }
      .hero { align-items: stretch; }
      .period-control { width: 100%; }
      .stats { grid-template-columns: 1fr; }
      .stat { min-height: 5.2rem; }
      .panel-head { padding: .9rem; }
      th, td { padding: .7rem .75rem; }
    }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
  </style>
</head>
<body>
  <div class="dashboard-surface">
  <div class="ambient" aria-hidden="true"><span></span><span></span><span></span></div>
  <header class="header">
    <nav class="wrap nav">
      <a href="DALUYAN-UIM.html" class="portal-brand" aria-label="DALUYAN home">
        <span class="portal-brand-mark" aria-hidden="true"><span><i class="fa-solid fa-shield-halved"></i></span></span>
        <span><span class="portal-brand-name">DALUYAN</span><span class="portal-brand-sub">SSC-UIM</span></span>
      </a>
      <div class="user-tools">
        <span id="user-name" class="user-name">Loading account...</span>
        <button id="sign-out" class="button" type="button"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</button>
      </div>
    </nav>
  </header>
  <main class="wrap stack">
    <section class="hero">
      <div>
        <span id="role-scope" class="scope"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Loading access scope</span>
        <h1>My <span class="grad-brand">dashboard</span></h1>
        <p class="muted">Attendance and document tracking records available to your account.</p>
      </div>
      <label class="period-control">Reporting period
        <select id="report-period" class="field" aria-label="Reporting period">
          <option value="7">Last 7 days</option>
          <option value="30" selected>Last 30 days</option>
          <option value="90">Last 90 days</option>
        </select>
      </label>
    </section>

    <section class="stats" aria-label="Attendance summary">
      <article class="stat"><span class="stat-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span><div><div class="stat-label">Attendance entries</div><div id="attendance-count" class="stat-value">--</div></div></article>
      <article class="stat"><span class="stat-icon"><i class="fa-regular fa-clock" aria-hidden="true"></i></span><div><div class="stat-label">Completed duty hours</div><div id="attendance-hours" class="stat-value">--</div></div></article>
      <article class="stat"><span class="stat-icon"><i class="fa-solid fa-hashtag" aria-hidden="true"></i></span><div><div class="stat-label">DTN requests in view</div><div id="dtn-count" class="stat-value">--</div></div></article>
    </section>

    <section class="panel">
      <div class="panel-head"><span class="panel-icon"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span><div><h2>Attendance overview & report</h2><p>Latest attendance records in your access scope.</p></div></div>
      <div id="attendance-table" class="table-wrap"><p class="empty"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Loading attendance...</p></div>
    </section>

    <section class="panel">
      <div class="panel-head"><span class="panel-icon"><i class="fa-solid fa-file-lines" aria-hidden="true"></i></span><div><h2>DTN requests</h2><p>Requests in your access scope.</p></div></div>
      <div id="dtn-table" class="table-wrap"><p class="empty"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Loading requests...</p></div>
    </section>

    <section id="leave-section" class="panel" hidden>
      <div class="panel-head"><span class="panel-icon"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span><div><h2>Leave of absence requests</h2><p>Leave requests in your access scope.</p></div></div>
      <div id="leave-table" class="table-wrap"><p class="empty"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Loading leave requests...</p></div>
    </section>

    <section id="comm-airs-tools" class="panel" hidden>
      <div class="panel-head"><span class="panel-icon"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i></span><div><h2>CommAIRS operations</h2><p>Duty entries are submitted for adviser or executive approval before they are added to attendance records. Leave requests can be filed for active CommAIRS members, including facilitating officers.</p></div></div>
      <div class="panel-body forms">
        <form id="duty-form" class="form">
          <strong class="form-title"><i class="fa-solid fa-user-clock" aria-hidden="true"></i> Submit duty entry for approval</strong>
          <label>Officer<select class="field" name="officerEmail" required></select></label>
          <label>Event / shift<input class="field" name="event" required maxlength="200"></label>
          <label>Venue<input class="field" name="venue" required maxlength="200"></label>
          <label>Shift status<select class="field" name="status"><option value="check_in">Duty check-in</option><option value="check_out">Duty check-out</option><option value="on_call">On-call duty</option></select></label>
          <button class="button submit-warm" type="submit">Submit for approval</button>
          <p id="duty-status" class="notice" role="status"></p>
        </form>
        <form id="leave-form" class="form">
          <strong class="form-title"><i class="fa-solid fa-file-signature" aria-hidden="true"></i> File a leave request</strong>
          <label>Officer<select class="field" name="email" required></select></label>
          <label>Start date<input class="field" name="start" type="date" required></label>
          <label>End date<input class="field" name="end" type="date" required></label>
          <label>Reason<textarea class="field" name="reason" rows="3" required maxlength="2000"></textarea></label>
          <button class="button submit" type="submit">Submit leave request</button>
          <p id="leave-status" class="notice" role="status"></p>
        </form>
      </div>
    </section>

    <section id="adviser-tools" class="panel" hidden>
      <div class="panel-head"><span class="panel-icon"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div><h2>Adviser operations</h2><p>Open the full set of council workflows and reports.</p></div></div>
      <div class="panel-body"><a class="button submit" href="mechanism-tools.html"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open all mechanism tools</a></div>
    </section>

    <p id="page-status" class="muted page-status" role="status" aria-live="polite"></p>
  </main>
  <footer class="wrap">Supreme Student Council · ARASOF-Nasugbu · Unified Internal Mechanism</footer>
  </div>
  <script>
    const csrfToken = <?= json_encode((string) $_SESSION['csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const currentUser = <?= json_encode(daluyan_current_user(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const reportPeriod = document.getElementById('report-period');

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
    }

    function formatDate(value) {
      if (!value) return '—';
      const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
      return Number.isNaN(date.getTime()) ? escapeHtml(value) : escapeHtml(date.toLocaleString());
    }

    async function request(action, options = {}, query = '') {
      const response = await fetch(`api.php?action=${encodeURIComponent(action)}${query}`, {
        credentials: 'same-origin',
        ...options,
        headers: { ...(options.headers || {}), ...(options.method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken } : {}) }
      });
      const data = await response.json();
      if (response.status === 401) {
        window.location.replace('login.html');
        throw new Error('Your session has ended. Log in again.');
      }
      if (!response.ok) throw new Error(data.error || 'Unable to load dashboard data.');
      return data;
    }

    function renderTable(container, headers, rows, emptyMessage) {
      if (!rows.length) {
        container.innerHTML = `<p class="empty"><i class="fa-regular fa-folder-open" aria-hidden="true"></i> ${escapeHtml(emptyMessage)}</p>`;
        return;
      }
      container.innerHTML = `<table><thead><tr>${headers.map(header => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table>`;
    }

    function statusBadge(value) {
      const status = String(value ?? '');
      const variant = ['pending', 'submitted'].includes(status.toLowerCase()) ? ' is-pending'
        : ['complete', 'approved'].includes(status.toLowerCase()) ? ' is-complete' : '';
      return `<span class="status-tag${variant}">${escapeHtml(status.replaceAll('_', ' '))}</span>`;
    }

    function renderWorkspace(workspace) {
      document.getElementById('attendance-count').textContent = workspace.attendanceSummary.entries;
      document.getElementById('attendance-hours').textContent = Number(workspace.attendanceSummary.hours).toFixed(2);
      document.getElementById('dtn-count').textContent = workspace.dtnRequests.length;
      const scopeLabel = workspace.scope.attendance === 'all' ? 'Adviser · all records'
        : workspace.scope.attendance === 'committee' ? `${workspace.scope.committees.join(', ')} · committee records`
        : 'Personal records';
      document.getElementById('role-scope').textContent = scopeLabel;
      document.getElementById('user-name').textContent = `${currentUser.name} · ${currentUser.position}`;

      renderTable(document.getElementById('attendance-table'),
        ['Officer', 'Position', 'Event', 'Venue', 'Status', 'Check-in', 'Hours'],
        workspace.attendance.map(item => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.position)}</td><td>${escapeHtml(item.event)}</td><td>${escapeHtml(item.venue)}</td><td>${statusBadge(item.status)}</td><td>${formatDate(item.checkIn)}</td><td>${Number(item.hours).toFixed(2)}</td></tr>`),
        'No attendance records are available in this period.');

      renderTable(document.getElementById('dtn-table'),
        ['Request', 'Subject', 'Requester', 'Committee', 'Status', 'Submitted'],
        workspace.dtnRequests.map(item => `<tr><td>${escapeHtml(item.code)}</td><td>${escapeHtml(item.subject)}</td><td>${escapeHtml(item.requesterEmail)}</td><td>${escapeHtml(item.committee)}</td><td>${statusBadge(item.status)}</td><td>${formatDate(item.createdAt)}</td></tr>`),
        'No DTN requests are available in your access scope.');

      const canViewLeave = currentUser.role === 'adviser' || workspace.scope.canManageCommAirs;
      document.getElementById('leave-section').hidden = !canViewLeave;
      document.getElementById('adviser-tools').hidden = currentUser.role !== 'adviser';
      if (canViewLeave) {
        renderTable(document.getElementById('leave-table'),
          ['Officer', 'Committee', 'Dates', 'Reason', 'Status', 'Submitted'],
          workspace.leaveRequests.map(item => `<tr><td>${escapeHtml(item.name)}<br><span class="subtle">${escapeHtml(item.email)}</span></td><td>${escapeHtml(item.committee)}</td><td>${escapeHtml(item.startDate)} – ${escapeHtml(item.endDate)}</td><td>${escapeHtml(item.reason)}</td><td>${statusBadge(item.status)}</td><td>${formatDate(item.createdAt)}</td></tr>`),
          'No leave requests are available in your access scope.');
      }

      const canManageCommAirs = workspace.scope.canManageCommAirs;
      document.getElementById('comm-airs-tools').hidden = !canManageCommAirs;
      if (canManageCommAirs) {
        for (const select of document.querySelectorAll('#duty-form select[name="officerEmail"], #leave-form select[name="email"]')) {
          select.innerHTML = workspace.commAirsMembers.map(member => `<option value="${escapeHtml(member.email)}">${escapeHtml(member.name)} — ${escapeHtml(member.position)}</option>`).join('');
        }
      }
    }

    async function loadWorkspace() {
      document.getElementById('page-status').textContent = '';
      try {
        const { workspace } = await request('workspace', {}, `&days=${Number(reportPeriod.value)}`);
        renderWorkspace(workspace);
      } catch (error) {
        document.getElementById('page-status').textContent = error.message || 'Unable to load your dashboard.';
      }
    }

    document.getElementById('duty-form').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const payload = Object.fromEntries(new FormData(form).entries());
      const officer = form.elements.officerEmail.selectedOptions[0]?.textContent || payload.officerEmail;
      const status = form.elements.status.selectedOptions[0]?.textContent || payload.status;
      if (!window.confirm(`Submit this duty entry for approval?\n\nOfficer: ${officer}\nEvent: ${payload.event}\nVenue: ${payload.venue}\nShift: ${status}`)) return;
      const message = document.getElementById('duty-status');
      try {
        await request('duty', { method: 'POST', body: JSON.stringify(payload) });
        message.textContent = 'Duty entry submitted for approval.';
        form.reset();
        await loadWorkspace();
      } catch (error) {
        message.textContent = error.message || 'Unable to save duty record.';
      }
    });

    document.getElementById('leave-form').addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const payload = Object.fromEntries(new FormData(form).entries());
      payload.committee = 'CommAIRS';
      const message = document.getElementById('leave-status');
      try {
        await request('leave', { method: 'POST', body: JSON.stringify(payload) });
        message.textContent = 'Leave request submitted.';
        form.reset();
        await loadWorkspace();
      } catch (error) {
        message.textContent = error.message || 'Unable to submit leave request.';
      }
    });

    reportPeriod.addEventListener('change', loadWorkspace);
    document.getElementById('sign-out').addEventListener('click', async () => {
      try {
        await request('logout', { method: 'POST' });
        window.location.replace('login.html');
      } catch (error) {
        document.getElementById('page-status').textContent = error.message || 'Unable to log out.';
      }
    });

    loadWorkspace();
  </script>
</body>
</html>
