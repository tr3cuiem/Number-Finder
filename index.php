<?php
declare(strict_types=1);
session_start();

/**
 * Random Match — Live Loop (v4)
 * Flow:
 *  - On load: setup menu (count/min/max/rate). Values persist via cookies (handled in JS, 30 days).
 *  - Start from menu => NEW GAME: resets tries/timer/top, applies config, starts running.
 *  - While running: live loop ticks via AJAX, top3 updates.
 *  - When perfect match (all N equal): server STOPS (like pressing Stop), timer freezes, loop must stop.
 *    UI shows confetti for 5 seconds.
 *  - Press Start after a stop/win: resumes WITHOUT resetting tries/timer, but DOES reset top3.
 *  - Reset: stops and clears tries/timer/top; returns to setup menu. (Cookies remain.)
 */

function now_ms(): int { return (int) round(microtime(true) * 1000); }

function session_init(): void {
    if (!isset($_SESSION['cfg'])) {
        $_SESSION['cfg'] = ['count'=>9,'min'=>0,'max'=>9,'rate_per_sec'=>10];
    }
    if (!isset($_SESSION['tries'])) $_SESSION['tries'] = 0;

    if (!isset($_SESSION['elapsed_ms'])) $_SESSION['elapsed_ms'] = 0;
    if (!isset($_SESSION['start_ms'])) $_SESSION['start_ms'] = null;
    if (!isset($_SESSION['running'])) $_SESSION['running'] = false;

    if (!isset($_SESSION['last_numbers'])) $_SESSION['last_numbers'] = null;

    // Top near-misses (Top 30)
    if (!isset($_SESSION['top3'])) $_SESSION['top3'] = [];

    // Win state (freeze)
    if (!isset($_SESSION['won'])) $_SESSION['won'] = false;
    if (!isset($_SESSION['win_numbers'])) $_SESSION['win_numbers'] = null;
}

function cfg_normalize(array $cfg): array {
    $count = max(1, min(50, (int)($cfg['count'] ?? 9)));
    $min = (int)($cfg['min'] ?? 0);
    $max = (int)($cfg['max'] ?? 9);
    if ($min > $max) { $t=$min; $min=$max; $max=$t; }

    $min = max(-999999, min(999999, $min));
    $max = max(-999999, min(999999, $max));

    $rate = max(1, min(60, (int)($cfg['rate_per_sec'] ?? 10)));
    return ['count'=>$count,'min'=>$min,'max'=>$max,'rate_per_sec'=>$rate];
}

function get_cfg(): array {
    session_init();
    $_SESSION['cfg'] = cfg_normalize($_SESSION['cfg']);
    return $_SESSION['cfg'];
}

function elapsed_ms(): int {
    session_init();
    $e = (int)$_SESSION['elapsed_ms'];
    if (!empty($_SESSION['running']) && is_int($_SESSION['start_ms']) && $_SESSION['start_ms'] > 0) {
        $e += max(0, now_ms() - (int)$_SESSION['start_ms']);
    }
    return $e;
}

function start_timer(): void {
    session_init();
    if (!empty($_SESSION['running'])) return;
    $_SESSION['running'] = true;
    $_SESSION['start_ms'] = now_ms();
}

function stop_timer(): void {
    session_init();
    if (!empty($_SESSION['running']) && is_int($_SESSION['start_ms']) && $_SESSION['start_ms'] > 0) {
        $delta = max(0, now_ms() - (int)$_SESSION['start_ms']);
        $_SESSION['elapsed_ms'] = (int)$_SESSION['elapsed_ms'] + $delta;
    }
    $_SESSION['running'] = false;
    $_SESSION['start_ms'] = null;
}

function reset_all_keep_cfg(): void {
    stop_timer();
    $_SESSION['tries'] = 0;
    $_SESSION['elapsed_ms'] = 0;
    $_SESSION['start_ms'] = null;
    $_SESSION['last_numbers'] = null;
    $_SESSION['top3'] = [];
    $_SESSION['won'] = false;
    $_SESSION['win_numbers'] = null;
}

function compute_best_streak(array $numbers): array {
    $freq = [];
    foreach ($numbers as $n) {
        $k = (string)$n;
        $freq[$k] = ($freq[$k] ?? 0) + 1;
    }

    $bestCount = -1;
    $bestValue = null;

    foreach ($freq as $valStr => $cnt) {
        $val = (int)$valStr;
        if ($cnt > $bestCount) {
            $bestCount = $cnt;
            $bestValue = $val;
        } elseif ($cnt === $bestCount && $bestValue !== null && $val > $bestValue) {
            $bestValue = $val;
        }
    }

    return ['count'=>$bestCount, 'value'=>(int)$bestValue];
}

function top3_update(array $candidate, array $combo, int $n): void {
    // Exclude perfect matches
    if ($candidate['count'] >= $n) return;

    $top3 = $_SESSION['top3'] ?? [];

    // If we already have the same (count,value), update its combo to the latest one.
    for ($i = 0; $i < count($top3); $i++) {
        if ((int)$top3[$i]['count'] === (int)$candidate['count'] && (int)$top3[$i]['value'] === (int)$candidate['value']) {
            $top3[$i]['combo'] = $combo;
            $_SESSION['top3'] = $top3;
            return;
        }
    }

    $top3[] = [
        'count' => (int)$candidate['count'],
        'value' => (int)$candidate['value'],
        'combo' => $combo,
    ];

    usort($top3, function($a, $b){
        $ac = (int)$a['count']; $bc = (int)$b['count'];
        if ($ac !== $bc) return $bc <=> $ac;
        return ((int)$b['value']) <=> ((int)$a['value']);
    });

    // Keep only the best 30 near-misses
    $_SESSION['top3'] = array_slice($top3, 0, 30);
}

function do_one_tick(): array {
    $cfg = get_cfg();
    $n = $cfg['count'];
    $min = $cfg['min'];
    $max = $cfg['max'];

    $_SESSION['tries'] = (int)$_SESSION['tries'] + 1;

    $numbers = [];
    for ($i=0; $i<$n; $i++) {
        $numbers[] = random_int($min, $max);
    }

    $_SESSION['last_numbers'] = $numbers;

    $best = compute_best_streak($numbers);
    top3_update($best, $numbers, $n);

    $win = ($best['count'] === $n);
    return [$numbers, $best, $win];
}

function api_reply(array $extra = []): void {
    $cfg = get_cfg();
    $payload = array_merge([
        'ok' => true,
        'cfg' => $cfg,
        'running' => (bool)$_SESSION['running'],
        'won' => (bool)$_SESSION['won'],
        'tries' => (int)$_SESSION['tries'],
        'elapsed_ms' => elapsed_ms(),
        'last_numbers' => $_SESSION['last_numbers'],
        'top3' => $_SESSION['top3'],
        'win_numbers' => $_SESSION['win_numbers'],
    ], $extra);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===================== API ===================== */
if (isset($_GET['api'])) {
    session_init();
    $action = $_GET['action'] ?? 'status';

    if ($action === 'status') {
        api_reply();
    }

    if ($action === 'new_game') {
        // Apply config and reset everything (except cookies are client-side)
        $cfg = [
            'count' => isset($_GET['count']) ? (int)$_GET['count'] : 9,
            'min' => isset($_GET['min']) ? (int)$_GET['min'] : 0,
            'max' => isset($_GET['max']) ? (int)$_GET['max'] : 9,
            'rate_per_sec' => isset($_GET['rate']) ? (int)$_GET['rate'] : 10,
        ];
        $_SESSION['cfg'] = cfg_normalize($cfg);

        $_SESSION['tries'] = 0;
        $_SESSION['elapsed_ms'] = 0;
        $_SESSION['start_ms'] = null;
        $_SESSION['last_numbers'] = null;
        $_SESSION['top3'] = [];
        $_SESSION['won'] = false;
        $_SESSION['win_numbers'] = null;

        // Start
        $_SESSION['running'] = true;
        $_SESSION['start_ms'] = now_ms();

        api_reply(['started' => true, 'mode' => 'new_game']);
    }

    if ($action === 'set_rate') {
        $rate = isset($_GET['rate']) ? (int)$_GET['rate'] : (int)($_SESSION['cfg']['rate_per_sec'] ?? 10);
        $rate = max(1, min(60, $rate));
        $_SESSION['cfg']['rate_per_sec'] = $rate;
        api_reply(['rate_updated' => true]);
    }

    if ($action === 'start') {
        // Start behavior:
        // - If a WIN happened (won=true), Start always resets Top 30 (ignores checkbox).
        // - If it's a normal Stop->Start, Top 30 resets only if reset_top3=1 is provided.
        $resetTop3 = isset($_GET['reset_top3']) ? ((int)$_GET['reset_top3'] === 1) : true;

        if (!empty($_SESSION['won'])) {
            // Always reset after a win
            $_SESSION['top3'] = [];
            $_SESSION['won'] = false;
            $_SESSION['win_numbers'] = null;
        } elseif (empty($_SESSION['running']) && $resetTop3) {
            $_SESSION['top3'] = [];
        }

        start_timer();
        api_reply(['started' => true, 'mode' => 'resume', 'reset_top3_applied' => (!empty($_SESSION['won']) ? true : $resetTop3)]);
    }

    if ($action === 'stop') {
        stop_timer();
        api_reply(['stopped' => true]);
    }

    if ($action === 'reset') {
        reset_all_keep_cfg();
        api_reply(['reset' => true]);
    }

    if ($action === 'tick') {
        // If won or stopped, do not tick.
        if (!empty($_SESSION['won']) || empty($_SESSION['running'])) {
            api_reply(['tick' => false]);
        }

        [$numbers, $best, $win] = do_one_tick();

        if ($win) {
            // Freeze like Stop, keep tries/timer as-is
            $_SESSION['won'] = true;
            $_SESSION['win_numbers'] = $numbers;
            stop_timer();

            api_reply([
                'tick' => true,
                'numbers' => $numbers,
                'best' => $best,
                'win' => true
            ]);
        }

        api_reply([
            'tick' => true,
            'numbers' => $numbers,
            'best' => $best,
            'win' => false
        ]);
    }

    if ($action === 'catchup') {
        // Catch up only when running (and not won)
        if (!empty($_SESSION['won']) || empty($_SESSION['running'])) {
            api_reply(['catchup' => false]);
        }

        $ticks = isset($_GET['ticks']) ? (int)$_GET['ticks'] : 0;
        $ticks = max(0, min(20000, $ticks));

        $numbers = null;
        $best = null;
        $win = false;
        $processed = 0;

        for ($i=0; $i<$ticks; $i++) {
            [$numbers, $best, $win] = do_one_tick();
            $processed++;
            if ($win) {
                $_SESSION['won'] = true;
                $_SESSION['win_numbers'] = $numbers;
                stop_timer();
                break;
            }
        }

        api_reply([
            'catchup' => true,
            'processed' => $processed,
            'numbers' => $numbers,
            'best' => $best,
            'win' => $win
        ]);
    }

    api_reply(['error' => 'Unknown action']);
}

/* ===================== PAGE ===================== */
session_init();
$cfg = get_cfg();
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Random Match — Live Loop</title>
  <style>
    :root{
      --bg:#000000;
      --card:#050505;
      --card2:#070707;
      --border:rgba(0,255,102,0.20);
      --text:#d9ffd9;
      --muted:rgba(0,255,102,0.70);
      --green:#00ff66;
      --blue:#2196f3;
      --red:#f44336;
      --shadow: 0 0 40px rgba(0,0,0,0.65);
      --radius: 10px;
      --font-regular: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      --font-light: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      --font-alt: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      min-height:100vh;
      background: var(--bg);
      color:var(--text);
      font-family: var(--font-regular);
      display:flex;
      font-weight: 300;
    }

    /* Sidebar */
    .sidebar{
      width: 420px;
      padding: 22px 18px;
      border-right: 1px solid var(--border);
      background: rgba(255,255,255,0.01);
      height: 100vh;
      overflow: hidden;
    }
    .sideCard{
      background: var(--card);
      border:1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: 0 0 0 1px rgba(0,255,102,0.10), 0 20px 50px rgba(0,0,0,0.65);
      padding: 18px;
      position: sticky;
      top: 18px;
      height: calc(100vh - 44px);
      display:flex;
      flex-direction:column;
    }
    .sideTitle{
      display:flex;
      align-items:baseline;
      justify-content:space-between;
      margin:0 0 10px;
      gap: 10px;
    }
    .sideTitle h2{
      font-size: 14px;
      letter-spacing: .6px;
      text-transform: uppercase;
      margin:0;
      color: var(--muted);
      font-weight: 600;
    }
    .sideHint{
      font-size: 13px;
      color: var(--muted);
      opacity: .95;
    }

    .toneGroup{ width:100%; }
    .toneRow{ display:flex; align-items:center; gap:10px; margin: 6px 0; }
    .toneLabel{ width: 18px; color: var(--muted); font-weight:700; }
    .toneRow input[type=range]{ width: 100%; accent-color: var(--green); }
    .toneVal{ min-width: 44px; text-align:right; font-variant-numeric: tabular-nums; color: var(--muted); }
    .topScroll{
      flex: 1 1 auto;
      overflow:auto;
      padding-right: 6px;
      margin-top: 6px;
    }

    /* Custom scrollbar (dark terminal style) */
    .topScroll{
      scrollbar-width: thin;
      scrollbar-color: rgba(0,255,102,0.35) rgba(255,255,255,0.04);
    }
    .topScroll::-webkit-scrollbar{ width: 10px; }
    .topScroll::-webkit-scrollbar-track{
      background: rgba(255,255,255,0.03);
      border-radius: 999px;
      border: 1px solid rgba(0,255,102,0.10);
    }
    .topScroll::-webkit-scrollbar-thumb{
      background: rgba(0,255,102,0.22);
      border-radius: 999px;
      border: 1px solid rgba(0,255,102,0.22);
    }
    .topScroll::-webkit-scrollbar-thumb:hover{ background: rgba(0,255,102,0.32); }
    .topList{
      margin: 8px 0 0;
      padding:0;
      list-style:none;
      display:flex;
      flex-direction:column;
      gap: 6px;
    }
    .topItem{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.02);
      border-radius: 14px;
      padding: 7px 9px;
    }
    .rank{
      width: 26px;
      height: 26px;
      border-radius: 999px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      color: var(--text);
      font-size: 12px;
      flex: 0 0 auto;
    }
    .topText{
      display:flex;
      flex-direction:column;
      gap: 4px;
      flex: 1 1 auto;
      min-width: 0;
    }
    .topMain{
      font-size: 14px;
      font-weight: 800;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .topSub{
      font-size: 12px;
      color: var(--muted);
    }

    /* Top list styling upgrades (first ranks) */
    .topItem.rank1 .rank{ border-color: rgba(255,215,0,0.35); background: rgba(255,215,0,0.08); }
    .topItem.rank2 .rank{ border-color: rgba(192,192,192,0.35); background: rgba(192,192,192,0.08); }
    .topItem.rank3 .rank{ border-color: rgba(205,127,50,0.35); background: rgba(205,127,50,0.08); }
    .topItem.rank4 .rank{ border-color: rgba(125,255,178,0.28); background: rgba(125,255,178,0.07); }
    .topItem.rank5 .rank{ border-color: rgba(255,125,178,0.28); background: rgba(255,125,178,0.07); }
    .topItem.rank6 .rank{ border-color: rgba(255,179,107,0.28); background: rgba(255,179,107,0.07); }
    .topItem.rank7 .rank{ border-color: rgba(159,167,255,0.28); background: rgba(159,167,255,0.07); }

    .topItem.rank1 .topMain{ font-size: 24px; }
    .topItem.rank2 .topMain{ font-size: 20px; }
    .topItem.rank3 .topMain{ font-size: 18px; }
    .topItem.rank4 .topMain,
    .topItem.rank5 .topMain,
    .topItem.rank6 .topMain,
    .topItem.rank7 .topMain{ font-size: 16px; }

    .topTitleLine{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 10px;
    }
    .topLabel{
      font-size: 13px;
      font-weight: 800;
      letter-spacing: .3px;
      text-transform: uppercase;
      display:flex;
      align-items:center;
      gap: 8px;
      opacity: .95;
      white-space: nowrap;
      color: var(--muted);
    }
    .topLabel span{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width: 22px;
      height: 22px;
      border-radius: 999px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.08);
      font-size: 13px;
      color: var(--text);
    }

    .topScore{
      font-weight: 900;
      letter-spacing: 0.4px;
      color: var(--text);
    }
    .topScore .x{
      font-weight: 700;
      opacity: .9;
      margin: 0 6px;
    }

    .topCombo{
      margin-top: 6px;
      font-size: 14px;
      color: var(--muted);
      line-height: 1.35;
      word-break: break-word;
      letter-spacing: 1.2px;
      white-space: normal;
    }

    /* Per-rank accent palette (fixed, bright) */
    .topItem.rank1{ --accent: #ffd86b; --accent2: #ffe7a7; }
    .topItem.rank2{ --accent: #6bd1ff; --accent2: #a6e6ff; }
    .topItem.rank3{ --accent: #c56bff; --accent2: #e0b3ff; }
    .topItem.rank4{ --accent: #7dffb2; --accent2: #b9ffd6; }
    .topItem.rank5{ --accent: #ff7db2; --accent2: #ffb9d6; }
    .topItem.rank6{ --accent: #ffb36b; --accent2: #ffd7a7; }
    .topItem.rank7{ --accent: #9fa7ff; --accent2: #cfd3ff; }

    .topScore .value{
      color: var(--accent);
      font-weight: 900;
    }
    .topCombo .hit{
      color: var(--accent2);
      font-weight: 600;
    }
    .topCombo .num{
      opacity: .95;
    }


    /* Main */
    .main{
      flex:1;
      display:flex;
      flex-direction:column;
      min-width: 0;
    }
    .wrap{
      flex:1;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:28px;
      min-width: 0;
    }
    .panel{
      width:min(980px, 100%);
      background: var(--card);
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
      font-size: 18px;
      margin:0;
      letter-spacing:0.2px;
      font-weight: 600;
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
    .badge b{ color: var(--text); font-weight: 800; }

    .numbers{
      display:flex;
      gap:12px;
      justify-content:center;
      margin: 18px 0 12px;
      flex-wrap:wrap;
    }
    .pill{
      width: 92px;
      height: 92px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: var(--card2);
      border:1px solid var(--border);
      border-radius: 18px;
      box-shadow: 0 12px 30px rgba(0,0,0,0.25);
      user-select:none;
      position: relative;
    }
    .pill.noBox{
      background: transparent;
      border-color: transparent;
      box-shadow: none;
    }
    .pill.pulse{
      animation: slowPulse 5.4s ease-in-out infinite;
    }
    @keyframes slowPulse{
      0%,100%{ transform: scale(1); box-shadow: 0 10px 28px rgba(0,0,0,0.30); }
      50%{ transform: scale(1.045); box-shadow: 0 18px 46px rgba(0,0,0,0.45); }
    }
    .pillNum{
      font-size: 80px;
      line-height: 1;
      font-weight: 800;
      letter-spacing: 0.5px;
      color: transparent;
      background-clip: text;
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      transition: filter 120ms linear;
      font-variant-numeric: tabular-nums;
    }

    .meta{
      display:flex;
      justify-content:center;
      gap:18px;
      color:var(--muted);
      font-size: 14px;
      margin-bottom: 8px;
      flex-wrap:wrap;
    }
    .meta .strong{ color: var(--text); font-weight: 800; }

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
    .dot.won{
      background: var(--blue);
      box-shadow: 0 0 0 6px rgba(33,150,243,0.10);
    }

    .footer{
      padding: 18px 22px 28px;
      display:flex;
      justify-content:center;
      gap: 14px;
      flex-wrap:wrap;
      align-items:center;
      border-top: 1px solid rgba(255,255,255,0.04);
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
      transition: transform .15s ease, border-color .15s ease, opacity .15s ease;
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
    .btn[disabled]{ opacity:0.5; cursor:not-allowed; transform:none !important; }

    .configCard{
      background: rgba(255,255,255,0.02);
      border:1px solid var(--border);
      border-radius: 18px;
      padding: 18px;
    }
    .configGrid{
      display:grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: 12px;
      margin-top: 12px;
    }
    @media (max-width: 980px){
      body{ flex-direction: column; }
      .sidebar{ width: 100%; border-right: none; border-bottom: 1px solid var(--border); }
      .wrap{ padding: 18px; }
      .panel{ padding: 18px; }
      .configGrid{ grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
    .field{ display:flex; flex-direction:column; gap:6px; min-width:0; }
    .label{ color: var(--muted); font-size: 12px; letter-spacing: .3px; }
    .input{
      width: 100%;
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.03);
      color: var(--text);
      font-size: 14px;
      outline: none;
    }
    .input:focus{
      border-color: rgba(33,150,243,0.45);
      box-shadow: 0 0 0 6px rgba(33,150,243,0.08);
    }

    .toast{
      position: fixed;
      right: 18px;
      bottom: 18px;
      background: rgba(22,22,22,0.92);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 14px;
      padding: 12px 14px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.55);
      color: var(--text);
      font-size: 13px;
      display:none;
      gap: 10px;
      align-items:center;
      z-index: 60;
      backdrop-filter: blur(6px);
    }
    .toast.show{ display:flex; }
    .toast .pillDot{
      width:10px; height:10px; border-radius:50%;
      background: var(--green);
      box-shadow: 0 0 0 6px rgba(76,175,80,0.10);
      flex: 0 0 auto;
    }

    .confetti{
      position:fixed;
      inset:0;
      pointer-events:none;
      opacity:0;
      z-index: 50;
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
      100%{ transform: translateY(110vh) rotate(220deg); opacity:0; }
    }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="sideCard">
      <!-- Options first -->
      <div class="sideTitle" style="margin-bottom: 8px;">
        <h2>Options</h2>
        <div class="sideHint">Sidebar</div>
      </div>
      <div class="sideHint" style="margin-top: 0; display:flex; align-items:flex-start; gap:10px;">
        <input type="checkbox" id="resetTopOnStart" style="margin-top: 2px; accent-color: var(--green);" checked>
        <label for="resetTopOnStart" style="cursor:pointer; line-height: 1.35;">
          Reset <b>Top 30</b> when pressing <b>Start</b> after <b>Stop</b>.
          <span style="display:block; margin-top:4px; opacity:.9;">
            (After a <b>win</b>, Top 30 resets on Start automatically.)
          </span>
        </label>
      </div>

      <div class="sideHint" style="margin-top: 10px; display:flex; align-items:flex-start; gap:10px;">
        <input type="checkbox" id="pillBoxesPulse" style="margin-top: 2px; accent-color: var(--green);" checked>
        <label for="pillBoxesPulse" style="cursor:pointer; line-height: 1.35;">
          Show pill <b>boxes</b> + slow <b>pulse</b> (asynchronous).
        </label>
      </div>

      <!-- RGB tone influence -->
      <div class="sideTitle" style="margin: 14px 0 10px; flex-direction:column; align-items:flex-start;">
        <h2 style="margin:0;">Number color influence</h2>
        <div class="sideHint">0% = black • 50% = rainbow base • 100% = white (layered tint)</div>
      </div>

      <div class="toneGroup">
        <div class="toneRow">
          <div class="toneLabel">R</div>
          <input id="toneR" type="range" min="0" max="100" value="50">
          <div class="toneVal" id="toneRVal">50%</div>
        </div>
        <div class="toneRow">
          <div class="toneLabel">G</div>
          <input id="toneG" type="range" min="0" max="100" value="50">
          <div class="toneVal" id="toneGVal">50%</div>
        </div>
        <div class="toneRow" style="margin-bottom: 12px;">
          <div class="toneLabel">B</div>
          <input id="toneB" type="range" min="0" max="100" value="50">
          <div class="toneVal" id="toneBVal">50%</div>
        </div>
      </div>

      <!-- Top list (scrollable, 100vh) -->
      <div class="sideTitle" style="margin-top: 12px;">
        <h2>Top 30 Near-Misses</h2>
        <div class="sideHint">Higher count wins • tie → higher value</div>
      </div>

      <div class="topScroll">
        <ul class="topList" id="topList"></ul>
      </div>
    </div>
  </aside>

  <main class="main">
    <div class="wrap">
      <div class="panel">
        <div class="title">
          <h1>🎲 Random Match — Live Loop</h1>
          <div class="badge">
            <span>Rate:</span>
            <b><span class="dyn-number" id="rateBadge"><?php echo htmlspecialchars((string)$cfg['rate_per_sec'], ENT_QUOTES); ?></span>/s</b>
            <span style="opacity:.8">•</span>
            <span>Range: <b><span class="dyn-number" id="rangeBadge"></span></b></span>
            <span style="opacity:.8">•</span>
            <span>Pills: <b><span class="dyn-number" id="countBadge"><?php echo htmlspecialchars((string)$cfg['count'], ENT_QUOTES); ?></span></b></span>
          </div>
        </div>

        <!-- Setup -->
        <div class="configCard" id="configCard">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div style="min-width: 220px;">
              <div style="font-size:14px; font-weight:800;">Game Setup</div>
              <div style="font-size:12px; color: var(--muted); margin-top: 4px;">
                Settings persist in cookies for <b>30 days</b>.
              </div>
            </div>
            <div style="font-size:12px; color: var(--muted);">
              After starting, you can adjust <b>ticks/sec</b> live.
            </div>
          </div>

          <div class="configGrid" style="margin-top: 14px;">
            <div class="field">
              <div class="label">Pill count</div>
              <input class="input" id="cfgCount" type="number" min="1" max="50" step="1" value="<?php echo htmlspecialchars((string)$cfg['count'], ENT_QUOTES); ?>">
            </div>
            <div class="field">
              <div class="label">Range min</div>
              <input class="input" id="cfgMin" type="number" step="1" value="<?php echo htmlspecialchars((string)$cfg['min'], ENT_QUOTES); ?>">
            </div>
            <div class="field">
              <div class="label">Range max</div>
              <input class="input" id="cfgMax" type="number" step="1" value="<?php echo htmlspecialchars((string)$cfg['max'], ENT_QUOTES); ?>">
            </div>
            <div class="field">
              <div class="label">Ticks / second</div>
              <input class="input" id="cfgRate" type="number" min="1" max="60" step="1" value="<?php echo htmlspecialchars((string)$cfg['rate_per_sec'], ENT_QUOTES); ?>">
            </div>
          </div>

          <div style="display:flex; justify-content:center; margin-top: 16px;">
            <button class="btn primary" id="startOnlyBtn">▶ Start</button>
          </div>
        </div>

        <!-- Game -->
        <div class="numbers" id="numbers" style="display:none;"></div>

        <div class="meta" id="meta" style="display:none;">
          <div>Tries: <span class="strong dyn-number" id="tries">0</span></div>
          <div>Last response: <span class="strong dyn-number" id="lat">—</span> ms</div>
          <div>Timer: <span class="strong dyn-number" id="timer">0 Years, 0 Days, 0 Hours, 0 Minutes, 0 Seconds.</span></div>
        </div>

        <div class="status" id="status" style="display:none;">
          <span class="dot stopped" id="dot"></span>
          <span id="statusText">Stopped</span>
          <span id="bgHint" style="opacity:.85"></span>
        </div>
      </div>
    </div>

    <div class="footer" id="footer" style="display:none;">
      <button class="btn primary" id="startBtn">▶ Start</button>
      <button class="btn danger" id="stopBtn" disabled>⏸ Stop</button>
      <button class="btn secondary" id="resetBtn">↻ Reset</button>

      <div class="configCard" style="padding: 12px 12px;">
        <div style="display:flex; align-items:center; gap: 10px; flex-wrap:wrap;">
          <div style="color: var(--muted); font-size: 12px;">Ticks/sec</div>
          <input class="input" id="rateInput" type="number" min="1" max="60" step="1"
                 value="<?php echo htmlspecialchars((string)$cfg['rate_per_sec'], ENT_QUOTES); ?>"
                 style="width: 120px; padding: 12px 12px;">
        </div>
      </div>
    </div>
  </main>

  <div class="confetti" id="confetti"></div>
  <div class="toast" id="toast">
    <span class="pillDot"></span>
    <span id="toastText">Perfect match! Paused.</span>
  </div>

<script>
  // Cookie helpers (30 days)
  const COOKIE_DAYS = 30;
  function setCookie(name, value, days=COOKIE_DAYS){
    const d = new Date();
    d.setTime(d.getTime() + days*24*60*60*1000);
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}; expires=${d.toUTCString()}; path=/; SameSite=Lax`;
  }
  function getCookie(name){
    const key = encodeURIComponent(name) + '=';
    return document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(key))?.slice(key.length) ?? null;
  }

  // Elements
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


  // Number color sliders (R/G/B), each 0..100
  // These act like *layers over the rainbow stream*:
  //  - All 0% => absolute black
  //  - All 100% => absolute white
  //  - Around 50% => rainbow base (with a gentle tint from the sliders)
  let tone = { r: 50, g: 50, b: 50 };
  const TONE_R_COOKIE = 'rm_loop_tone_r';
  const TONE_G_COOKIE = 'rm_loop_tone_g';
  const TONE_B_COOKIE = 'rm_loop_tone_b';


  function setToneValues(next){
    tone = {
      r: clamp(parseInt(String(next.r),10) || 0, 0, 100),
      g: clamp(parseInt(String(next.g),10) || 0, 0, 100),
      b: clamp(parseInt(String(next.b),10) || 0, 0, 100),
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

  function syncToneFromSliders(){
    setToneValues({
      r: toneR ? toneR.value : 50,
      g: toneG ? toneG.value : 50,
      b: toneB ? toneB.value : 50,
    });
  }

  // Deterministic "random" helpers (so elements don't flicker too harshly)
  function xorshift32(x){
    x |= 0;
    x ^= x << 13; x |= 0;
    x ^= x >>> 17; x |= 0;
    x ^= x << 5; x |= 0;
    return x | 0;
  }
  function rand01(seed){
    const x = xorshift32(seed);
    return ((x >>> 0) / 4294967295);
  }
  function pick(seed, arr){
    return arr[Math.floor(rand01(seed) * arr.length) % arr.length];
  }

  function hslToRgb(h, s, l){
    h = ((h % 360) + 360) % 360;
    const c = (1 - Math.abs(2*l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c/2;
    let r=0,g=0,b=0;
    if (h < 60){ r=c; g=x; b=0; }
    else if (h < 120){ r=x; g=c; b=0; }
    else if (h < 180){ r=0; g=c; b=x; }
    else if (h < 240){ r=0; g=x; b=c; }
    else if (h < 300){ r=x; g=0; b=c; }
    else { r=c; g=0; b=x; }
    return [
      Math.round((r + m) * 255),
      Math.round((g + m) * 255),
      Math.round((b + m) * 255),
    ];
  }

  function lerp(a, b, t){ return a + (b - a) * t; }
  function lerpRgb(a, b, t){
    return [
      Math.round(lerp(a[0], b[0], t)),
      Math.round(lerp(a[1], b[1], t)),
      Math.round(lerp(a[2], b[2], t)),
    ];
  }
  function rgbToCss(rgb){ return `rgb(${rgb[0]} ${rgb[1]} ${rgb[2]})`; }

  function rainbowRgb(seed){
    const h = Math.floor(rand01(seed) * 360);
    return hslToRgb(h, 0.92, 0.64);
  }

  function applyToneLayer(baseRgb){
    const w = (tone.r + tone.g + tone.b) / 300;
    const tint = [
      Math.round((tone.r/100) * 255),
      Math.round((tone.g/100) * 255),
      Math.round((tone.b/100) * 255),
    ];

    let rgb = lerpRgb(baseRgb, tint, 0.22);

    if (w <= 0.5){
      rgb = lerpRgb([0,0,0], rgb, w / 0.5);
    } else {
      rgb = lerpRgb(rgb, [255,255,255], (w - 0.5) / 0.5);
    }

    return rgb;
  }

  const FONT_FAMILIES = [
    'ui-monospace',
    'SFMono-Regular',
    'Menlo',
    'Monaco',
    'Consolas',
    '"Liberation Mono"',
    '"Courier New"',
    '"DejaVu Sans Mono"',
    '"Ubuntu Mono"',
    '"Fira Code"',
    '"JetBrains Mono"'
  ];

  // Pills box + pulse (live toggle)
  const pillBoxesPulse = document.getElementById('pillBoxesPulse');
  const PILL_BOX_COOKIE = 'rm_pillBoxesPulse';

  function loadPillBoxesCheckbox(){
    const v = getCookie(PILL_BOX_COOKIE);
    if (v !== null && pillBoxesPulse) pillBoxesPulse.checked = (v === '1' || v === 'true');
  }
  function persistPillBoxesCheckbox(){
    if (!pillBoxesPulse) return;
    setCookie(PILL_BOX_COOKIE, pillBoxesPulse.checked ? '1' : '0');
    applyPillBoxesState();
  }

  function applyPillBoxesState(){
    const on = !!pillBoxesPulse?.checked;
    document.querySelectorAll('.pill').forEach((p, idx) => {
      p.classList.toggle('noBox', !on);
      p.classList.toggle('pulse', on);
      if (on){
        p.style.animationDelay = `${(idx * 0.37) % 2.4}s`;
      } else {
        p.style.animationDelay = '0s';
      }
    });
  }

  let rainbowTick = 0;
  function applyRainbowStreaming(){
    rainbowTick++;

    const dyn = document.querySelectorAll('.dyn-number');
    dyn.forEach((el, idx) => {
      if (el.classList.contains('pillNum')) return;
      if (el.closest && el.closest('.pill')) return;

      const base = rainbowRgb((rainbowTick * 9973) + idx * 131);
      const out = applyToneLayer(base);
      el.style.color = rgbToCss(out);

      const d = pick((rainbowTick * 733) + idx * 17, [-1,0,1]);
      el.style.fontSize = '';
      if (d !== 0){
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
      const delta = pick(seedB, [-1,0,1]);
      const baseSize = 80;
      el.style.fontSize = `${baseSize + (delta * 3)}px`;
    });

    applyPillBoxesState();
  }

  // Runtime
  let intervalId = null;
  let tickMs = 100;
  let inFlight = false;

  let serverRunning = false;
  let serverWon = false;

  // Timer sync
  let baseElapsedMs = 0;
  let baseClientNowMs = 0;

  // Background catch-up
  let lastVisibleAt = Date.now();

  function clamp(n, lo, hi){ return Math.max(lo, Math.min(hi, n)); }

  // Format integers with dot thousand separators (e.g., 102302130 -> 102.302.130)
  function formatIntDots(x){
    const n = Math.floor(Number(x) || 0);
    // Use a locale that formats thousands with dots
    try { return new Intl.NumberFormat('de-DE').format(n); } catch(e) {}
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  async function api(action, params = {}){
    const usp = new URLSearchParams({ api: '1', action, ...params });
    const res = await fetch('?' + usp.toString(), { cache: 'no-store' });
    return res.json();
  }

  // Bright colors (avoid near-black): fixed L and high S
  function randomBrightColor(){
    const h = Math.floor(Math.random() * 360);
    return `hsl(${h} 90% 65%)`;
  }
  function recolorPills(){
    applyRainbowStreaming();
  }
  function ensurePills(count){
    const existing = numbersEl.querySelectorAll('.pill').length;
    if (existing === count) return;
    numbersEl.innerHTML = '';
    for (let i=0; i<count; i++){
      const d = document.createElement('div');
      d.className = 'pill';
      const s = document.createElement('span');
      s.className = 'pillNum dyn-number';
      s.textContent = '–';
      d.appendChild(s);
      numbersEl.appendChild(d);
    }
    recolorPills();
  }
  function updateNumbers(nums){
    const pills = numbersEl.querySelectorAll('.pillNum');
    pills.forEach((p,i)=> p.textContent = (nums && typeof nums[i] !== 'undefined') ? nums[i] : '–');
    applyRainbowStreaming();
  }

  function updateBadges(cfg){
    if (!rateBadge || !rangeBadge || !countBadge) return;
    rateBadge.textContent = String(cfg.rate_per_sec);
    rangeBadge.textContent = `From ${cfg.min} to ${cfg.max}`;
    countBadge.textContent = String(cfg.count);
  }

  function setStatusDot(mode){
    dotEl.classList.remove('running','stopped','won');
    if (mode === 'running') dotEl.classList.add('running');
    else if (mode === 'won') dotEl.classList.add('won');
    else dotEl.classList.add('stopped');
  }

  function setRunningUI(isRunning, isWon){
    serverRunning = !!isRunning;
    serverWon = !!isWon;

    if (serverWon){
      setStatusDot('won');
      statusTextEl.textContent = 'Paused (perfect match)';
      startBtn.disabled = false;
      stopBtn.disabled = true;
      return;
    }

    if (serverRunning){
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

  function applyRate(ratePerSec){
    const r = clamp(parseInt(ratePerSec, 10) || 10, 1, 60);
    tickMs = Math.max(1, Math.round(1000 / r));
    rateInput.value = r;
  }

  function formatTimerStrict(ms){
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

  function getLiveElapsedMs(){
    if (serverRunning){
      return baseElapsedMs + Math.max(0, (Date.now() - baseClientNowMs));
    }
    return baseElapsedMs;
  }

  let uiTimerInterval = null;
  function startUiTimer(){
    if (uiTimerInterval) return;
    uiTimerInterval = setInterval(()=> {
      timerEl.textContent = formatTimerStrict(getLiveElapsedMs());
    }, 250);
  }
  function stopUiTimer(){
    if (uiTimerInterval){
      clearInterval(uiTimerInterval);
      uiTimerInterval = null;
    }
  }

  function renderTop30(top3, cfg){
    const n = cfg.count;
    const items = (top3 || []).slice(0, 30);
    const slots = [];
    for (let i=0; i<30; i++) slots.push(items[i] || null);

    function labelFor(idx){
      if (idx === 0) return {emoji:'🥇', text:'Best streak'};
      if (idx === 1) return {emoji:'🥈', text:'Runner-up'};
      if (idx === 2) return {emoji:'🥉', text:'Third place'};
      return {emoji:'#', text:`Rank ${idx+1}`};
    }

    topList.innerHTML = '';
    slots.forEach((entry, idx)=> {
      const li = document.createElement('li');
      li.className = 'topItem ' + `rank${idx+1}`;

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

      if (!entry){
        main.textContent = '—';
        combo.textContent = `Waiting for near-misses (out of ${n})`;
      } else {
        // Elegant score: "8 × 9" with colored value
        main.innerHTML = `<span class="count dyn-number">${entry.count}</span><span class="x">×</span><span class="value dyn-number">${entry.value}</span>`;

        // Combo line: highlight occurrences of entry.value with a close color.
        if (entry.combo && Array.isArray(entry.combo)){
          const v = String(entry.value);
          combo.innerHTML = entry.combo.map(num => {
            const s = String(num);
            const cls = (s === v) ? 'num hit dyn-number' : 'num dyn-number';
            return `<span class="${cls}">${s}</span>`;
          }).join('&nbsp;&nbsp;'); // double space
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

  // Confetti

  function launchConfetti(durationMs = 5000){
    confetti.innerHTML = '';
    confetti.classList.add('on');

    const spawnBurst = () => {
      const pieces = 40;
      for (let i=0; i<pieces; i++){
        const p = document.createElement('i');
        p.style.left = (Math.random() * 100) + '%';
        p.style.animationDelay = (Math.random() * 120) + 'ms';
        p.style.background = `hsl(${Math.floor(Math.random()*360)} 90% 60%)`;
        p.style.width = (8 + Math.random()*8) + 'px';
        p.style.height = (12 + Math.random()*14) + 'px';
        p.style.transform = `rotate(${Math.random()*90}deg)`;
        confetti.appendChild(p);
      }
      // Clean after each burst
      setTimeout(()=> { confetti.innerHTML = ''; }, 1200);
    };

    spawnBurst();
    const burstInterval = setInterval(spawnBurst, 900);

    setTimeout(() => {
      clearInterval(burstInterval);
      confetti.classList.remove('on');
      confetti.innerHTML = '';
    }, durationMs);
  }

  function showToast(msg, duration=1800){
    toastText.textContent = msg;
    toast.classList.add('show');
    setTimeout(()=> toast.classList.remove('show'), duration);
  }

  // Loop control
  function startClientInterval(){
    if (intervalId) return;
    loopStep();
    intervalId = setInterval(loopStep, tickMs);
  }
  function stopClientInterval(){
    if (intervalId){
      clearInterval(intervalId);
      intervalId = null;
    }
  }

  async function loopStep(){
    if (inFlight) return;
    inFlight = true;
    try{
      await tickOnce();
    } finally {
      inFlight = false;
    }
  }

  async function tickOnce(){
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

    // If win, freeze everything client-side immediately.
    if (data.win === true || data.won === true){
      stopClientInterval();
      stopUiTimer();
      // Ensure timer freezes at exact server elapsed
      baseElapsedMs = data.elapsed_ms;
      baseClientNowMs = Date.now();
      timerEl.textContent = formatTimerStrict(baseElapsedMs);launchConfetti(5000);
      showToast('Perfect match found — paused. Press Start to continue (Top 30 resets).', 3500);
    }
  }

  // Background catch-up (only for running + not won)
  async function catchUpIfNeeded(){
    if (!serverRunning || serverWon) return;

    const secondsAway = Math.max(0, (Date.now() - lastVisibleAt) / 1000);
    const rate = clamp(parseInt(rateInput.value, 10) || 10, 1, 60);
    let ticks = Math.floor(secondsAway * rate);
    if (ticks <= 0) return;

    const MAX_PER_REQ = 15000;
    bgHintEl.textContent = `Catching up ${ticks} tick(s)…`;

    while (ticks > 0 && serverRunning && !serverWon){
      const batch = Math.min(MAX_PER_REQ, ticks);
      try{
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

        if (data.win){
          stopClientInterval();
          stopUiTimer();
          baseElapsedMs = data.elapsed_ms;
          timerEl.textContent = formatTimerStrict(baseElapsedMs);launchConfetti(5000);
          showToast('Perfect match found — paused. Press Start to continue (Top 30 resets).', 3500);
          break;
        }

      } catch(e){ break; }

      ticks -= batch;
    }

    bgHintEl.textContent = '';
  }

  document.addEventListener('visibilitychange', async () => {
    if (document.visibilityState === 'hidden'){
      lastVisibleAt = Date.now();
    } else {
      await catchUpIfNeeded();
    }
  });

  // Setup -> cookie persistence
  function loadCfgFromCookiesIntoInputs(){
    const c = getCookie('rm_count');
    const mn = getCookie('rm_min');
    const mx = getCookie('rm_max');
    const rt = getCookie('rm_rate');

    if (c !== null) cfgCount.value = c;
    if (mn !== null) cfgMin.value = mn;
    if (mx !== null) cfgMax.value = mx;
    if (rt !== null) cfgRate.value = rt;
  }

  function loadResetTopCheckbox(){
    const v = getCookie('rm_resetTopOnStart');
    if (v !== null){
      resetTopOnStart.checked = (v === '1' || v === 'true');
    }
  }
  function persistResetTopCheckbox(){
    setCookie('rm_resetTopOnStart', resetTopOnStart.checked ? '1' : '0');
  }

  function persistInputsToCookies(){
    setCookie('rm_count', cfgCount.value);
    setCookie('rm_min', cfgMin.value);
    setCookie('rm_max', cfgMax.value);
    setCookie('rm_rate', cfgRate.value);
  }

  ['input','change'].forEach(evt => {
    cfgCount.addEventListener(evt, persistInputsToCookies);
    cfgMin.addEventListener(evt, persistInputsToCookies);
    cfgMax.addEventListener(evt, persistInputsToCookies);
    cfgRate.addEventListener(evt, persistInputsToCookies);
  });

  resetTopOnStart.addEventListener('change', persistResetTopCheckbox);

  // Start from menu (NEW GAME)
  startOnlyBtn.addEventListener('click', async () => {
    persistInputsToCookies();

    const count = clamp(parseInt(cfgCount.value,10) || 9, 1, 50);
    let min = parseInt(cfgMin.value,10); if (Number.isNaN(min)) min = 0;
    let max = parseInt(cfgMax.value,10); if (Number.isNaN(max)) max = 9;
    const rate = clamp(parseInt(cfgRate.value,10) || 10, 1, 60);

    const data = await api('new_game', {
      count: String(count), min: String(min), max: String(max), rate: String(rate)
    });

    if (!data.ok) return;

    // Switch UI to game
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

  // Start (RESUME) => resets Top3 only (server does that), keeps tries/timer
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

    // Restore inputs from cookies (or session defaults)
    loadCfgFromCookiesIntoInputs();
    loadResetTopCheckbox();
    loadPillBoxesCheckbox();
    applyPillBoxesState();

    renderTop30(r.top3, r.cfg);
    applyRainbowStreaming();
  });

  // Live rate change (during game) + cookie persist
  rateInput.addEventListener('input', async () => {
    const rate = clamp(parseInt(rateInput.value,10) || 10, 1, 60);
    rateInput.value = rate;
    setCookie('rm_rate', rate); // keep last used rate for menu too
    applyRate(rate);

    await api('set_rate', { rate: String(rate) }).catch(()=>{});

    if (serverRunning && !serverWon){
      stopClientInterval();
      startClientInterval();
    }
  });

  async function init(){
    // Apply cookies to menu inputs immediately
    loadCfgFromCookiesIntoInputs();
    loadResetTopCheckbox();
    loadPillBoxesCheckbox();

    if (pillBoxesPulse){
      pillBoxesPulse.addEventListener('change', persistPillBoxesCheckbox);
    }

    // Restore R/G/B tone sliders from cookies
    const rC = getCookie(TONE_R_COOKIE);
    const gC = getCookie(TONE_G_COOKIE);
    const bC = getCookie(TONE_B_COOKIE);

    setToneValues({
      r: rC !== null ? rC : 50,
      g: gC !== null ? gC : 50,
      b: bC !== null ? bC : 50,
    });

    if (toneR){
      toneR.addEventListener('input', syncToneFromSliders);
      toneR.addEventListener('change', syncToneFromSliders);
    }
    if (toneG){
      toneG.addEventListener('input', syncToneFromSliders);
      toneG.addEventListener('change', syncToneFromSliders);
    }
    if (toneB){
      toneB.addEventListener('input', syncToneFromSliders);
      toneB.addEventListener('change', syncToneFromSliders);
    }

    const s = await api('status');
    if (!s.ok) return;

    renderTop30(s.top3, s.cfg);
    applyRainbowStreaming();

    // If already in game (running or stopped after start), show game UI.
    // We'll detect: if tries > 0 OR elapsed > 0 OR last_numbers exists, show game.
    const hasGameState = (s.last_numbers !== null) || (s.tries > 0) || (s.elapsed_ms > 0) || s.won || s.running;

    if (hasGameState){
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

      if (s.running && !s.won){
        startUiTimer();
        startClientInterval();
      } else {
        // stopped/won: freeze timer
        stopUiTimer();
        stopClientInterval();
        timerEl.textContent = formatTimerStrict(s.elapsed_ms);
      }
    } else {
      // setup screen stays
      configCard.style.display = 'block';
      numbersEl.style.display = 'none';
      metaEl.style.display = 'none';
      statusEl.style.display = 'none';
      footerEl.style.display = 'none';
    }
  }

  init().catch(()=>{});
</script>
</body>
</html>
