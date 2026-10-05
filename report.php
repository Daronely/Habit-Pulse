<?php
declare(strict_types=1);
session_start();

// ۱. اتصال به دیتابیس SQLite (db/users.sqlite)
$dbFile = __DIR__ . '/db/users.sqlite';
if (!file_exists($dbFile)) {
    die('Database not initialized yet.');
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database Error: ' . htmlspecialchars($e->getMessage()));
}

// ۲. دریافت توکن اختصاصی عادت از لینک (?habit=TOKEN)
$habitToken = trim($_GET['habit'] ?? $_GET['token'] ?? '');

$nowTime   = time();
$todayDate = date('Y-m-d');

$ownerUser   = null;
$sharedHabit = null;
$viewMode    = 'not_found';

if ($habitToken !== '') {
    $stmt = $pdo->prepare("
        SELECT h.*, u.display_name, u.username
        FROM habits h
        JOIN users u ON u.id = h.user_id
        WHERE h.share_token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $habitToken]);
    $row = $stmt->fetch();

    if ($row) {
        $sharedHabit = $row;
        $ownerUser   = [
            'id'           => (int)$row['user_id'],
            'display_name' => $row['display_name'],
            'username'     => $row['username']
        ];
        $viewMode = 'single_habit';
    }
}

// =========================================================================
// ۳. استخراج آمار کامل عادت (امروز، هفته، ماه، سال + نمودار ۱۴ روزه + تایمر)
// =========================================================================
$todayCount     = 0;
$weekTotal      = 0;
$monthTotal     = 0;
$yearTotal      = 0;
$activeDaysCount= 0;
$history14Days  = [];
$maxBarVal      = 5;
$recentLogs     = [];
$elapsedSec     = 0;
$recommendedGap = '';

if ($viewMode === 'single_habit' && $sharedHabit) {
    $hid    = (int)$sharedHabit['id'];
    $target = max(1, (int)$sharedHabit['daily_target']);

    // ۱. تعداد ثبت‌شده امروز (روزانه)
    $stmtToday = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date = :d");
    $stmtToday->execute([':hid' => $hid, ':d' => $todayDate]);
    $todayCount = (int)$stmtToday->fetchColumn();

    // ۲. مجموع ۷ روز اخیر (هفتگی)
    $date7Ago = date('Y-m-d', strtotime('-6 days'));
    $stmtWeek = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date >= :d7");
    $stmtWeek->execute([':hid' => $hid, ':d7' => $date7Ago]);
    $weekTotal = (int)$stmtWeek->fetchColumn();

    // ۳. مجموع ۳۰ روز اخیر (ماهانه)
    $date30Ago = date('Y-m-d', strtotime('-29 days'));
    $stmtMonth = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date >= :d30");
    $stmtMonth->execute([':hid' => $hid, ':d30' => $date30Ago]);
    $monthTotal = (int)$stmtMonth->fetchColumn();

    // ۴. مجموع ۳۶۵ روز اخیر (سالانه) و تعداد روزهای فعال
    $date365Ago = date('Y-m-d', strtotime('-364 days'));
    $stmtYear = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_amt, COUNT(DISTINCT log_date) AS days_cnt FROM habit_logs WHERE habit_id = :hid AND log_date >= :d365");
    $stmtYear->execute([':hid' => $hid, ':d365' => $date365Ago]);
    $yearRow = $stmtYear->fetch();
    $yearTotal       = (int)($yearRow['total_amt'] ?? 0);
    $activeDaysCount = max(1, (int)($yearRow['days_cnt'] ?? 1));

    // محاسبه زمان سپری‌شده از آخرین لغزش (برای عادت بد)
    $elapsedSec = max(0, $nowTime - (int)$sharedHabit['last_slip_at']);

    // فاصله پیشنهادی بین هر بار در بیداری (۱۶ ساعت تقسیم بر سقف روزانه)
    $gapMinutes = (int)round((16 * 60) / $target);
    $gapH       = intdiv($gapMinutes, 60);
    $gapM       = $gapMinutes % 60;
    $recommendedGap = ($gapH > 0 ? "{$gapH}h " : "") . "{$gapM}m";

    // نمودار ۱۴ روز اخیر این عادت
    $maxBarVal = max(1, $target);
    for ($i = 13; $i >= 0; $i--) {
        $dStr   = date('Y-m-d', strtotime("-$i days"));
        $dShort = date('d', strtotime("-$i days"));

        $stmtDay = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date = :d");
        $stmtDay->execute([':hid' => $hid, ':d' => $dStr]);
        $val = (int)$stmtDay->fetchColumn();

        if ($val > $maxBarVal) {
            $maxBarVal = $val;
        }

        $history14Days[] = [
            'date'     => $dStr,
            'day_num'  => $dShort,
            'count'    => $val,
            'is_today' => ($i === 0)
        ];
    }

    // لیست ۲۰ ثبت آخر با ساعت و تاریخ دقیق
    $stmtLogs = $pdo->prepare("SELECT amount, log_date, logged_at FROM habit_logs WHERE habit_id = :hid ORDER BY logged_at DESC LIMIT 20");
    $stmtLogs->execute([':hid' => $hid]);
    $recentLogs = $stmtLogs->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" class="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#07090E" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <link rel="icon" type="image/png" href="img/icon.png" />
  <link rel="apple-touch-icon" href="img/icon.png" />
  <link rel="manifest" href="manifest.json" />
  <title>HabitPulse — Live Shared Telemetry</title>

  <!-- Google Fonts: Inter (EN), Vazirmatn (FA), JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            obsidian: '#07090E',
            carbon: '#0F1420',
            slateCard: '#161D2F',
            telemetry: '#F59E0B',
          },
          fontFamily: {
            sans: ['Inter', 'Vazirmatn', 'system-ui', 'sans-serif'],
            mono: ['JetBrains Mono', 'monospace'],
          }
        }
      }
    }
  </script>

  <style>
    * { -webkit-tap-highlight-color: transparent; user-select: none; box-sizing: border-box; }
    html, body {
      background-color: #07090E;
      color: #F8FAFC;
      overflow-x: hidden;
      font-family: 'Inter', 'Vazirmatn', sans-serif;
    }
    @media (min-width: 640px) {
      html, body { background-color: #04060A; }
    }
    body.lang-fa .i18n-text {
      font-family: 'Vazirmatn', 'Inter', sans-serif;
      letter-spacing: 0 !important;
    }
    .text-lock {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .tabular-timer {
      font-family: 'JetBrains Mono', monospace;
      font-variant-numeric: tabular-nums;
    }
    .tactical-card {
      background: linear-gradient(180deg, rgba(22, 29, 47, 0.88) 0%, rgba(15, 20, 32, 0.96) 100%);
      border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
  </style>
</head>

<body class="min-h-[100dvh] flex flex-col items-center justify-center sm:py-5">

  <!-- ===================================================================== -->
  <!-- ANDROID SHELL: Fullscreen on Mobile | Framed ONLY on PC               -->
  <!-- ===================================================================== -->
  <div class="relative w-full min-h-[100dvh] sm:min-h-[840px] sm:max-h-[880px] sm:max-w-[420px] bg-obsidian sm:rounded-[38px] sm:border-[6px] sm:border-[#1E2638] sm:shadow-[0_25px_70px_rgba(0,0,0,0.85)] flex flex-col justify-between overflow-hidden">

    <!-- Ambient Top Glow -->
    <div class="pointer-events-none absolute -top-24 left-1/2 -translate-x-1/2 w-80 h-56 rounded-full <?php echo ($sharedHabit && $sharedHabit['type'] === 'bad') ? 'bg-rose-500/10' : 'bg-emerald-500/10'; ?> blur-3xl"></div>

    <!-- ================================================================= -->
    <!-- 1. STICKY TOP HEADER                                              -->
    <!-- ================================================================= -->
    <header class="relative z-20 pt-3 sm:pt-2 px-4 pb-3 border-b border-white/[0.07] bg-obsidian/90 backdrop-blur-md shrink-0">
      <!-- PC-ONLY Status Bar -->
      <div class="hidden sm:flex items-center justify-between text-[11px] text-slate-400 font-mono mb-2 px-0.5">
        <span id="pcClock">22:14</span>
        <div class="flex items-center gap-1.5">
          <span class="text-[10px] text-emerald-400 font-semibold">LIVE</span>
          <svg class="w-3.5 h-3.5 text-slate-300" fill="currentColor" viewBox="0 0 24 24"><path d="M2 22h20V2z"/></svg>
          <svg class="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M15.67 4H14V2h-4v2H8.33C7.6 4 7 4.6 7 5.33v15.33C7 21.4 7.6 22 8.33 22h7.33c.74 0 1.34-.6 1.34-1.33V5.33C17 4.6 16.4 4 15.67 4z"/></svg>
        </div>
      </div>

      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2.5 min-w-0">
          <img src="img/icon.png" alt="HabitPulse" class="w-9 h-9 rounded-xl object-cover border border-white/10 bg-carbon shrink-0" />
          <div class="min-w-0">
            <div class="flex items-center gap-1.5">
              <span class="font-bold text-[14px] text-white text-lock">HabitPulse</span>
              <span class="flex items-center gap-1 text-[10px] font-mono px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                <span class="i18n-text" data-i18n="badge_live">LIVE REPORT</span>
              </span>
            </div>
            <?php if ($ownerUser): ?>
              <p class="text-[11px] text-slate-400 text-lock">
                <span class="i18n-text" data-i18n="shared_by">Shared by</span>
                <strong class="text-slate-200"><?php echo htmlspecialchars($ownerUser['display_name']); ?></strong>
                <span class="font-mono text-[10px] text-slate-500">(@<?php echo htmlspecialchars($ownerUser['username']); ?>)</span>
              </p>
            <?php else: ?>
              <p class="text-[11px] text-slate-400 text-lock i18n-text" data-i18n="hdr_telemetry">Public Telemetry Link</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Language Switcher (EN / FA) -->
        <button type="button" onclick="toggleLanguage()" class="h-9 px-2.5 rounded-xl bg-carbon border border-white/10 active:scale-95 transition flex items-center gap-1 text-[11px] font-mono shrink-0">
          <span id="langBadgeEN" class="px-1 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold">EN</span>
          <span class="text-slate-600">/</span>
          <span id="langBadgeFA" class="px-1 py-0.5 rounded text-slate-400">FA</span>
        </button>
      </div>
    </header>

    <!-- ================================================================= -->
    <!-- 2. MAIN CONTENT AREA                                              -->
    <!-- ================================================================= -->
    <main class="relative z-10 flex-1 overflow-y-auto no-scrollbar px-4 py-4 space-y-4">

      <?php if ($viewMode !== 'single_habit' || !$sharedHabit): ?>
        <!-- ============================================================= -->
        <!-- STATE A: INVALID OR REVOKED SHARE TOKEN                       -->
        <!-- ============================================================= -->
        <div class="tactical-card rounded-2xl p-6 text-center my-8 space-y-3 border-rose-500/30">
          <div class="w-14 h-14 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-rose-400 flex items-center justify-center mx-auto text-2xl">
            🔒
          </div>
          <h1 class="text-[16px] font-extrabold text-white text-lock i18n-text" data-i18n="nf_title">
            Link Not Found or Revoked
          </h1>
          <p class="text-[12px] text-slate-400 leading-relaxed i18n-text" data-i18n="nf_desc">
            This habit telemetry link is invalid, or its owner has generated a new security token.
          </p>
          <div class="pt-2">
            <a href="index.php" class="inline-flex items-center justify-center h-10 px-5 rounded-xl bg-emerald-500 text-obsidian font-extrabold text-[12px] text-lock i18n-text" data-i18n="btn_open_app">
              Open HabitPulse App
            </a>
          </div>
        </div>

      <?php else: ?>
        <!-- ============================================================= -->
        <!-- STATE B: FULL TELEMETRY FOR THE SHARED HABIT                  -->
        <!-- ============================================================= -->
        <?php
          $isBad    = ($sharedHabit['type'] === 'bad');
          $target   = max(1, (int)$sharedHabit['daily_target']);
          $base     = max($target, (int)$sharedHabit['baseline']);
          $pctToday = min(100, (int)round(($todayCount / $target) * 100));
          $overLim  = ($isBad && $todayCount > $target);
        ?>

        <!-- 1. HERO HABIT IDENTITY & LIVE STATUS CARD -->
        <div class="tactical-card rounded-2xl p-4 <?php echo $isBad ? 'border-rose-500/30' : 'border-emerald-500/30'; ?> space-y-3.5">
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-3 min-w-0">
              <div class="w-12 h-12 rounded-2xl <?php echo $isBad ? 'bg-rose-500/15 border-rose-500/30' : 'bg-emerald-500/15 border-emerald-500/30'; ?> border flex items-center justify-center text-2xl shrink-0">
                <?php echo htmlspecialchars($sharedHabit['icon']); ?>
              </div>
              <div class="min-w-0">
                <span class="inline-block text-[10px] font-bold uppercase px-2 py-0.5 rounded-md mb-1 <?php echo $isBad ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/20 text-emerald-300'; ?> i18n-text" data-i18n="<?php echo $isBad ? 'badge_bad_habit' : 'badge_good_habit'; ?>">
                  <?php echo $isBad ? '🔴 Bad Habit • Quit System' : '🟢 Good Habit • Daily Growth'; ?>
                </span>
                <h1 class="text-[18px] font-extrabold text-white text-lock">
                  <?php echo htmlspecialchars($sharedHabit['title']); ?>
                </h1>
              </div>
            </div>
          </div>

          <?php if ($isBad): ?>
            <!-- LIVE CLEAN TIMER BOX (For Bad Habits) -->
            <div class="rounded-2xl bg-obsidian border border-white/[0.08] p-3.5 text-center space-y-1">
              <div class="text-[11px] text-slate-400 font-semibold text-lock i18n-text" data-i18n="lbl_time_since_slip">
                LIVE TIME SINCE LAST SLIP
              </div>
              <div id="publicLiveTimer" data-elapsed="<?php echo $elapsedSec; ?>" class="tabular-timer text-[24px] font-extrabold text-telemetry tracking-wider py-0.5">
                00h : 00m : 00s
              </div>
              <div class="flex items-center justify-center gap-2 text-[11px]">
                <span class="px-2 py-0.5 rounded-md font-semibold <?php echo $overLim ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/15 text-emerald-300'; ?> i18n-text" data-i18n="<?php echo $overLim ? 'status_over_limit' : 'status_on_track'; ?>">
                  <?php echo $overLim ? 'Warning • Over Daily Limit' : 'On Track • Within Daily Limit'; ?>
                </span>
              </div>
            </div>

            <!-- Today's Slips vs Daily Limit Progress -->
            <div class="space-y-1.5">
              <div class="flex items-center justify-between text-[12px]">
                <span class="text-slate-300 font-semibold text-lock i18n-text" data-i18n="lbl_today_slips">Today's Slips:</span>
                <span class="font-mono font-bold <?php echo $overLim ? 'text-rose-400' : 'text-white'; ?>">
                  <?php echo $todayCount; ?> / <?php echo $target . ' ' . htmlspecialchars($sharedHabit['unit']); ?>
                </span>
              </div>
              <div class="w-full h-2.5 rounded-full bg-obsidian overflow-hidden border border-white/[0.08]">
                <div class="h-full bg-gradient-to-r from-rose-600 to-rose-400 transition-all" style="width: <?php echo $pctToday; ?>%"></div>
              </div>
              <div class="flex items-center justify-between text-[10px] text-slate-400 pt-0.5">
                <span><span class="i18n-text" data-i18n="lbl_rec_gap">Recommended Gap:</span> <strong class="font-mono text-slate-200"><?php echo $recommendedGap; ?></strong></span>
                <span><span class="i18n-text" data-i18n="lbl_baseline">Baseline:</span> <strong class="font-mono text-slate-200"><?php echo $base; ?>/day</strong></span>
              </div>
            </div>

          <?php else: ?>
            <!-- GOOD HABIT PROGRESS BOX -->
            <div class="rounded-2xl bg-obsidian border border-white/[0.08] p-3.5 space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-[12px] text-slate-300 font-semibold text-lock i18n-text" data-i18n="lbl_today_progress">Today's Goal Progress:</span>
                <span class="font-mono text-[15px] font-extrabold text-emerald-400">
                  <?php echo $todayCount; ?> / <?php echo $target . ' ' . htmlspecialchars($sharedHabit['unit']); ?> (<?php echo $pctToday; ?>%)
                </span>
              </div>
              <div class="w-full h-3 rounded-full bg-carbon overflow-hidden border border-white/[0.08]">
                <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-400 transition-all" style="width: <?php echo $pctToday; ?>%"></div>
              </div>
              <div class="flex items-center justify-between text-[11px] text-slate-400 pt-0.5">
                <span class="i18n-text" data-i18n="<?php echo $todayCount >= $target ? 'status_goal_hit' : 'status_in_progress'; ?>">
                  <?php echo $todayCount >= $target ? '✅ Daily Target Completed!' : '⚡ In Progress Today'; ?>
                </span>
                <span class="font-mono text-emerald-300">🔥 <?php echo $activeDaysCount; ?>d</span>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- 2. FOUR-CARD MULTI-TIMEFRAME SUMMARY (Day | Week | Month | Year) -->
        <div class="grid grid-cols-4 gap-2">
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="stat_today">Today</div>
            <div class="font-mono text-[15px] font-bold text-white mt-0.5"><?php echo $todayCount; ?></div>
          </div>
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="stat_week">This Week</div>
            <div class="font-mono text-[15px] font-bold text-emerald-400 mt-0.5"><?php echo $weekTotal; ?></div>
          </div>
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="stat_month">This Month</div>
            <div class="font-mono text-[15px] font-bold text-amber-400 mt-0.5"><?php echo $monthTotal; ?></div>
          </div>
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="stat_year">This Year</div>
            <div class="font-mono text-[15px] font-bold text-sky-400 mt-0.5"><?php echo $yearTotal; ?></div>
          </div>
        </div>

        <!-- 3. 14-DAY HISTORY BAR CHART FOR THIS HABIT -->
        <div class="tactical-card rounded-2xl p-3.5 space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-[13px] font-bold text-white text-lock i18n-text" data-i18n="chart_14d_title">14-Day Activity History</h2>
              <p class="text-[10px] text-slate-400 text-lock">
                <span class="i18n-text" data-i18n="lbl_target_line">Daily Target / Limit:</span>
                <strong class="font-mono text-slate-200"><?php echo $target . ' ' . htmlspecialchars($sharedHabit['unit']); ?></strong>
              </p>
            </div>
            <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-white/5 text-slate-300">14 DAYS</span>
          </div>

          <div class="grid gap-1 items-end h-28 pt-3 px-0.5 border-b border-white/[0.07]" style="grid-template-columns: repeat(14, minmax(0, 1fr));">
            <?php foreach ($history14Days as $day):
              $barH = max(6, (int)round(($day['count'] / $maxBarVal) * 76));
              if ($day['count'] === 0) {
                  $barColor = 'bg-white/10';
              } elseif ($isBad) {
                  $barColor = ($day['count'] > $target) ? 'bg-rose-500' : 'bg-amber-400';
              } else {
                  $barColor = ($day['count'] >= $target) ? 'bg-emerald-400' : 'bg-emerald-600/70';
              }
            ?>
              <div class="flex flex-col items-center justify-end h-full">
                <span class="text-[9px] font-mono text-slate-400 mb-1"><?php echo $day['count'] > 0 ? $day['count'] : ''; ?></span>
                <div title="<?php echo $day['date'] . ': ' . $day['count']; ?>"
                     style="height: <?php echo $barH; ?>px"
                     class="w-2.5 rounded-t <?php echo $barColor; ?> transition-all"></div>
                <span class="text-[9px] font-mono mt-1 <?php echo $day['is_today'] ? 'text-emerald-400 font-bold' : 'text-slate-500'; ?>">
                  <?php echo htmlspecialchars($day['day_num']); ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 4. CHRONOLOGICAL LOG TIMESTAMPS (RECENT RECORDS) -->
        <div class="tactical-card rounded-2xl p-3.5 space-y-2.5">
          <div class="flex items-center justify-between">
            <h3 class="text-[13px] font-bold text-white text-lock i18n-text" data-i18n="logs_hdr_title">
              Recent Recorded Timestamps
            </h3>
            <span class="text-[10px] font-mono text-slate-400"><?php echo count($recentLogs); ?></span>
          </div>

          <?php if (empty($recentLogs)): ?>
            <p class="text-[12px] text-slate-400 py-3 text-center i18n-text" data-i18n="logs_empty">
              No logs recorded for this habit yet.
            </p>
          <?php else: ?>
            <div class="space-y-1.5 max-h-56 overflow-y-auto no-scrollbar pr-0.5">
              <?php foreach ($recentLogs as$lg): ?>
                <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-obsidian border border-white/[0.06] text-[11px]">
                  <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full <?php echo $isBad ? 'bg-rose-500' : 'bg-emerald-400'; ?>"></span>
                    <span class="font-mono text-slate-300"><?php echo htmlspecialchars($lg['log_date']); ?></span>
                  </div>
                  <div class="flex items-center gap-3 font-mono">
                    <span class="text-slate-400"><?php echo date('H:i:s', (int)$lg['logged_at']); ?></span>
                    <span class="px-1.5 py-0.5 rounded <?php echo $isBad ? 'bg-rose-500/15 text-rose-300' : 'bg-emerald-500/15 text-emerald-300'; ?> font-bold">
                      +<?php echo (int)$lg['amount'] . ' ' . htmlspecialchars($sharedHabit['unit']); ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>

    </main>

    <!-- ================================================================= -->
    <!-- 3. BOTTOM FOOTER BAR (Read-Only Notice + Back to App)             -->
    <!-- ================================================================= -->
    <footer class="relative z-20 px-4 pt-2.5 pb-3 border-t border-white/[0.08] bg-obsidian/95 backdrop-blur-md shrink-0">
      <div class="flex items-center justify-between gap-2">
        <span class="text-[11px] text-slate-400 text-lock i18n-text" data-i18n="footer_readonly">
          🔒 Read-Only Live Telemetry View
        </span>
        <a href="<?php echo isset($_SESSION['user_id']) ? 'main.php' : 'index.php'; ?>" class="h-9 px-3.5 rounded-xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 font-bold text-[11px] flex items-center justify-center shrink-0 text-lock i18n-text" data-i18n="btn_my_dashboard">
          <?php echo isset($_SESSION['user_id']) ? 'Back to My Dashboard' : 'Get HabitPulse'; ?>
        </a>
      </div>
      <!-- PC-ONLY Android Home Indicator Line -->
      <div class="hidden sm:block w-32 h-1 bg-white/20 rounded-full mx-auto mt-2.5"></div>
    </footer>

  </div>

  <!-- ===================================================================== -->
  <!-- JAVASCRIPT: LIVE TIMER & BILINGUAL TRANSLATION                        -->
  <!-- ===================================================================== -->
  <script>
    const i18n = {
      en: {
        badge_live: "LIVE REPORT",
        shared_by: "Shared by",
        hdr_telemetry: "Public Telemetry Link",
        nf_title: "Link Not Found or Revoked",
        nf_desc: "This habit telemetry link is invalid, or its owner has generated a new security token.",
        btn_open_app: "Open HabitPulse App",
        badge_bad_habit: "🔴 Bad Habit • Quit System",
        badge_good_habit: "🟢 Good Habit • Daily Growth",
        lbl_time_since_slip: "LIVE TIME SINCE LAST SLIP",
        status_over_limit: "Warning • Over Daily Limit",
        status_on_track: "On Track • Within Daily Limit",
        lbl_today_slips: "Today's Slips:",
        lbl_rec_gap: "Recommended Gap:",
        lbl_baseline: "Baseline:",
        lbl_today_progress: "Today's Goal Progress:",
        status_goal_hit: "✅ Daily Target Completed!",
        status_in_progress: "⚡ In Progress Today",
        stat_today: "Today",
        stat_week: "This Week",
        stat_month: "This Month",
        stat_year: "This Year",
        chart_14d_title: "14-Day Activity History",
        lbl_target_line: "Daily Target / Limit:",
        logs_hdr_title: "Recent Recorded Timestamps",
        logs_empty: "No logs recorded for this habit yet.",
        footer_readonly: "🔒 Read-Only Live Telemetry View",
        btn_my_dashboard: "<?php echo isset($_SESSION['user_id']) ? 'Back to My Dashboard' : 'Get HabitPulse'; ?>"
      },
      fa: {
        badge_live: "گزارش زنده",
        shared_by: "اشتراک‌گذاری توسط",
        hdr_telemetry: "لینک عمومی آمار عادت",
        nf_title: "لینک پیدا نشد یا منقضی شده است",
        nf_desc: "این لینک نامعتبر است یا صاحب حساب توکن امنیتی آن را تغییر داده است.",
        btn_open_app: "ورود به برنامه HabitPulse",
        badge_bad_habit: "🔴 عادت بد • سیستم ترک تدریجی",
        badge_good_habit: "🟢 عادت خوب • رشد روزانه",
        lbl_time_since_slip: "مدت زمان پاک بودن (تایمر زنده)",
        status_over_limit: "هشدار • عبور از سقف مجاز امروز",
        status_on_track: "عالی • زیر سقف مجاز روزانه",
        lbl_today_slips: "تعداد لغزش امروز:",
        lbl_rec_gap: "فاصله پیشنهادی:",
        lbl_baseline: "میزان اولیه:",
        lbl_today_progress: "پیشرفت هدف امروز:",
        status_goal_hit: "✅ هدف امروز کامل شد!",
        status_in_progress: "⚡ در حال انجام در روز جاری",
        stat_today: "امروز",
        stat_week: "این هفته",
        stat_month: "این ماه",
        stat_year: "امسال",
        chart_14d_title: "نمودار عملکرد ۱۴ روز گذشته",
        lbl_target_line: "هدف / سقف روزانه:",
        logs_hdr_title: "لیست دقیق آخرین زمان‌های ثبت‌شده",
        logs_empty: "هنوز رکوردی برای این عادت ثبت نشده است.",
        footer_readonly: "🔒 نمای زنده و فقط‌خواندنی (Read-Only)",
        btn_my_dashboard: "<?php echo isset($_SESSION['user_id']) ? 'بازگشت به داشبورد من' : 'ورود به HabitPulse'; ?>"
      }
    };

    let currentLang = localStorage.getItem('habitpulse_lang') || 'en';

    function applyLanguage(lang) {
      currentLang = lang;
      localStorage.setItem('habitpulse_lang', lang);
      document.body.classList.toggle('lang-fa', lang === 'fa');

      const badgeEN = document.getElementById('langBadgeEN');
      const badgeFA = document.getElementById('langBadgeFA');
      if (lang === 'fa') {
        badgeFA.className = 'px-1 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
        badgeEN.className = 'px-1 py-0.5 rounded text-slate-400';
      } else {
        badgeEN.className = 'px-1 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
        badgeFA.className = 'px-1 py-0.5 rounded text-slate-400';
      }

      document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (i18n[lang] && i18n[lang][key]) {
          el.textContent = i18n[lang][key];
        }
      });
    }

    function toggleLanguage() {
      applyLanguage(currentLang === 'en' ? 'fa' : 'en');
    }

    function formatElapsed(sec) {
      const hrs  = String(Math.floor(sec / 3600)).padStart(2, '0');
      const mins = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
      const secs = String(sec % 60).padStart(2, '0');
      return `${hrs}h : ${mins}m : ${secs}s`;
    }

    document.addEventListener('DOMContentLoaded', () => {
      applyLanguage(currentLang);

      const timerEl = document.getElementById('publicLiveTimer');
      if (timerEl) {
        let elapsed = parseInt(timerEl.getAttribute('data-elapsed') || '0', 10);
        timerEl.textContent = formatElapsed(elapsed);
        setInterval(() => {
          elapsed++;
          timerEl.textContent = formatElapsed(elapsed);
        }, 1000);
      }

      const pcClock = document.getElementById('pcClock');
      if (pcClock) {
        const now = new Date();
        pcClock.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
      }
    });
  </script>
</body>
</html>