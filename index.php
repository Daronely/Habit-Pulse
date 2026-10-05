<?php
declare(strict_types=1);
session_start();

// 1. اگر کاربر از قبل لاگین کرده است، مستقیم به main.php منتقل شود
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: main.php');
    exit;
}

// 2. تنظیم و ساخت خودکار پوشه و دیتابیس SQLite (db/users.sqlite)
$dbDir  = __DIR__ . '/db';
$dbFile = $dbDir . '/users.sqlite';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // ایجاد جدول کاربران در صورت عدم وجود
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            display_name TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            fast_pin TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )
    ");
} catch (PDOException $e) {
    die('Database Connection Error: ' . htmlspecialchars($e->getMessage()));
}

// 3. پردازش فرم‌های ورود (Login) و ثبت‌نام (Register)
$authErrorKey  = '';
$activeAuthTab = ''; // 'login' یا 'register' برای باز ماندن شیت در صورت بروز خطا

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['auth_action'] ?? '';

    // --- الف) پردازش ورود به حساب (Sign In) ---
    if ($action === 'login') {
        $activeAuthTab = 'login';
        $username      = trim($_POST['username'] ?? '');
        $password      = $_POST['master_password'] ?? '';

        if ($username === '' || $password === '') {
            $authErrorKey = 'err_empty_fields';
        } else {
            $stmt = $pdo->prepare('SELECT id, display_name, username, password_hash FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']      = (int)$user['id'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['display_name'] = $user['display_name'];

                header('Location: main.php');
                exit;
            } else {
                $authErrorKey = 'err_invalid_login';
            }
        }
    }

    // --- ب) پردازش ساخت حساب جدید (Quick Sign Up) ---
    elseif ($action === 'register') {
        $activeAuthTab = 'register';
        $displayName   = trim($_POST['display_name'] ?? '');
        $username      = trim($_POST['username'] ?? '');
        $fastPin       = trim($_POST['fast_pin'] ?? '');
        $password      = $_POST['master_password'] ?? '';

        if ($displayName === '' || $username === '' || $fastPin === '' || $password === '') {
            $authErrorKey = 'err_empty_fields';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,24}$/', $username)) {
            $authErrorKey = 'err_invalid_username';
        } elseif (!preg_match('/^\d{4}$/', $fastPin)) {
            $authErrorKey = 'err_invalid_pin';
        } elseif (strlen($password) < 4) {
            $authErrorKey = 'err_short_password';
        } else {
            // بررسی تکراری نبودن نام کاربری
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
            $checkStmt->execute([':username' => $username]);

            if ($checkStmt->fetch()) {
                $authErrorKey = 'err_username_taken';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $pinHash      = password_hash($fastPin, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare('
                    INSERT INTO users (display_name, username, fast_pin, password_hash, created_at)
                    VALUES (:display_name, :username, :fast_pin, :password_hash, :created_at)
                ');
                $insertStmt->execute([
                    ':display_name'  => $displayName,
                    ':username'      => strtolower($username),
                    ':fast_pin'      => $pinHash,
                    ':password_hash' => $passwordHash,
                    ':created_at'    => time()
                ]);

                session_regenerate_id(true);
                $_SESSION['user_id']      = (int)$pdo->lastInsertId();
                $_SESSION['username']     = strtolower($username);
                $_SESSION['display_name'] = $displayName;

                header('Location: main.php');
                exit;
            }
        }
    }
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
  <title>HabitPulse</title>

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
    /* Strict Layout Lock for Bilingual Text */
    body.lang-fa .i18n-text {
      font-family: 'Vazirmatn', 'Inter', sans-serif;
      letter-spacing: 0 !important;
    }
    .text-lock {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .emerald-glow {
      box-shadow: 0 0 25px -4px rgba(16, 185, 129, 0.35);
    }
  </style>
</head>

<body class="min-h-[100dvh] flex flex-col items-center justify-center sm:py-6">

  <!-- ===================================================================== -->
  <!-- APP CONTAINER: Full-Screen Native on Mobile | Phone Frame ONLY on PC  -->
  <!-- ===================================================================== -->
  <div id="androidAppShell" class="relative w-full min-h-[100dvh] sm:min-h-[800px] sm:max-h-[860px] sm:max-w-[410px] bg-obsidian sm:rounded-[38px] sm:border-[6px] sm:border-[#1E2638] sm:shadow-[0_25px_70px_rgba(0,0,0,0.85)] flex flex-col justify-between overflow-hidden">

    <!-- Subtle Ambient Glows -->
    <div class="pointer-events-none absolute -top-24 left-1/2 -translate-x-1/2 w-80 h-60 rounded-full bg-emerald-500/10 blur-3xl"></div>
    <div class="pointer-events-none absolute bottom-10 left-1/2 -translate-x-1/2 w-72 h-52 rounded-full bg-rose-500/5 blur-3xl"></div>

    <!-- ================================================================= -->
    <!-- 1. TOP NAVBAR (Status Bar Icons Shown ONLY on Desktop `sm:flex`)  -->
    <!-- ================================================================= -->
    <header class="relative z-20 pt-3 sm:pt-2 px-5 pb-3 border-b border-white/[0.06] bg-obsidian/80 backdrop-blur-md">
      
      <!-- PC-ONLY Simulated Android Status Bar (Hidden on Mobile Devices) -->
      <div class="hidden sm:flex items-center justify-between text-[11px] text-slate-400 font-mono mb-2.5 px-0.5">
        <span id="pcClock">22:14</span>
        <div class="flex items-center gap-1.5">
          <span class="text-[10px] text-emerald-400 font-semibold">5G</span>
          <svg class="w-3.5 h-3.5 text-slate-300" fill="currentColor" viewBox="0 0 24 24"><path d="M2 22h20V2z"/></svg>
          <svg class="w-4 h-4 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M15.67 4H14V2h-4v2H8.33C7.6 4 7 4.6 7 5.33v15.33C7 21.4 7.6 22 8.33 22h7.33c.74 0 1.34-.6 1.34-1.33V5.33C17 4.6 16.4 4 15.67 4z"/></svg>
        </div>
      </div>

      <!-- Main App Header -->
      <div class="flex items-center justify-between h-11">
        <!-- Brand Identity with img/icon.png -->
        <div class="flex items-center gap-2.5 min-w-0">
          <img src="img/icon.png" alt="HabitPulse Icon" class="w-9 h-9 rounded-xl object-cover border border-white/10 shadow-inner shrink-0 bg-carbon" />
          <div class="min-w-0">
            <div class="flex items-center gap-1.5">
              <span class="font-bold text-[15px] tracking-tight text-white">HabitPulse</span>
            </div>
            <p class="text-[11px] text-slate-400 text-lock i18n-text" data-i18n="nav_sub">Tactical Habit System</p>
          </div>
        </div>

        <!-- Language Switcher (EN / FA) -->
        <button id="langToggleBtn" type="button" onclick="toggleLanguage()" class="h-9 px-3 rounded-xl bg-carbon border border-white/10 hover:border-white/20 active:scale-95 transition flex items-center gap-1.5 text-xs font-mono shrink-0">
          <span id="langBadgeEN" class="px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold">EN</span>
          <span class="text-slate-600">/</span>
          <span id="langBadgeFA" class="px-1.5 py-0.5 rounded text-slate-400">FA</span>
        </button>
      </div>
    </header>

    <!-- ================================================================= -->
    <!-- 2. CENTER HERO & LOGIN CTA                                        -->
    <!-- ================================================================= -->
    <main class="relative z-10 flex-1 flex flex-col justify-center px-6 py-6">
      <div class="text-center my-auto">

        <!-- Center App Icon (Exact same img/icon.png) -->
        <div class="relative mx-auto w-24 h-24 rounded-[28px] p-1 bg-gradient-to-b from-white/15 to-white/[0.03] shadow-2xl mb-6 flex items-center justify-center">
          <div class="absolute -inset-1.5 rounded-[32px] bg-gradient-to-r from-emerald-500/25 via-transparent to-rose-500/25 blur-md -z-10"></div>
          <img src="img/icon.png" alt="HabitPulse" class="w-full h-full rounded-[24px] object-cover bg-carbon" />
        </div>

        <!-- Title & Simple Project Description -->
        <h1 class="text-[22px] font-extrabold text-white tracking-tight mb-2.5 text-lock i18n-text" data-i18n="hero_title">
          Build Good Habits. Quit the Bad.
        </h1>
        <p class="text-[13px] text-slate-300 leading-relaxed max-w-[320px] mx-auto mb-6 i18n-text" data-i18n="hero_desc">
          A simple pocket tracker to grow positive daily routines with 1 tap and gradually reduce bad habits with a live clean timer.
        </p>

        <!-- Dual Feature Summary Pills -->
        <div class="grid grid-cols-2 gap-2.5 mb-8 text-left">
          <div class="p-3 rounded-2xl bg-emerald-500/[0.07] border border-emerald-500/20 flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 font-bold text-sm">+1</div>
            <div class="min-w-0">
              <div class="text-[12px] font-bold text-emerald-300 text-lock i18n-text" data-i18n="pill_good_title">Good Habits</div>
              <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="pill_good_sub">1-Tap Daily Growth</div>
            </div>
          </div>
          <div class="p-3 rounded-2xl bg-rose-500/[0.07] border border-rose-500/20 flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center shrink-0 font-mono text-[11px] font-bold">1.0s</div>
            <div class="min-w-0">
              <div class="text-[12px] font-bold text-rose-300 text-lock i18n-text" data-i18n="pill_bad_title">Bad Habits</div>
              <div class="text-[10px] text-slate-400 text-lock i18n-text" data-i18n="pill_bad_sub">Hold & Live Timer</div>
            </div>
          </div>
        </div>

        <!-- Primary Center Action Buttons -->
        <div class="space-y-3">
          <button type="button" onclick="openAuthSheet('login')" class="w-full h-12 rounded-2xl bg-emerald-500 hover:bg-emerald-400 active:scale-[0.98] transition-all emerald-glow text-obsidian font-extrabold text-[14px] flex items-center justify-center gap-2 shadow-lg">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3" />
            </svg>
            <span class="text-lock i18n-text" data-i18n="btn_main_login">Sign In to Account</span>
          </button>

          <button type="button" onclick="openAuthSheet('register')" class="w-full h-12 rounded-2xl bg-slateCard hover:bg-white/[0.08] active:scale-[0.98] transition border border-white/10 text-slate-200 font-semibold text-[13px] flex items-center justify-center gap-2">
            <span class="text-lock i18n-text" data-i18n="btn_create_account">New User? Create Free Account</span>
          </button>
        </div>

      </div>
    </main>

    <!-- ================================================================= -->
    <!-- 3. PC-ONLY ANDROID HOME GESTURE BAR (Hidden on Mobile Devices)    -->
    <!-- ================================================================= -->
    <footer class="hidden sm:block relative z-10 px-5 pt-2 pb-3 bg-obsidian/90 text-center">
      <div class="w-32 h-1 bg-white/20 rounded-full mx-auto"></div>
    </footer>

    <!-- ================================================================= -->
    <!-- 4. SLIDING BOTTOM-SHEET MODAL: SIGN IN & SIGN UP                  -->
    <!-- ================================================================= -->
    <div id="authSheetBackdrop" onclick="closeAuthSheet()" class="fixed sm:absolute inset-0 z-40 bg-black/75 backdrop-blur-sm hidden opacity-0 transition-opacity duration-200 flex flex-col justify-end">
      <div id="authSheetPanel" onclick="event.stopPropagation()" class="w-full bg-carbon border-t border-white/15 rounded-t-[28px] p-5 pb-7 sm:pb-5 shadow-2xl transform translate-y-full transition-transform duration-300">
        
        <!-- Drag Handle -->
        <div class="w-10 h-1.5 bg-white/20 rounded-full mx-auto mb-4"></div>

        <!-- Segmented Switcher: Sign In | Quick Sign Up -->
        <div class="grid grid-cols-2 p-1 rounded-xl bg-obsidian border border-white/10 mb-4">
          <button id="tabBtnLogin" type="button" onclick="switchAuthTab('login')" class="h-9 rounded-lg text-[12px] font-bold bg-slateCard text-white shadow text-lock i18n-text" data-i18n="tab_signin">
            Sign In
          </button>
          <button id="tabBtnRegister" type="button" onclick="switchAuthTab('register')" class="h-9 rounded-lg text-[12px] font-bold text-slate-400 hover:text-white text-lock i18n-text" data-i18n="tab_signup">
            Quick Sign Up
          </button>
        </div>

        <!-- Server-Side Validation Error Banner -->
        <div id="authErrorBanner" class="<?php echo $authErrorKey ? 'flex' : 'hidden'; ?> mb-3.5 p-3 rounded-xl bg-rose-500/15 border border-rose-500/40 items-center justify-between text-rose-200 text-[12px]">
          <div class="flex items-center gap-2 min-w-0">
            <span class="text-rose-400 font-bold">!</span>
            <span id="authErrorText" class="text-lock i18n-text" data-i18n="<?php echo htmlspecialchars($authErrorKey ?: 'err_invalid_login'); ?>">
              Invalid Username or Master Password
            </span>
          </div>
          <button type="button" onclick="hideErrorBanner()" class="text-xs text-rose-300 px-1.5">✕</button>
        </div>

        <!-- FORM 1: SIGN IN -->
        <form id="loginForm" method="POST" action="index.php" class="space-y-3">
          <input type="hidden" name="auth_action" value="login" />
          <div>
            <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_username">Username</label>
            <input type="text" name="username" required autocomplete="username" placeholder="amirali"
              value="<?php echo ($activeAuthTab === 'login' && isset($_POST['username'])) ? htmlspecialchars($_POST['username']) : ''; ?>"
              class="w-full h-11 px-3.5 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px] font-mono" />
          </div>
          <div>
            <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_password">Master Password</label>
            <input type="password" name="master_password" required autocomplete="current-password" placeholder="••••••••"
              class="w-full h-11 px-3.5 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px] font-mono" />
          </div>

          <button type="submit" class="w-full h-12 mt-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 active:scale-[0.98] transition text-obsidian font-extrabold text-[14px] shadow-lg text-lock i18n-text" data-i18n="btn_unlock_dashboard">
            Unlock Dashboard Now
          </button>
        </form>

        <!-- FORM 2: QUICK SIGN UP -->
        <form id="registerForm" method="POST" action="index.php" class="hidden space-y-3">
          <input type="hidden" name="auth_action" value="register" />
          <div class="grid grid-cols-2 gap-2.5">
            <div>
              <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_fullname">Display Name</label>
              <input type="text" name="display_name" required placeholder="AmirAli"
                value="<?php echo ($activeAuthTab === 'register' && isset($_POST['display_name'])) ? htmlspecialchars($_POST['display_name']) : ''; ?>"
                class="w-full h-11 px-3 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px]" />
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_username">Username</label>
              <input type="text" name="username" required autocomplete="username" placeholder="amirali"
                value="<?php echo ($activeAuthTab === 'register' && isset($_POST['username'])) ? htmlspecialchars($_POST['username']) : ''; ?>"
                class="w-full h-11 px-3 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px] font-mono" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2.5">
            <div>
              <label class="block text-[11px] font-semibold text-emerald-400 mb-1 text-lock i18n-text" data-i18n="lbl_fast_pin">4-Digit Fast PIN</label>
              <input type="password" name="fast_pin" maxlength="4" pattern="\d{4}" inputmode="numeric" required placeholder="0000"
                class="w-full h-11 px-3 rounded-xl bg-obsidian border border-emerald-500/30 focus:border-emerald-500 focus:outline-none text-emerald-300 text-center text-[15px] font-mono tracking-[0.35em]" />
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-slate-300 mb-1 text-lock i18n-text" data-i18n="lbl_password">Master Password</label>
              <input type="password" name="master_password" required autocomplete="new-password" placeholder="••••••••"
                class="w-full h-11 px-3 rounded-xl bg-obsidian border border-white/10 focus:border-emerald-500 focus:outline-none text-white text-[13px] font-mono" />
            </div>
          </div>

          <button type="submit" class="w-full h-12 mt-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 active:scale-[0.98] transition text-obsidian font-extrabold text-[14px] shadow-lg text-lock i18n-text" data-i18n="btn_create_launch">
            Create Account & Launch
          </button>
        </form>

      </div>
    </div>

  </div>

  <!-- ===================================================================== -->
  <!-- JAVASCRIPT: BILINGUAL DICTIONARY & BOTTOM-SHEET CONTROLLER            -->
  <!-- ===================================================================== -->
  <script>
    const i18n = {
      en: {
        nav_sub: "Tactical Habit System",
        hero_title: "Build Good Habits. Quit the Bad.",
        hero_desc: "A simple pocket tracker to grow positive daily routines with 1 tap and gradually reduce bad habits with a live clean timer.",
        pill_good_title: "Good Habits",
        pill_good_sub: "1-Tap Daily Growth",
        pill_bad_title: "Bad Habits",
        pill_bad_sub: "Hold & Live Timer",
        btn_main_login: "Sign In to Account",
        btn_create_account: "New User? Create Free Account",
        tab_signin: "Sign In",
        tab_signup: "Quick Sign Up",
        lbl_username: "Username",
        lbl_password: "Master Password",
        lbl_fullname: "Display Name",
        lbl_fast_pin: "4-Digit Fast PIN",
        btn_unlock_dashboard: "Unlock Dashboard Now",
        btn_create_launch: "Create Account & Launch",
        err_empty_fields: "Please fill in all required fields",
        err_invalid_login: "Invalid Username or Master Password",
        err_invalid_username: "Username must be 3-24 English letters/numbers",
        err_invalid_pin: "Fast PIN must be exactly 4 digits",
        err_short_password: "Password must be at least 4 characters",
        err_username_taken: "This username is already taken"
      },
      fa: {
        nav_sub: "سیستم هوشمند مدیریت عادت",
        hero_title: "ببین پسر عادت کن عادت نکنی",
        hero_desc: "برنامه‌ای ساده و کاربردی برای ثبت عادت‌های مثبت روزانه با یک لمس و کاهش تدریجی عادت‌های مضر همراه با تایمر زنده.",
        pill_good_title: "عادت‌های خوب",
        pill_good_sub: "ثبت سریع با ۱ لمس",
        pill_bad_title: "عادت‌های بد",
        pill_bad_sub: "لمس طولانی و تایمر",
        btn_main_login: "ورود به حساب کاربری",
        btn_create_account: "کاربر جدید هستید؟ ساخت حساب",
        tab_signin: "ورود به حساب",
        tab_signup: "ثبت‌نام سریع",
        lbl_username: "نام کاربری (انگلیسی)",
        lbl_password: "رمز عبور اصلی",
        lbl_fullname: "نام نمایشی",
        lbl_fast_pin: "رمز سریع ۴ رقمی",
        btn_unlock_dashboard: "ورود به داشبورد",
        btn_create_launch: "ساخت حساب و ورود",
        err_empty_fields: "لطفاً تمام فیلدها را کامل کنید",
        err_invalid_login: "نام کاربری یا رمز عبور اشتباه است",
        err_invalid_username: "نام کاربری باید ۳ تا ۲۴ حرف انگلیسی/عدد باشد",
        err_invalid_pin: "رمز سریع باید دقیقاً ۴ رقم عددی باشد",
        err_short_password: "رمز عبور باید حداقل ۴ کاراکتر باشد",
        err_username_taken: "این نام کاربری قبلاً ثبت شده است"
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
        badgeFA.className = 'px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
        badgeEN.className = 'px-1.5 py-0.5 rounded text-slate-400';
      } else {
        badgeEN.className = 'px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-bold';
        badgeFA.className = 'px-1.5 py-0.5 rounded text-slate-400';
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

    // Bottom Sheet Modal Logic
    function openAuthSheet(mode = 'login') {
      const backdrop = document.getElementById('authSheetBackdrop');
      const panel = document.getElementById('authSheetPanel');
      backdrop.classList.remove('hidden');
      switchAuthTab(mode, false);
      requestAnimationFrame(() => {
        backdrop.classList.remove('opacity-0');
        panel.classList.remove('translate-y-full');
      });
    }

    function closeAuthSheet() {
      const backdrop = document.getElementById('authSheetBackdrop');
      const panel = document.getElementById('authSheetPanel');
      backdrop.classList.add('opacity-0');
      panel.classList.add('translate-y-full');
      setTimeout(() => backdrop.classList.add('hidden'), 250);
    }

    function switchAuthTab(mode, clearError = true) {
      const loginForm = document.getElementById('loginForm');
      const registerForm = document.getElementById('registerForm');
      const tabLogin = document.getElementById('tabBtnLogin');
      const tabRegister = document.getElementById('tabBtnRegister');

      if (clearError) hideErrorBanner();

      if (mode === 'login') {
        loginForm.classList.remove('hidden');
        registerForm.classList.add('hidden');
        tabLogin.className = 'h-9 rounded-lg text-[12px] font-bold bg-slateCard text-white shadow text-lock i18n-text';
        tabRegister.className = 'h-9 rounded-lg text-[12px] font-bold text-slate-400 hover:text-white text-lock i18n-text';
      } else {
        registerForm.classList.remove('hidden');
        loginForm.classList.add('hidden');
        tabRegister.className = 'h-9 rounded-lg text-[12px] font-bold bg-slateCard text-white shadow text-lock i18n-text';
        tabLogin.className = 'h-9 rounded-lg text-[12px] font-bold text-slate-400 hover:text-white text-lock i18n-text';
      }
    }

    function hideErrorBanner() {
      const banner = document.getElementById('authErrorBanner');
      banner.classList.add('hidden');
      banner.classList.remove('flex');
    }

    // Update PC status bar clock
    function updatePcClock() {
      const now = new Date();
      const el = document.getElementById('pcClock');
      if (el) {
        el.textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
      }
    }

    // Initialize on load
    document.addEventListener('DOMContentLoaded', () => {
      applyLanguage(currentLang);
      updatePcClock();
      setInterval(updatePcClock, 30000);

      // اگر خطای PHP وجود داشت، شیت مربوطه به صورت خودکار باز شود
      const serverActiveTab = "<?php echo htmlspecialchars($activeAuthTab); ?>";
      if (serverActiveTab === 'login' || serverActiveTab === 'register') {
        openAuthSheet(serverActiveTab);
      }
    });
  </script>
</body>
</html>