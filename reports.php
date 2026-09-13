<?php
session_start();

// Authentication Check: Ensure Admin Is Logged In
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Database Connection Configuration
$host = 'sql101.infinityfree.com';
$db   = 'if0_42538348_gigpay_db';
$user = 'if0_42538348';
$pass = 'w6qxDZ0nlp1hr';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Ensure columns for government deductions, absents, and tardiness exist safely in payrolls table
try {
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS sss_deduction DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS philhealth_deduction DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS pagibig_deduction DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS absent_days DECIMAL(5,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS tardy_count INT DEFAULT 0");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS absent_deduction DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS tardy_deduction DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE payrolls ADD COLUMN IF NOT EXISTS net_pay DECIMAL(10,2) DEFAULT 0.00");
} catch (\PDOException $e) {}

/**
 * ROBUST PAYROLL UPSERT LOGIC (ANTI-TAMPERING / SINGLE ACTIVE UNPAID RECORD)
 * This handles saving payroll computations securely: updates active row if unpaid, inserts only if previous is paid.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_payroll') {
    $employee_id = filter_var($_POST['employee_id'], FILTER_VALIDATE_INT);
    $pay_period_start = $_POST['pay_period_start'] ?? date('Y-m-01');
    $pay_period_end = $_POST['pay_period_end'] ?? date('Y-m-t');
    $days_worked = filter_var($_POST['days_worked'] ?? 0, FILTER_VALIDATE_FLOAT);
    $basic_pay = filter_var($_POST['basic_pay'] ?? 0, FILTER_VALIDATE_FLOAT);
    $allowance = filter_var($_POST['allowance'] ?? 0, FILTER_VALIDATE_FLOAT);
    $overtime_pay = filter_var($_POST['overtime_pay'] ?? 0, FILTER_VALIDATE_FLOAT);
    $sss = filter_var($_POST['sss_deduction'] ?? 0, FILTER_VALIDATE_FLOAT);
    $philhealth = filter_var($_POST['philhealth_deduction'] ?? 0, FILTER_VALIDATE_FLOAT);
    $pagibig = filter_var($_POST['pagibig_deduction'] ?? 0, FILTER_VALIDATE_FLOAT);
    $cash_advance = filter_var($_POST['cash_advance'] ?? 0, FILTER_VALIDATE_FLOAT);
    $total_deductions = $sss + $philhealth + $pagibig + $cash_advance;
    $net_pay = ($basic_pay + $allowance + $overtime_pay) - $total_deductions;
    $status = trim(strtolower($_POST['status'] ?? 'pending'));

    if ($employee_id) {
        // Check if there is an existing UNPAID/PENDING record for this employee
        $stmtCheck = $pdo->prepare("SELECT id, status FROM payrolls WHERE employee_id = ? AND LOWER(TRIM(status)) NOT IN ('paid', 'released') ORDER BY id DESC LIMIT 1");
        $stmtCheck->execute([$employee_id]);
        $existingRecord = $stmtCheck->fetch();

        if ($existingRecord && $status !== 'paid') {
            // UPDATE existing row if it's still unpaid/pending to prevent spam/tampering duplication rows
            $stmtUpdate = $pdo->prepare("
                UPDATE payrolls SET 
                    pay_period_start = ?, pay_period_end = ?, days_worked = ?, basic_pay = ?, 
                    allowance = ?, overtime_pay = ?, sss_deduction = ?, philhealth_deduction = ?, 
                    pagibig_deduction = ?, cash_advance = ?, total_deductions = ?, net_pay = ?, status = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $pay_period_start, $pay_period_end, $days_worked, $basic_pay,
                $allowance, $overtime_pay, $sss, $philhealth,
                $pagibig, $cash_advance, $total_deductions, $net_pay, $status, $existingRecord['id']
            ]);
        } else {
            // INSERT a brand new row if the previous one is already marked 'paid'/'released', or if no record exists yet
            $stmtInsert = $pdo->prepare("
                INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, days_worked, basic_pay, allowance, overtime_pay, sss_deduction, philhealth_deduction, pagibig_deduction, cash_advance, total_deductions, net_pay, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $employee_id, $pay_period_start, $pay_period_end, $days_worked, $basic_pay, $allowance, $overtime_pay, $sss, $philhealth, $pagibig, $cash_advance, $total_deductions, $net_pay, $status
            ]);
        }
    }
    header('Location: reports.php?success=payroll_saved');
    exit;
}

// Robust Multi-Source Attendance Table Resolution
$attendance_table_to_query = 'attendance';
foreach(['attendance', 'biometric_logs', 'f01h_logs', 'device_logs'] as $tbl) {
    try {
        $chk = $pdo->query("SHOW TABLES LIKE '$tbl'")->rowCount();
        if($chk > 0) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
            if($cnt > 0) {
                $attendance_table_to_query = $tbl;
                break;
            }
        }
    } catch (Exception $e) {}
}

// Fetch all independent records for the unified master table with robust fallbacks
$all_employees = [];
$all_attendance = [];
$all_requests = [];
$all_payrolls = [];
$all_biometrics = [];

try {
    $all_employees = $pdo->query("
        SELECT e.*, 
               COALESCE(e.daily_rate, e.salary_rate, d.default_daily_salary, d.daily_rate, 0.00) AS resolved_daily_rate 
        FROM employees e 
        LEFT JOIN departments d ON (e.department_id = d.id OR e.department = d.department_name)
        ORDER BY e.id DESC
    ")->fetchAll();
} catch (Exception $e) {
    try {
        $all_employees = $pdo->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll();
    } catch (Exception $ex) {}
}

try {
    $all_attendance = $pdo->query("
        SELECT 
            COALESCE(e.id, a.employee_id) as employee_id,
            COALESCE(e.first_name, 'Unknown') as first_name, 
            COALESCE(e.last_name, '') as last_name, 
            COALESCE(e.department, 'N/A') as department,
            COUNT(a.id) as total_logs,
            MAX(COALESCE(a.check_in, a.timestamp, NOW())) as latest_timestamp
        FROM $attendance_table_to_query a 
        LEFT JOIN employees e ON a.employee_id = e.id 
        GROUP BY COALESCE(e.id, a.employee_id), COALESCE(e.first_name, 'Unknown'), COALESCE(e.last_name, ''), COALESCE(e.department, 'N/A')
        ORDER BY latest_timestamp DESC
    ")->fetchAll();
} catch (Exception $e) {
    $all_attendance = [];
}

try {
    $reqs = [];
    $gen_req_check = $pdo->query("SHOW TABLES LIKE 'requests'")->rowCount();
    if ($gen_req_check > 0) {
        $gen_rows = $pdo->query("
            SELECT r.id, r.employee_id, r.type as req_type, r.status, r.created_at, 
                   COALESCE(r.details, r.amount, 'N/A') as details,
                   COALESCE(r.amount, 0.00) as amount,
                   e.first_name, e.last_name, e.department
            FROM requests r
            LEFT JOIN employees e ON r.employee_id = e.id
            ORDER BY r.created_at DESC
        ")->fetchAll();
        $reqs = array_merge($reqs, $gen_rows);
    }
    $all_requests = $reqs;
} catch (Exception $e) {
    $all_requests = [];
}

try {
    $all_payrolls = $pdo->query("
        SELECT 
            COALESCE(e.id, p.employee_id) as employee_id,
            COALESCE(e.first_name, 'Unknown') as first_name, 
            COALESCE(e.last_name, '') as last_name, 
            COALESCE(e.department, 'N/A') as department,
            p.id as payroll_id,
            p.pay_period_start,
            p.pay_period_end,
            p.days_worked,
            p.basic_pay,
            p.allowance,
            p.overtime_pay,
            p.sss_deduction,
            p.philhealth_deduction,
            p.pagibig_deduction,
            p.cash_advance,
            p.total_deductions,
            COALESCE(p.net_pay, (p.basic_pay + COALESCE(p.allowance, 0) + COALESCE(p.overtime_pay, 0) - COALESCE(p.total_deductions, 0))) as total_net_pay,
            p.status,
            1 as total_payout_records
        FROM payrolls p 
        LEFT JOIN employees e ON p.employee_id = e.id 
        ORDER BY p.id DESC
    ")->fetchAll();
} catch (Exception $e) {
    $all_payrolls = [];
}

$all_biometrics = $all_attendance;

$total_employees = count($all_employees);
$total_attendance_logs = count($all_attendance);

$total_payroll_disbursed = 0.00;
try {
    $total_payroll_disbursed = $pdo->query("
        SELECT SUM(COALESCE(net_pay, (basic_pay + COALESCE(allowance, 0) + COALESCE(overtime_pay, 0) - COALESCE(total_deductions, (COALESCE(sss_deduction, 0) + COALESCE(philhealth_deduction, 0) + COALESCE(pagibig_deduction, 0) + COALESCE(cash_advance, 0)))))) 
        FROM payrolls
        WHERE LOWER(TRIM(status)) IN ('paid', 'released')
    ")->fetchColumn() ?: 0.00;
} catch (Exception $e) {
    $total_payroll_disbursed = 0.00;
}

$total_cash_advances = 0.00;
try {
    $ca_check = $pdo->query("SHOW TABLES LIKE 'cash_advance_requests'")->rowCount();
    if ($ca_check > 0) {
        $total_cash_advances += (float) $pdo->query("SELECT SUM(COALESCE(amount, 0)) FROM cash_advance_requests WHERE LOWER(TRIM(status)) IN ('accepted', 'approved', 'paid', 'released')")->fetchColumn() ?: 0.00;
    }
    
    $gen_req_check = $pdo->query("SHOW TABLES LIKE 'requests'")->rowCount();
    if ($gen_req_check > 0) {
        $total_cash_advances += (float) $pdo->query("SELECT SUM(COALESCE(amount, 0)) FROM requests WHERE (LOWER(TRIM(type)) LIKE '%cash advance%' OR LOWER(TRIM(req_type)) LIKE '%cash advance%') AND LOWER(TRIM(status)) IN ('accepted', 'approved', 'paid', 'released')")->fetchColumn() ?: 0.00;
    }
} catch (Exception $e) {
    $total_cash_advances = 0.00;
}

$total_payroll_disbursed_with_advances = $total_payroll_disbursed + $total_cash_advances;
?>
<!DOCTYPE html>
<html lang="en" class="min-h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Reports & Analytics</title>
    <script>
    // App-Wide Dark Mode: applied before first paint to avoid a flash of the wrong theme.
    // Preference is stored in localStorage under 'gigpay_app_dark_mode' so it persists
    // across EVERY admin page (Admin Dashboard, Manage Requests, Attendance, Employees,
    // Biometrics, Hikvision, Payroll, Reports, Settings) and covers both the sidebar
    // AND the inside content/features of each page — not just the sidebar.
    (function () {
        try {
            var pref = localStorage.getItem('gigpay_app_dark_mode');
            if (pref === null) {
                // Backward-compatible migration from the old sidebar-only key, if present.
                var legacy = localStorage.getItem('gigpay_sidebar_dark_mode');
                pref = (legacy === '0') ? '0' : '1'; // default ON the first time ever loading an admin page
                localStorage.setItem('gigpay_app_dark_mode', pref);
            }
            document.documentElement.setAttribute('data-app-dark', pref === '1' ? '1' : '0');
        } catch (e) {
            document.documentElement.setAttribute('data-app-dark', '1');
        }
    })();
</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal-backdrop {
            background-color: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        @media print {
            body * { visibility: hidden !important; }
            #recordModal, #recordModal * { visibility: visible !important; }
            #recordModal {
                position: absolute !important;
                left: 0 !important; top: 0 !important;
                width: 100% !important; height: auto !important;
                background: white !important; backdrop-filter: none !important;
                z-index: 99999 !important; display: flex !important;
            }
            aside, header, .no-print, button, .modal-backdrop { display: none !important; }
        }
            /* ======================================================================
           APP-WIDE DARK MODE
           Toggled via the "Dark Mode" tab in Settings. Preference lives in
           localStorage under 'gigpay_app_dark_mode' and is read by every page
           that includes this same block (Admin Dashboard, Manage Requests,
           Attendance, Employees, Biometrics, Hikvision, Payroll, Reports,
           Settings) — so BOTH the sidebar AND the inside content/features of
           each page switch together and stay in sync everywhere.
           ====================================================================== */

        /* ---- Sidebar (desktop + mobile variants; matched by their shared
               navy background color since the sidebar element doesn't share
               one consistent id across every page) ---- */
        html[data-app-dark="1"] [class*="0c132b"] {
            background-image: none !important;
            background-color: #05070f !important;
        }
        html[data-app-dark="1"] [class*="0c132b"] nav a {
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        html[data-app-dark="1"] [class*="0c132b"] nav a i {
            color: #94a3b8 !important;
        }
        html[data-app-dark="1"] [class*="0c132b"] nav a:hover {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
        }
        html[data-app-dark="1"] [class*="0c132b"] nav a.bg-blue-700 {
            background-color: #1d4ed8 !important;
            border-color: #1e40af !important;
        }
        html[data-app-dark="1"] [class*="0c132b"] .border-b-2,
        html[data-app-dark="1"] [class*="0c132b"] .border-t-2 {
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        html[data-app-dark="1"] [class*="0c132b"] span.text-blue-400 {
            color: #60a5fa !important;
        }

        /* ---- App shell (page background + top header bar) ---- */
        html[data-app-dark="1"] body,
        html[data-app-dark="1"] html {
            background-color: #0b0f1a !important;
        }
        html[data-app-dark="1"] .text-slate-900 { color: #f8fafc !important; }

        /* ---- Generic content surfaces: cards, panels, header, tabs, modals,
               tables. These key off the same Tailwind utility classes used
               consistently on every page, so the "features" inside each page
               go dark too — not just the sidebar. The sidebar rules above are
               more specific and always win, so the sidebar look is untouched
               by the rules below. ---- */
        html[data-app-dark="1"] .bg-white     { background-color: #10162a !important; }
        html[data-app-dark="1"] .bg-slate-50  { background-color: #161d33 !important; }
        html[data-app-dark="1"] .bg-slate-100 { background-color: #0b0f1a !important; }
        html[data-app-dark="1"] .bg-slate-200 { background-color: #1c2540 !important; }
        html[data-app-dark="1"] .bg-slate-300 { background-color: #26304f !important; }

        html[data-app-dark="1"] .hover\:bg-slate-50:hover  { background-color: #161d33 !important; }
        html[data-app-dark="1"] .hover\:bg-slate-100:hover { background-color: #1c2540 !important; }
        html[data-app-dark="1"] .hover\:bg-slate-200:hover { background-color: #232d4d !important; }
        html[data-app-dark="1"] .hover\:bg-slate-300:hover { background-color: #2c3757 !important; }

        html[data-app-dark="1"] .text-slate-800 { color: #f1f5f9 !important; }
        html[data-app-dark="1"] .text-slate-700 { color: #e2e8f0 !important; }
        html[data-app-dark="1"] .text-slate-600 { color: #cbd5e1 !important; }
        html[data-app-dark="1"] .text-slate-500 { color: #94a3b8 !important; }
        html[data-app-dark="1"] .text-slate-400 { color: #748094 !important; }
        html[data-app-dark="1"] .hover\:text-slate-600:hover { color: #f1f5f9 !important; }

        html[data-app-dark="1"] .border-slate-200 { border-color: #232d4d !important; }
        html[data-app-dark="1"] .border-slate-300 { border-color: #2c3757 !important; }
        html[data-app-dark="1"] .divide-slate-100 > * { border-color: #1c2540 !important; }

        /* Form fields */
        html[data-app-dark="1"] input,
        html[data-app-dark="1"] select,
        html[data-app-dark="1"] textarea {
            background-color: #0b0f1a !important;
            color: #f1f5f9 !important;
            border-color: #2c3757 !important;
        }
        html[data-app-dark="1"] input:focus,
        html[data-app-dark="1"] select:focus,
        html[data-app-dark="1"] textarea:focus {
            border-color: #60a5fa !important;
            box-shadow: 0 0 0 2px rgba(96, 165, 250, 0.15) !important;
        }
        html[data-app-dark="1"] input::placeholder,
        html[data-app-dark="1"] textarea::placeholder { color: #64748b !important; }
        html[data-app-dark="1"] label { color: #cbd5e1 !important; }

        /* Tables */
        html[data-app-dark="1"] th { color: #94a3b8 !important; }
        html[data-app-dark="1"] td { color: #e2e8f0 !important; }

        /* Shared "info"/alert banners keep readable text on dark surfaces */
        html[data-app-dark="1"] .bg-red-100     { background-color: #3b1420 !important; }
        html[data-app-dark="1"] .bg-emerald-100 { background-color: #0d2b22 !important; }
        html[data-app-dark="1"] .bg-red-50      { background-color: #3b1420 !important; }
    </style>
</head>
<body class="min-h-full font-sans antialiased flex text-slate-950 font-bold bg-slate-100">

    <!-- Sidebar Navigation -->
    <aside class="w-64 bg-[#0c132b] text-slate-100 flex-col justify-between hidden md:flex shrink-0 select-none border-r-2 border-slate-900" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg'); background-size: cover; background-position: center;">
    <div class="h-full flex flex-col justify-between">
        <div>
            <div class="h-20 flex items-center px-6 space-x-3 border-b-2 border-slate-800">
                <div class="flex items-center justify-center">
                    <img src="pay.png" alt="Pay Icon" class="w-12 h-10 object-contain">
                </div>
                <span class="text-xl font-black tracking-tight text-white">Gig<span class="text-blue-400">Pay</span></span>
            </div>
            <nav class="p-4 space-y-1.5 text-sm font-bold">
                <a href="admin_dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-chart-pie w-5 text-slate-300"></i><span>Dashboard</span>
                </a>
                <a href="manage_requests.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-clock-rotate-left w-5 text-slate-300"></i><span>Manage Requests</span>
                </a>
                <a href="attendance.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-calendar-days w-5 text-slate-300"></i><span>Attendance</span>
                </a>
                <a href="employee.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-users w-5 text-slate-300"></i><span>Employees</span>
                </a>
                <a href="biometric.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-fingerprint w-5 text-slate-300"></i><span>F01H Biometrics</span>
                </a>
                <a href="hikvision.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-video w-5 text-slate-300"></i><span>Hikvision Biometrics</span>
                </a>
                <a href="payroll.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-wallet w-5 text-slate-300"></i><span>Payroll Computation</span>
                </a>
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
                    <i class="fa-solid fa-chart-line w-5 text-slate-300"></i><span>Reports & Analytics</span>
                </a>
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-gear w-5 text-slate-300"></i><span>Settings</span>
                </a>
            </nav>
        </div>
        <div class="p-4 border-t-2 border-slate-800">
            <button onclick="openLogoutModal()" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-red-300 hover:bg-red-500/20 hover:text-red-200 transition text-sm font-black text-left border border-red-500/30">
                <i class="fa-solid fa-arrow-right-from-bracket w-5"></i><span>Logout</span>
            </button>
        </div>
    </div>
</aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto h-screen">
        
        <!-- Header -->
        <header class="h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-8 shrink-0 sticky top-0 z-30">
            <div class="flex items-center space-x-3">
                <h1 class="text-xl font-black text-slate-950 tracking-tight truncate">Reports & Analytics</h1>
                <button onclick="location.reload()" class="flex items-center space-x-2 px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-900 text-xs font-extrabold transition border-2 border-slate-300 shadow-sm">
                    <i class="fa-solid fa-rotate"></i><span>Refresh</span>
                </button>
            </div>
            <div class="flex items-center space-x-3 pl-4 border-l-2 border-slate-300">
                <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-900 flex items-center justify-center font-black text-sm border-2 border-blue-300 shrink-0">AD</div>
                <div class="text-left">
                    <p class="text-xs font-black text-slate-950">System Admin</p>
                    <p class="text-[11px] font-extrabold text-slate-700">Administrator</p>
                </div>
            </div>
        </header>

        <main class="flex-1 p-8 space-y-8 bg-slate-100">
            
            <!-- Analytics Cards -->
            <div class="grid grid-cols-3 gap-6 no-print">
                <div class="bg-white p-6 rounded-2xl border-2 border-slate-300 shadow-md flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-slate-700">Total Workforce</p>
                        <p class="text-3xl font-black text-slate-950 mt-1"><?php echo number_format($total_employees); ?></p>
                        <span class="text-xs font-extrabold text-slate-700 mt-1 inline-block">Active workforce</span>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 text-blue-800 rounded-xl flex items-center justify-center text-xl font-bold border-2 border-blue-300 shrink-0">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border-2 border-slate-300 shadow-md flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-slate-700">Total Payroll Disbursed</p>
                        <p class="text-3xl font-black text-slate-950 mt-1">₱<?php echo number_format($total_payroll_disbursed_with_advances, 2); ?></p>
                        <span class="text-xs font-extrabold text-emerald-800 mt-1 inline-block">Computed Net Earnings (Paid Only) + Advances</span>
                    </div>
                    <div class="w-12 h-12 bg-emerald-100 text-emerald-800 rounded-xl flex items-center justify-center text-xl font-bold border-2 border-emerald-300 shrink-0">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border-2 border-slate-300 shadow-md flex items-center justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider text-slate-700">Attendance Sync Logs</p>
                        <p class="text-3xl font-black text-slate-950 mt-1"><?php echo number_format($total_attendance_logs); ?></p>
                        <span class="text-xs font-extrabold text-indigo-800 mt-1 inline-block">Biometric Check-ins</span>
                    </div>
                    <div class="w-12 h-12 bg-indigo-100 text-indigo-800 rounded-xl flex items-center justify-center text-xl font-bold border-2 border-indigo-300 shrink-0">
                        <i class="fa-solid fa-fingerprint"></i>
                    </div>
                </div>
            </div>

            <!-- Scrollable Tab Switchers -->
            <div class="flex items-center gap-2 bg-white p-2 rounded-2xl border-2 border-slate-300 shadow-md no-print overflow-x-auto whitespace-nowrap">
                <button onclick="switchMasterTab('employees')" id="tab_btn_employees" class="master-tab-btn px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center space-x-2 text-slate-900 hover:bg-slate-200 border-2 border-transparent">
                    <i class="fa-solid fa-users"></i> <span>Employees (<?php echo count($all_employees); ?>)</span>
                </button>
                <button onclick="switchMasterTab('attendance')" id="tab_btn_attendance" class="master-tab-btn px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center space-x-2 text-slate-900 hover:bg-slate-200 border-2 border-transparent">
                    <i class="fa-solid fa-calendar-days"></i> <span>Attendance (<?php echo count($all_attendance); ?>)</span>
                </button>
                <button onclick="switchMasterTab('requests')" id="tab_btn_requests" class="master-tab-btn px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center space-x-2 text-slate-900 hover:bg-slate-200 border-2 border-transparent">
                    <i class="fa-solid fa-clock-rotate-left"></i> <span>Manage Requests (<?php echo count($all_requests); ?>)</span>
                </button>
                <button onclick="switchMasterTab('payroll')" id="tab_btn_payroll" class="master-tab-btn px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center space-x-2 bg-blue-700 text-white shadow-md border border-blue-500">
                    <i class="fa-solid fa-wallet"></i> <span>Payroll Payouts (<?php echo count($all_payrolls); ?>)</span>
                </button>
                <button onclick="switchMasterTab('dashboard')" id="tab_btn_dashboard" class="master-tab-btn px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center space-x-2 text-slate-900 hover:bg-slate-200 border-2 border-transparent">
                    <i class="fa-solid fa-chart-pie"></i> <span>Dashboard Metrics</span>
                </button>
                <button onclick="switchMasterTab('biometrics')" id="tab_btn_biometrics" class="master-tab-btn px-4 py-2 rounded-xl text-xs font-extrabold transition flex items-center space-x-2 text-slate-900 hover:bg-slate-200 border-2 border-transparent">
                    <i class="fa-solid fa-fingerprint"></i> <span>Biometrics (<?php echo count($all_biometrics); ?>)</span>
                </button>
            </div>

            <!-- Master Section Datatables -->
            <div class="bg-white rounded-2xl border-2 border-slate-300 shadow-md overflow-hidden">
                
                <div id="master_section_employees" class="master-section hidden">
                    <div class="p-6 border-b-2 border-slate-300 flex items-center justify-between bg-slate-100">
                        <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm">
                            <i class="fa-solid fa-users text-blue-700"></i><span>Employees Master Records</span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-200 text-slate-950 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                    <th class="py-3.5 px-6">Name</th>
                                    <th class="py-3.5 px-6">Email</th>
                                    <th class="py-3.5 px-6">Department</th>
                                    <th class="py-3.5 px-6">Daily Salary Rate</th>
                                    <th class="py-3.5 px-6 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                                <?php if(empty($all_employees)): ?>
                                    <tr><td colspan="5" class="py-8 text-center text-slate-500 font-extrabold">No employee records found.</td></tr>
                                <?php else: foreach($all_employees as $emp): 
                                    $computed_rate = $emp['resolved_daily_rate'] 
                                        ?? $emp['daily_rate'] 
                                        ?? $emp['salary_rate'] 
                                        ?? $emp['rate'] 
                                        ?? $emp['default_daily_salary'] 
                                        ?? 0;
                                ?>
                                    <tr onclick='openRecordModal("Employee Record", <?php echo json_encode($emp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="hover:bg-slate-100 transition cursor-pointer">
                                        <td class="py-4 px-6 font-black text-slate-950"><?php echo htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')); ?></td>
                                        <td class="py-4 px-6 text-slate-900 font-extrabold"><?php echo htmlspecialchars($emp['email'] ?? 'N/A'); ?></td>
                                        <td class="py-4 px-6"><span class="px-2.5 py-1 bg-slate-200 text-slate-950 rounded-lg text-xs font-black border border-slate-300"><?php echo htmlspecialchars($emp['department_name'] ?? $emp['department'] ?? 'N/A'); ?></span></td>
                                        <td class="py-4 px-6 font-black text-emerald-800">₱<?php echo number_format((float)$computed_rate, 2); ?></td>
                                        <td class="py-4 px-6 text-right"><button class="px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-black transition shadow-sm border border-blue-500">Inspect</button></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="master_section_attendance" class="master-section hidden">
                    <div class="p-6 border-b-2 border-slate-300 flex items-center justify-between bg-slate-100">
                        <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm">
                            <i class="fa-solid fa-calendar-days text-blue-700"></i><span>Attendance Master Summary</span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-200 text-slate-950 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                    <th class="py-3.5 px-6">Employee ID</th>
                                    <th class="py-3.5 px-6">Employee Name</th>
                                    <th class="py-3.5 px-6">Department</th>
                                    <th class="py-3.5 px-6">Total Logs Recorded</th>
                                    <th class="py-3.5 px-6">Latest Activity</th>
                                    <th class="py-3.5 px-6 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                                <?php if(empty($all_attendance)): ?>
                                    <tr><td colspan="6" class="py-8 text-center text-slate-500 font-extrabold">No attendance logs found.</td></tr>
                                <?php else: foreach($all_attendance as $att): ?>
                                    <tr onclick='openRecordModal("Attendance Log Summary", <?php echo json_encode($att, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="hover:bg-slate-100 transition cursor-pointer">
                                        <td class="py-4 px-6 font-mono text-xs font-black text-blue-800">#<?php echo htmlspecialchars($att['employee_id'] ?? '1'); ?></td>
                                        <td class="py-4 px-6 font-black text-slate-950"><?php echo htmlspecialchars(($att['first_name'] ?? 'Unknown') . ' ' . ($att['last_name'] ?? '')); ?></td>
                                        <td class="py-4 px-6"><span class="px-2.5 py-1 bg-slate-200 text-slate-950 rounded-lg text-xs font-black border border-slate-300"><?php echo htmlspecialchars($att['department'] ?? 'N/A'); ?></span></td>
                                        <td class="py-4 px-6 font-black text-indigo-800"><?php echo htmlspecialchars($att['total_logs'] ?? '0'); ?> log(s)</td>
                                        <td class="py-4 px-6 text-slate-900 font-extrabold"><?php echo htmlspecialchars($att['latest_timestamp'] ?? 'N/A'); ?></td>
                                        <td class="py-4 px-6 text-right"><button class="px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-black transition shadow-sm border border-blue-500">Inspect</button></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="master_section_requests" class="master-section hidden">
                    <div class="p-6 border-b-2 border-slate-300 flex items-center justify-between bg-slate-100">
                        <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm">
                            <i class="fa-solid fa-clock-rotate-left text-blue-700"></i><span>Manage Requests Master Records</span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-200 text-slate-950 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                    <th class="py-3.5 px-6">Request ID</th>
                                    <th class="py-3.5 px-6">Employee</th>
                                    <th class="py-3.5 px-6">Type</th>
                                    <th class="py-3.5 px-6">Status</th>
                                    <th class="py-3.5 px-6">Amount / Details</th>
                                    <th class="py-3.5 px-6 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                                <?php if(empty($all_requests)): ?>
                                    <tr><td colspan="6" class="py-8 text-center text-slate-500 font-extrabold">No requests found.</td></tr>
                                <?php else: foreach($all_requests as $req): ?>
                                    <tr onclick='openRecordModal("Request Record", <?php echo json_encode($req, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="hover:bg-slate-100 transition cursor-pointer">
                                        <td class="py-4 px-6 font-mono text-xs font-black text-blue-800">#<?php echo htmlspecialchars($req['id'] ?? '1'); ?></td>
                                        <td class="py-4 px-6 font-black text-slate-950"><?php echo htmlspecialchars(($req['first_name'] ?? 'Unknown') . ' ' . ($req['last_name'] ?? '')); ?></td>
                                        <td class="py-4 px-6"><span class="px-2.5 py-1 bg-slate-200 text-slate-950 rounded-lg text-xs font-black border border-slate-300"><?php echo htmlspecialchars($req['req_type'] ?? 'General'); ?></span></td>
                                        <td class="py-4 px-6"><span class="px-2.5 py-1 rounded-full text-xs font-black border-2 bg-amber-100 text-amber-900 border-amber-300"><?php echo ucfirst($req['status'] ?? 'Pending'); ?></span></td>
                                        <td class="py-4 px-6 font-black text-emerald-800"><?php echo isset($req['amount']) && $req['amount'] > 0 ? '₱' . number_format($req['amount'], 2) : 'N/A'; ?></td>
                                        <td class="py-4 px-6 text-right"><button class="px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-black transition shadow-sm border border-blue-500">Inspect</button></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="master_section_payroll" class="master-section">
                    <div class="p-6 border-b-2 border-slate-300 flex items-center justify-between bg-slate-100">
                        <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm">
                            <i class="fa-solid fa-wallet text-blue-700"></i><span>Payroll Payouts Database Records</span>
                        </h3>
                        <span class="text-xs bg-emerald-100 text-emerald-900 font-black px-3 py-1 rounded-full border-2 border-emerald-300">Connected to Table: payrolls</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-200 text-slate-950 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                    <th class="py-3.5 px-6">Payroll ID</th>
                                    <th class="py-3.5 px-6">Employee</th>
                                    <th class="py-3.5 px-6">Pay Period</th>
                                    <th class="py-3.5 px-6">Basic Pay</th>
                                    <th class="py-3.5 px-6">Allow/OT</th>
                                    <th class="py-3.5 px-6">Net Payout</th>
                                    <th class="py-3.5 px-6 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                                <?php if(empty($all_payrolls)): ?>
                                    <tr><td colspan="7" class="py-8 text-center text-slate-500 font-extrabold">No payroll records found.</td></tr>
                                <?php else: foreach($all_payrolls as $pay): ?>
                                    <tr onclick='openRecordModal("Payroll Database Record", <?php echo json_encode($pay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="hover:bg-slate-100 transition cursor-pointer">
                                        <td class="py-4 px-6 font-mono text-xs font-black text-blue-800">#<?php echo htmlspecialchars($pay['payroll_id'] ?? $pay['employee_id']); ?></td>
                                        <td class="py-4 px-6 font-black text-slate-950"><?php echo htmlspecialchars(($pay['first_name'] ?? 'Unknown') . ' ' . ($pay['last_name'] ?? '')); ?></td>
                                        <td class="py-4 px-6 text-xs font-extrabold text-slate-700"><?php echo htmlspecialchars($pay['pay_period_start'] . ' to ' . $pay['pay_period_end']); ?></td>
                                        <td class="py-4 px-6 font-black text-slate-950">₱<?php echo number_format($pay['basic_pay'] ?? 0, 2); ?></td>
                                        <td class="py-4 px-6 font-black text-blue-800">₱<?php echo number_format(($pay['allowance'] ?? 0) + ($pay['overtime_pay'] ?? 0), 2); ?></td>
                                        <td class="py-4 px-6 font-black text-emerald-800">₱<?php echo number_format($pay['total_net_pay'] ?? 0, 2); ?></td>
                                        <td class="py-4 px-6 text-right"><button class="px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-black transition shadow-sm border border-blue-500">Inspect</button></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="master_section_dashboard" class="master-section hidden">
                    <div class="p-6 border-b-2 border-slate-300 bg-slate-100">
                        <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm">
                            <i class="fa-solid fa-chart-pie text-blue-700"></i><span>Dashboard System Metrics Overview</span>
                        </h3>
                    </div>
                    <div class="p-8 space-y-6">
                        <div class="grid grid-cols-3 gap-6">
                            <div class="bg-slate-100 p-6 rounded-2xl border-2 border-slate-300">
                                <p class="text-xs font-black uppercase text-slate-700">Total Registered Employees</p>
                                <p class="text-3xl font-black text-slate-950 mt-2"><?php echo count($all_employees); ?></p>
                            </div>
                            <div class="bg-slate-100 p-6 rounded-2xl border-2 border-slate-300">
                                <p class="text-xs font-black uppercase text-slate-700">Total Attendance Logs</p>
                                <p class="text-3xl font-black text-slate-950 mt-2"><?php echo count($all_attendance); ?></p>
                            </div>
                            <div class="bg-slate-100 p-6 rounded-2xl border-2 border-slate-300">
                                <p class="text-xs font-black uppercase text-slate-700">Total Requests Filed</p>
                                <p class="text-3xl font-black text-slate-950 mt-2"><?php echo count($all_requests); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="master_section_biometrics" class="master-section hidden">
                    <div class="p-6 border-b-2 border-slate-300 flex items-center justify-between bg-slate-100">
                        <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm">
                            <i class="fa-solid fa-fingerprint text-blue-700"></i><span>F01H Biometrics Master Logs</span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-slate-200 text-slate-950 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                    <th class="py-3.5 px-6">Employee ID</th>
                                    <th class="py-3.5 px-6">Employee Name</th>
                                    <th class="py-3.5 px-6">Total Logs Recorded</th>
                                    <th class="py-3.5 px-6 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                                <?php if(empty($all_biometrics)): ?>
                                    <tr><td colspan="4" class="py-8 text-center text-slate-500 font-extrabold">No biometric logs found.</td></tr>
                                <?php else: foreach($all_biometrics as $bio): ?>
                                    <tr onclick='openRecordModal("Biometric Log Summary", <?php echo json_encode($bio, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="hover:bg-slate-100 transition cursor-pointer">
                                        <td class="py-4 px-6 font-mono text-xs font-black text-blue-800">#<?php echo htmlspecialchars($bio['employee_id']); ?></td>
                                        <td class="py-4 px-6 font-black text-slate-950"><?php echo htmlspecialchars(($bio['first_name'] ?? 'Unknown') . ' ' . ($bio['last_name'] ?? '')); ?></td>
                                        <td class="py-4 px-6 text-slate-900 font-extrabold"><?php echo htmlspecialchars($bio['total_logs']); ?> log(s)</td>
                                        <td class="py-4 px-6 text-right"><button class="px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-black transition shadow-sm border border-blue-500">Inspect</button></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- Enhanced Modal with Raw Data & Formatted Views -->
    <div id="recordModal" class="fixed inset-0 modal-backdrop z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border-2 border-slate-400 transform transition-all flex flex-col max-h-[90vh]">
            <div class="flex items-center justify-between pb-4 border-b-2 border-slate-300 shrink-0">
                <h3 id="modalTitle" class="text-base font-black text-slate-950">Record Inspector</h3>
                <button type="button" onclick="closeRecordModal()" class="w-8 h-8 bg-slate-200 hover:bg-slate-300 text-slate-950 rounded-xl flex items-center justify-center text-sm font-black border-2 border-slate-300 transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="flex items-center space-x-2 py-3 border-b-2 border-slate-200 shrink-0">
                <button type="button" onclick="switchModalView('formatted')" id="modal_tab_formatted" class="px-3.5 py-1.5 rounded-xl text-xs font-black bg-blue-700 text-white shadow-sm transition border border-blue-500">
                    <i class="fa-solid fa-list-check mr-1.5"></i> Formatted View
                </button>
                <button type="button" onclick="switchModalView('raw')" id="modal_tab_raw" class="px-3.5 py-1.5 rounded-xl text-xs font-black bg-slate-200 text-slate-900 hover:bg-slate-300 transition border-2 border-slate-300">
                    <i class="fa-solid fa-code mr-1.5"></i> Raw JSON Data
                </button>
            </div>

            <div class="py-4 overflow-y-auto flex-1 space-y-4">
                <div id="modalFormattedView" class="space-y-3">
                    <div id="formattedCardContent" class="grid grid-cols-2 gap-3"></div>
                </div>
                <div id="modalRawView" class="hidden">
                    <pre id="modalJsonOutput" class="bg-slate-950 text-emerald-400 p-4 rounded-xl font-mono text-xs overflow-x-auto border-2 border-slate-800"></pre>
                </div>
            </div>

            <div class="mt-6 pt-4 border-t-2 border-slate-300 flex items-center justify-between shrink-0">
                <span id="modalFooterText" class="text-[11px] text-slate-700 font-extrabold">Formatted card summary view.</span>
                <div class="flex items-center space-x-3">
                    <button type="button" onclick="triggerActualPrint()" class="px-4 py-2.5 rounded-xl bg-blue-700 hover:bg-blue-800 text-white font-black text-xs transition shadow-md no-print border border-blue-500">
                        <i class="fa-solid fa-print mr-1.5"></i> Print Record
                    </button>
                    <button type="button" onclick="closeRecordModal()" class="px-4 py-2.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-950 font-black text-xs transition no-print border-2 border-slate-300">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 modal-backdrop z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border-2 border-slate-400 transform transition-all">
            <div class="w-12 h-12 bg-red-100 text-red-700 rounded-xl flex items-center justify-center text-xl mb-4 font-black border-2 border-red-300">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 class="text-lg font-black text-slate-950 mb-1">Confirm Logout</h3>
            <p class="text-xs font-extrabold text-slate-700 mb-6">Are you sure you want to log out of your admin account?</p>
            <div class="flex space-x-3">
                <button onclick="closeLogoutModal()" class="flex-1 px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border-2 border-slate-300">
                    Cancel
                </button>
                <a href="index.php?logout=true" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white text-xs font-black rounded-xl transition text-center flex items-center justify-center shadow-md border border-red-500">
                    Yes, Logout
                </a>
            </div>
        </div>
    </div>

    <script>
        function switchMasterTab(tabName) {
            document.querySelectorAll('.master-section').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.master-tab-btn').forEach(el => {
                el.classList.remove('bg-blue-700', 'text-white', 'shadow-md', 'border-blue-500');
                el.classList.add('text-slate-900', 'hover:bg-slate-200', 'border-transparent');
            });
            document.getElementById('master_section_' + tabName).classList.remove('hidden');
            const activeBtn = document.getElementById('tab_btn_' + tabName);
            activeBtn.classList.remove('text-slate-900', 'hover:bg-slate-200', 'border-transparent');
            activeBtn.classList.add('bg-blue-700', 'text-white', 'shadow-md', 'border-blue-500');
        }

        function switchModalView(viewType) {
            const formattedView = document.getElementById('modalFormattedView');
            const rawView = document.getElementById('modalRawView');
            const tabFormatted = document.getElementById('modal_tab_formatted');
            const tabRaw = document.getElementById('modal_tab_raw');
            const footerText = document.getElementById('modalFooterText');

            if (viewType === 'formatted') {
                formattedView.classList.remove('hidden');
                rawView.classList.add('hidden');
                tabFormatted.className = "px-3.5 py-1.5 rounded-xl text-xs font-black bg-blue-700 text-white shadow-sm transition border border-blue-500";
                tabRaw.className = "px-3.5 py-1.5 rounded-xl text-xs font-black bg-slate-200 text-slate-900 hover:bg-slate-300 transition border-2 border-slate-300";
                footerText.innerText = "Formatted card summary view.";
            } else {
                formattedView.classList.add('hidden');
                rawView.classList.remove('hidden');
                tabRaw.className = "px-3.5 py-1.5 rounded-xl text-xs font-black bg-blue-700 text-white shadow-sm transition border border-blue-500";
                tabFormatted.className = "px-3.5 py-1.5 rounded-xl text-xs font-black bg-slate-200 text-slate-900 hover:bg-slate-300 transition border-2 border-slate-300";
                footerText.innerText = "Raw JSON code block inspector view.";
            }
        }

        function openRecordModal(title, data) {
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalJsonOutput').innerText = JSON.stringify(data, null, 2);
            
            let container = document.getElementById('formattedCardContent');
            let cardsHTML = '';
            
            for (const [key, value] of Object.entries(data)) {
                let formattedKey = key.replace(/_/g, ' ').toUpperCase();
                let displayVal = (value !== null && value !== '') ? value : 'N/A';
                
                cardsHTML += `
                    <div class="bg-slate-50 p-3.5 rounded-xl border-2 border-slate-200 flex flex-col shadow-xs">
                        <span class="text-[10px] font-black text-slate-500 tracking-wider uppercase">${formattedKey}</span>
                        <span class="text-xs font-black text-slate-950 mt-1 break-words">${displayVal}</span>
                    </div>
                `;
            }
            container.innerHTML = cardsHTML;
            switchModalView('formatted');
            document.getElementById('recordModal').classList.remove('hidden');
        }

        function closeRecordModal() {
            document.getElementById('recordModal').classList.add('hidden');
        }

        function triggerActualPrint() {
            window.print();
        }

        function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }
    </script>
</body>
</html>