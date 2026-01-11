
/* Random Match — Live Loop (static HTML/CSS/JS build)
   - No PHP / no server required.
   - State is persisted in localStorage (plus cookies for UI prefs, like the original).
*/
(() => {
  'use strict';

  /* ===================== Cookie helpers (30 days) ===================== */
  const COOKIE_DAYS = 30;
  function setCookie(name, value, days = COOKIE_DAYS) {
    const d = new Date();
    d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}; expires=${d.toUTCString()}; path=/; SameSite=Lax`;
  }
  function getCookie(name) {
    const key = encodeURIComponent(name) + '=';
    return document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(key))?.slice(key.length) ?? null;
  }

  /* ===================== Elements ===================== */
  const topList = document.getElementById('topList');
  const toneR = document.getElementById('toneR');
  const toneG = document.getElementById('toneG');
  const toneB = document.getElementById('toneB');
  const toneRVal = document.getElementById('toneRVal');
  const toneGVal = document.getElementById('toneGVal');
  const toneBVal = document.getElementById('toneBVal');

  const configCard = document.getElementById('configCard');
  const cfgCount = document.getElementById('cfgCount');
  const cfgMin = document.getElementById('cfgMin');
  const cfgMax = document.getElementById('cfgMax');
  const cfgRate = document.getElementById('cfgRate');
  const startOnlyBtn = document.getElementById('startOnlyBtn');

  const numbersEl = document.getElementById('numbers');
  const metaEl = document.getElementById('meta');
  const statusEl = document.getElementById('status');
  const footerEl = document.getElementById('footer');

  const triesEl = document.getElementById('tries');
  const latEl = document.getElementById('lat');
  const timerEl = document.getElementById('timer');

  const dotEl = document.getElementById('dot');
  const statusTextEl = document.getElementById('statusText');
  const bgHintEl = document.getElementById('bgHint');

  const rateInput = document.getElementById('rateInput');
  const rateBadge = document.getElementById('rateBadge');
  const rangeBadge = document.getElementById('rangeBadge');
  const countBadge = document.getElementById('countBadge');

  const startBtn = document.getElementById('startBtn');
  const stopBtn = document.getElementById('stopBtn');
  const resetBtn = document.getElementById('resetBtn');

  const confetti = document.getElementById('confetti');
  const toast = document.getElementById('toast');
  const toastText = document.getElementById('toastText');
  const resetTopOnStart = document.getElementById('resetTopOnStart');

  /* ===================== Utilities ===================== */
  function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

  // Format integers with dot thousand separators (e.g., 102302130 -> 102.302.130)
  function formatIntDots(x) {
    const n = Math.floor(Number(x) || 0);
    try { return new Intl.NumberFormat('de-DE').format(n); } catch (e) {}
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function formatTimerStrict(ms) {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const seconds = totalSeconds % 60;
    const totalMinutes = Math.floor(totalSeconds / 60);
    const minutes = totalMinutes % 60;
    const totalHours = Math.floor(totalMinutes / 60);
    const hours = totalHours % 24;
    const totalDays = Math.floor(totalHours / 24);
    const years = Math.floor(totalDays / 365);
    const days = totalDays % 365;
    return `${years} Years, ${days} Days, ${hours} Hours, ${minutes} Minutes, ${seconds} Seconds.`;
  }

  /* ===================== Deterministic “random” helpers (visuals) ===================== */
  function xorshift32(x) {
    x |= 0;
    x ^= x << 13; x |= 0;
    x ^= x >>> 17; x |= 0;
    x ^= x << 5; x |= 0;
    return x | 0;
  }
  function rand01(seed) {
    const x = xorshift32(seed);
    return ((x >>> 0) / 4294967295);
  }
  function pick(seed, arr) {
    return arr[Math.floor(rand01(seed) * arr.length) % arr.length];
  }

  function hslToRgb(h, s, l) {
    h = ((h % 360) + 360) % 360;
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    let r = 0, g = 0, b = 0;
    if (h < 60) { r = c; g = x; b = 0; }
    else if (h < 120) { r = x; g = c; b = 0; }
    else if (h < 180) { r = 0; g = c; b = x; }
    else if (h < 240) { r = 0; g = x; b = c; }
    else if (h < 300) { r = x; g = 0; b = c; }
    else { r = c; g = 0; b = x; }
    return [
      Math.round((r + m) * 255),
      Math.round((g + m) * 255),
      Math.round((b + m) * 255),
    ];
  }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function lerpRgb(a, b, t) {
    return [
      Math.round(lerp(a[0], b[0], t)),
      Math.round(lerp(a[1], b[1], t)),
      Math.round(lerp(a[2], b[2], t)),
    ];
  }
  function rgbToCss(rgb) { return `rgb(${rgb[0]} ${rgb[1]} ${rgb[2]})`; }
  function rainbowRgb(seed) {
    const h = Math.floor(rand01(seed) * 360);
    return hslToRgb(h, 0.92, 0.64);
  }

  /* ===================== Tone sliders ===================== */
  let tone = { r: 50, g: 50, b: 50 };
  const TONE_R_COOKIE = 'rm_loop_tone_r';
  const TONE_G_COOKIE = 'rm_loop_tone_g';
  const TONE_B_COOKIE = 'rm_loop_tone_b';

  function applyToneLayer(baseRgb) {
    const w = (tone.r + tone.g + tone.b) / 300;
    const tint = [
      Math.round((tone.r / 100) * 255),
      Math.round((tone.g / 100) * 255),
      Math.round((tone.b / 100) * 255),
    ];

    let rgb = lerpRgb(baseRgb, tint, 0.22);
    if (w <= 0.5) rgb = lerpRgb([0, 0, 0], rgb, w / 0.5);
    else rgb = lerpRgb(rgb, [255, 255, 255], (w - 0.5) / 0.5);
    return rgb;
  }

  function setToneValues(next) {
    tone = {
      r: clamp(parseInt(String(next.r), 10) || 0, 0, 100),
      g: clamp(parseInt(String(next.g), 10) || 0, 0, 100),
      b: clamp(parseInt(String(next.b), 10) || 0, 0, 100),
    };

    if (toneR) toneR.value = String(tone.r);
    if (toneG) toneG.value = String(tone.g);
    if (toneB) toneB.value = String(tone.b);

    if (toneRVal) toneRVal.textContent = `${tone.r}%`;
    if (toneGVal) toneGVal.textContent = `${tone.g}%`;
    if (toneBVal) toneBVal.textContent = `${tone.b}%`;

    setCookie(TONE_R_COOKIE, String(tone.r));
    setCookie(TONE_G_COOKIE, String(tone.g));
    setCookie(TONE_B_COOKIE, String(tone.b));

    applyRainbowStreaming();
  }
  function syncToneFromSliders() {
    setToneValues({
      r: toneR ? toneR.value : 50,
      g: toneG ? toneG.value : 50,
      b: toneB ? toneB.value : 50,
    });
  }

  const FONT_FAMILIES = [
    'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas',
    '"Liberation Mono"', '"Courier New"', '"DejaVu Sans Mono"',
    '"Ubuntu Mono"', '"Fira Code"', '"JetBrains Mono"'
  ];

  /* ===================== Pill box / pulse toggle ===================== */
  const pillBoxesPulse = document.getElementById('pillBoxesPulse');
  const PILL_BOX_COOKIE = 'rm_pillBoxesPulse';

  function loadPillBoxesCheckbox() {
    const v = getCookie(PILL_BOX_COOKIE);
    if (v !== null && pillBoxesPulse) pillBoxesPulse.checked = (v === '1' || v === 'true');
  }
  function persistPillBoxesCheckbox() {
    if (!pillBoxesPulse) return;
    setCookie(PILL_BOX_COOKIE, pillBoxesPulse.checked ? '1' : '0');
    applyPillBoxesState();
  }
  function applyPillBoxesState() {
    const on = !!pillBoxesPulse?.checked;
    document.querySelectorAll('.pill').forEach((p, idx) => {
      p.classList.toggle('noBox', !on);
      p.classList.toggle('pulse', on);
      p.style.animationDelay = on ? `${(idx * 0.37) % 2.4}s` : '0s';
    });
  }

  let rainbowTick = 0;
  function applyRainbowStreaming() {
    rainbowTick++;

    const dyn = document.querySelectorAll('.dyn-number');
    dyn.forEach((el, idx) => {
      if (el.classList.contains('pillNum')) return;
      if (el.closest && el.closest('.pill')) return;

      const base = rainbowRgb((rainbowTick * 9973) + idx * 131);
      const out = applyToneLayer(base);
      el.style.color = rgbToCss(out);

      const d = pick((rainbowTick * 733) + idx * 17, [-1, 0, 1]);
      el.style.fontSize = '';
      if (d !== 0) {
        const cs = getComputedStyle(el);
        const fs = parseFloat(cs.fontSize || '14');
        el.style.fontSize = `${Math.max(10, fs + d)}px`;
      }
    });

    const pillNums = document.querySelectorAll('.pillNum');
    pillNums.forEach((el, idx) => {
      const seedA = (rainbowTick * 941) + idx * 101;
      const seedB = seedA + 777;

      const c1 = applyToneLayer(rainbowRgb(seedA));
      const c2 = applyToneLayer(rainbowRgb(seedB));

      el.style.backgroundImage = `linear-gradient(90deg, ${rgbToCss(c1)}, ${rgbToCss(c2)})`;
      el.style.fontFamily = pick(seedA, FONT_FAMILIES);

      const delta = pick(seedB, [-1, 0, 1]);
      const baseSize = 80;
      el.style.fontSize = `${baseSize + (delta * 3)}px`;
    });

    applyPillBoxesState();
  }

  /* ===================== Game logic (client “server”) ===================== */
  const STORAGE_KEY = 'rm_state_v4';

  function nowMs() { return Date.now(); }

  function cfgNormalize(cfg) {
    const count = clamp(parseInt(cfg?.count ?? 9, 10) || 9, 1, 50);

    let min = parseInt(cfg?.min ?? 0, 10); if (Number.isNaN(min)) min = 0;
    let max = parseInt(cfg?.max ?? 9, 10); if (Number.isNaN(max)) max = 9;
    if (min > max) { const t = min; min = max; max = t; }

    min = clamp(min, -999999, 999999);
    max = clamp(max, -999999, 999999);

    const rate = clamp(parseInt(cfg?.rate_per_sec ?? 10, 10) || 10, 1, 60);
    return { count, min, max, rate_per_sec: rate };
  }

  function defaultState() {
    return {
      cfg: cfgNormalize({ count: 9, min: 0, max: 9, rate_per_sec: 10 }),
      tries: 0,
      elapsed_ms: 0,     // accumulated while stopped
      start_ms: null,    // timestamp when running started
      running: false,
      last_numbers: null,
      top3: [],
      won: false,
      win_numbers: null
    };
  }

  function loadState() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return defaultState();
      const s = JSON.parse(raw);
      const st = defaultState();
      st.cfg = cfgNormalize(s.cfg ?? st.cfg);
      st.tries = Math.max(0, parseInt(s.tries ?? 0, 10) || 0);
      st.elapsed_ms = Math.max(0, parseInt(s.elapsed_ms ?? 0, 10) || 0);
      st.start_ms = (typeof s.start_ms === 'number' && s.start_ms > 0) ? s.start_ms : null;
      st.running = !!s.running;
      st.last_numbers = Array.isArray(s.last_numbers) ? s.last_numbers : null;
      st.top3 = Array.isArray(s.top3) ? s.top3 : [];
      st.won = !!s.won;
      st.win_numbers = Array.isArray(s.win_numbers) ? s.win_numbers : null;

      // If start_ms missing but running=true, fix it
      if (st.running && !st.start_ms) st.start_ms = nowMs();

      return st;
    } catch (e) {
      return defaultState();
    }
  }

  function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  function elapsedMs() {
    let e = state.elapsed_ms | 0;
    if (state.running && typeof state.start_ms === 'number' && state.start_ms > 0) {
      e += Math.max(0, nowMs() - state.start_ms);
    }
    return e;
  }

  function startTimer() {
    if (state.running) return;
    state.running = true;
    state.start_ms = nowMs();
  }

  function stopTimer() {
    if (state.running && typeof state.start_ms === 'number' && state.start_ms > 0) {
      const delta = Math.max(0, nowMs() - state.start_ms);
      state.elapsed_ms = (state.elapsed_ms | 0) + delta;
    }
    state.running = false;
    state.start_ms = null;
  }

  function resetAllKeepCfg() {
    stopTimer();
    state.tries = 0;
    state.elapsed_ms = 0;
    state.start_ms = null;
    state.last_numbers = null;
    state.top3 = [];
    state.won = false;
    state.win_numbers = null;
  }

  function randIntInclusive(min, max) {
    // inclusive
    return Math.floor(Math.random() * (max - min + 1)) + min;
  }

  function computeBestStreak(numbers) {
    const freq = new Map();
    for (const n of numbers) freq.set(n, (freq.get(n) || 0) + 1);

    let bestCount = -1;
    let bestValue = null;

    for (const [val, cnt] of freq.entries()) {
      if (cnt > bestCount) { bestCount = cnt; bestValue = val; }
      else if (cnt === bestCount && bestValue !== null && val > bestValue) { bestValue = val; }
    }
    return { count: bestCount, value: bestValue ?? 0 };
  }

  function top3Update(candidate, combo, n) {
    if (candidate.count >= n) return; // exclude perfect matches

    // If already have same (count,value), update combo
    for (let i = 0; i < state.top3.length; i++) {
      const e = state.top3[i];
      if ((e.count | 0) === (candidate.count | 0) && (e.value | 0) === (candidate.value | 0)) {
        state.top3[i] = { ...e, combo };
        return;
      }
    }

    state.top3.push({ count: candidate.count | 0, value: candidate.value | 0, combo });

    state.top3.sort((a, b) => {
      const ac = a.count | 0, bc = b.count | 0;
      if (ac !== bc) return bc - ac;
      return (b.value | 0) - (a.value | 0);
    });

    state.top3 = state.top3.slice(0, 30);
  }

  function doOneTick() {
    const cfg = state.cfg;
    const n = cfg.count;
    const min = cfg.min;
    const max = cfg.max;

    state.tries = (state.tries | 0) + 1;

    const numbers = [];
    for (let i = 0; i < n; i++) numbers.push(randIntInclusive(min, max));

    state.last_numbers = numbers;

    const best = computeBestStreak(numbers);
    top3Update(best, numbers, n);

    const win = (best.count === n);
    return { numbers, best, win };
  }

  function apiReply(extra = {}) {
    return {
      ok: true,
      cfg: state.cfg,
      running: !!state.running,
      won: !!state.won,
      tries: state.tries | 0,
      elapsed_ms: elapsedMs(),
      last_numbers: state.last_numbers,
      top3: state.top3,
      win_numbers: state.win_numbers,
      ...extra
    };
  }

  async function api(action, params = {}) {
    // Local, async-compatible API (keeps the rest of the UI code structure)
    // Small micro-delay to mimic async fetch and keep latency field meaningful.
    await Promise.resolve();

    if (action === 'status') {
      saveState();
      return apiReply();
    }

    if (action === 'new_game') {
      const cfg = cfgNormalize({
        count: params.count,
        min: params.min,
        max: params.max,
        rate_per_sec: params.rate,
      });
      state.cfg = cfg;

      state.tries = 0;
      state.elapsed_ms = 0;
      state.start_ms = nowMs();
      state.running = true;

      state.last_numbers = null;
      state.top3 = [];
      state.won = false;
      state.win_numbers = null;

      saveState();
      return apiReply({ started: true, mode: 'new_game' });
    }

    if (action === 'set_rate') {
      const rate = clamp(parseInt(params.rate ?? state.cfg.rate_per_sec, 10) || state.cfg.rate_per_sec, 1, 60);
      state.cfg.rate_per_sec = rate;
      saveState();
      return apiReply({ rate_updated: true });
    }

    if (action === 'start') {
      const resetTop3 = (String(params.reset_top3 ?? '1') === '1');
      if (state.won) {
        state.top3 = [];
        state.won = false;
        state.win_numbers = null;
      } else if (!state.running && resetTop3) {
        state.top3 = [];
      }

      startTimer();
      saveState();
      return apiReply({ started: true, mode: 'resume', reset_top3_applied: (state.won ? true : resetTop3) });
    }

    if (action === 'stop') {
      stopTimer();
      saveState();
      return apiReply({ stopped: true });
    }

    if (action === 'reset') {
      resetAllKeepCfg();
      saveState();
      return apiReply({ reset: true });
    }

    if (action === 'tick') {
      if (state.won || !state.running) {
        saveState();
        return apiReply({ tick: false });
      }

      const { numbers, best, win } = doOneTick();

      if (win) {
        state.won = true;
        state.win_numbers = numbers;
        stopTimer();
        saveState();
        return apiReply({ tick: true, numbers, best, win: true });
      }

      saveState();
      return apiReply({ tick: true, numbers, best, win: false });
    }

    if (action === 'catchup') {
      if (state.won || !state.running) {
        saveState();
        return apiReply({ catchup: false });
      }

      let ticks = clamp(parseInt(params.ticks ?? 0, 10) || 0, 0, 20000);

      let numbers = null;
      let best = null;
      let win = false;
      let processed = 0;

      for (let i = 0; i < ticks; i++) {
        const out = doOneTick();
        numbers = out.numbers;
        best = out.best;
        win = out.win;
        processed++;
        if (win) {
          state.won = true;
          state.win_numbers = numbers;
          stopTimer();
          break;
        }
      }

      saveState();
      return apiReply({ catchup: true, processed, numbers, best, win });
    }

    saveState();
    return apiReply({ error: 'Unknown action' });
  }

  /* ===================== UI helpers ===================== */
  function ensurePills(count) {
    const existing = numbersEl.querySelectorAll('.pill').length;
    if (existing === count) return;
    numbersEl.innerHTML = '';
    for (let i = 0; i < count; i++) {
      const d = document.createElement('div');
      d.className = 'pill';
      const s = document.createElement('span');
      s.className = 'pillNum dyn-number';
      s.textContent = '–';
      d.appendChild(s);
      numbersEl.appendChild(d);
    }
    applyRainbowStreaming();
  }

  function updateNumbers(nums) {
    const pills = numbersEl.querySelectorAll('.pillNum');
    pills.forEach((p, i) => p.textContent = (nums && typeof nums[i] !== 'undefined') ? nums[i] : '–');
    applyRainbowStreaming();
  }

  function updateBadges(cfg) {
    if (!rateBadge || !rangeBadge || !countBadge) return;
    rateBadge.textContent = String(cfg.rate_per_sec);
    rangeBadge.textContent = `From ${cfg.min} to ${cfg.max}`;
    countBadge.textContent = String(cfg.count);
  }

  function setStatusDot(mode) {
    dotEl.classList.remove('running', 'stopped', 'won');
    if (mode === 'running') dotEl.classList.add('running');
    else if (mode === 'won') dotEl.classList.add('won');
    else dotEl.classList.add('stopped');
  }

  let serverRunning = false;
  let serverWon = false;

  // Timer sync
  let baseElapsedMs = 0;
  let baseClientNowMs = 0;

  function getLiveElapsedMs() {
    if (serverRunning) return baseElapsedMs + Math.max(0, (Date.now() - baseClientNowMs));
    return baseElapsedMs;
  }

  function setRunningUI(isRunning, isWon) {
    serverRunning = !!isRunning;
    serverWon = !!isWon;

    if (serverWon) {
      setStatusDot('won');
      statusTextEl.textContent = 'Paused (perfect match)';
      startBtn.disabled = false;
      stopBtn.disabled = true;
      return;
    }

    if (serverRunning) {
      setStatusDot('running');
      statusTextEl.textContent = 'Running…';
      startBtn.disabled = true;
      stopBtn.disabled = false;
    } else {
      setStatusDot('stopped');
      statusTextEl.textContent = 'Stopped';
      startBtn.disabled = false;
      stopBtn.disabled = true;
    }
  }

  let tickMs = 100;
  function applyRate(ratePerSec) {
    const r = clamp(parseInt(ratePerSec, 10) || 10, 1, 60);
    tickMs = Math.max(1, Math.round(1000 / r));
    rateInput.value = r;
  }

  function renderTop30(top3, cfg) {
    const n = cfg.count;
    const items = (top3 || []).slice(0, 30);
    const slots = [];
    for (let i = 0; i < 30; i++) slots.push(items[i] || null);

    function labelFor(idx) {
      if (idx === 0) return { emoji: '🥇', text: 'Best streak' };
      if (idx === 1) return { emoji: '🥈', text: 'Runner-up' };
      if (idx === 2) return { emoji: '🥉', text: 'Third place' };
      return { emoji: '#', text: `Rank ${idx + 1}` };
    }

    topList.innerHTML = '';
    slots.forEach((entry, idx) => {
      const li = document.createElement('li');
      li.className = 'topItem ' + `rank${idx + 1}`;

      const rank = document.createElement('div');
      rank.className = 'rank';
      rank.textContent = String(idx + 1);
      rank.classList.add('dyn-number');

      const text = document.createElement('div');
      text.className = 'topText';

      const titleLine = document.createElement('div');
      titleLine.className = 'topTitleLine';

      const label = document.createElement('div');
      label.className = 'topLabel';
      const l = labelFor(idx);
      label.innerHTML = `<span>${l.emoji}</span>${l.text}`;

      const main = document.createElement('div');
      main.className = 'topMain topScore';

      const combo = document.createElement('div');
      combo.className = 'topCombo';

      if (!entry) {
        main.textContent = '—';
        combo.textContent = `Waiting for near-misses (out of ${n})`;
      } else {
        main.innerHTML = `<span class="count dyn-number">${entry.count}</span><span class="x">×</span><span class="value dyn-number">${entry.value}</span>`;
        if (entry.combo && Array.isArray(entry.combo)) {
          const v = String(entry.value);
          combo.innerHTML = entry.combo.map(num => {
            const s = String(num);
            const cls = (s === v) ? 'num hit dyn-number' : 'num dyn-number';
            return `<span class="${cls}">${s}</span>`;
          }).join('&nbsp;&nbsp;');
        } else {
          combo.textContent = '';
        }
      }

      titleLine.appendChild(label);
      titleLine.appendChild(main);
      text.appendChild(titleLine);
      text.appendChild(combo);
      li.appendChild(rank);
      li.appendChild(text);
      topList.appendChild(li);
    });

    applyRainbowStreaming();
  }

  /* ===================== Confetti / toast ===================== */
  function launchConfetti(durationMs = 5000) {
    confetti.innerHTML = '';
    confetti.classList.add('on');

    const spawnBurst = () => {
      const pieces = 40;
      for (let i = 0; i < pieces; i++) {
        const p = document.createElement('i');
        p.style.left = (Math.random() * 100) + '%';
        p.style.animationDelay = (Math.random() * 120) + 'ms';
        p.style.background = `hsl(${Math.floor(Math.random() * 360)} 90% 60%)`;
        p.style.width = (8 + Math.random() * 8) + 'px';
        p.style.height = (12 + Math.random() * 14) + 'px';
        p.style.transform = `rotate(${Math.random() * 90}deg)`;
        confetti.appendChild(p);
      }
      setTimeout(() => { confetti.innerHTML = ''; }, 1200);
    };

    spawnBurst();
    const burstInterval = setInterval(spawnBurst, 900);

    setTimeout(() => {
      clearInterval(burstInterval);
      confetti.classList.remove('on');
      confetti.innerHTML = '';
    }, durationMs);
  }

  function showToast(msg, duration = 1800) {
    toastText.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), duration);
  }

  /* ===================== Loop control ===================== */
  let intervalId = null;
  let inFlight = false;

  function startClientInterval() {
    if (intervalId) return;
    loopStep();
    intervalId = setInterval(loopStep, tickMs);
  }
  function stopClientInterval() {
    if (intervalId) {
      clearInterval(intervalId);
      intervalId = null;
    }
  }

  async function loopStep() {
    if (inFlight) return;
    inFlight = true;
    try { await tickOnce(); }
    finally { inFlight = false; }
  }

  async function tickOnce() {
    const t0 = performance.now();
    const data = await api('tick');
    const t1 = performance.now();
    latEl.textContent = Math.round(t1 - t0);

    if (!data.ok) return;

    updateBadges(data.cfg);
    ensurePills(data.cfg.count);
    updateNumbers(data.numbers || data.last_numbers);

    triesEl.textContent = formatIntDots(data.tries);
    baseElapsedMs = data.elapsed_ms;
    baseClientNowMs = Date.now();
    renderTop30(data.top3, data.cfg);

    setRunningUI(data.running, data.won);
    timerEl.textContent = formatTimerStrict(getLiveElapsedMs());

    if (data.win === true || data.won === true) {
      stopClientInterval();
      stopUiTimer();
      baseElapsedMs = data.elapsed_ms;
      baseClientNowMs = Date.now();
      timerEl.textContent = formatTimerStrict(baseElapsedMs);
      launchConfetti(5000);
      showToast('Perfect match found — paused. Press Start to continue (Top 30 resets).', 3500);
    }
  }

  // Background catch-up (only for running + not won)
  let lastVisibleAt = Date.now();
  async function catchUpIfNeeded() {
    if (!serverRunning || serverWon) return;

    const secondsAway = Math.max(0, (Date.now() - lastVisibleAt) / 1000);
    const rate = clamp(parseInt(rateInput.value, 10) || 10, 1, 60);
    let ticks = Math.floor(secondsAway * rate);
    if (ticks <= 0) return;

    const MAX_PER_REQ = 15000;
    bgHintEl.textContent = `Catching up ${ticks} tick(s)…`;

    while (ticks > 0 && serverRunning && !serverWon) {
      const batch = Math.min(MAX_PER_REQ, ticks);
      try {
        const data = await api('catchup', { ticks: String(batch) });
        if (!data.ok || !data.catchup) break;

        updateBadges(data.cfg);
        ensurePills(data.cfg.count);
        updateNumbers(data.numbers || data.last_numbers);

        triesEl.textContent = formatIntDots(data.tries);
        baseElapsedMs = data.elapsed_ms;
        baseClientNowMs = Date.now();
        renderTop30(data.top3, data.cfg);

        setRunningUI(data.running, data.won);
        timerEl.textContent = formatTimerStrict(getLiveElapsedMs());

        if (data.win) {
          stopClientInterval();
          stopUiTimer();
          baseElapsedMs = data.elapsed_ms;
          timerEl.textContent = formatTimerStrict(baseElapsedMs);
          launchConfetti(5000);
          showToast('Perfect match found — paused. Press Start to continue (Top 30 resets).', 3500);
          break;
        }

      } catch (e) { break; }

      ticks -= batch;
    }

    bgHintEl.textContent = '';
  }

  document.addEventListener('visibilitychange', async () => {
    if (document.visibilityState === 'hidden') lastVisibleAt = Date.now();
    else await catchUpIfNeeded();
  });

  /* ===================== Setup cookies (matches original) ===================== */
  function loadCfgFromCookiesIntoInputs() {
    const c = getCookie('rm_count');
    const mn = getCookie('rm_min');
    const mx = getCookie('rm_max');
    const rt = getCookie('rm_rate');

    if (c !== null) cfgCount.value = c;
    if (mn !== null) cfgMin.value = mn;
    if (mx !== null) cfgMax.value = mx;
    if (rt !== null) cfgRate.value = rt;
  }

  function loadResetTopCheckbox() {
    const v = getCookie('rm_resetTopOnStart');
    if (v !== null) resetTopOnStart.checked = (v === '1' || v === 'true');
  }
  function persistResetTopCheckbox() {
    setCookie('rm_resetTopOnStart', resetTopOnStart.checked ? '1' : '0');
  }

  function persistInputsToCookies() {
    setCookie('rm_count', cfgCount.value);
    setCookie('rm_min', cfgMin.value);
    setCookie('rm_max', cfgMax.value);
    setCookie('rm_rate', cfgRate.value);
  }

  ['input', 'change'].forEach(evt => {
    cfgCount.addEventListener(evt, persistInputsToCookies);
    cfgMin.addEventListener(evt, persistInputsToCookies);
    cfgMax.addEventListener(evt, persistInputsToCookies);
    cfgRate.addEventListener(evt, persistInputsToCookies);
  });

  resetTopOnStart.addEventListener('change', persistResetTopCheckbox);

  /* ===================== Setup / controls ===================== */
  // UI timer ticker (display only)
  let uiTimerInterval = null;
  function startUiTimer() {
    if (uiTimerInterval) return;
    uiTimerInterval = setInterval(() => {
      timerEl.textContent = formatTimerStrict(getLiveElapsedMs());
    }, 250);
  }
  function stopUiTimer() {
    if (uiTimerInterval) {
      clearInterval(uiTimerInterval);
      uiTimerInterval = null;
    }
  }

  // Start from menu (NEW GAME)
  startOnlyBtn.addEventListener('click', async () => {
    persistInputsToCookies();

    const count = clamp(parseInt(cfgCount.value, 10) || 9, 1, 50);
    let min = parseInt(cfgMin.value, 10); if (Number.isNaN(min)) min = 0;
    let max = parseInt(cfgMax.value, 10); if (Number.isNaN(max)) max = 9;
    const rate = clamp(parseInt(cfgRate.value, 10) || 10, 1, 60);

    const data = await api('new_game', {
      count: String(count), min: String(min), max: String(max), rate: String(rate)
    });

    if (!data.ok) return;

    configCard.style.display = 'none';
    numbersEl.style.display = 'flex';
    metaEl.style.display = 'flex';
    statusEl.style.display = 'flex';
    footerEl.style.display = 'flex';

    updateBadges(data.cfg);
    ensurePills(data.cfg.count);
    updateNumbers(data.last_numbers);

    triesEl.textContent = formatIntDots(data.tries);
    baseElapsedMs = data.elapsed_ms;
    baseClientNowMs = Date.now();
    renderTop30(data.top3, data.cfg);
    applyRainbowStreaming();

    setRunningUI(data.running, data.won);
    applyRate(data.cfg.rate_per_sec);
    rateInput.value = data.cfg.rate_per_sec;

    startUiTimer();
    startClientInterval();
  });

  // Start (RESUME)
  startBtn.addEventListener('click', async () => {
    const s = await api('start', { reset_top3: resetTopOnStart.checked ? '1' : '0' });
    if (!s.ok) return;

    updateBadges(s.cfg);
    renderTop30(s.top3, s.cfg);
    applyRainbowStreaming();

    baseElapsedMs = s.elapsed_ms;
    baseClientNowMs = Date.now();
    setRunningUI(s.running, s.won);

    applyRate(s.cfg.rate_per_sec);
    rateInput.value = s.cfg.rate_per_sec;

    startUiTimer();
    startClientInterval();
  });

  stopBtn.addEventListener('click', async () => {
    stopClientInterval();
    stopUiTimer();

    const s = await api('stop');
    if (!s.ok) return;

    baseElapsedMs = s.elapsed_ms;
    baseClientNowMs = Date.now();
    setRunningUI(s.running, s.won);
    timerEl.textContent = formatTimerStrict(baseElapsedMs);
  });

  resetBtn.addEventListener('click', async () => {
    stopClientInterval();
    stopUiTimer();

    const r = await api('reset');
    if (!r.ok) return;

    // Back to setup (cookies remain)
    configCard.style.display = 'block';
    numbersEl.style.display = 'none';
    metaEl.style.display = 'none';
    statusEl.style.display = 'none';
    footerEl.style.display = 'none';

    loadCfgFromCookiesIntoInputs();
    loadResetTopCheckbox();
    loadPillBoxesCheckbox();
    applyPillBoxesState();

    renderTop30(r.top3, r.cfg);
    applyRainbowStreaming();
  });

  // Live rate change
  rateInput.addEventListener('input', async () => {
    const rate = clamp(parseInt(rateInput.value, 10) || 10, 1, 60);
    rateInput.value = rate;
    setCookie('rm_rate', rate);
    applyRate(rate);

    await api('set_rate', { rate: String(rate) }).catch(() => {});

    if (serverRunning && !serverWon) {
      stopClientInterval();
      startClientInterval();
    }
  });

  /* ===================== Init ===================== */
  let state = loadState();

  async function init() {
    // Apply cookie UI prefs first
    loadCfgFromCookiesIntoInputs();
    loadResetTopCheckbox();
    loadPillBoxesCheckbox();

    if (pillBoxesPulse) pillBoxesPulse.addEventListener('change', persistPillBoxesCheckbox);

    // Restore tone sliders
    const rC = getCookie(TONE_R_COOKIE);
    const gC = getCookie(TONE_G_COOKIE);
    const bC = getCookie(TONE_B_COOKIE);
    setToneValues({ r: rC !== null ? rC : 50, g: gC !== null ? gC : 50, b: bC !== null ? bC : 50 });

    if (toneR) { toneR.addEventListener('input', syncToneFromSliders); toneR.addEventListener('change', syncToneFromSliders); }
    if (toneG) { toneG.addEventListener('input', syncToneFromSliders); toneG.addEventListener('change', syncToneFromSliders); }
    if (toneB) { toneB.addEventListener('input', syncToneFromSliders); toneB.addEventListener('change', syncToneFromSliders); }

    // Load current local state via API (keeps payload shape consistent)
    const s = await api('status');
    if (!s.ok) return;

    renderTop30(s.top3, s.cfg);
    applyRainbowStreaming();

    // Detect if game already started
    const hasGameState = (s.last_numbers !== null) || (s.tries > 0) || (s.elapsed_ms > 0) || s.won || s.running;

    if (hasGameState) {
      configCard.style.display = 'none';
      numbersEl.style.display = 'flex';
      metaEl.style.display = 'flex';
      statusEl.style.display = 'flex';
      footerEl.style.display = 'flex';

      updateBadges(s.cfg);
      ensurePills(s.cfg.count);
      updateNumbers(s.last_numbers);

      triesEl.textContent = formatIntDots(s.tries);
      baseElapsedMs = s.elapsed_ms;
      baseClientNowMs = Date.now();

      setRunningUI(s.running, s.won);
      timerEl.textContent = formatTimerStrict(getLiveElapsedMs());

      applyRate(s.cfg.rate_per_sec);
      rateInput.value = s.cfg.rate_per_sec;

      if (s.running && !s.won) {
        startUiTimer();
        startClientInterval();
      } else {
        stopUiTimer();
        stopClientInterval();
        timerEl.textContent = formatTimerStrict(s.elapsed_ms);
      }
    } else {
      configCard.style.display = 'block';
      numbersEl.style.display = 'none';
      metaEl.style.display = 'none';
      statusEl.style.display = 'none';
      footerEl.style.display = 'none';

      // If cookies are empty, populate inputs from stored cfg
      if (getCookie('rm_count') === null) cfgCount.value = String(s.cfg.count);
      if (getCookie('rm_min') === null) cfgMin.value = String(s.cfg.min);
      if (getCookie('rm_max') === null) cfgMax.value = String(s.cfg.max);
      if (getCookie('rm_rate') === null) cfgRate.value = String(s.cfg.rate_per_sec);
    }
  }

  init().catch(() => {});
})();
