<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

daluyan_session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (daluyan_current_user() === null) {
    header('Location: login.html?next=council-services.php', true, 302);
    exit;
}

$csrfToken = htmlspecialchars((string) $_SESSION['csrf'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" class="dark scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Council Services | DALUYAN SSC-UIM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="brand.css">
  <style>
    :root {
      color-scheme: dark;
      --cyan: #55CFF5;
      --orange: #FF6F32;
      --amber: #EEA61C;
      --base: #060a12;
      --muted: #94a3b8;
      --line: rgba(255,255,255,.12);
      font-family: Poppins, sans-serif;
      background: var(--base);
      color: #f3f4f6;
    }
    * { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; background: radial-gradient(ellipse at top right, rgba(0,136,179,.12), transparent 40%), var(--base); }
    a { color: inherit; text-decoration: none; }
    .wrap { width: min(1200px, calc(100% - 2rem)); margin: 0 auto; }
    .site-header { border-bottom: 1px solid var(--line); background: rgba(6,10,18,.84); }
    .nav { min-height: 4.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .nav-links { display: flex; align-items: center; gap: 1rem; font-size: .72rem; font-weight: 600; color: #cbd5e1; }
    .nav-links a:hover { color: var(--cyan); }
    .signout { padding: .55rem .8rem; border: 1px solid var(--line); border-radius: .65rem; background: rgba(255,255,255,.05); color: #cbd5e1; font: inherit; cursor: pointer; }
    .signout:hover { border-color: rgba(85,207,245,.45); color: #fff; }
    main { padding: clamp(2.5rem, 7vw, 5rem) 0; }
    .eyebrow { color: var(--amber); font-size: .65rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
    h1 { margin: .65rem 0 .45rem; font-size: clamp(2rem, 5vw, 3rem); line-height: 1.1; letter-spacing: -.03em; }
    .intro { max-width: 42rem; margin: 0; color: var(--muted); font-size: .84rem; line-height: 1.8; }
    .workspace-count { margin: 1.8rem 0 .5rem; color: var(--cyan); font-size: .65rem; font-weight: 700; letter-spacing: .1em; text-align: right; text-transform: uppercase; }
    .service-list { border-top: 1px solid var(--line); }
    .service-row { display: grid; grid-template-columns: 3rem 1fr 2.4rem; align-items: start; gap: .8rem 1.3rem; padding: 1.15rem .8rem; border-bottom: 1px solid var(--line); border-radius: .8rem; transition: background-color .2s ease, padding-left .2s ease; }
    .service-row:hover { padding-left: 1.15rem; background: rgba(255,255,255,.05); }
    .service-num { padding-top: .5rem; color: var(--orange); font-size: .6rem; letter-spacing: .1em; }
    .service-title { margin: 0; font-size: clamp(1rem, 2.1vw, 1.2rem); line-height: 1.35; }
    .service-tag { display: inline-block; margin-left: .5rem; padding: .14rem .4rem; border: 1px solid rgba(85,207,245,.3); border-radius: 999px; background: rgba(85,207,245,.1); color: var(--cyan); font-size: .48rem; letter-spacing: .1em; text-transform: uppercase; vertical-align: middle; white-space: nowrap; }
    .service-desc { margin: .35rem 0 0; color: var(--muted); font-size: .75rem; font-weight: 300; line-height: 1.7; }
    .service-arrow { width: 2.2rem; height: 2.2rem; display: grid; place-items: center; border: 1px solid var(--line); border-radius: .7rem; background: rgba(255,255,255,.05); color: var(--cyan); font-size: .65rem; }
    .service-row:hover .service-arrow { border-color: var(--cyan); background: var(--cyan); color: var(--base); }
    .service-note { display: flex; flex-wrap: wrap; gap: .6rem 1.3rem; margin-top: 1.2rem; color: #64748b; font-size: .56rem; letter-spacing: .07em; text-transform: uppercase; }
    .service-note i { margin-right: .3rem; color: var(--cyan); }
    .status { margin-top: 1rem; color: #fca5a5; font-size: .75rem; }
    @media (max-width: 640px) {
      .nav-links a { display: none; }
      .service-row { grid-template-columns: 2rem 1fr 2.2rem; gap: .7rem; padding-inline: .35rem; }
      .service-row:hover { padding-left: .65rem; }
      .service-tag { margin: .35rem 0 0; }
    }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="wrap nav">
      <a href="DALUYAN-UIM.html" class="portal-brand" aria-label="DALUYAN home">
        <span class="portal-brand-mark" aria-hidden="true"><span><i class="fa-solid fa-shield-halved"></i></span></span>
        <span><span class="portal-brand-name">DALUYAN</span><span class="portal-brand-sub">SSC-UIM</span></span>
      </a>
      <nav class="nav-links" aria-label="Council navigation">
        <a href="DALUYAN-UIM.html#directory">Committee Directory</a>
        <a href="mechanism-tools.html">Mechanism Tools</a>
        <button class="signout" type="button" id="signout">Sign Out</button>
      </nav>
    </div>
  </header>
  <main>
    <div class="wrap">
      <span class="eyebrow"><i class="fa-solid fa-circle" aria-hidden="true"></i> Internal mechanism protocols</span>
      <h1>Council services</h1>
      <p class="intro">Automated forms, live attendance logging, and archival document tracking for every committee.</p>
      <p class="workspace-count">Workspace · 4 active protocols</p>
      <div class="service-list">
        <a class="service-row" href="mechanism-tools.html?tool=dtn">
          <span class="service-num">01</span>
          <span><h2 class="service-title">Document Tracking Number <span class="service-tag">CommAIRS</span></h2><p class="service-desc">Generate an official DTN for any council communication, routed to the SecGen and the archives vault.</p></span>
          <span class="service-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a class="service-row" href="mechanism-tools.html?tool=duty">
          <span class="service-num">02</span>
          <span><h2 class="service-title">Event Duty Tracker <span class="service-tag">Operations</span></h2><p class="service-desc">Log check-in and check-out timestamps for campus events, assemblies, and committee shifts.</p></span>
          <span class="service-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a class="service-row" href="mechanism-tools.html?tool=leave">
          <span class="service-num">03</span>
          <span><h2 class="service-title">Leave of Absence <span class="service-tag">Administrative</span></h2><p class="service-desc">File a formal LOA with committee, inclusive dates, and justification for head review.</p></span>
          <span class="service-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
        </a>
        <a class="service-row" href="mechanism-tools.html?tool=report">
          <span class="service-num">04</span>
          <span><h2 class="service-title">Attendance Report <span class="service-tag">Council-wide</span></h2><p class="service-desc">Review weekly duty hours and participation across CommAIRS, CommCREL, CommFIMA, and CommPROL.</p></span>
          <span class="service-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
        </a>
      </div>
      <p class="service-note">
        <span><i class="fa-solid fa-lock" aria-hidden="true"></i> Adviser &amp; executive reports require elevated access</span>
        <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i> R. Martinez St., Brgy. Bucana, Nasugbu, Batangas 4231</span>
      </p>
      <p class="status" id="status" role="status" aria-live="polite" hidden></p>
    </div>
  </main>
  <script>
    const csrfToken = '<?= $csrfToken ?>';
    const signoutButton = document.getElementById('signout');
    const status = document.getElementById('status');

    signoutButton.addEventListener('click', async () => {
      signoutButton.disabled = true;
      status.hidden = true;
      try {
        const response = await fetch('api.php?action=logout', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: '{}'
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Unable to sign out.');
        window.location.replace('login.html');
      } catch (error) {
        status.textContent = error.message || 'Unable to sign out.';
        status.hidden = false;
        signoutButton.disabled = false;
      }
    });
  </script>
</body>
</html>
