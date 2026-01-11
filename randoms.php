<?php
declare(strict_types=1);

session_start();

function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function session_init(): void {
    if (!isset($_SESSION['iterations'])) $_SESSION['iterations'] = 0;

    // Timer persistence
    if (!isset($_SESSION['elapsed_ms'])) $_SESSION['elapsed_ms'] = 0; // accumulated (paused) time
    if (!isset($_SESSION['start_ms'])) $_SESSION['start_ms'] = null;  // current running start timestamp (ms) or null
    if (!isset($_SESSION['running'])) $_SESSION['running'] = false;

    // Remember last chosen rate (ticks/sec) for UI defaults
    if (!isset($_SESSION['rate_per_sec'])) $_SESSION['rate_per_sec'] = 10;
}

function get_elapsed_ms(): int {
    session_init();
    $elapsed = (int) $_SESSION['elapsed_ms'];
    if (!empty($_SESSION['running']) && is_int($_SESSION['start_ms']) && $_SESSION['start_ms'] > 0) {
        $elapsed += max(0, now_ms() - (int)$_SESSION['start_ms']);
    }
    return $elapsed;
}

function start_timer_if_needed(): void {
    session_init();
    if (empty($_SESSION['running'])) {
        $_SESSION['running'] = true;
        $_SESSION['start_ms'] = now_ms();
    }
}

function stop_timer_if_running(): void {
    session_init();
    if (!empty($_SESSION['running']) && is_int($_SESSION['start_ms']) && $_SESSION['start_ms'] > 0) {
        $delta = max(0, now_ms() - (int)$_SESSION['start_ms']);
        $_SESSION['elapsed_ms'] = (int)$_SESSION['elapsed_ms'] + $delta;
        $_SESSION['running'] = false;
        $_SESSION['start_ms'] = null;
    } else {
        $_SESSION['running'] = false;
        $_SESSION['start_ms'] = null;
    }
}

function do_one_tick(): array {
    $_SESSION['iterations'] = (int)$_SESSION['iterations'] + 1;

    $numbers = [];
    for ($i = 0; $i < 9; $i++) {
        $numbers[] = random_int(0, 2);
    }

    $win = (count(array_unique($numbers)) === 1);

    if ($win) {
        stop_timer_if_running(); // freeze time on win
    }

    return [$numbers, $win];
}

// --- API endpoint (AJAX) ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    session_init();
    $action = $_GET['action'] ?? 'tick';

    if ($action === 'status') {
        echo json_encode([
            'ok' => true,
            'iterations' => (int)$_SESSION['iterations'],
            'running' => (bool)$_SESSION['running'],
            'elapsed_ms' => get_elapsed_ms(),
            'rate_per_sec' => (int)$_SESSION['rate_per_sec'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reset') {
        $_SESSION['iterations'] = 0;
        $_SESSION['elapsed_ms'] = 0;
        $_SESSION['start_ms'] = null;
        $_SESSION['running'] = false;
        // keep rate_per_sec as preference
        echo json_encode([
            'ok' => true,
            'iterations' => 0,
            'running' => false,
            'elapsed_ms' => 0,
            'rate_per_sec' => (int)$_SESSION['rate_per_sec'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'set_rate') {
        $rate = isset($_GET['rate']) ? (int)$_GET['rate'] : (int)$_SESSION['rate_per_sec'];
        $rate = max(1, min(60, $rate));
        $_SESSION['rate_per_sec'] = $rate;
        echo json_encode([
            'ok' => true,
            'rate_per_sec' => $rate,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'start') {
        start_timer_if_needed();
        echo json_encode([
            'ok' => true,
            'running' => (bool)$_SESSION['running'],
            'iterations' => (int)$_SESSION['iterations'],
            'elapsed_ms' => get_elapsed_ms(),
            'rate_per_sec' => (int)$_SESSION['rate_per_sec'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'stop') {
        stop_timer_if_running();
        echo json_encode([
            'ok' => true,
            'running' => (bool)$_SESSION['running'],
            'iterations' => (int)$_SESSION['iterations'],
            'elapsed_ms' => get_elapsed_ms(),
            'rate_per_sec' => (int)$_SESSION['rate_per_sec'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'catchup') {
        start_timer_if_needed();

        $ticks = isset($_GET['ticks']) ? (int)$_GET['ticks'] : 0;
        $ticks = max(0, min(20000, $ticks)); // safety cap

        $numbers = null;
        $win = false;

        for ($i = 0; $i < $ticks; $i++) {
            [$numbers, $win] = do_one_tick();
            if ($win) break;
        }

        echo json_encode([
            'ok' => true,
            'numbers' => $numbers,
            'win' => $win,
            'iterations' => (int)$_SESSION['iterations'],
            'running' => (bool)$_SESSION['running'],
            'elapsed_ms' => get_elapsed_ms(),
            'rate_per_sec' => (int)$_SESSION['rate_per_sec'],
            'processed' => $ticks,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // tick
    start_timer_if_needed();
    [$numbers, $win] = do_one_tick();

    echo json_encode([
        'ok' => true,
        'numbers' => $numbers,
        'win' => $win,
        'value' => $numbers[0],
        'iterations' => (int)$_SESSION['iterations'],
        'running' => (bool)$_SESSION['running'],
        'elapsed_ms' => get_elapsed_ms(),
        'rate_per_sec' => (int)$_SESSION['rate_per_sec'],
        'server_ms' => now_ms(),
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

session_init();
$defaultRate = (int)$_SESSION['rate_per_sec'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Random Loop (AJAX)</title>
  <style>
    :root{
      --bg:#0f0f0f;
      --card:#161616;
      --card2:#1c1c1c;
      --border:#2a2a2a;
      --text:#e8e8e8;
      --muted:#a0a0a0;
      --green:#4caf50;
      --blue:#2196f3;
      --shadow: 0 0 40px rgba(0,0,0,0.65);
      --radius: 16px;
    }
    * { box-sizing: border-box; }
    body{
      margin:0;
      min-height:100vh;
      background: radial-gradient(1200px 600px at 50% 30%, rgba(76,175,80,0.08), transparent 60%),
                  radial-gradient(1000px 500px at 70% 70%, rgba(33,150,243,0.08), transparent 60%),
                  var(--bg);
      color:var(--text);
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Helvetica Neue", sans-serif;
      display:flex;
      flex-direction:column;
    }

    .wrap{
      flex:1;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:28px;
    }

    .panel{
      width:min(1300px, 100%);
      background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent 40%), var(--card);
      border:1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 26px;
      position:relative;
      overflow:hidden;
    }

    .title{
      display:flex;
      align-items:baseline;
      justify-content:space-between;
      gap:12px;
      margin-bottom:18px;
      flex-wrap:wrap;
    }
    .title h1{
      font-size: 20px;
      margin:0;
      letter-spacing:0.2px;
    }
    .badge{
      font-size:12px;
      color:var(--muted);
      border:1px solid var(--border);
      padding:6px 10px;
      border-radius:999px;
      background: rgba(255,255,255,0.02);
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }
    .badge b{ color: var(--text); font-weight: 700; }

    .numbers{
      display:flex;
      gap:10px;
      justify-content:center;
      margin: 18px 0 12px;
      flex-wrap:wrap;
    }
    .pill{
      width: 88px;
      height: 64px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: var(--card2);
      border:1px solid var(--border);
      border-radius: 14px;
      font-size: 80px;
      letter-spacing: 1px;
      box-shadow: 0 12px 30px rgba(0,0,0,0.25);
      transform: translateZ(0);
      user-select:none;
      transition: color 120ms linear; /* smooth color change */
    }

    .meta{
      display:flex;
      justify-content:center;
      gap:18px;
      color:var(--muted);
      font-size: 13px;
      margin-bottom: 8px;
      flex-wrap:wrap;
    }
    .meta .strong{ color: var(--text); font-weight: 700; }

    .status{
      display:flex;
      justify-content:center;
      margin-top: 10px;
      font-size: 13px;
      color: var(--muted);
      align-items:center;
      gap: 10px;
      flex-wrap:wrap;
    }
    .dot{
      width:10px; height:10px; border-radius:50%;
      display:inline-block;
      background:#666;
      box-shadow: 0 0 0 0 rgba(255,255,255,0.0);
    }
    .dot.running{
      background: var(--green);
      box-shadow: 0 0 0 6px rgba(76,175,80,0.10);
    }
    .dot.stopped{
      background: #888;
    }

    .footer{
      padding: 18px 22px 28px;
      display:flex;
      justify-content:center;
      gap: 14px;
      flex-wrap:wrap;
      align-items:center;
    }
    .btn{
      min-width: 190px;
      padding: 16px 22px;
      font-size: 16px;
      border-radius: 14px;
      border:1px solid var(--border);
      color: var(--text);
      background: rgba(255,255,255,0.03);
      cursor:pointer;
      transition: transform .15s ease, background .15s ease, border-color .15s ease, opacity .15s ease;
      box-shadow: 0 18px 40px rgba(0,0,0,0.25);
    }
    .btn:hover{ transform: translateY(-2px); border-color: rgba(255,255,255,0.15); }
    .btn:active{ transform: translateY(0px); }
    .btn.primary{
      border-color: rgba(76,175,80,0.35);
      background: linear-gradient(180deg, rgba(76,175,80,0.16), rgba(255,255,255,0.03));
    }
    .btn.danger{
      border-color: rgba(244,67,54,0.35);
      background: linear-gradient(180deg, rgba(244,67,54,0.14), rgba(255,255,255,0.03));
    }
    .btn.secondary{
      border-color: rgba(33,150,243,0.35);
      background: linear-gradient(180deg, rgba(33,150,243,0.14), rgba(255,255,255,0.03));
    }
    .btn[disabled]{
      opacity:0.5;
      cursor:not-allowed;
      transform:none !important;
    }

    .rateBox{
      display:flex;
      align-items:center;
      gap:10px;
      padding: 10px 12px;
      border:1px solid var(--border);
      background: rgba(255,255,255,0.02);
      border-radius: 14px;
      box-shadow: 0 18px 40px rgba(0,0,0,0.18);
    }
    .rateBox label{
      color: var(--muted);
      font-size: 13px;
      white-space:nowrap;
    }
    .rateBox input{
      width: 110px;
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.03);
      color: var(--text);
      font-size: 16px;
      outline: none;
    }
    .rateBox input:focus{
      border-color: rgba(33,150,243,0.45);
      box-shadow: 0 0 0 6px rgba(33,150,243,0.08);
    }

    .overlay{
      position:fixed;
      inset:0;
      display:none;
      align-items:center;
      justify-content:center;
      background: rgba(0,0,0,0.65);
      padding: 24px;
      z-index: 10;
    }
    .overlay.show{ display:flex; }
    .modal{
      width:min(640px, 100%);
      background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)), var(--card);
      border:1px solid rgba(255,255,255,0.10);
      border-radius: 18px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.6);
      padding: 26px;
      text-align:center;
      position:relative;
      overflow:hidden;
      transform: scale(0.98);
      animation: pop .22s ease forwards;
    }
    @keyframes pop{ to { transform: scale(1); } }
    .modal h2{
      margin: 0 0 10px;
      color: var(--green);
      letter-spacing:0.2px;
    }
    .modal .big{
      font-size: 28px;
      margin: 10px 0 12px;
      letter-spacing: 6px;
    }
    .modal .small{
      color: var(--muted);
      font-size: 13px;
      margin: 0 0 10px;
    }
    .modal .timeBox{
      margin-top: 10px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.02);
      border-radius: 14px;
      padding: 12px 14px;
      color: var(--text);
      display:inline-block;
      font-size: 14px;
    }

    .confetti{
      position:absolute;
      inset:0;
      pointer-events:none;
      opacity:0;
    }
    .confetti.on{ opacity:1; }
    .confetti i{
      position:absolute;
      top:-10%;
      width:10px; height:18px;
      border-radius: 3px;
      opacity:0.95;
      animation: fall 900ms ease-in forwards;
      filter: drop-shadow(0 10px 18px rgba(0,0,0,0.25));
    }
    @keyframes fall{
      0%{ transform: translateY(-20px) rotate(0deg); opacity:1; }
      100%{ transform: translateY(520px) rotate(220deg); opacity:0; }
    }

    .pulse{
      animation: pulse 360ms ease-in-out 2;
      border-color: rgba(76,175,80,0.65) !important;
      box-shadow: 0 0 0 10px rgba(76,175,80,0.08);
    }
    @keyframes pulse{
      0%{ transform: scale(1); }
      50%{ transform: scale(1.06); }
      100%{ transform: scale(1); }
    }
  </style>
</head>
<body>

  <div class="wrap">
    <div class="panel">
      <div class="title">
        <h1>🎲 Random loop (AJAX) — 5 numbers (1..7)</h1>
        <div class="badge">
          <span>Rate:</span>
          <b><span id="rateBadge"><?php echo htmlspecialchars((string)$defaultRate, ENT_QUOTES); ?></span>/s</b>
          <span style="opacity:.8">•</span>
          <span>≈ <b><span id="msBadge">100</span> ms</b> / tick</span>
        </div>
      </div>

      <div class="numbers" id="numbers">
        <div class="pill">1</div>
        <div class="pill">2</div>
        <div class="pill">3</div>
        <div class="pill">4</div>
        <div class="pill">5</div>
        <div class="pill">6</div>
        <div class="pill">7</div>
        <div class="pill">8</div>
        <div class="pill">9</div>
      </div>

      <div class="meta">
        <div>Tries: <span class="strong" id="iters">0</span></div>
        <div>Last response: <span class="strong" id="lat">—</span> ms</div>
        <div>Time: <span class="strong" id="timer">00:00:00</span> <span id="timerLong" style="opacity:.85"></span></div>
      </div>

      <div class="status">
        <span class="dot stopped" id="dot"></span>
        <span id="statusText">Stopped</span>
        <span id="bgHint" style="opacity:.85"></span>
      </div>
    </div>
  </div>

  <div class="footer">
    <button class="btn primary" id="startBtn">▶ Start</button>
    <button class="btn danger" id="stopBtn" disabled>⏸ Stop</button>
    <button class="btn secondary" id="resetBtn">↻ Reset</button>

    <div class="rateBox" title="Change how often it refreshes (live). Recommended: 1–30/s.">
      <label for="rateInput">Ticks/sec</label>
      <input id="rateInput" type="number" min="1" max="60" step="1" value="<?php echo htmlspecialchars((string)$defaultRate, ENT_QUOTES); ?>" inputmode="numeric">
    </div>
  </div>

  <div class="overlay" id="overlay">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="winTitle">
      <div class="confetti" id="confetti"></div>
      <h2 id="winTitle">🎉 Match found!</h2>
      <div class="big" id="winNumbers">—</div>
      <p class="small">
        Tries: <b id="winIters">0</b>
      </p>
      <div class="timeBox">
        ⏱ Duration: <b id="winTime">—</b>
      </div>
      <p class="small" style="margin-top: 12px; opacity:.9;">
        Tip: if you don’t hear sound, press Start (some browsers block audio until a user interaction).
      </p>
      <div style="display:flex; gap:12px; justify-content:center; margin-top: 6px; flex-wrap:wrap;">
        <button class="btn primary" id="againBtn">🔄 Run again</button>
        <button class="btn" id="closeBtn">Close</button>
      </div>
    </div>
  </div>

  <script>
    const startBtn = document.getElementById('startBtn');
    const stopBtn  = document.getElementById('stopBtn');
    const resetBtn = document.getElementById('resetBtn');

    const rateInput = document.getElementById('rateInput');
    const rateBadge = document.getElementById('rateBadge');
    const msBadge = document.getElementById('msBadge');

    const numbersEl = document.getElementById('numbers');
    const itersEl = document.getElementById('iters');
    const latEl = document.getElementById('lat');

    const timerEl = document.getElementById('timer');
    const timerLongEl = document.getElementById('timerLong');

    const dotEl = document.getElementById('dot');
    const statusTextEl = document.getElementById('statusText');
    const bgHintEl = document.getElementById('bgHint');

    const overlay = document.getElementById('overlay');
    const winNumbersEl = document.getElementById('winNumbers');
    const winItersEl = document.getElementById('winIters');
    const winTimeEl = document.getElementById('winTime');
    const againBtn = document.getElementById('againBtn');
    const closeBtn = document.getElementById('closeBtn');
    const confetti = document.getElementById('confetti');

    let timer = null;
    let running = false;

    let tickMs = 100;

    let inFlight = false;

    let baseElapsedMs = 0;
    let baseClientNowMs = 0;
    let serverRunning = false;

    let uiTimerInterval = null;

    // Background catch-up
    let lastVisibleAt = Date.now();

    function pad2(n){ return String(n).padStart(2,'0'); }

    function formatHMS(totalMs){
      const totalSec = Math.floor(totalMs / 1000);
      const s = totalSec % 60;
      const totalMin = Math.floor(totalSec / 60);
      const m = totalMin % 60;
      const totalHr = Math.floor(totalMin / 60);
      const h = totalHr % 24;
      const d = Math.floor(totalHr / 24);
      const hms = `${pad2(h)}:${pad2(m)}:${pad2(s)}`;
      return d > 0 ? `${d}d ${hms}` : hms;
    }

    function calendarDiffFromDuration(ms){
      const start = new Date(Date.UTC(2000,0,1,0,0,0));
      const end = new Date(start.getTime() + ms);

      let y = end.getUTCFullYear() - start.getUTCFullYear();
      let mo = end.getUTCMonth() - start.getUTCMonth();
      let d = end.getUTCDate() - start.getUTCDate();
      let h = end.getUTCHours() - start.getUTCHours();
      let mi = end.getUTCMinutes() - start.getUTCMinutes();
      let s = end.getUTCSeconds() - start.getUTCSeconds();

      if (s < 0) { s += 60; mi -= 1; }
      if (mi < 0) { mi += 60; h -= 1; }
      if (h < 0) { h += 24; d -= 1; }
      if (d < 0) {
        const prevMonth = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), 0));
        d += prevMonth.getUTCDate();
        mo -= 1;
      }
      if (mo < 0) { mo += 12; y -= 1; }
      return { years:y, months:mo, days:d, hours:h, minutes:mi, seconds:s };
    }

    function formatLong(ms){
      const {years, months, days, hours, minutes, seconds} = calendarDiffFromDuration(ms);
      const parts = [];
      if (years) parts.push(`${years} year${years===1?'':'s'}`);
      if (months) parts.push(`${months} month${months===1?'':'s'}`);
      if (days) parts.push(`${days} day${days===1?'':'s'}`);
      if (hours) parts.push(`${hours} hour${hours===1?'':'s'}`);
      if (minutes) parts.push(`${minutes} minute${minutes===1?'':'s'}`);
      if (seconds || parts.length===0) parts.push(`${seconds} second${seconds===1?'':'s'}`);
      return parts.join(', ');
    }

    function getLiveElapsedMs(){
      if (serverRunning) {
        return baseElapsedMs + Math.max(0, (Date.now() - baseClientNowMs));
      }
      return baseElapsedMs;
    }

    function renderTimer(){
      const ms = getLiveElapsedMs();
      timerEl.textContent = formatHMS(ms);
      timerLongEl.textContent = `(${formatLong(ms)})`;
    }

    function startUiTimer(){
      if (uiTimerInterval) return;
      uiTimerInterval = setInterval(renderTimer, 250);
    }
    function stopUiTimer(){
      if (uiTimerInterval) {
        clearInterval(uiTimerInterval);
        uiTimerInterval = null;
      }
    }

    // Random pill colors (full RGB range)
    function randomRgbColor(){
      const r = Math.floor(Math.random() * 256);
      const g = Math.floor(Math.random() * 256);
      const b = Math.floor(Math.random() * 256);
      return `rgb(${r}, ${g}, ${b})`;
    }
    function recolorPills(){
      const pills = numbersEl.querySelectorAll('.pill');
      pills.forEach(p => {
        p.style.color = randomRgbColor();
      });
    }

    function playSuccessSound() {
      try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        const ctx = new AudioContext();

        const now = ctx.currentTime;
        const master = ctx.createGain();
        master.gain.setValueAtTime(0.0001, now);
        master.gain.exponentialRampToValueAtTime(0.22, now + 0.02);
        master.gain.exponentialRampToValueAtTime(0.0001, now + 0.80);
        master.connect(ctx.destination);

        const freqs = [523.25, 659.25, 783.99];
        freqs.forEach((f, idx) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.type = 'sine';
          osc.frequency.setValueAtTime(f, now + idx * 0.02);
          gain.gain.setValueAtTime(0.0, now);
          gain.gain.linearRampToValueAtTime(0.35, now + 0.02 + idx*0.01);
          gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.60);
          osc.connect(gain);
          gain.connect(master);
          osc.start(now + idx * 0.02);
          osc.stop(now + 0.70);
        });

        setTimeout(() => ctx.close().catch(()=>{}), 900);
      } catch (e) {}
    }

    function launchConfetti() {
      confetti.innerHTML = '';
      confetti.classList.add('on');

      const pieces = 28;
      for (let i = 0; i < pieces; i++) {
        const p = document.createElement('i');
        const left = Math.random() * 100;
        const delay = Math.random() * 120;
        const hue = Math.floor(Math.random() * 360);
        const width = 8 + Math.random() * 8;
        const height = 12 + Math.random() * 14;

        p.style.left = left + '%';
        p.style.animationDelay = delay + 'ms';
        p.style.background = `hsl(${hue} 90% 60%)`;
        p.style.width = width + 'px';
        p.style.height = height + 'px';
        p.style.transform = `rotate(${Math.random()*90}deg)`;

        confetti.appendChild(p);
      }

      setTimeout(() => {
        confetti.classList.remove('on');
        confetti.innerHTML = '';
      }, 1100);
    }

    function setRunningUI(isRunning) {
      running = isRunning;
      if (isRunning) {
        dotEl.classList.add('running');
        dotEl.classList.remove('stopped');
        statusTextEl.textContent = 'Running…';
        startBtn.disabled = true;
        stopBtn.disabled = false;
      } else {
        dotEl.classList.remove('running');
        dotEl.classList.add('stopped');
        statusTextEl.textContent = 'Stopped';
        startBtn.disabled = false;
        stopBtn.disabled = true;
      }
    }

    function updateNumbers(nums) {
      const pills = numbersEl.querySelectorAll('.pill');
      pills.forEach((p, idx) => p.textContent = (nums?.[idx] ?? '–'));
      recolorPills(); // change colors every refresh/update
    }

    function updateRateBadges(ratePerSec) {
      rateBadge.textContent = String(ratePerSec);
      msBadge.textContent = String(tickMs);
    }

    async function api(action, params = {}) {
      const usp = new URLSearchParams({ api: '1', action, ...params });
      const res = await fetch('?' + usp.toString(), { cache: 'no-store' });
      return res.json();
    }

    function applyRateFromInput() {
      let r = parseInt(rateInput.value, 10);
      if (Number.isNaN(r)) r = 10;

      r = Math.max(1, Math.min(60, r));
      rateInput.value = r;

      tickMs = Math.max(1, Math.round(1000 / r));
      updateRateBadges(r);

      api('set_rate', { rate: String(r) }).catch(()=>{});

      if (running) {
        if (timer) clearInterval(timer);
        timer = setInterval(loopStep, tickMs);
      }
    }

    function syncFromServerStatus(data){
      itersEl.textContent = data.iterations;
      baseElapsedMs = data.elapsed_ms;
      baseClientNowMs = Date.now();
      serverRunning = !!data.running;

      if (typeof data.rate_per_sec === 'number') {
        if (parseInt(rateInput.value, 10) !== data.rate_per_sec) {
          rateInput.value = data.rate_per_sec;
        }
        applyRateFromInput();
      } else {
        renderTimer();
      }

      setRunningUI(serverRunning);
      renderTimer();
      recolorPills();

      if (serverRunning) startUiTimer();
      else stopUiTimer();
    }

    async function loadStatus(){
      try {
        const data = await api('status');
        if (data && data.ok) syncFromServerStatus(data);
      } catch(e) {}
    }

    async function tickOnce() {
      const t0 = performance.now();
      const data = await api('tick');
      const t1 = performance.now();

      latEl.textContent = Math.round(t1 - t0);
      if (!data.ok) return;

      updateNumbers(data.numbers);
      itersEl.textContent = data.iterations;

      baseElapsedMs = data.elapsed_ms;
      baseClientNowMs = Date.now();
      serverRunning = !!data.running;
      renderTimer();

      if (data.win) {
        await stopLoopClientOnly();
        serverRunning = false;
        stopUiTimer();

        const pills = numbersEl.querySelectorAll('.pill');
        pills.forEach(p => p.classList.add('pulse'));
        setTimeout(() => pills.forEach(p => p.classList.remove('pulse')), 900);

        playSuccessSound();
        launchConfetti();

        winNumbersEl.textContent = data.numbers.join(' - ');
        winItersEl.textContent = data.iterations;
        winTimeEl.textContent = `${formatHMS(data.elapsed_ms)} — ${formatLong(data.elapsed_ms)}`;
        overlay.classList.add('show');
      }
    }

    async function loopStep() {
      if (inFlight) return;
      inFlight = true;
      try {
        await tickOnce();
      } finally {
        inFlight = false;
      }
    }

    async function startLoop() {
      if (timer) return;
      overlay.classList.remove('show');

      try {
        const s = await api('start');
        if (s && s.ok) syncFromServerStatus(s);
      } catch(e) {
        serverRunning = true;
        setRunningUI(true);
      }

      startUiTimer();
      loopStep();
      timer = setInterval(loopStep, tickMs);
    }

    async function stopLoopClientOnly() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      setRunningUI(false);
    }

    async function stopLoop() {
      await stopLoopClientOnly();
      try {
        const s = await api('stop');
        if (s && s.ok) syncFromServerStatus(s);
      } catch(e) {
        serverRunning = false;
        stopUiTimer();
      }
    }

    async function resetAll() {
      if (timer) { clearInterval(timer); timer = null; }
      setRunningUI(false);
      overlay.classList.remove('show');
      updateNumbers([null, null, null, null, null]);
      itersEl.textContent = '0';
      latEl.textContent = '—';

      try {
        const r = await api('reset');
        if (r && r.ok) syncFromServerStatus(r);
      } catch(e) {
        baseElapsedMs = 0;
        baseClientNowMs = Date.now();
        serverRunning = false;
        renderTimer();
        stopUiTimer();
      }
      bgHintEl.textContent = '';
    }

    async function catchUpIfNeeded() {
      if (!serverRunning) return;

      const secondsAway = Math.max(0, (Date.now() - lastVisibleAt) / 1000);
      const rate = parseInt(rateInput.value, 10) || 10;

      let ticks = Math.floor(secondsAway * rate);
      if (ticks <= 0) return;

      const MAX_PER_REQ = 15000;
      bgHintEl.textContent = `Catching up ${ticks} tick(s)…`;

      while (ticks > 0 && serverRunning) {
        const batch = Math.min(MAX_PER_REQ, ticks);
        try {
          const data = await api('catchup', { ticks: String(batch) });
          if (!data.ok) break;

          if (data.numbers) updateNumbers(data.numbers);
          itersEl.textContent = data.iterations;

          baseElapsedMs = data.elapsed_ms;
          baseClientNowMs = Date.now();
          serverRunning = !!data.running;
          renderTimer();

          if (data.win) {
            await stopLoopClientOnly();
            serverRunning = false;
            stopUiTimer();

            playSuccessSound();
            launchConfetti();

            winNumbersEl.textContent = (data.numbers || []).join(' - ');
            winItersEl.textContent = data.iterations;
            winTimeEl.textContent = `${formatHMS(data.elapsed_ms)} — ${formatLong(data.elapsed_ms)}`;
            overlay.classList.add('show');
            break;
          }
        } catch(e) {
          break;
        }
        ticks -= batch;
      }

      bgHintEl.textContent = '';
    }

    document.addEventListener('visibilitychange', async () => {
      if (document.visibilityState === 'hidden') {
        lastVisibleAt = Date.now();
      } else {
        await catchUpIfNeeded();
      }
    });

    // Buttons
    startBtn.addEventListener('click', startLoop);
    stopBtn.addEventListener('click', () => stopLoop());
    resetBtn.addEventListener('click', resetAll);

    // Rate input live
    rateInput.addEventListener('input', applyRateFromInput);

    againBtn.addEventListener('click', async () => {
      await resetAll();
      await startLoop();
    });
    closeBtn.addEventListener('click', () => overlay.classList.remove('show'));
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('show');
    });

    // init
    applyRateFromInput();
    recolorPills();
    loadStatus();
  </script>
</body>
</html>
