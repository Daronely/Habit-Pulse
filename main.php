<?php
declare(strict_types=1);
session_start();

// ۱. بررسی لاگین بودن کاربر؛ در غیر این صورت هدایت به index.php
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// ۲. اتصال به دیتابیس SQLite و ساخت/بررسی جداول
$dbDir  = __DIR__ . '/db';
$dbFile = $dbDir . '/users.sqlite';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // جدول عادت‌ها
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS habits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('good', 'bad')),
            icon TEXT NOT NULL DEFAULT '⚡',
            unit TEXT NOT NULL DEFAULT 'Times',
            daily_target INTEGER NOT NULL DEFAULT 1,
            baseline INTEGER NOT NULL DEFAULT 0,
            last_slip_at INTEGER NOT NULL DEFAULT 0,
            share_token TEXT NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL
        )
    ");

    // جدول لاگ‌های ثبت عادت
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS habit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            habit_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            amount INTEGER NOT NULL DEFAULT 1,
            log_date TEXT NOT NULL,
            logged_at INTEGER NOT NULL
        )
    ");
} catch (PDOException $e) {
    die('Database Error: ' . htmlspecialchars($e->getMessage()));
}

// دریافت اطلاعات کاربر فعلی
$stmtUser = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmtUser->execute([':id' => $userId]);
$currentUser = $stmtUser->fetch();

if (!$currentUser) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// وضعیت قفل ۴ رقمی در سشن
if (!isset($_SESSION['pin_unlocked'])) {
    $_SESSION['pin_unlocked'] = false;
}

$todayDate = date('Y-m-d');
$nowTime   = time();

// =========================================================================
// ۳. پردازش درخواست‌های AJAX (بررسی پین، قفل، ثبت عادت)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'];

    // الف) تایید پین ۴ رقمی
    if ($action === 'verify_pin') {
        $pin = trim($_POST['pin'] ?? '');
        if (password_verify($pin, $currentUser['fast_pin'])) {
            $_SESSION['pin_unlocked'] = true;
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'invalid_pin']);
        }
        exit;
    }

    // ب) قفل دستی برنامه
    if ($action === 'lock_app') {
        $_SESSION['pin_unlocked'] = false;
        echo json_encode(['ok' => true]);
        exit;
    }

    // ج) ثبت عادت خوب (+1) یا ثبت لغزش عادت بد (1.0s Hold)
    if ($action === 'log_habit') {
        $habitId = (int)($_POST['habit_id'] ?? 0);
        $stmtH = $pdo->prepare('SELECT * FROM habits WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmtH->execute([':id' => $habitId, ':uid' => $userId]);
        $habit = $stmtH->fetch();

        if (!$habit) {
            echo json_encode(['ok' => false]);
            exit;
        }

        $pdo->prepare('INSERT INTO habit_logs (habit_id, user_id, amount, log_date, logged_at) VALUES (:hid, :uid, 1, :ldate, :lat)')
            ->execute([
                ':hid'   => $habitId,
                ':uid'   => $userId,
                ':ldate' => $todayDate,
                ':lat'   => $nowTime
            ]);

        if ($habit['type'] === 'bad') {
            $pdo->prepare('UPDATE habits SET last_slip_at = :now WHERE id = :id')
                ->execute([':now' => $nowTime, ':id' => $habitId]);
        }

        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date = :ldate');
        $sumStmt->execute([':hid' => $habitId, ':ldate' => $todayDate]);
        $todayCount = (int)$sumStmt->fetchColumn();

        echo json_encode([
            'ok'          => true,
            'today_count' => $todayCount,
            'target'      => (int)$habit['daily_target'],
            'type'        => $habit['type']
        ]);
        exit;
    }

    echo json_encode(['ok' => false]);
    exit;
}

// =========================================================================
// ۴. پردازش فرم‌های معمولی (افزودن، حذف، تغییر لینک عادت، تنظیمات، خروج)
// =========================================================================
$flashMsg      = '';
$flashType     = 'success';
$openTabOnLoad = 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $fAction = $_POST['form_action'];

    // ۱. خروج از حساب
    if ($fAction === 'logout') {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }

    // ۲. افزودن عادت جدید
    if ($fAction === 'add_habit') {
        $title  = trim($_POST['title'] ?? '');
        $type   = ($_POST['type'] ?? 'good') === 'bad' ? 'bad' : 'good';
        $icon   = trim($_POST['icon'] ?? ($type === 'good' ? '💧' : '🚬'));
        $unit   = trim($_POST['unit'] ?? ($type === 'good' ? 'Times' : 'Slips'));
        $target = max(1, (int)($_POST['daily_target'] ?? 5));
        $base   = max($target, (int)($_POST['baseline'] ?? ($target * 2)));

        if ($title !== '') {
            $token = bin2hex(random_bytes(8));
            $pdo->prepare("
                INSERT INTO habits (user_id, title, type, icon, unit, daily_target, baseline, last_slip_at, share_token, is_public, created_at)
                VALUES (:uid, :title, :type, :icon, :unit, :target, :base, :slip, :token, 1, :cat)
            ")->execute([
                ':uid'    => $userId,
                ':title'  => $title,
                ':type'   => $type,
                ':icon'   => $icon !== '' ? $icon : '⚡',
                ':unit'   => $unit !== '' ? $unit : 'Unit',
                ':target' => $target,
                ':base'   => $base,
                ':slip'   => $nowTime - 7200,
                ':token'  => $token,
                ':cat'    => $nowTime
            ]);
            $flashMsg = 'msg_habit_added';
        }
    }

    // ۳. افزودن سریع عادت‌های نمونه
    if ($fAction === 'seed_presets') {
        $presets = [
            ['title' => 'Smoking Reduction', 'type' => 'bad',  'icon' => '🚬', 'unit' => 'Cigs',    'target' => 8, 'base' => 15, 'slip' => $nowTime - 8140],
            ['title' => 'No Late Sugar',     'type' => 'bad',  'icon' => '🍬', 'unit' => 'Sweets',  'target' => 2, 'base' => 5,  'slip' => $nowTime - 19400],
            ['title' => 'Daily Hydration',   'type' => 'good', 'icon' => '💧', 'unit' => 'Glasses', 'target' => 8, 'base' => 8,  'slip' => 0],
            ['title' => 'Deep Reading',      'type' => 'good', 'icon' => '📚', 'unit' => 'Pages',   'target' => 20,'base' => 20, 'slip' => 0],
        ];
        $ins = $pdo->prepare("
            INSERT INTO habits (user_id, title, type, icon, unit, daily_target, baseline, last_slip_at, share_token, is_public, created_at)
            VALUES (:uid, :title, :type, :icon, :unit, :target, :base, :slip, :token, 1, :cat)
        ");
        foreach ($presets as $p) {
            $ins->execute([
                ':uid'    => $userId,
                ':title'  => $p['title'],
                ':type'   => $p['type'],
                ':icon'   => $p['icon'],
                ':unit'   => $p['unit'],
                ':target' => $p['target'],
                ':base'   => $p['base'],
                ':slip'   => $p['slip'],
                ':token'  => bin2hex(random_bytes(8)),
                ':cat'    => $nowTime
            ]);
        }
        $flashMsg = 'msg_presets_added';
    }

    // ۴. حذف عادت
    if ($fAction === 'delete_habit') {
        $hid = (int)($_POST['habit_id'] ?? 0);
        $pdo->prepare('DELETE FROM habits WHERE id = :id AND user_id = :uid')->execute([':id' => $hid, ':uid' => $userId]);
        $pdo->prepare('DELETE FROM habit_logs WHERE habit_id = :id AND user_id = :uid')->execute([':id' => $hid, ':uid' => $userId]);
        $flashMsg = 'msg_habit_deleted';
    }

    // ۵. ساخت توکن جدید برای لینک اختصاصی یک عادت (ابطال لینک قبلی)
    if ($fAction === 'regenerate_habit_token') {
        $openTabOnLoad = 'share';
        $hid = (int)($_POST['habit_id'] ?? 0);
        $newToken = bin2hex(random_bytes(8));
        $pdo->prepare('UPDATE habits SET share_token = :t WHERE id = :id AND user_id = :uid')
            ->execute([':t' => $newToken, ':id' => $hid, ':uid' => $userId]);
        $flashMsg = 'msg_token_regenerated';
    }

    // ۶. بروزرسانی نام نمایشی و یوزرنیم
    if ($fAction === 'update_profile') {
        $openTabOnLoad = 'settings';
        $newDisplay = trim($_POST['display_name'] ?? '');
        $newUser    = strtolower(trim($_POST['username'] ?? ''));

        if ($newDisplay === '' || !preg_match('/^[a-z0-9_]{3,24}$/', $newUser)) {
            $flashMsg  = 'err_invalid_profile';
            $flashType = 'error';
        } else {
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = :u AND id != :id LIMIT 1');
            $chk->execute([':u' => $newUser, ':id' => $userId]);
            if ($chk->fetch()) {
                $flashMsg  = 'err_username_taken';
                $flashType = 'error';
            } else {
                $pdo->prepare('UPDATE users SET display_name = :d, username = :u WHERE id = :id')
                    ->execute([':d' => $newDisplay, ':u' => $newUser, ':id' => $userId]);
                $_SESSION['display_name']    = $newDisplay;
                $_SESSION['username']        = $newUser;
                $currentUser['display_name'] = $newDisplay;
                $currentUser['username']     = $newUser;
                $flashMsg = 'msg_profile_saved';
            }
        }
    }

    // ۷. تغییر رمز عبور و پین ۴ رقمی
    if ($fAction === 'update_security') {
        $openTabOnLoad = 'settings';
        $newPass = $_POST['new_password'] ?? '';
        $newPin  = trim($_POST['new_pin'] ?? '');

        $updatedAny = false;
        if ($newPass !== '') {
            if (strlen($newPass) < 4) {
                $flashMsg  = 'err_short_password';
                $flashType = 'error';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                    ->execute([':p' => password_hash($newPass, PASSWORD_DEFAULT), ':id' => $userId]);
                $updatedAny = true;
            }
        }
        if ($newPin !== '' && $flashType !== 'error') {
            if (!preg_match('/^\d{4}$/', $newPin)) {
                $flashMsg  = 'err_invalid_pin';
                $flashType = 'error';
            } else {
                $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET fast_pin = :pin WHERE id = :id')
                    ->execute([':pin' => $pinHash, ':id' => $userId]);
                $currentUser['fast_pin'] = $pinHash;
                $updatedAny = true;
            }
        }
        if ($updatedAny && $flashType !== 'error') {
            $flashMsg = 'msg_security_saved';
        }
    }
}

// =========================================================================
// ۵. واکشی عادت‌ها + آمار کامل تاریخچه (روز، هفته، ماه، سال)
// =========================================================================
$stmtHabits = $pdo->prepare('SELECT * FROM habits WHERE user_id = :uid ORDER BY type DESC, id DESC');
$stmtHabits->execute([':uid' => $userId]);
$habits = $stmtHabits->fetchAll();

$weekStart  = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-d', strtotime('-29 days'));
$yearStart  = date('Y-m-d', strtotime('-364 days'));

$goodHabitsCount = 0;
$badHabitsCount  = 0;
$todayGoodDone   = 0;
$todayGoodTarget = 0;
$todayBadSlips   = 0;
$todayBadLimit   = 0;

foreach ($habits as &$h) {
    $hid = (int)$h['id'];

    // آمار امروز
    $sToday = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date = :d');
    $sToday->execute([':hid' => $hid, ':d' => $todayDate]);
    $h['today_count'] = (int)$sToday->fetchColumn();

    // آمار ۷ روز اخیر (هفته)
    $sWeek = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date >= :d');
    $sWeek->execute([':hid' => $hid, ':d' => $weekStart]);
    $h['week_count'] = (int)$sWeek->fetchColumn();

    // آمار ۳۰ روز اخیر (ماه)
    $sMonth = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date >= :d');
    $sMonth->execute([':hid' => $hid, ':d' => $monthStart]);
    $h['month_count'] = (int)$sMonth->fetchColumn();

    // آمار ۳۶۵ روز اخیر (سال)
    $sYear = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM habit_logs WHERE habit_id = :hid AND log_date >= :d');
    $sYear->execute([':hid' => $hid, ':d' => $yearStart]);
    $h['year_count'] = (int)$sYear->fetchColumn();

    if ($h['type'] === 'good') {
        $goodHabitsCount++;
        $todayGoodDone   += min($h['today_count'], (int)$h['daily_target']);
        $todayGoodTarget += (int)$h['daily_target'];
    } else {
        $badHabitsCount++;
        $todayBadSlips += $h['today_count'];
        $todayBadLimit += (int)$h['daily_target'];
    }
}
unset($h);

$goodScorePct = $todayGoodTarget > 0 ? (int)round(($todayGoodDone / $todayGoodTarget) * 100) : 0;

// نمودار ۷ روزه وسط داشبورد
$chartDays = [];
$maxChartVal = 5;
for ($i = 6; $i >= 0; $i--) {
    $dStr   = date('Y-m-d', strtotime("-$i days"));
    $dLabel = date('D', strtotime("-$i days"));

    $qGood = $pdo->prepare("
        SELECT COALESCE(SUM(l.amount), 0)
        FROM habit_logs l JOIN habits h ON h.id = l.habit_id
        WHERE l.user_id = :uid AND l.log_date = :d AND h.type = 'good'
    ");
    $qGood->execute([':uid' => $userId, ':d' => $dStr]);
    $gVal = (int)$qGood->fetchColumn();

    $qBad = $pdo->prepare("
        SELECT COALESCE(SUM(l.amount), 0)
        FROM habit_logs l JOIN habits h ON h.id = l.habit_id
        WHERE l.user_id = :uid AND l.log_date = :d AND h.type = 'bad'
    ");
    $qBad->execute([':uid' => $userId, ':d' => $dStr]);
    $bVal = (int)$qBad->fetchColumn();

    if ($gVal > $maxChartVal) $maxChartVal = $gVal;
    if ($bVal > $maxChartVal) $maxChartVal = $bVal;

    $chartDays[] = [
        'date'    => $dStr,
        'label'   => $dLabel,
        'good'    => $gVal,
        'bad'     => $bVal,
        'isToday' => ($i === 0)
    ];
}

// =========================================================================
// ۶. آماده‌سازی داده‌های کامل تب «تاریخچه عادت‌ها» (روز، هفته، ماه، سال)
// =========================================================================
$stmtAllLogs = $pdo->prepare("
    SELECT l.habit_id, l.amount, l.log_date, l.logged_at, h.title, h.icon, h.type, h.unit
    FROM habit_logs l
    JOIN habits h ON h.id = l.habit_id
    WHERE l.user_id = :uid AND l.log_date >= :ystart
    ORDER BY l.logged_at DESC
");
$stmtAllLogs->execute([':uid' => $userId, ':ystart' => $yearStart]);
$allLogsRaw = $stmtAllLogs->fetchAll();

// ساختارهای زمانی برای جاوااسکریپت (تغییر آنی بین روز / هفته / ماه / سال و فیلتر هر عادت)
$dayBlocksLabels = ['00-04', '04-08', '08-12', '12-16', '16-20', '20-24'];
$weekDates = [];
for ($i = 6; $i >= 0; $i--) {
    $weekDates[date('Y-m-d', strtotime("-$i days"))] = date('D d', strtotime("-$i days"));
}
$monthDates = [];
for ($i = 29; $i >= 0; $i--) {
    $monthDates[date('Y-m-d', strtotime("-$i days"))] = date('m/d', strtotime("-$i days"));
}
$yearMonths = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i months"));
    $yearMonths[$ym] = date('M', strtotime("first day of -$i months"));
}

$historyLogsFormatted = [];
foreach ($allLogsRaw as $r) {
    $ts = (int)$r['logged_at'];
    $historyLogsFormatted[] = [
        'habit_id' => (int)$r['habit_id'],
        'title'    => $r['title'],
        'icon'     => $r['icon'],
        'type'     => $r['type'],
        'unit'     => $r['unit'],
        'amount'   => (int)$r['amount'],
        'date'     => $r['log_date'],
        'month'    => substr($r['log_date'], 0, 7),
        'hour'     => (int)date('G', $ts),
        'time_str' => date('H:i', $ts)
    ];
}

// آدرس پایه برای لینک اختصاصی هر عادت
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$baseShareDomain = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'habitpulse.app') . dirname($_SERVER['PHP_SELF']);
$baseShareDomain = rtrim($baseShareDomain, '/\\');
$fullBaseUrl = $protocol . $baseShareDomain;
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
  <title>HabitPulse — Dashboard</title>

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
    * {
      -webkit-tap-highlight-color: transparent;
      user-select: none;
      box-sizing: border-box;
    }
    html, body {
      background-color: #07090E;
      color: #F8FAFC;
      overflow-x: hidden;
      font-family: 'Inter', 'Vazirmatn', sans-serif;
    }
    @media (min-width: 640px) {
      html, body {
        background-color: #04060A;
      }
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

    @keyframes tacticalShake {
      0%, 100% { transform: translateX(0); }
      20% { transform: translateX(-3px) rotate(-0.4deg); }
      40% { transform: translateX(3px) rotate(0.4deg); }
      60% { transform: translateX(-3px); }
      80% { transform: translateX(3px); }
    }
    .holding-shake {
      animation: tacticalShake 0.22s infinite;
    }
  </style>
</head>

<body class="min-h-[100dvh] flex flex-col items-center justify-center sm:py-5">

  <!-- ===================================================================== -->
  <!-- ANDROID APP SHELL: Fullscreen on Mobile | Framed ONLY on PC           -->
  <!-- ===================================================================== -->
  <div id="androidAppShell" class="relative w-full min-h-[100dvh] sm:min-h-[840px] sm:max-h-[880px] sm:max-w-[420px] bg-obsidian sm:rounded-[38px] sm:border-[6px] sm:border-[#1E2638] sm:shadow-[0_25px_70px_rgba(0,0,0,0.85)] flex flex-col justify-between overflow-hidden">

    <!-- Ambient Top Glow -->
    <div class="pointer-events-none absolute -top-24 left-1/2 -translate-x-1/2 w-80 h-56 rounded-full bg-emerald-500/10 blur-3xl"></div>

    <!-- ================================================================= -->
    <!-- 1. STICKY TOP HEADER                                              -->
    <!-- ================================================================= -->
    <header class="relative z-20 pt-3 sm:pt-2 px-4 pb-3 border-b border-white/[0.07] bg-obsidian/90 backdrop-blur-md shrink-0">
      
      <!-- PC-ONLY Android Status Bar -->
      <div class="hidden sm:flex items-center justify-between text-[11px] text-slate-400 font-mono mb-2 px-0.5">
        <span id="pcClock">22:14</span>
        <div class="flex items-center gap-1.5">
          <span class="text-[10px] text-emerald-400 font-semibold">5G</span>
          <svg class="w-3.5 h-3.5 text-slate-300" fill="currentColor" viewBox="0 0 24 24"><path d="M2 22h20V2z"/></svg>
          <svg class="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M15.67 4H14V2h-4v2H8.33C7.6 4 7 4.6 7 5.33v15.33C7 21.4 7.6 22 8.33 22h7.33c.74 0 1.34-.6 1.34-1.33V5.33C17 4.6 16.4 4 15.67 4z"/></svg>
        </div>
      </div>

      <!-- Main Header Bar -->
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2.5 min-w-0">
          <img src="img/icon.png" alt="HabitPulse" class="w-10 h-10 rounded-xl object-cover border border-white/10 bg-carbon shrink-0" />
          <div class="min-w-0">
            <div class="flex items-center gap-1.5">
              <span class="font-bold text-[14px] text-white text-lock"><?php echo htmlspecialchars($currentUser['display_name']); ?></span>
              <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 text-lock">
                @<?php echo htmlspecialchars($currentUser['username']); ?>
              </span>
            </div>
            <p class="text-[11px] text-slate-400 text-lock i18n-text" data-i18n="hdr_subtitle">HabitPulse Telemetry</p>
          </div>
        </div>

        <div class="flex items-center gap-1.5 shrink-0">
          <!-- Language Toggle (EN/FA) -->
          <button type="button" onclick="toggleLanguage()" class="h-9 px-2.5 rounded-xl bg-carbon border border-white/10 active:scale-95 transition flex items-center gap-1 text-[11px] font-mono">
            <span id="langBadgeEN" class="px-1 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold">EN</span>
            <span class="text-slate-600">/</span>
            <span id="langBadgeFA" class="px-1 py-0.5 rounded text-slate-400">FA</span>
          </button>

          <!-- Instant PIN Lock -->
          <button type="button" onclick="lockAppNow()" title="Lock Screen" class="w-9 h-9 rounded-xl bg-carbon border border-white/10 hover:border-amber-500/40 active:scale-95 transition flex items-center justify-center text-amber-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </button>

          <!-- Add Habit (+) Button -->
          <button type="button" onclick="openAddHabitModal()" class="h-9 px-3 rounded-xl bg-emerald-500 hover:bg-emerald-400 active:scale-95 transition text-obsidian font-extrabold text-[12px] flex items-center gap-1 shadow">
            <span class="text-base leading-none">+</span>
            <span class="text-lock i18n-text" data-i18n="btn_new_habit">New</span>
          </button>
        </div>
      </div>
    </header>

    <!-- ================================================================= -->
    <!-- 2. SCROLLABLE MAIN VIEWPORT                                       -->
    <!-- ================================================================= -->
    <main class="relative z-10 flex-1 overflow-y-auto no-scrollbar px-4 py-3.5 space-y-4">

      <?php if ($flashMsg !== ''): ?>
        <div id="serverFlashBanner" class="p-3 rounded-xl border <?php echo $flashType === 'error' ? 'bg-rose-500/15 border-rose-500/40 text-rose-200' : 'bg-emerald-500/15 border-emerald-500/40 text-emerald-200'; ?> flex items-center justify-between text-[12px]">
          <span class="text-lock i18n-text" data-i18n="<?php echo htmlspecialchars($flashMsg); ?>"><?php echo htmlspecialchars($flashMsg); ?></span>
          <button type="button" onclick="this.parentElement.remove()" class="text-xs px-1.5">✕</button>
        </div>
      <?php endif; ?>

      <!-- =============================================================== -->
      <!-- TAB 1: DASHBOARD VIEW                                           -->
      <!-- =============================================================== -->
      <div id="viewDashboard" class="space-y-4">

        <!-- 3-Column Daily Telemetry Summary HUD -->
        <div class="grid grid-cols-3 gap-2">
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="hud_good_score">Good Score</div>
            <div class="font-mono text-[16px] font-bold text-emerald-400 mt-0.5"><?php echo $goodScorePct; ?>%</div>
          </div>
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="hud_bad_slips">Today Slips</div>
            <div class="font-mono text-[16px] font-bold text-rose-400 mt-0.5">
              <span id="hudTotalSlips"><?php echo $todayBadSlips; ?></span> / <?php echo $todayBadLimit ?: 0; ?>
            </div>
          </div>
          <div class="tactical-card rounded-2xl p-2.5 text-center">
            <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="hud_total_habits">Total Habits</div>
            <div class="font-mono text-[16px] font-bold text-amber-400 mt-0.5"><?php echo count($habits); ?></div>
          </div>
        </div>

        <!-- Center 7-Day Telemetry Chart -->
        <div class="tactical-card rounded-2xl p-3.5">
          <div class="flex items-center justify-between mb-3">
            <div>
              <h2 class="text-[13px] font-bold text-white text-lock i18n-text" data-i18n="chart_title">7-Day Habit Pulse Chart</h2>
              <p class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="chart_subtitle">Green: Good Actions • Red: Bad Slips</p>
            </div>
            <button type="button" onclick="switchMainTab('history')" class="text-[10px] font-bold text-emerald-400 hover:underline text-lock i18n-text" data-i18n="btn_see_full_history">
              Full History →
            </button>
          </div>

          <div class="grid grid-cols-7 gap-1.5 items-end h-28 pt-3 px-1 border-b border-white/[0.07]">
            <?php foreach ($chartDays as $cd):
              $gHeight = max(8, (int)round(($cd['good'] / $maxChartVal) * 76));
              $bHeight = max(8, (int)round(($cd['bad'] / $maxChartVal) * 76));
            ?>
              <div class="flex flex-col items-center justify-end h-full">
                <div class="flex items-end justify-center gap-1 w-full h-20">
                  <div title="Good: <?php echo $cd['good']; ?>"
                       style="height: <?php echo $cd['good'] > 0 ? $gHeight : 6; ?>px"
                       class="w-2.5 rounded-t-md transition-all <?php echo $cd['good'] > 0 ? 'bg-gradient-to-t from-emerald-600 to-emerald-400' : 'bg-white/10'; ?>"></div>
                  <div title="Slips: <?php echo $cd['bad']; ?>"
                       style="height: <?php echo $cd['bad'] > 0 ? $bHeight : 6; ?>px"
                       class="w-2.5 rounded-t-md transition-all <?php echo $cd['bad'] > 0 ? 'bg-gradient-to-t from-rose-600 to-rose-400' : 'bg-white/10'; ?>"></div>
                </div>
                <span class="text-[10px] font-mono mt-1.5 <?php echo $cd['isToday'] ? 'text-emerald-400 font-bold' : 'text-slate-400'; ?>">
                  <?php echo htmlspecialchars($cd['label']); ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Category Filter Pills -->
        <div class="grid grid-cols-3 p-1 rounded-xl bg-carbon border border-white/10">
          <button id="filterBtnAll" type="button" onclick="filterHabits('all')" class="h-8 rounded-lg text-[11px] font-bold bg-slateCard text-white shadow text-lock i18n-text" data-i18n="filter_all">
            All Habits (<?php echo count($habits); ?>)
          </button>
          <button id="filterBtnGood" type="button" onclick="filterHabits('good')" class="h-8 rounded-lg text-[11px] font-bold text-emerald-400 hover:text-white text-lock i18n-text" data-i18n="filter_good">
            Good Habits
          </button>
          <button id="filterBtnBad" type="button" onclick="filterHabits('bad')" class="h-8 rounded-lg text-[11px] font-bold text-rose-400 hover:text-white text-lock i18n-text" data-i18n="filter_bad">
            Bad Habits
          </button>
        </div>

        <?php if (empty($habits)): ?>
          <div class="tactical-card rounded-2xl p-5 text-center space-y-3">
            <div class="w-12 h-12 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex items-center justify-center mx-auto text-xl">⚡</div>
            <h3 class="text-[14px] font-bold text-white text-lock i18n-text" data-i18n="empty_title">No Habits Added Yet</h3>
            <p class="text-[12px] text-slate-400 i18n-text" data-i18n="empty_desc">
              Start by loading 4 ready-made Good & Bad habits with 1 tap, or create your own custom habit.
            </p>
            <div class="grid grid-cols-2 gap-2 pt-1">
              <form method="POST" action="main.php">
                <input type="hidden" name="form_action" value="seed_presets" />
                <button type="submit" class="w-full h-10 rounded-xl bg-emerald-500 text-obsidian font-extrabold text-[12px] text-lock i18n-text" data-i18n="btn_load_presets">
                  + Load Sample Habits
                </button>
              </form>
              <button type="button" onclick="openAddHabitModal()" class="w-full h-10 rounded-xl bg-slateCard border border-white/15 text-white font-bold text-[12px] text-lock i18n-text" data-i18n="btn_create_custom">
                + Custom Habit
              </button>
            </div>
          </div>
        <?php else: ?>

          <!-- BAD HABITS LIST -->
          <div id="sectionBadHabits" class="space-y-2.5 <?php echo $badHabitsCount === 0 ? 'hidden' : ''; ?>">
            <div class="flex items-center justify-between px-1">
              <span class="text-[11px] font-bold uppercase tracking-wider text-rose-400 text-lock i18n-text" data-i18n="sec_bad_title">
                🔴 Bad Habits (Gradual Quit & Timer)
              </span>
              <span class="text-[10px] font-mono text-slate-400"><?php echo $badHabitsCount; ?></span>
            </div>

            <?php foreach ($habits as $h): if ($h['type'] !== 'bad') continue;
              $elapsed = max(0, $nowTime - (int)$h['last_slip_at']);
            ?>
              <div id="habitCard_<?php echo $h['id']; ?>" class="tactical-card rounded-2xl p-3.5 border-rose-500/25">
                <div class="flex items-center justify-between gap-2 mb-2.5">
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="w-8 h-8 rounded-xl bg-rose-500/15 border border-rose-500/30 flex items-center justify-center text-base shrink-0">
                      <?php echo htmlspecialchars($h['icon']); ?>
                    </span>
                    <div class="min-w-0">
                      <h4 class="text-[14px] font-bold text-white text-lock"><?php echo htmlspecialchars($h['title']); ?></h4>
                      <span class="text-[10px] text-slate-400 text-lock">
                        <span class="i18n-text" data-i18n="lbl_daily_limit">Daily Limit:</span> <?php echo (int)$h['daily_target'] . ' ' . htmlspecialchars($h['unit']); ?>
                      </span>
                    </div>
                  </div>

                  <div class="flex items-center gap-1.5 shrink-0">
                    <span class="font-mono text-[11px] px-2 py-1 rounded-lg bg-rose-500/15 text-rose-300 border border-rose-500/30">
                      <span id="habitCount_<?php echo $h['id']; ?>"><?php echo $h['today_count']; ?></span> / <?php echo (int)$h['daily_target']; ?>
                    </span>
                    <form method="POST" action="main.php" onsubmit="return confirm('Delete this habit?');">
                      <input type="hidden" name="form_action" value="delete_habit" />
                      <input type="hidden" name="habit_id" value="<?php echo $h['id']; ?>" />
                      <button type="submit" class="w-7 h-7 rounded-lg bg-white/5 hover:bg-rose-500/20 text-slate-400 hover:text-rose-300 text-xs">✕</button>
                    </form>
                  </div>
                </div>

                <!-- Live Clean Timer -->
                <div class="flex items-center justify-between bg-obsidian/90 border border-white/[0.06] rounded-xl px-3 py-2 mb-2.5">
                  <span class="text-[11px] text-slate-400 text-lock i18n-text" data-i18n="lbl_clean_timer">Time Since Last Slip:</span>
                  <span class="live-bad-timer tabular-timer text-[13px] font-bold text-telemetry" data-elapsed="<?php echo $elapsed; ?>" id="timer_<?php echo $h['id']; ?>">
                    00h : 00m : 00s
                  </span>
                </div>

                <!-- 1.0s Hold Button -->
                <button type="button"
                  onmousedown="startHoldBadHabit(event, <?php echo $h['id']; ?>)"
                  onmouseup="cancelHoldBadHabit(<?php echo $h['id']; ?>)"
                  onmouseleave="cancelHoldBadHabit(<?php echo $h['id']; ?>)"
                  ontouchstart="startHoldBadHabit(event, <?php echo $h['id']; ?>)"
                  ontouchend="cancelHoldBadHabit(<?php echo $h['id']; ?>)"
                  ontouchcancel="cancelHoldBadHabit(<?php echo $h['id']; ?>)"
                  class="relative w-full h-10 rounded-xl bg-rose-500/15 border border-rose-500/40 overflow-hidden flex items-center justify-center select-none active:scale-[0.99] transition">
                  <div id="holdFill_<?php echo $h['id']; ?>" class="absolute left-0 top-0 bottom-0 w-0 bg-gradient-to-r from-rose-600 to-rose-500 opacity-60 pointer-events-none"></div>
                  <span id="holdLabel_<?php echo $h['id']; ?>" class="relative z-10 text-[12px] font-bold text-rose-200 text-lock i18n-text" data-i18n="btn_hold_1s">
                    Hold 1.0s to Log Slip (Reset Timer)
                  </span>
                </button>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- GOOD HABITS LIST -->
          <div id="sectionGoodHabits" class="space-y-2.5 <?php echo $goodHabitsCount === 0 ? 'hidden' : ''; ?>">
            <div class="flex items-center justify-between px-1">
              <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 text-lock i18n-text" data-i18n="sec_good_title">
                🟢 Good Habits (Daily Routine & Growth)
              </span>
              <span class="text-[10px] font-mono text-slate-400"><?php echo $goodHabitsCount; ?></span>
            </div>

            <?php foreach ($habits as $h): if ($h['type'] !== 'good') continue;
              $pct = min(100, (int)round(($h['today_count'] / max(1, (int)$h['daily_target'])) * 100));
            ?>
              <div id="habitCard_<?php echo $h['id']; ?>" class="tactical-card rounded-2xl p-3.5 border-emerald-500/25">
                <div class="flex items-center justify-between gap-2 mb-2">
                  <div class="flex items-center gap-2 min-w-0">
                    <span class="w-8 h-8 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-base shrink-0">
                      <?php echo htmlspecialchars($h['icon']); ?>
                    </span>
                    <div class="min-w-0">
                      <h4 class="text-[14px] font-bold text-white text-lock"><?php echo htmlspecialchars($h['title']); ?></h4>
                      <span class="text-[10px] text-slate-400 text-lock">
                        <span class="i18n-text" data-i18n="lbl_daily_goal">Daily Goal:</span> <?php echo (int)$h['daily_target'] . ' ' . htmlspecialchars($h['unit']); ?>
                      </span>
                    </div>
                  </div>

                  <div class="flex items-center gap-1.5 shrink-0">
                    <span class="font-mono text-[11px] px-2 py-1 rounded-lg bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                      <span id="habitCount_<?php echo $h['id']; ?>"><?php echo $h['today_count']; ?></span> / <?php echo (int)$h['daily_target']; ?>
                    </span>
                    <form method="POST" action="main.php" onsubmit="return confirm('Delete this habit?');">
                      <input type="hidden" name="form_action" value="delete_habit" />
                      <input type="hidden" name="habit_id" value="<?php echo $h['id']; ?>" />
                      <button type="submit" class="w-7 h-7 rounded-lg bg-white/5 hover:bg-rose-500/20 text-slate-400 hover:text-rose-300 text-xs">✕</button>
                    </form>
                  </div>
                </div>

                <!-- Emerald Progress Bar -->
                <div class="w-full h-2.5 rounded-full bg-obsidian overflow-hidden border border-white/[0.06] mb-2.5">
                  <div id="goodBar_<?php echo $h['id']; ?>" class="h-full bg-gradient-to-r from-emerald-500 to-teal-400 transition-all duration-300" style="width: <?php echo $pct; ?>%"></div>
                </div>

                <!-- 1-Tap Instant Log Button -->
                <button type="button" onclick="logGoodHabit(<?php echo $h['id']; ?>)" class="w-full h-10 rounded-xl bg-emerald-500/20 hover:bg-emerald-500/30 active:scale-[0.98] border border-emerald-500/40 text-emerald-200 font-bold text-[12px] flex items-center justify-center gap-1.5 transition">
                  <span>+1</span>
                  <span class="text-lock i18n-text" data-i18n="btn_tap_log">Tap to Log Progress</span>
                </button>
              </div>
            <?php endforeach; ?>
          </div>

        <?php endif; ?>
      </div>

      <!-- =============================================================== -->
      <!-- TAB 2: HABIT HISTORY VIEW (روز | هفته | ماه | سال)               -->
      <!-- =============================================================== -->
      <div id="viewHistory" class="hidden space-y-4 pb-4">

        <!-- Header & Habit Selector -->
        <div class="tactical-card rounded-2xl p-3.5 space-y-3">
          <div class="flex items-center justify-between gap-2">
            <div>
              <h3 class="text-[15px] font-bold text-white text-lock i18n-text" data-i18n="hist_title">Habit History Explorer</h3>
              <p class="text-[11px] text-slate-400 text-lock i18n-text" data-i18n="hist_subtitle">Track habits across Day, Week, Month, or Year</p>
            </div>
          </div>

          <!-- Filter by Specific Habit Dropdown -->
          <div>
            <label class="block text-[10px] font-semibold text-slate-400 mb-1 text-lock i18n-text" data-i18n="hist_select_habit">Select Habit to Inspect:</label>
            <select id="historyHabitSelect" onchange="renderHistoryView()" class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/15 text-white text-[12px] font-semibold focus:border-emerald-500 focus:outline-none">
              <option value="all" class="i18n-text" data-i18n="opt_all_habits">⚡ All Habits Combined</option>
              <?php foreach ($habits as $h): ?>
                <option value="<?php echo (int)$h['id']; ?>">
                  <?php echo htmlspecialchars($h['icon'] . ' ' . $h['title'] . ' (' . ($h['type'] === 'bad' ? 'Bad' : 'Good') . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 4-Way Period Switcher: Day | Week | Month | Year -->
          <div class="grid grid-cols-4 p-1 rounded-xl bg-obsidian border border-white/10">
            <button id="periodBtn_day" type="button" onclick="setHistoryPeriod('day')" class="h-8 rounded-lg text-[11px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-lock i18n-text" data-i18n="period_day">
              Day
            </button>
            <button id="periodBtn_week" type="button" onclick="setHistoryPeriod('week')" class="h-8 rounded-lg text-[11px] font-bold text-slate-400 hover:text-white text-lock i18n-text" data-i18n="period_week">
              Week
            </button>
            <button id="periodBtn_month" type="button" onclick="setHistoryPeriod('month')" class="h-8 rounded-lg text-[11px] font-bold text-slate-400 hover:text-white text-lock i18n-text" data-i18n="period_month">
              Month
            </button>
            <button id="periodBtn_year" type="button" onclick="setHistoryPeriod('year')" class="h-8 rounded-lg text-[11px] font-bold text-slate-400 hover:text-white text-lock i18n-text" data-i18n="period_year">
              Year
            </button>
          </div>
        </div>

        <!-- Dynamic Interactive Chart for Selected Timeframe (Day / Week / Month / Year) -->
        <div class="tactical-card rounded-2xl p-3.5 space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <h4 id="historyChartTitle" class="text-[13px] font-bold text-white text-lock">Timeline Breakdown</h4>
              <p id="historyChartSub" class="text-[10px] text-slate-400 text-lock">Total logs in selected period</p>
            </div>
            <div class="text-right">
              <span id="historyPeriodTotalBadge" class="font-mono text-[14px] font-extrabold px-2.5 py-1 rounded-lg bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                0
              </span>
            </div>
          </div>

          <!-- Dynamic Bar Chart Container -->
          <div id="historyDynamicBars" class="flex items-end justify-between gap-1 h-32 pt-4 px-1 border-b border-white/[0.08] overflow-x-auto no-scrollbar">
            <!-- Populated dynamically by JS -->
          </div>
        </div>

        <!-- Per-Habit Multi-Timeframe Summary Table (Day | Week | Month | Year at a glance) -->
        <div class="tactical-card rounded-2xl p-3.5 space-y-2.5">
          <div class="flex items-center justify-between">
            <h4 class="text-[13px] font-bold text-white text-lock i18n-text" data-i18n="hist_matrix_title">
              All Habits Summary (Day / Week / Month / Year)
            </h4>
          </div>

          <?php if (empty($habits)): ?>
            <p class="text-[12px] text-slate-400 text-center py-3 i18n-text" data-i18n="empty_title">No Habits Added Yet</p>
          <?php else: ?>
            <div class="space-y-2">
              <?php foreach ($habits as $h):
                $isBad = ($h['type'] === 'bad');
              ?>
                <div onclick="selectHabitInHistory(<?php echo (int)$h['id']; ?>)" class="cursor-pointer p-3 rounded-xl bg-obsidian border <?php echo $isBad ? 'border-rose-500/25 hover:border-rose-500/50' : 'border-emerald-500/25 hover:border-emerald-500/50'; ?> transition space-y-2">
                  <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                      <span class="text-base"><?php echo htmlspecialchars($h['icon']); ?></span>
                      <span class="text-[13px] font-bold text-white text-lock"><?php echo htmlspecialchars($h['title']); ?></span>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded <?php echo $isBad ? 'bg-rose-500/15 text-rose-300' : 'bg-emerald-500/15 text-emerald-300'; ?>">
                      <?php echo htmlspecialchars($h['unit']); ?>
                    </span>
                  </div>

                  <!-- 4 Columns: Day | Week | Month | Year -->
                  <div class="grid grid-cols-4 gap-1.5 text-center pt-1">
                    <div class="p-1.5 rounded-lg bg-carbon border border-white/[0.05]">
                      <div class="text-[9px] text-slate-400 i18n-text" data-i18n="period_day">Day</div>
                      <div class="font-mono text-[13px] font-bold text-white"><?php echo (int)$h['today_count']; ?></div>
                    </div>
                    <div class="p-1.5 rounded-lg bg-carbon border border-white/[0.05]">
                      <div class="text-[9px] text-slate-400 i18n-text" data-i18n="period_week">Week</div>
                      <div class="font-mono text-[13px] font-bold text-emerald-400"><?php echo (int)$h['week_count']; ?></div>
                    </div>
                    <div class="p-1.5 rounded-lg bg-carbon border border-white/[0.05]">
                      <div class="text-[9px] text-slate-400 i18n-text" data-i18n="period_month">Month</div>
                      <div class="font-mono text-[13px] font-bold text-amber-400"><?php echo (int)$h['month_count']; ?></div>
                    </div>
                    <div class="p-1.5 rounded-lg bg-carbon border border-white/[0.05]">
                      <div class="text-[9px] text-slate-400 i18n-text" data-i18n="period_year">Year</div>
                      <div class="font-mono text-[13px] font-bold text-sky-400"><?php echo (int)$h['year_count']; ?></div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Exact Timestamps Log Feed for Selected Period & Habit -->
        <div class="tactical-card rounded-2xl p-3.5 space-y-2.5">
          <div class="flex items-center justify-between">
            <h4 class="text-[13px] font-bold text-white text-lock i18n-text" data-i18n="hist_logs_title">
              Recorded Log Timestamps
            </h4>
            <span id="historyLogsCount" class="text-[10px] font-mono text-slate-400">0</span>
          </div>
          <div id="historyLogsList" class="space-y-1.5 max-h-56 overflow-y-auto no-scrollbar">
            <!-- Populated dynamically by JS -->
          </div>
        </div>

      </div>

      <!-- =============================================================== -->
      <!-- TAB 3: PER-HABIT SHARE LINKS ONLY (بدون لینک کلی پروفایل)        -->
      <!-- =============================================================== -->
      <div id="viewShare" class="hidden space-y-4 pb-4">
        <div class="tactical-card rounded-2xl p-4 space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-[15px] font-bold text-white text-lock i18n-text" data-i18n="share_per_habit_title">Per-Habit Direct Share Links</h3>
              <p class="text-[11px] text-slate-400 mt-0.5 i18n-text" data-i18n="share_per_habit_desc">
                Each habit has its own private link. Share only the specific habit you want others to see.
              </p>
            </div>
          </div>

          <?php if (empty($habits)): ?>
            <p class="text-[12px] text-slate-400 py-4 text-center i18n-text" data-i18n="empty_title">No Habits Added Yet</p>
          <?php else: ?>
            <div class="space-y-3 pt-1">
              <?php foreach ($habits as $h):
                $habitUrl = $fullBaseUrl . '/report.php?habit=' . urlencode($h['share_token']);
                $isBad    = ($h['type'] === 'bad');
              ?>
                <div class="p-3.5 rounded-2xl bg-obsidian border <?php echo $isBad ? 'border-rose-500/25' : 'border-emerald-500/25'; ?> space-y-2.5">
                  <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                      <span class="w-8 h-8 rounded-xl <?php echo $isBad ? 'bg-rose-500/15' : 'bg-emerald-500/15'; ?> flex items-center justify-center text-base shrink-0">
                        <?php echo htmlspecialchars($h['icon']); ?>
                      </span>
                      <div class="min-w-0">
                        <div class="text-[13px] font-bold text-white text-lock">
                          <?php echo htmlspecialchars($h['title']); ?>
                        </div>
                        <div class="text-[10px] text-slate-400 text-lock">
                          <?php echo (int)$h['today_count'] . ' / ' . (int)$h['daily_target'] . ' ' . htmlspecialchars($h['unit']); ?>
                        </div>
                      </div>
                    </div>

                    <!-- Regenerate Token Button -->
                    <form method="POST" action="main.php" onsubmit="return confirm('Generate a new link and revoke the old link for this habit?');">
                      <input type="hidden" name="form_action" value="regenerate_habit_token" />
                      <input type="hidden" name="habit_id" value="<?php echo (int)$h['id']; ?>" />
                      <button type="submit" title="Reset Link Token" class="h-7 px-2 rounded-lg bg-white/5 hover:bg-amber-500/20 text-slate-400 hover:text-amber-300 text-[10px] font-mono flex items-center gap-1">
                        ↻ <span class="i18n-text" data-i18n="btn_reset_link">New Token</span>
                      </button>
                    </form>
                  </div>

                  <!-- Direct Habit URL Box -->
                  <div class="px-2.5 py-2 rounded-xl bg-carbon border border-white/[0.06] text-[10px] font-mono text-slate-300 text-lock">
                    <?php echo htmlspecialchars($habitUrl); ?>
                  </div>

                  <!-- Copy & Open Buttons -->
                  <div class="flex items-center gap-2">
                    <button type="button" onclick="copyText('<?php echo htmlspecialchars($habitUrl, ENT_QUOTES); ?>')" class="flex-1 h-9 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-obsidian font-extrabold text-[11px] i18n-text" data-i18n="btn_copy">
                      Copy Habit Link
                    </button>
                    <a href="<?php echo htmlspecialchars($habitUrl); ?>" target="_blank" class="px-3.5 h-9 rounded-xl bg-slateCard border border-white/15 text-white font-bold text-[11px] flex items-center justify-center shrink-0">
                      Open ↗
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- =============================================================== -->
      <!-- TAB 4: SETTINGS VIEW                                            -->
      <!-- =============================================================== -->
      <div id="viewSettings" class="hidden space-y-4 pb-4">

        <!-- CARD 1: Profile & Username -->
        <form method="POST" action="main.php" class="tactical-card rounded-2xl p-4 space-y-3">
          <input type="hidden" name="form_action" value="update_profile" />
          <h3 class="text-[14px] font-bold text-white border-b border-white/10 pb-2 text-lock i18n-text" data-i18n="set_profile_hdr">
            Profile & Account Username
          </h3>

          <div>
            <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_fullname">Display Name</label>
            <input type="text" name="display_name" required value="<?php echo htmlspecialchars($currentUser['display_name']); ?>"
              class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px]" />
          </div>

          <div>
            <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_username">Account Username (Login ID)</label>
            <input type="text" name="username" required value="<?php echo htmlspecialchars($currentUser['username']); ?>"
              class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px] font-mono" />
          </div>

          <button type="submit" class="w-full h-10 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-obsidian font-extrabold text-[12px] text-lock i18n-text" data-i18n="btn_save_profile">
            Save Profile Changes
          </button>
        </form>

        <!-- CARD 2: Change Master Password & 4-Digit Fast PIN -->
        <form method="POST" action="main.php" class="tactical-card rounded-2xl p-4 space-y-3">
          <input type="hidden" name="form_action" value="update_security" />
          <h3 class="text-[14px] font-bold text-white border-b border-white/10 pb-2 text-lock i18n-text" data-i18n="set_security_hdr">
            Change Password & 4-Digit PIN
          </h3>

          <div class="grid grid-cols-2 gap-2.5">
            <div>
              <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_new_pass">New Master Password</label>
              <input type="password" name="new_password" placeholder="••••••••"
                class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px] font-mono" />
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-amber-400 mb-1 text-lock i18n-text" data-i18n="lbl_new_pin">New 4-Digit PIN</label>
              <input type="password" name="new_pin" maxlength="4" pattern="\d{4}" inputmode="numeric" placeholder="0000"
                class="w-full h-10 px-3 rounded-xl bg-obsidian border border-amber-500/30 focus:border-amber-400 focus:outline-none text-amber-300 text-center text-[14px] font-mono tracking-[0.3em]" />
            </div>
          </div>

          <button type="submit" class="w-full h-10 rounded-xl bg-slateCard hover:bg-white/10 border border-white/15 text-white font-bold text-[12px] text-lock i18n-text" data-i18n="btn_save_security">
            Update Password / PIN
          </button>
        </form>

        <!-- CARD 3: Quick Lock & Logout -->
        <div class="tactical-card rounded-2xl p-4 space-y-2.5">
          <button type="button" onclick="lockAppNow()" class="w-full h-10 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-300 font-bold text-[12px] flex items-center justify-center gap-2 text-lock i18n-text" data-i18n="btn_lock_now">
            🔒 Lock App with 4-Digit PIN Now
          </button>

          <form method="POST" action="main.php">
            <input type="hidden" name="form_action" value="logout" />
            <button type="submit" class="w-full h-10 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/40 text-rose-300 font-bold text-[12px] flex items-center justify-center gap-2 text-lock i18n-text" data-i18n="btn_logout">
              Sign Out of Account (Logout)
            </button>
          </form>
        </div>

      </div>

    </main>

    <!-- ================================================================= -->
    <!-- 3. STICKY 4-TAB ANDROID BOTTOM NAVIGATION BAR                     -->
    <!-- ================================================================= -->
    <nav class="relative z-20 px-2.5 pt-2 pb-3 sm:pb-2.5 border-t border-white/[0.08] bg-obsidian/95 backdrop-blur-md shrink-0">
      <div class="grid grid-cols-4 gap-1.5">
        <!-- Tab 1: Dashboard -->
        <button id="navBtnDashboard" type="button" onclick="switchMainTab('dashboard')" class="h-11 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex flex-col items-center justify-center px-1">
          <svg class="w-4 h-4 mb-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          <span class="text-[10px] font-bold text-lock i18n-text" data-i18n="nav_dashboard">Dashboard</span>
        </button>

        <!-- Tab 2: Habit History (تاریخچه عادت‌ها) -->
        <button id="navBtnHistory" type="button" onclick="switchMainTab('history')" class="h-11 rounded-2xl bg-transparent border border-transparent text-slate-400 flex flex-col items-center justify-center px-1">
          <svg class="w-4 h-4 mb-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          <span class="text-[10px] font-bold text-lock i18n-text" data-i18n="nav_history">History</span>
        </button>

        <!-- Tab 3: Per-Habit Share Links -->
        <button id="navBtnShare" type="button" onclick="switchMainTab('share')" class="h-11 rounded-2xl bg-transparent border border-transparent text-slate-400 flex flex-col items-center justify-center px-1">
          <svg class="w-4 h-4 mb-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          <span class="text-[10px] font-bold text-lock i18n-text" data-i18n="nav_share">Habit Links</span>
        </button>

        <!-- Tab 4: Settings -->
        <button id="navBtnSettings" type="button" onclick="switchMainTab('settings')" class="h-11 rounded-2xl bg-transparent border border-transparent text-slate-400 flex flex-col items-center justify-center px-1">
          <svg class="w-4 h-4 mb-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          <span class="text-[10px] font-bold text-lock i18n-text" data-i18n="nav_settings">Settings</span>
        </button>
      </div>

      <!-- PC-ONLY Android Home Indicator Line -->
      <div class="hidden sm:block w-32 h-1 bg-white/20 rounded-full mx-auto mt-2.5"></div>
    </nav>

    <!-- ================================================================= -->
    <!-- 4. MODAL: ADD NEW HABIT                                           -->
    <!-- ================================================================= -->
    <div id="addHabitModal" onclick="closeAddHabitModal()" class="fixed sm:absolute inset-0 z-40 bg-black/75 backdrop-blur-sm hidden items-end justify-center">
      <div onclick="event.stopPropagation()" class="w-full bg-carbon border-t border-white/15 rounded-t-[28px] p-5 space-y-3.5 shadow-2xl">
        <div class="w-10 h-1.5 bg-white/20 rounded-full mx-auto"></div>
        <div class="flex items-center justify-between">
          <h3 class="text-[15px] font-bold text-white text-lock i18n-text" data-i18n="modal_add_title">Create New Habit</h3>
          <button type="button" onclick="closeAddHabitModal()" class="text-xs text-slate-400 px-2 py-1">✕</button>
        </div>

        <form method="POST" action="main.php" class="space-y-3">
          <input type="hidden" name="form_action" value="add_habit" />
          <input type="hidden" id="habitTypeInput" name="type" value="good" />

          <div class="grid grid-cols-2 gap-2 p-1 rounded-xl bg-obsidian border border-white/10">
            <button id="typeBtnGood" type="button" onclick="selectHabitType('good')" class="h-9 rounded-lg text-[12px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-lock i18n-text" data-i18n="type_good_btn">
              🟢 Good Habit (Build)
            </button>
            <button id="typeBtnBad" type="button" onclick="selectHabitType('bad')" class="h-9 rounded-lg text-[12px] font-bold text-slate-400 text-lock i18n-text" data-i18n="type_bad_btn">
              🔴 Bad Habit (Quit)
            </button>
          </div>

          <div class="grid grid-cols-4 gap-2">
            <div>
              <label class="block text-[10px] text-slate-400 mb-1 text-lock i18n-text" data-i18n="lbl_icon">Emoji</label>
              <input type="text" id="habitIconInput" name="icon" value="💧" maxlength="4"
                class="w-full h-10 text-center rounded-xl bg-obsidian border border-white/10 text-base" />
            </div>
            <div class="col-span-3">
              <label class="block text-[10px] text-slate-400 mb-1 text-lock i18n-text" data-i18n="lbl_habit_title">Habit Title</label>
              <input type="text" name="title" required placeholder="e.g. Drink Water / Quit Smoking"
                class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/10 text-white text-[13px]" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-[10px] text-slate-400 mb-1 text-lock i18n-text" data-i18n="lbl_target_num">Target / Limit</label>
              <input type="number" name="daily_target" min="1" max="500" value="8" required
                class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/10 text-white font-mono text-[13px]" />
            </div>
            <div>
              <label class="block text-[10px] text-slate-400 mb-1 text-lock i18n-text" data-i18n="lbl_unit_name">Unit (Glasses, Cigs...)</label>
              <input type="text" id="habitUnitInput" name="unit" value="Glasses" required
                class="w-full h-10 px-3 rounded-xl bg-obsidian border border-white/10 text-white text-[13px]" />
            </div>
          </div>

          <button type="submit" class="w-full h-11 rounded-xl bg-emerald-500 text-obsidian font-extrabold text-[13px] shadow-lg text-lock i18n-text" data-i18n="btn_save_habit">
            Save & Add to Dashboard
          </button>
        </form>
      </div>
    </div>

    <!-- ================================================================= -->
    <!-- 5. MANDATORY 4-DIGIT PIN LOCK OVERLAY                             -->
    <!-- ================================================================= -->
    <div id="pinLockOverlay" class="fixed sm:absolute inset-0 z-50 bg-obsidian/98 backdrop-blur-2xl <?php echo $_SESSION['pin_unlocked'] ? 'hidden' : 'flex'; ?> flex-col justify-between p-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <img src="img/icon.png" alt="HabitPulse" class="w-7 h-7 rounded-lg object-cover border border-white/10" />
          <span class="text-xs font-bold text-white">HabitPulse</span>
        </div>
        <button type="button" onclick="toggleLanguage()" class="px-2.5 py-1 rounded-lg bg-carbon border border-white/10 text-[11px] font-mono text-emerald-400">
          EN / FA
        </button>
      </div>

      <div id="pinCardBox" class="text-center my-auto">
        <div class="relative w-16 h-16 mx-auto mb-3 rounded-2xl p-0.5 bg-gradient-to-b from-emerald-400/40 to-white/5">
          <img src="img/icon.png" alt="Logo" class="w-full h-full rounded-[14px] object-cover bg-carbon" />
        </div>

        <h2 class="text-[17px] font-extrabold text-white mb-0.5 text-lock">
          <?php echo htmlspecialchars($currentUser['display_name']); ?>
        </h2>
        <p id="pinStatusText" class="text-[12px] text-slate-400 mb-5 text-lock i18n-text" data-i18n="pin_prompt">
          Enter your 4-Digit Fast PIN to unlock
        </p>

        <div id="pinDotsRow" class="flex items-center justify-center gap-4 mb-6">
          <span class="pin-dot w-3.5 h-3.5 rounded-full border border-white/30 bg-transparent transition-all"></span>
          <span class="pin-dot w-3.5 h-3.5 rounded-full border border-white/30 bg-transparent transition-all"></span>
          <span class="pin-dot w-3.5 h-3.5 rounded-full border border-white/30 bg-transparent transition-all"></span>
          <span class="pin-dot w-3.5 h-3.5 rounded-full border border-white/30 bg-transparent transition-all"></span>
        </div>

        <div class="grid grid-cols-3 gap-2.5 max-w-[260px] mx-auto font-mono">
          <?php foreach ([1,2,3,4,5,6,7,8,9] as $num): ?>
            <button type="button" onclick="pressPinDigit('<?php echo $num; ?>')" class="h-12 rounded-2xl bg-slateCard hover:bg-white/10 active:bg-emerald-500/25 border border-white/[0.06] text-white font-bold text-lg transition">
              <?php echo $num; ?>
            </button>
          <?php endforeach; ?>
          <button type="button" onclick="pressPinDigit('C')" class="h-12 rounded-2xl bg-carbon border border-white/[0.05] text-[11px] text-slate-400 font-bold">CLR</button>
          <button type="button" onclick="pressPinDigit('0')" class="h-12 rounded-2xl bg-slateCard hover:bg-white/10 active:bg-emerald-500/25 border border-white/[0.06] text-white font-bold text-lg transition">0</button>
          <button type="button" onclick="pressPinDigit('B')" class="h-12 rounded-2xl bg-carbon border border-white/[0.05] text-sm text-slate-300 font-bold">⌫</button>
        </div>
      </div>

      <form method="POST" action="main.php" class="text-center">
        <input type="hidden" name="form_action" value="logout" />
        <button type="submit" class="text-[12px] text-slate-400 hover:text-rose-400 transition text-lock i18n-text" data-i18n="pin_logout_link">
          Forgot PIN or Switch Account? Sign Out
        </button>
      </form>
    </div>

    <!-- ================================================================= -->
    <!-- 6. FLOATING TOAST NOTIFICATION                                    -->
    <!-- ================================================================= -->
    <div id="appToast" class="pointer-events-none fixed sm:absolute top-14 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-xl bg-emerald-500 text-obsidian font-extrabold text-xs shadow-xl opacity-0 transition-opacity duration-200 text-lock">
      Saved
    </div>

  </div>

  <!-- ===================================================================== -->
  <!-- JAVASCRIPT ENGINE: PIN, TIMERS, HISTORY (DAY/WEEK/MONTH/YEAR), I18N   -->
  <!-- ===================================================================== -->
  <script>
    // Server-side data injected for interactive Day/Week/Month/Year History Explorer
    const TODAY_DATE   = <?php echo json_encode($todayDate); ?>;
    const WEEK_START   = <?php echo json_encode($weekStart); ?>;
    const MONTH_START  = <?php echo json_encode($monthStart); ?>;
    const YEAR_START   = <?php echo json_encode($yearStart); ?>;
    const DAY_BLOCKS   = <?php echo json_encode($dayBlocksLabels); ?>;
    const WEEK_DATES   = <?php echo json_encode($weekDates); ?>;
    const MONTH_DATES  = <?php echo json_encode($monthDates); ?>;
    const YEAR_MONTHS  = <?php echo json_encode($yearMonths); ?>;
    const ALL_LOGS     = <?php echo json_encode($historyLogsFormatted); ?>;

    const i18n = {
      en: {
        hdr_subtitle: "HabitPulse Telemetry",
        btn_new_habit: "New",
        hud_good_score: "Good Score",
        hud_bad_slips: "Today Slips",
        hud_total_habits: "Total Habits",
        chart_title: "7-Day Habit Pulse Chart",
        chart_subtitle: "Green: Good Actions • Red: Bad Slips",
        btn_see_full_history: "Full History →",
        filter_all: "All Habits (<?php echo count($habits); ?>)",
        filter_good: "Good Habits",
        filter_bad: "Bad Habits",
        empty_title: "No Habits Added Yet",
        empty_desc: "Start by loading 4 ready-made Good & Bad habits with 1 tap, or create your own custom habit.",
        btn_load_presets: "+ Load Sample Habits",
        btn_create_custom: "+ Custom Habit",
        sec_bad_title: "🔴 Bad Habits (Gradual Quit & Timer)",
        sec_good_title: "🟢 Good Habits (Daily Routine & Growth)",
        lbl_daily_limit: "Daily Limit:",
        lbl_daily_goal: "Daily Goal:",
        lbl_clean_timer: "Time Since Last Slip:",
        btn_hold_1s: "Hold 1.0s to Log Slip (Reset Timer)",
        btn_holding_1s: "Keep Holding... (1.0s)",
        btn_slip_recorded: "Slip Logged • Timer Reset!",
        btn_tap_log: "Tap to Log Progress",
        hist_title: "Habit History Explorer",
        hist_subtitle: "Track habits across Day, Week, Month, or Year",
        hist_select_habit: "Select Habit to Inspect:",
        opt_all_habits: "⚡ All Habits Combined",
        period_day: "Day",
        period_week: "Week",
        period_month: "Month",
        period_year: "Year",
        hist_matrix_title: "All Habits Summary (Day / Week / Month / Year)",
        hist_logs_title: "Recorded Log Timestamps",
        hist_no_logs: "No activity recorded in this timeframe.",
        share_per_habit_title: "Per-Habit Direct Share Links",
        share_per_habit_desc: "Each habit has its own private link. Share only the specific habit you want others to see.",
        btn_reset_link: "New Token",
        btn_copy: "Copy Habit Link",
        set_profile_hdr: "Profile & Account Username",
        lbl_fullname: "Display Name",
        lbl_username: "Account Username (Login ID)",
        btn_save_profile: "Save Profile Changes",
        set_security_hdr: "Change Password & 4-Digit PIN",
        lbl_new_pass: "New Master Password",
        lbl_new_pin: "New 4-Digit PIN",
        btn_save_security: "Update Password / PIN",
        btn_lock_now: "🔒 Lock App with 4-Digit PIN Now",
        btn_logout: "Sign Out of Account (Logout)",
        nav_dashboard: "Dashboard",
        nav_history: "History",
        nav_share: "Habit Links",
        nav_settings: "Settings",
        modal_add_title: "Create New Habit",
        type_good_btn: "🟢 Good Habit (Build)",
        type_bad_btn: "🔴 Bad Habit (Quit)",
        lbl_icon: "Emoji",
        lbl_habit_title: "Habit Title",
        lbl_target_num: "Target / Limit",
        lbl_unit_name: "Unit (Glasses, Cigs...)",
        btn_save_habit: "Save & Add to Dashboard",
        pin_prompt: "Enter your 4-Digit Fast PIN to unlock",
        pin_wrong: "Incorrect PIN! Try again",
        pin_logout_link: "Forgot PIN or Switch Account? Sign Out",
        msg_habit_added: "New habit added successfully!",
        msg_presets_added: "Sample Good & Bad habits loaded!",
        msg_habit_deleted: "Habit removed.",
        msg_token_regenerated: "New habit share link generated!",
        msg_profile_saved: "Profile & Username updated!",
        msg_security_saved: "Security credentials updated!",
        err_invalid_profile: "Please enter a valid English Username",
        err_username_taken: "That username is already taken",
        err_short_password: "Password must be at least 4 characters",
        err_invalid_pin: "PIN must be 4 digits"
      },
      fa: {
        hdr_subtitle: "سیستم هوشمند کنترل عادت",
        btn_new_habit: "افزودن",
        hud_good_score: "امتیاز مثبت",
        hud_bad_slips: "لغزش امروز",
        hud_total_habits: "کل عادت‌ها",
        chart_title: "نمودار ۷ روزه عادت‌های خوب و بد",
        chart_subtitle: "سبز: عادت خوب • قرمز: لغزش عادت بد",
        btn_see_full_history: "تاریخچه کامل ←",
        filter_all: "همه عادت‌ها (<?php echo count($habits); ?>)",
        filter_good: "عادت‌های خوب",
        filter_bad: "عادت‌های بد",
        empty_title: "هنوز عادتی ثبت نشده است",
        empty_desc: "با یک کلیک ۴ عادت آماده (خوب و بد) اضافه کنید یا عادت دلخواه خودتان را بسازید.",
        btn_load_presets: "+ افزودن عادت‌های نمونه",
        btn_create_custom: "+ ساخت عادت جدید",
        sec_bad_title: "🔴 عادت‌های بد (ترک تدریجی + تایمر پاک بودن)",
        sec_good_title: "🟢 عادت‌های خوب (رشد روزانه با ۱ لمس)",
        lbl_daily_limit: "سقف مجاز روزانه:",
        lbl_daily_goal: "هدف روزانه:",
        lbl_clean_timer: "مدت زمان پاک بودن:",
        btn_hold_1s: "برای ثبت لغزش ۱ ثانیه نگه دارید",
        btn_holding_1s: "نگه دارید... (۱.۰ ثانیه)",
        btn_slip_recorded: "ثبت شد • تایمر صفر شد!",
        btn_tap_log: "ثبت +۱ پیشرفت روزانه",
        hist_title: "تاریخچه عادت‌ها (روز / هفته / ماه / سال)",
        hist_subtitle: "بررسی دقیق عادت‌ها در طول روز، هفته، ماه یا کل سال",
        hist_select_habit: "انتخاب عادت برای مشاهده تاریخچه:",
        opt_all_habits: "⚡ همه عادت‌ها با هم",
        period_day: "روز",
        period_week: "هفته",
        period_month: "ماه",
        period_year: "سال",
        hist_matrix_title: "خلاصه کل عادت‌ها (روز / هفته / ماه / سال)",
        hist_logs_title: "ریز ساعت و تاریخ ثبت‌شده‌ها",
        hist_no_logs: "در این بازه زمانی موردی ثبت نشده است.",
        share_per_habit_title: "لینک اختصاصی اشتراک‌گذاری هر عادت",
        share_per_habit_desc: "هر عادت لینک مخصوص به خودش را دارد تا فقط همان عادت دلخواه را به اشتراک بگذارید.",
        btn_reset_link: "لینک جدید",
        btn_copy: "کپی لینک این عادت",
        set_profile_hdr: "تغییر نام نمایشی و یوزرنیم",
        lbl_fullname: "نام نمایشی",
        lbl_username: "یوزرنیم ورود (انگلیسی)",
        btn_save_profile: "ذخیره تغییرات پروفایل",
        set_security_hdr: "تغییر رمز عبور و پین ۴ رقمی",
        lbl_new_pass: "رمز عبور اصلی جدید",
        lbl_new_pin: "پین ۴ رقمی جدید",
        btn_save_security: "بروزرسانی رمز / پین",
        btn_lock_now: "🔒 قفل فوری برنامه با پین ۴ رقمی",
        btn_logout: "خروج از حساب کاربری (Logout)",
        nav_dashboard: "داشبورد",
        nav_history: "تاریخچه عادت‌ها",
        nav_share: "لینک عادت‌ها",
        nav_settings: "تنظیمات",
        modal_add_title: "ساخت عادت جدید",
        type_good_btn: "🟢 عادت خوب (ساختن)",
        type_bad_btn: "🔴 عادت بد (ترک کردن)",
        lbl_icon: "آیکون",
        lbl_habit_title: "عنوان عادت",
        lbl_target_num: "هدف یا سقف",
        lbl_unit_name: "واحد (لیوان، نخ، بار...)",
        btn_save_habit: "ذخیره و افزودن به برنامه",
        pin_prompt: "پین ۴ رقمی خود را برای ورود وارد کنید",
        pin_wrong: "پین اشتباه است! دوباره تلاش کنید",
        pin_logout_link: "فراموشی پین یا تغییر حساب؟ خروج",
        msg_habit_added: "عادت جدید با موفقیت اضافه شد!",
        msg_presets_added: "عادت‌های نمونه اضافه شدند!",
        msg_habit_deleted: "عادت حذف شد.",
        msg_token_regenerated: "لینک جدید برای این عادت ساخته شد!",
        msg_profile_saved: "نام نمایشی و یوزرنیم ذخیره شد!",
        msg_security_saved: "رمز عبور / پین ۴ رقمی بروزرسانی شد!",
        err_invalid_profile: "یوزرنیم باید شامل حروف انگلیسی و عدد باشد",
        err_username_taken: "این نام کاربری قبلاً استفاده شده است",
        err_short_password: "رمز عبور باید حداقل ۴ کاراکتر باشد",
        err_invalid_pin: "پین باید دقیقاً ۴ رقم عددی باشد"
      }
    };

    let currentLang = localStorage.getItem('habitpulse_lang') || 'en';
    let currentHistoryPeriod = 'month'; // پیش‌فرض روی «ماه» تا عادت‌های کم‌تکرار هم عالی دیده شوند

    function applyLanguage(lang) {
      currentLang = lang;
      localStorage.setItem('habitpulse_lang', lang);
      document.body.classList.toggle('lang-fa', lang === 'fa');

      const badgeEN = document.getElementById('langBadgeEN');
      const badgeFA = document.getElementById('langBadgeFA');
      if (badgeEN && badgeFA) {
        if (lang === 'fa') {
          badgeFA.className = 'px-1 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
          badgeEN.className = 'px-1 py-0.5 rounded text-slate-400';
        } else {
          badgeEN.className = 'px-1 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
          badgeFA.className = 'px-1 py-0.5 rounded text-slate-400';
        }
      }

      document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (i18n[lang] && i18n[lang][key]) {
          el.textContent = i18n[lang][key];
        }
      });

      renderHistoryView();
    }

    function toggleLanguage() {
      applyLanguage(currentLang === 'en' ? 'fa' : 'en');
    }

    // =====================================================================
    // 1. HISTORY EXPLORER ENGINE (DAY / WEEK / MONTH / YEAR)
    // =====================================================================
    function setHistoryPeriod(period) {
      currentHistoryPeriod = period;
      ['day', 'week', 'month', 'year'].forEach(p => {
        const btn = document.getElementById(`periodBtn_${p}`);
        if (p === period) {
          btn.className = 'h-8 rounded-lg text-[11px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-lock i18n-text';
        } else {
          btn.className = 'h-8 rounded-lg text-[11px] font-bold text-slate-400 hover:text-white text-lock i18n-text';
        }
      });
      renderHistoryView();
    }

    function selectHabitInHistory(habitId) {
      const sel = document.getElementById('historyHabitSelect');
      if (sel) {
        sel.value = String(habitId);
        renderHistoryView();
      }
    }

    function renderHistoryView() {
      const selectEl = document.getElementById('historyHabitSelect');
      if (!selectEl) return;
      const selectedHabit = selectEl.value; // 'all' or habit_id

      // فیلتر لاگ‌ها بر اساس عادت انتخاب‌شده
      const filteredByHabit = ALL_LOGS.filter(item => {
        return selectedHabit === 'all' || String(item.habit_id) === String(selectedHabit);
      });

      let buckets = []; // [{ label, count, typeHint }]
      let periodLogs = [];
      let titleText = '';
      let subText = '';

      if (currentHistoryPeriod === 'day') {
        titleText = currentLang === 'fa' ? 'نمودار ساعتی امروز (۲۴ ساعت)' : "Today's Hourly Breakdown (24h)";
        subText   = currentLang === 'fa' ? 'تعداد دفعات انجام در ساعت‌های مختلف امروز' : 'Occurrences across 4-hour blocks today';
        periodLogs = filteredByHabit.filter(l => l.date === TODAY_DATE);

        const counts = [0, 0, 0, 0, 0, 0];
        periodLogs.forEach(l => {
          const idx = Math.min(5, Math.floor(l.hour / 4));
          counts[idx] += l.amount;
        });
        DAY_BLOCKS.forEach((lbl, idx) => {
          buckets.push({ label: lbl, count: counts[idx] });
        });
      }
      else if (currentHistoryPeriod === 'week') {
        titleText = currentLang === 'fa' ? 'نمودار ۷ روز اخیر (هفتگی)' : 'Last 7 Days Breakdown (Weekly)';
        subText   = currentLang === 'fa' ? 'مناسب برای عادت‌هایی که در طول هفته چند بار رخ می‌دهند' : 'Ideal for habits occurring a few times a week';
        periodLogs = filteredByHabit.filter(l => l.date >= WEEK_START);

        Object.keys(WEEK_DATES).forEach(dKey => {
          const sum = periodLogs.filter(l => l.date === dKey).reduce((acc, c) => acc + c.amount, 0);
          buckets.push({ label: WEEK_DATES[dKey].split(' ')[0], count: sum });
        });
      }
      else if (currentHistoryPeriod === 'month') {
        titleText = currentLang === 'fa' ? 'نمودار ۳۰ روز اخیر (ماهانه)' : 'Last 30 Days Breakdown (Monthly)';
        subText   = currentLang === 'fa' ? 'نمایش کامل تمام روزهای ماه برای رصد عادت‌های ماهانه' : 'Full 30-day timeline for monthly habit tracking';
        periodLogs = filteredByHabit.filter(l => l.date >= MONTH_START);

        Object.keys(MONTH_DATES).forEach(dKey => {
          const sum = periodLogs.filter(l => l.date === dKey).reduce((acc, c) => acc + c.amount, 0);
          buckets.push({ label: dKey.slice(8, 10), count: sum });
        });
      }
      else if (currentHistoryPeriod === 'year') {
        titleText = currentLang === 'fa' ? 'نمودار ۱۲ ماه اخیر (سالانه)' : '12-Month Breakdown (Yearly)';
        subText   = currentLang === 'fa' ? 'مجموع دفعات تکرار عادت در هر ماه از سال' : 'Total habit occurrences per month across the year';
        periodLogs = filteredByHabit.filter(l => l.date >= YEAR_START);

        Object.keys(YEAR_MONTHS).forEach(ymKey => {
          const sum = periodLogs.filter(l => l.month === ymKey).reduce((acc, c) => acc + c.amount, 0);
          buckets.push({ label: YEAR_MONTHS[ymKey], count: sum });
        });
      }

      const totalInPeriod = periodLogs.reduce((acc, c) => acc + c.amount, 0);
      document.getElementById('historyChartTitle').textContent = titleText;
      document.getElementById('historyChartSub').textContent   = subText;
      document.getElementById('historyPeriodTotalBadge').textContent = totalInPeriod;

      // رندر میله‌های نمودار
      const barsContainer = document.getElementById('historyDynamicBars');
      const maxVal = Math.max(4, ...buckets.map(b => b.count));

      barsContainer.innerHTML = buckets.map(b => {
        const hPx = b.count > 0 ? Math.max(10, Math.round((b.count / maxVal) * 85)) : 5;
        const barColor = b.count > 0 ? 'bg-gradient-to-t from-emerald-600 to-emerald-400' : 'bg-white/10';
        const barWidth = currentHistoryPeriod === 'month' ? 'w-1.5' : 'w-3.5';
        return `
          <div class="flex flex-col items-center justify-end h-full flex-1 min-w-[10px]">
            <span class="text-[8px] font-mono text-slate-400 mb-0.5">${b.count > 0 ? b.count : ''}</span>
            <div style="height:${hPx}px" class="${barWidth} rounded-t ${barColor} transition-all"></div>
            <span class="text-[8px] font-mono text-slate-400 mt-1 truncate max-w-full">${b.label}</span>
          </div>
        `;
      }).join('');

      // رندر لیست لاگ‌های ثبت‌شده در این بازه
      const logsListEl  = document.getElementById('historyLogsList');
      const logsCountEl = document.getElementById('historyLogsCount');
      logsCountEl.textContent = periodLogs.length;

      if (periodLogs.length === 0) {
        logsListEl.innerHTML = `<p class="text-[12px] text-slate-400 text-center py-4">${i18n[currentLang].hist_no_logs}</p>`;
      } else {
        logsListEl.innerHTML = periodLogs.slice(0, 40).map(lg => {
          const isBad = lg.type === 'bad';
          const badgeCls = isBad ? 'bg-rose-500/15 text-rose-300' : 'bg-emerald-500/15 text-emerald-300';
          return `
            <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-obsidian border border-white/[0.06] text-[11px]">
              <div class="flex items-center gap-2 min-w-0">
                <span>${lg.icon}</span>
                <span class="font-bold text-white text-lock">${lg.title}</span>
              </div>
              <div class="flex items-center gap-2.5 font-mono shrink-0">
                <span class="text-slate-400 text-[10px]">${lg.date} • ${lg.time_str}</span>
                <span class="px-1.5 py-0.5 rounded font-bold text-[10px] ${badgeCls}">+${lg.amount} ${lg.unit}</span>
              </div>
            </div>
          `;
        }).join('');
      }
    }

    // =====================================================================
    // 2. 4-DIGIT PIN VERIFICATION ENGINE
    // =====================================================================
    let currentPin = '';
    let pinBusy = false;

    function pressPinDigit(key) {
      if (pinBusy) return;
      if (key === 'C') currentPin = '';
      else if (key === 'B') currentPin = currentPin.slice(0, -1);
      else if (currentPin.length < 4 && /^\d$/.test(key)) currentPin += key;

      renderPinDots();
      if (currentPin.length === 4) verifyPinWithServer(currentPin);
    }

    function renderPinDots(isError = false) {
      const dots = document.querySelectorAll('#pinDotsRow .pin-dot');
      dots.forEach((dot, idx) => {
        if (isError) {
          dot.className = 'pin-dot w-3.5 h-3.5 rounded-full bg-rose-500 border border-rose-300 shadow-[0_0_10px_#F43F5E] transition-all';
        } else if (idx < currentPin.length) {
          dot.className = 'pin-dot w-3.5 h-3.5 rounded-full bg-emerald-400 border border-emerald-300 shadow-[0_0_10px_#10B981] transition-all';
        } else {
          dot.className = 'pin-dot w-3.5 h-3.5 rounded-full border border-white/30 bg-transparent transition-all';
        }
      });
    }

    async function verifyPinWithServer(pinValue) {
      pinBusy = true;
      const fd = new FormData();
      fd.append('ajax_action', 'verify_pin');
      fd.append('pin', pinValue);

      try {
        const res = await fetch('main.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          document.getElementById('pinLockOverlay').classList.add('hidden');
          document.getElementById('pinLockOverlay').classList.remove('flex');
          currentPin = '';
          renderPinDots();
        } else {
          renderPinDots(true);
          const box = document.getElementById('pinCardBox');
          const status = document.getElementById('pinStatusText');
          status.textContent = i18n[currentLang].pin_wrong;
          status.classList.add('text-rose-400');
          box.classList.add('holding-shake');
          if (navigator.vibrate) navigator.vibrate([70, 40, 70]);
          setTimeout(() => {
            box.classList.remove('holding-shake');
            currentPin = '';
            renderPinDots(false);
          }, 550);
        }
      } catch (err) {
        currentPin = '';
        renderPinDots(false);
      }
      pinBusy = false;
    }

    async function lockAppNow() {
      const fd = new FormData();
      fd.append('ajax_action', 'lock_app');
      await fetch('main.php', { method: 'POST', body: fd });
      currentPin = '';
      renderPinDots(false);
      const status = document.getElementById('pinStatusText');
      status.textContent = i18n[currentLang].pin_prompt;
      status.classList.remove('text-rose-400');
      const overlay = document.getElementById('pinLockOverlay');
      overlay.classList.remove('hidden');
      overlay.classList.add('flex');
    }

    window.addEventListener('keydown', (e) => {
      const overlay = document.getElementById('pinLockOverlay');
      if (overlay.classList.contains('hidden')) return;
      if (e.key >= '0' && e.key <= '9') pressPinDigit(e.key);
      else if (e.key === 'Backspace') pressPinDigit('B');
      else if (e.key === 'Escape') pressPinDigit('C');
    });

    // =====================================================================
    // 3. LIVE CLEAN TIMERS & HABIT LOGGING
    // =====================================================================
    function formatElapsed(sec) {
      const hrs  = String(Math.floor(sec / 3600)).padStart(2, '0');
      const mins = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
      const secs = String(sec % 60).padStart(2, '0');
      return `${hrs}h : ${mins}m : ${secs}s`;
    }

    function updateAllBadTimers() {
      document.querySelectorAll('.live-bad-timer').forEach(el => {
        let elapsed = parseInt(el.getAttribute('data-elapsed') || '0', 10);
        elapsed++;
        el.setAttribute('data-elapsed', String(elapsed));
        el.textContent = formatElapsed(elapsed);
      });
    }

    const activeHoldTimers = {};

    function startHoldBadHabit(e, habitId) {
      if (e.type === 'touchstart') e.preventDefault();
      if (activeHoldTimers[habitId]) return;

      const card  = document.getElementById(`habitCard_${habitId}`);
      const fill  = document.getElementById(`holdFill_${habitId}`);
      const label = document.getElementById(`holdLabel_${habitId}`);

      card.classList.add('holding-shake');
      fill.style.transition = 'width 1000ms linear';
      fill.style.width = '100%';
      label.textContent = i18n[currentLang].btn_holding_1s;

      activeHoldTimers[habitId] = setTimeout(async () => {
        delete activeHoldTimers[habitId];
        card.classList.remove('holding-shake');
        if (navigator.vibrate) navigator.vibrate(90);

        const fd = new FormData();
        fd.append('ajax_action', 'log_habit');
        fd.append('habit_id', String(habitId));

        const res = await fetch('main.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          document.getElementById(`habitCount_${habitId}`).textContent = data.today_count;
          const timerEl = document.getElementById(`timer_${habitId}`);
          timerEl.setAttribute('data-elapsed', '0');
          timerEl.textContent = '00h : 00m : 00s';

          const hudSlips = document.getElementById('hudTotalSlips');
          if (hudSlips) hudSlips.textContent = parseInt(hudSlips.textContent, 10) + 1;

          label.textContent = i18n[currentLang].btn_slip_recorded;
        }

        setTimeout(() => {
          fill.style.transition = 'none';
          fill.style.width = '0%';
          label.textContent = i18n[currentLang].btn_hold_1s;
        }, 1200);
      }, 1000);
    }

    function cancelHoldBadHabit(habitId) {
      if (!activeHoldTimers[habitId]) return;
      clearTimeout(activeHoldTimers[habitId]);
      delete activeHoldTimers[habitId];

      const card  = document.getElementById(`habitCard_${habitId}`);
      const fill  = document.getElementById(`holdFill_${habitId}`);
      const label = document.getElementById(`holdLabel_${habitId}`);

      card.classList.remove('holding-shake');
      fill.style.transition = 'width 200ms ease-out';
      fill.style.width = '0%';
      label.textContent = i18n[currentLang].btn_hold_1s;
    }

    async function logGoodHabit(habitId) {
      const fd = new FormData();
      fd.append('ajax_action', 'log_habit');
      fd.append('habit_id', String(habitId));

      const res = await fetch('main.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        document.getElementById(`habitCount_${habitId}`).textContent = data.today_count;
        const pct = Math.min(100, Math.round((data.today_count / Math.max(1, data.target)) * 100));
        document.getElementById(`goodBar_${habitId}`).style.width = `${pct}%`;
        showToast(currentLang === 'fa' ? '+۱ ثبت شد!' : '+1 Logged!');
      }
    }

    // =====================================================================
    // 4. FILTERING & 4-TAB NAVIGATION
    // =====================================================================
    function filterHabits(mode) {
      const secBad  = document.getElementById('sectionBadHabits');
      const secGood = document.getElementById('sectionGoodHabits');
      const btnAll  = document.getElementById('filterBtnAll');
      const btnGood = document.getElementById('filterBtnGood');
      const btnBad  = document.getElementById('filterBtnBad');

      btnAll.className  = 'h-8 rounded-lg text-[11px] font-bold text-slate-400 text-lock i18n-text';
      btnGood.className = 'h-8 rounded-lg text-[11px] font-bold text-emerald-400 text-lock i18n-text';
      btnBad.className  = 'h-8 rounded-lg text-[11px] font-bold text-rose-400 text-lock i18n-text';

      if (mode === 'all') {
        btnAll.className = 'h-8 rounded-lg text-[11px] font-bold bg-slateCard text-white shadow text-lock i18n-text';
        if (secBad) secBad.classList.remove('hidden');
        if (secGood) secGood.classList.remove('hidden');
      } else if (mode === 'good') {
        btnGood.className = 'h-8 rounded-lg text-[11px] font-bold bg-emerald-500/20 text-emerald-300 shadow text-lock i18n-text';
        if (secGood) secGood.classList.remove('hidden');
        if (secBad) secBad.classList.add('hidden');
      } else {
        btnBad.className = 'h-8 rounded-lg text-[11px] font-bold bg-rose-500/20 text-rose-300 shadow text-lock i18n-text';
        if (secBad) secBad.classList.remove('hidden');
        if (secGood) secGood.classList.add('hidden');
      }
    }

    function switchMainTab(tab) {
      const views = {
        dashboard: document.getElementById('viewDashboard'),
        history:   document.getElementById('viewHistory'),
        share:     document.getElementById('viewShare'),
        settings:  document.getElementById('viewSettings')
      };
      const btns = {
        dashboard: document.getElementById('navBtnDashboard'),
        history:   document.getElementById('navBtnHistory'),
        share:     document.getElementById('navBtnShare'),
        settings:  document.getElementById('navBtnSettings')
      };

      Object.keys(views).forEach(k => {
        if (k === tab) {
          views[k].classList.remove('hidden');
          btns[k].className = 'h-11 rounded-2xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 flex flex-col items-center justify-center px-1';
        } else {
          views[k].classList.add('hidden');
          btns[k].className = 'h-11 rounded-2xl bg-transparent border border-transparent text-slate-400 flex flex-col items-center justify-center px-1';
        }
      });

      if (tab === 'history') {
        renderHistoryView();
      }
    }

    function openAddHabitModal() {
      const m = document.getElementById('addHabitModal');
      m.classList.remove('hidden');
      m.classList.add('flex');
    }
    function closeAddHabitModal() {
      const m = document.getElementById('addHabitModal');
      m.classList.add('hidden');
      m.classList.remove('flex');
    }
    function selectHabitType(type) {
      document.getElementById('habitTypeInput').value = type;
      const bGood = document.getElementById('typeBtnGood');
      const bBad  = document.getElementById('typeBtnBad');
      const icon  = document.getElementById('habitIconInput');
      const unit  = document.getElementById('habitUnitInput');

      if (type === 'good') {
        bGood.className = 'h-9 rounded-lg text-[12px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-lock i18n-text';
        bBad.className  = 'h-9 rounded-lg text-[12px] font-bold text-slate-400 text-lock i18n-text';
        icon.value = '💧';
        unit.value = 'Glasses';
      } else {
        bBad.className  = 'h-9 rounded-lg text-[12px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40 text-lock i18n-text';
        bGood.className = 'h-9 rounded-lg text-[12px] font-bold text-slate-400 text-lock i18n-text';
        icon.value = '🚬';
        unit.value = 'Cigarettes';
      }
    }

    function copyText(txt) {
      navigator.clipboard.writeText(txt);
      showToast(currentLang === 'fa' ? 'لینک عادت کپی شد!' : 'Habit Link Copied!');
    }

    function showToast(msg) {
      const t = document.getElementById('appToast');
      t.textContent = msg;
      t.classList.remove('opacity-0');
      setTimeout(() => t.classList.add('opacity-0'), 2000);
    }

    function updatePcClock() {
      const now = new Date();
      const el = document.getElementById('pcClock');
      if (el) el.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    }

    document.addEventListener('DOMContentLoaded', () => {
      applyLanguage(currentLang);
      setHistoryPeriod('month');
      updatePcClock();
      setInterval(updatePcClock, 30000);

      document.querySelectorAll('.live-bad-timer').forEach(el => {
        const elapsed = parseInt(el.getAttribute('data-elapsed') || '0', 10);
        el.textContent = formatElapsed(elapsed);
      });
      setInterval(updateAllBadTimers, 1000);

      const initialTab = "<?php echo htmlspecialchars($openTabOnLoad); ?>";
      if (initialTab) switchMainTab(initialTab);
    });
  </script>
</body>
</html>