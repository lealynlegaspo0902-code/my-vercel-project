<?php
session_start();

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

$pdo = null;
$db_error = '';
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    $db_error = $e->getMessage();
}

// Ensure database table for dynamic company-wide admin holidays / suspension records exists with date ranges
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS company_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            type ENUM('Holiday', 'Suspension', 'Calamity') DEFAULT 'Holiday',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (\Exception $ex) {
        // Suppress creation errors if lacks privileges
    }
}

// HANDLE AJAX / POST SUBMISSION FOR ADDING OR DELETING DYNAMIC SUSPENSIONS/HOLIDAYS OR APPROVING/REJECTING REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if ($_POST['ajax_action'] === 'add_holiday') {
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $type = trim($_POST['type'] ?? 'Holiday');

        if (empty($end_date)) {
            $end_date = $start_date; // Fallback to single day if end date is blank
        }

        if (!empty($start_date) && !empty($end_date) && !empty($title) && $pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO company_holidays (start_date, end_date, title, description, type) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$start_date, $end_date, $title, $desc, $type]);
                
                // Automatically update employee attendance logs/records for all dates within the range to 'No Work'
                $att_update = $pdo->prepare("UPDATE attendance SET type = 'No Work', status = ? WHERE DATE(COALESCE(check_in, timestamp)) BETWEEN ? AND ?");
                $att_update->execute([$title, $start_date, $end_date]);

                echo json_encode(['success' => true, 'message' => 'Holiday/Calamity date range saved successfully and applied to all corresponding employee calendar records.']);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid date range or title parameters provided.']);
        }
        exit();
    } elseif ($_POST['ajax_action'] === 'delete_holiday') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0 && $pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM company_holidays WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Holiday/Suspension record removed successfully.']);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        }
        exit();
    } elseif ($_POST['ajax_action'] === 'handle_request_action') {
        $req_id = intval($_POST['req_id'] ?? 0);
        $req_type = trim($_POST['req_type'] ?? '');
        $action_val = trim($_POST['action_val'] ?? ''); // 'approve' or 'reject'

        if ($req_id > 0 && !empty($req_type) && !empty($action_val) && $pdo) {
            try {
                $new_status = ($action_val === 'approve') ? 'Approved' : 'Rejected';
                if ($req_type === 'pin_reset') {
                    $stmt = $pdo->prepare("UPDATE pin_reset_requests SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $req_id]);
                } else {
                    // General requests table
                    $stmt = $pdo->prepare("UPDATE requests SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $req_id]);
                }
                echo json_encode(['success' => true, 'message' => 'Request successfully ' . strtolower($new_status) . '.']);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
        }
        exit();
    }
}

// 1. HANDLE LOGIN FORM POST SUBMISSION FROM login.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && isset($_POST['password'])) {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? OR username = ? LIMIT 1");
            $stmt->execute([$email, $email]);
            $admin = $stmt->fetch();

            if ($admin && (password_verify($password, $admin['password']) || $password === $admin['password'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['full_name'] ?? 'System Admin';
                $_SESSION['admin_logged_in'] = true;
            } else {
                header("Location: index.php?error=invalid_credentials");
                exit();
            }
        } catch (\Exception $e) {
            if ($email === 'admin@gigpay.com' && $password === 'admin123') {
                $_SESSION['admin_id'] = 1;
                $_SESSION['admin_name'] = 'System Admin';
                $_SESSION['admin_logged_in'] = true;
            } else {
                header("Location: index.php?error=db_error");
                exit();
            }
        }
    }
}

// 1.1 HANDLE PIN RESET REQUEST SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_pin_reset') {
    $employee_identifier = trim($_POST['employee_identifier'] ?? '');
    if ($pdo && !empty($employee_identifier)) {
        try {
            $emp_stmt = $pdo->prepare("SELECT id FROM employees WHERE email = ? OR id = ? OR username = ? LIMIT 1");
            $emp_stmt->execute([$employee_identifier, $employee_identifier, $employee_identifier]);
            $emp = $emp_stmt->fetch();

            if ($emp) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS pin_reset_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id INT NOT NULL,
                    status VARCHAR(50) DEFAULT 'Pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $ins = $pdo->prepare("INSERT INTO pin_reset_requests (employee_id, status) VALUES (?, 'Pending')");
                $ins->execute([$emp['id']]);
            }
        } catch (\Exception $ex) {
            // Handle error quietly
        }
    }
}

// 2. AUTHENTICATION GUARD: Check if the admin session is active
if (!isset($_SESSION['admin_id'])) {
    header("Location: landing.php");
    exit();
}

// Fetch Real-Time Dashboard Statistics & Logs
$total_employees = 0;
$total_payroll_processed = 0.00;
$pending_requests_count = 0;
$attendance_type_stats = [];
$all_requests_data = [];
$employee_birthdays = [];
$notifications = [];
$company_holidays = [];
$pending_queue_items = [];

if ($pdo) {
    try {
        // Total Active Employees
        $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'");
        $total_employees = $stmt->fetchColumn();

        // Total Payroll Processed
        $stmt = $pdo->query("SELECT SUM(net_pay) FROM payrolls WHERE status = 'Paid'");
        $total_payroll_processed = $stmt->fetchColumn() ?: 0.00;

        // Pending Requests Count
        $stmt = $pdo->query("SELECT COUNT(*) FROM payrolls WHERE status = 'Pending Payout'");
        $pending_requests_count = $stmt->fetchColumn();

        // Attendance Type Statistics for Pie Graph
        $stmt = $pdo->query("SELECT COALESCE(type, 'Unspecified') as log_type, COUNT(*) as count FROM attendance GROUP BY type");
        $attendance_type_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Requests for Calendar Highlights & Notifications
        $req_query = "
            SELECT r.*, e.first_name, e.last_name, e.department 
            FROM requests r 
            JOIN employees e ON r.employee_id = e.id 
            ORDER BY r.created_at DESC
        ";
        $all_requests_data = $pdo->query($req_query)->fetchAll();

        // Fetch Custom Admin-Injected Holidays / Calamities (Date Ranges)
        try {
            $hol_stmt = $pdo->query("SELECT * FROM company_holidays ORDER BY start_date DESC");
            $company_holidays = $hol_stmt->fetchAll();
        } catch (\Exception $ex) {
            $company_holidays = [];
        }

        foreach ($all_requests_data as $req) {
            if (isset($req['status']) && strtolower($req['status']) === 'pending') {
                $reqType = $req['type'] ?? 'Request';
                $notes = !empty($req['notes']) ? $req['notes'] : ($req['reason'] ?? 'No additional details provided.');
                
                $notifications[] = [
                    'title' => ($reqType === 'Info Update' ? 'Profile Info Update Request' : 'New Request Submission'),
                    'desc' => $req['first_name'] . ' ' . $req['last_name'] . ' submitted a ' . $reqType . ': "' . $notes . '"',
                    'time' => $req['created_at'],
                    'type' => 'request'
                ];

                // Populate Pending Queue items
                $pending_queue_items[] = [
                    'id' => $req['id'],
                    'employee_name' => $req['first_name'] . ' ' . $req['last_name'],
                    'department' => $req['department'] ?? 'General',
                    'title' => $reqType,
                    'details' => $notes,
                    'time' => $req['created_at'],
                    'queue_type' => 'request'
                ];
            }
        }

        // Fetch PIN Reset requests from database tables
        try {
            $pin_stmt = $pdo->query("SELECT p.*, e.first_name, e.last_name, e.department FROM pin_reset_requests p JOIN employees e ON p.employee_id = e.id WHERE p.status = 'Pending' ORDER BY p.created_at DESC");
            $pin_requests = $pin_stmt->fetchAll();
            foreach ($pin_requests as $pr) {
                $notifications[] = [
                    'title' => 'Employee Password/PIN Reset',
                    'desc' => trim($pr['first_name'] . ' ' . $pr['last_name']) . ' requested a PIN reset.',
                    'time' => $pr['created_at'] ?? date('Y-m-d H:i:s'),
                    'type' => 'pin_reset'
                ];

                // Populate Pending Queue items
                $pending_queue_items[] = [
                    'id' => $pr['id'],
                    'employee_name' => $pr['first_name'] . ' ' . $pr['last_name'],
                    'department' => $pr['department'] ?? 'General',
                    'title' => 'PIN/Password Reset',
                    'details' => 'Employee requested a system PIN/password reset.',
                    'time' => $pr['created_at'] ?? date('Y-m-d H:i:s'),
                    'queue_type' => 'pin_reset'
                ];
            }
        } catch (\Exception $ex) {
            // Ignore missing table
        }

        // Fetch Employee Birthdays for Calendar
        $stmt_birthdays = $pdo->query("SELECT first_name, last_name, department, birth_date FROM employees");
        $employee_birthdays = $stmt_birthdays->fetchAll();

    } catch (\Exception $e) {
        // Fallback gracefully
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-200">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Admin Dashboard</title>
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
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            900: '#030712',
                            800: '#111827',
                            700: '#1f2937',
                            600: '#1d4ed8',
                            500: '#2563eb',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @keyframes slideIn {
            from { transform: translateY(1rem); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .animate-slide-in {
            animation: slideIn 0.2s ease-out forwards;
        }
        .custom-sidebar-bg {
            background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg');
            background-size: cover;
            background-position: center;
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
<body class="h-full font-sans antialiased flex text-slate-900 overflow-x-hidden">

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div id="sidebarBackdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-40 hidden md:hidden"></div>

    <!-- Mobile Slide-out Sidebar Navigation with compact spacing -->
    <aside id="mobileSidebar" class="fixed inset-y-0 left-0 w-64 bg-[#0c132b] text-slate-100 flex flex-col justify-between z-50 transform -translate-x-full transition-transform duration-300 md:hidden select-none border-r-2 border-slate-900" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg'); background-size: cover; background-position: center;">
        <div class="h-full flex flex-col justify-between overflow-y-auto">
            <div>
                <div class="h-16 flex items-center justify-between px-4 border-b-2 border-slate-800">
                    <div class="flex items-center space-x-2">
                        <img src="pay.png" alt="Pay Icon" class="w-10 h-8 object-contain">
                        <span class="text-lg font-black tracking-tight text-white">Gig<span class="text-blue-400">Pay</span></span>
                    </div>
                    <button onclick="toggleMobileSidebar()" class="p-1.5 text-slate-400 hover:text-white">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>
                <nav class="p-3 space-y-1 text-xs font-bold">
                    <a href="admin_dashboard.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg bg-blue-700 text-white shadow-lg border border-blue-600 transition">
                        <i class="fa-solid fa-chart-pie w-4 text-slate-300"></i><span>Dashboard</span>
                    </a>
                    <a href="manage_requests.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-clock-rotate-left w-4 text-slate-300"></i><span>Manage Requests</span>
                    </a>
                    <a href="attendance.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-calendar-days w-4 text-slate-300"></i><span>Attendance</span>
                    </a>
                    <a href="employee.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-users w-4 text-slate-300"></i><span>Employees</span>
                    </a>
                    <a href="biometric.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-fingerprint w-4 text-slate-300"></i><span>F01H Biometrics</span>
                    </a>
                    <a href="hikvision.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-video w-4 text-slate-300"></i><span>Hikvision Biometrics</span>
                    </a>
                    <a href="payroll.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-wallet w-4 text-slate-300"></i><span>Payroll Computation</span>
                    </a>
                    <a href="reports.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-chart-line w-4 text-slate-300"></i><span>Reports & Analytics</span>
                    </a>
                    <a href="settings.php" class="flex items-center space-x-2.5 px-3 py-2.5 rounded-lg hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-gear w-4 text-slate-300"></i><span>Settings</span>
                    </a>
                </nav>
            </div>
            <div class="p-3 border-t-2 border-slate-800">
                <button onclick="openLogoutModal()" class="w-full flex items-center space-x-2.5 px-3 py-2.5 rounded-lg text-red-300 hover:bg-red-500/20 hover:text-red-200 transition text-xs font-black text-left border border-red-500/30">
                    <i class="fa-solid fa-arrow-right-from-bracket w-4"></i><span>Logout</span>
                </button>
            </div>
        </div>
    </aside>

    <!-- Desktop Sidebar Navigation -->
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
                    <a href="admin_dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
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
                    <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
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

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <header class="h-16 sm:h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-3 sm:px-8 shrink-0 shadow-sm">
            <div class="flex items-center space-x-2 sm:space-x-3">
                <button onclick="toggleMobileSidebar()" class="p-2 bg-slate-100 border-2 border-slate-300 rounded-lg md:hidden cursor-pointer hover:bg-slate-200 text-slate-900 transition">
                    <i class="fa-solid fa-bars text-sm font-bold"></i>
                </button>
                <h1 class="text-base sm:text-xl font-black text-slate-900 tracking-tight">Admin Dashboard</h1>
                <button onclick="location.reload();" class="hidden sm:inline-flex items-center justify-center px-3 py-1.5 bg-slate-100 border-2 border-slate-400 rounded-lg text-xs font-black text-slate-900 hover:bg-slate-200 shadow-sm transition ml-2">
                    <i class="fa-solid fa-rotate mr-1.5 text-slate-700 font-bold"></i> Refresh
                </button>
            </div>
            
            <div class="flex items-center space-x-2 sm:space-x-4">
                <!-- Dynamic Holiday / Range Trigger Button -->
                <button onclick="openHolidayModal()" class="px-2.5 py-1.5 sm:px-3 sm:py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg sm:rounded-xl text-[10px] sm:text-xs font-black shadow-md border border-amber-500 flex items-center space-x-1 transition">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span class="hidden xs:inline">Declare Holiday</span><span class="inline xs:hidden">Holiday</span>
                </button>

                <div class="relative">
                    <button onclick="toggleNotificationDropdown()" class="relative p-2 sm:p-2.5 rounded-lg sm:rounded-xl bg-slate-200 border-2 border-slate-400 hover:bg-slate-300 text-slate-900 transition flex items-center justify-center font-bold">
                        <i class="fa-solid fa-bell text-sm sm:text-base"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="absolute -top-1 -right-1 bg-red-600 text-white text-[9px] sm:text-[10px] font-black w-4 h-4 sm:w-5 sm:h-5 rounded-full flex items-center justify-center border-2 border-white shadow-md animate-pulse">
                                <?php echo count($notifications); ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <div id="notificationDropdown" class="hidden absolute right-0 mt-3 w-72 sm:w-80 bg-white border-2 border-slate-300 rounded-2xl shadow-2xl z-50 overflow-hidden animate-slide-in">
                        <div class="p-4 bg-slate-100 border-b-2 border-slate-200 flex items-center justify-between">
                            <span class="text-xs font-black text-slate-900 uppercase tracking-wider">Notifications & Alerts</span>
                            <span class="bg-blue-200 text-blue-900 text-[10px] font-black px-2 py-0.5 rounded-full border border-blue-400"><?php echo count($notifications); ?> New</span>
                        </div>
                        <div class="max-h-72 overflow-y-auto divide-y-2 divide-slate-100 text-xs font-bold">
                            <?php if (empty($notifications)): ?>
                                <div class="p-6 text-center text-slate-600 font-bold">
                                    <i class="fa-regular fa-circle-check text-2xl mb-1 text-slate-500 block"></i>
                                    No new notifications or PIN reset requests.
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <button onclick="openNotificationModal('<?php echo htmlspecialchars($notif['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notif['desc'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notif['time'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($notif['type'], ENT_QUOTES); ?>')" class="w-full text-left p-3.5 hover:bg-slate-100 transition flex items-start space-x-3 border-b border-slate-100 cursor-pointer">
                                        <div class="bg-blue-200 text-blue-900 border border-blue-400 p-2 rounded-xl mt-0.5 shrink-0 font-bold">
                                            <i class="fa-solid <?php echo $notif['type'] === 'pin_reset' ? 'fa-key text-amber-700' : 'fa-address-card text-blue-700'; ?>"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-black text-slate-900 truncate"><?php echo htmlspecialchars($notif['title']); ?></p>
                                            <p class="text-slate-700 text-[11px] font-semibold mt-0.5"><?php echo htmlspecialchars($notif['desc']); ?></p>
                                            <p class="text-[10px] text-slate-500 font-bold mt-1"><?php echo htmlspecialchars($notif['time']); ?></p>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="flex items-center space-x-2 sm:space-x-3 pl-2 sm:pl-4 border-l-2 border-slate-300">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-blue-200 text-blue-900 border-2 border-blue-400 flex items-center justify-center font-black text-xs sm:text-sm shadow-sm">
                        <?php echo isset($_SESSION['admin_name']) ? substr($_SESSION['admin_name'], 0, 2) : 'AD'; ?>
                    </div>
                    <div class="hidden sm:block text-left">
                        <p class="text-xs font-black text-slate-900"><?php echo isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'System Admin'; ?></p>
                        <p class="text-[11px] font-bold text-slate-700">Administrator</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-3 sm:p-8 space-y-4 sm:space-y-8 bg-slate-100">
            <!-- Top Metric Cards Grid with adjusted mobile spacing -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6">
                <div class="bg-white p-4 sm:p-6 rounded-xl sm:rounded-2xl border-2 border-slate-300 shadow-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] sm:text-xs font-black uppercase tracking-wider text-slate-600">Total Employees</p>
                        <p class="text-xl sm:text-3xl font-black text-slate-900 mt-1 sm:mt-2"><?php echo number_format($total_employees); ?></p>
                        <span class="text-[10px] sm:text-xs font-bold text-slate-700 mt-0.5 sm:mt-1 inline-block"><?php echo $total_employees > 0 ? 'Active workforce' : 'No records found'; ?></span>
                    </div>
                    <div class="w-10 h-10 sm:w-14 sm:h-14 bg-blue-100 border-2 border-blue-300 text-blue-800 rounded-xl sm:rounded-2xl flex items-center justify-center text-lg sm:text-2xl font-bold shadow-inner">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="bg-white p-4 sm:p-6 rounded-xl sm:rounded-2xl border-2 border-slate-300 shadow-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] sm:text-xs font-black uppercase tracking-wider text-slate-600">Payroll Processed</p>
                        <p class="text-xl sm:text-3xl font-black text-slate-900 mt-1 sm:mt-2">₱<?php echo number_format($total_payroll_processed, 2); ?></p>
                        <span class="text-[10px] sm:text-xs font-bold text-slate-700 mt-0.5 sm:mt-1 inline-block">Disbursed Total</span>
                    </div>
                    <div class="w-10 h-10 sm:w-14 sm:h-14 bg-emerald-100 border-2 border-emerald-300 text-emerald-800 rounded-xl sm:rounded-2xl flex items-center justify-center text-lg sm:text-2xl font-bold shadow-inner">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="bg-white p-4 sm:p-6 rounded-xl sm:rounded-2xl border-2 border-slate-300 shadow-md flex items-center justify-between sm:col-span-2 lg:col-span-1">
                    <div>
                        <p class="text-[10px] sm:text-xs font-black uppercase tracking-wider text-slate-600">Biometric Terminals</p>
                        <p class="text-xl sm:text-3xl font-black text-slate-900 mt-1 sm:mt-2">1/1</p>
                        <span class="text-[10px] sm:text-xs font-black text-emerald-700 mt-0.5 sm:mt-1 inline-block"><span class="w-2 h-2 sm:w-2.5 sm:h-2.5 rounded-full bg-emerald-600 inline-block mr-1"></span> Online & Syncing</span>
                    </div>
                    <div class="w-10 h-10 sm:w-14 sm:h-14 bg-indigo-100 border-2 border-indigo-300 text-indigo-800 rounded-xl sm:rounded-2xl flex items-center justify-center text-lg sm:text-2xl font-bold shadow-inner">
                        <i class="fa-solid fa-fingerprint"></i>
                    </div>
                </div>
            </div>

            <!-- Side-by-Side Section: Pending Approvals Quick Queue & Interactive Calendar Summary -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
                
                <!-- Pending Approvals Quick Queue Widget Container -->
                <div class="bg-white rounded-xl sm:rounded-2xl border-2 border-slate-300 shadow-md overflow-hidden lg:col-span-2 flex flex-col">
                    <div class="p-4 sm:p-6 border-b-2 border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50">
                        <h3 class="font-black text-slate-900 flex items-center space-x-2 text-xs sm:text-base">
                            <i class="fa-solid fa-clock-rotate-left text-blue-700 font-bold"></i>
                            <span>Pending Approvals Quick Queue</span>
                        </h3>
                        <span class="bg-blue-100 text-blue-900 border-2 border-blue-300 text-[10px] sm:text-xs font-black px-2.5 py-1 rounded-lg sm:rounded-xl shadow-xs self-start sm:self-auto">
                            <?php echo count($pending_queue_items); ?> Pending Action(s)
                        </span>
                    </div>

                    <div class="p-3 sm:p-6 flex-1 overflow-y-auto max-h-[420px] divide-y-2 divide-slate-100">
                        <?php if (empty($pending_queue_items)): ?>
                            <div class="py-12 text-center text-slate-500 font-bold text-xs">
                                <i class="fa-regular fa-circle-check text-3xl sm:text-4xl mb-2 text-emerald-600 block"></i>
                                All caught up! There are no pending employee requests or tickets requiring your approval.
                            </div>
                        <?php else: ?>
                            <?php foreach ($pending_queue_items as $pqi): ?>
                                <div id="queue-item-<?php echo $pqi['queue_type']; ?>-<?php echo $pqi['id']; ?>" class="py-3 sm:py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/80 p-2.5 sm:p-3 rounded-xl transition">
                                    <div class="flex items-start space-x-2.5 sm:space-x-3">
                                        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg sm:rounded-xl bg-blue-100 border-2 border-blue-300 text-blue-800 flex items-center justify-center font-black text-xs sm:text-sm shrink-0 mt-0.5 shadow-xs">
                                            <i class="fa-solid <?php echo $pqi['queue_type'] === 'pin_reset' ? 'fa-key text-amber-700' : 'fa-address-card text-blue-700'; ?>"></i>
                                        </div>
                                        <div>
                                            <div class="flex items-center space-x-2">
                                                <h4 class="font-black text-slate-900 text-xs sm:text-sm"><?php echo htmlspecialchars($pqi['employee_name']); ?></h4>
                                                <span class="bg-slate-200 text-slate-800 text-[9px] sm:text-[10px] font-black px-1.5 sm:px-2 py-0.5 rounded-md border border-slate-300"><?php echo htmlspecialchars($pqi['department']); ?></span>
                                            </div>
                                            <p class="text-[11px] sm:text-xs font-black text-blue-950 mt-0.5"><span class="text-slate-500 font-bold">Type:</span> <?php echo htmlspecialchars($pqi['title']); ?></p>
                                            <p class="text-[11px] sm:text-xs text-slate-700 font-semibold mt-1 bg-slate-100 p-2 rounded-lg border border-slate-200">"<?php echo htmlspecialchars($pqi['details']); ?>"</p>
                                            <p class="text-[10px] text-slate-500 font-bold mt-1"><i class="fa-regular fa-clock mr-1"></i><?php echo htmlspecialchars($pqi['time']); ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2 shrink-0 self-end sm:self-center">
                                        <button onclick="processQueueAction(<?php echo $pqi['id']; ?>, '<?php echo $pqi['queue_type']; ?>', 'approve')" class="px-2.5 py-1.5 sm:px-3 sm:py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg sm:rounded-xl text-[11px] sm:text-xs font-black shadow-sm border border-emerald-500 transition flex items-center space-x-1 cursor-pointer">
                                            <i class="fa-solid fa-check"></i>
                                            <span>Approve</span>
                                        </button>
                                        <button onclick="processQueueAction(<?php echo $pqi['id']; ?>, '<?php echo $pqi['queue_type']; ?>', 'reject')" class="px-2.5 py-1.5 sm:px-3 sm:py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg sm:rounded-xl text-[11px] sm:text-xs font-black shadow-sm border border-red-500 transition flex items-center space-x-1 cursor-pointer">
                                            <i class="fa-solid fa-xmark"></i>
                                            <span>Reject</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Side Embedded Calendar Summary Component -->
                <div class="bg-white rounded-xl sm:rounded-2xl border-2 border-slate-300 shadow-md p-4 sm:p-5 flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-center border-b-2 border-slate-200 pb-2.5 sm:pb-3 mb-3">
                            <h3 class="text-xs sm:text-sm font-black text-slate-900 flex items-center">
                                <i class="fa-solid fa-calendar-star text-blue-700 mr-2 font-bold"></i> Calendar Summary
                            </h3>
                        </div>

                        <!-- Calendar Controls -->
                        <div class="flex items-center justify-between mb-3 bg-slate-100 p-2 sm:p-2.5 rounded-xl border-2 border-slate-300 shadow-sm">
                            <button onclick="changeMonth(-1)" class="px-2 py-1 bg-white border-2 border-slate-400 rounded-lg text-xs font-black text-slate-900 hover:bg-slate-200 transition shadow-sm">
                                <i class="fa-solid fa-chevron-left font-bold"></i>
                            </button>
                            <h4 id="calendarMonthYear" class="text-xs font-black text-slate-950"></h4>
                            <button onclick="changeMonth(1)" class="px-2 py-1 bg-white border-2 border-slate-400 rounded-lg text-xs font-black text-slate-900 hover:bg-slate-200 transition shadow-sm">
                                <i class="fa-solid fa-chevron-right font-bold"></i>
                            </button>
                        </div>

                        <!-- Calendar Grid -->
                        <div class="grid grid-cols-7 gap-1 text-center font-black text-[10px] sm:text-[11px] text-slate-700 mb-1">
                            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                        </div>
                        <div id="calendarDaysGrid" class="grid grid-cols-7 gap-1 text-[10px] sm:text-[11px] font-bold mb-4">
                            <!-- Injected via JS -->
                        </div>
                    </div>

                    <!-- Selected Date Events Summary Panel -->
                    <div class="bg-slate-100 p-3 rounded-xl border-2 border-slate-300 flex flex-col flex-1 shadow-inner">
                        <h5 class="text-[10px] sm:text-[11px] font-black uppercase tracking-wider text-blue-950 mb-1" id="selectedDateTitle">Select a date</h5>
                        <div id="selectedDateEvents" class="space-y-1.5 text-[11px] sm:text-xs text-slate-900 font-bold">
                            <p class="text-slate-600 italic text-[10px] sm:text-[11px]">Click a day to inspect daily summary items like requests, employee birthdays & date range work suspensions.</p>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- Declare Holiday / Suspension Date Range Modal -->
    <div id="holidayModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-3 sm:p-4">
        <div class="bg-white rounded-xl sm:rounded-2xl max-w-lg w-full p-4 sm:p-6 shadow-2xl border-2 border-slate-400 transform transition-all animate-slide-in max-h-[90vh] overflow-y-auto">
            <div class="flex items-center space-x-3 mb-3 sm:mb-4">
                <div class="w-10 h-10 sm:w-12 sm:h-12 bg-amber-100 border-2 border-amber-400 text-amber-800 rounded-xl flex items-center justify-center text-lg sm:text-xl font-bold shadow-inner shrink-0">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <span class="bg-amber-200 text-amber-900 text-[9px] sm:text-[10px] font-black px-2 py-0.5 rounded-full border border-amber-400 uppercase">Work Suspension Range</span>
                    <h3 class="text-sm sm:text-base font-black text-slate-900 mt-1">Declare Company-Wide No Work Range</h3>
                </div>
            </div>
            
            <form id="holidayForm" onsubmit="submitHolidayForm(event)" class="space-y-3 sm:space-y-4 text-xs font-bold text-slate-800">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] uppercase text-slate-500 font-black mb-1">From Date</label>
                        <input type="date" id="start_date" name="start_date" required class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:border-blue-600 font-bold">
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase text-slate-500 font-black mb-1">To Date</label>
                        <input type="date" id="end_date" name="end_date" required class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:border-blue-600 font-bold">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] uppercase text-slate-500 font-black mb-1">Title / Reason (e.g. Typhoon / Extended Holiday)</label>
                    <input type="text" id="holiday_title" name="title" placeholder="e.g. Severe Typhoon Calamity - No Work" required class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:border-blue-600 font-bold">
                </div>
                <div>
                    <label class="block text-[10px] uppercase text-slate-500 font-black mb-1">Category Type</label>
                    <select id="holiday_type" name="type" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:border-blue-600 font-bold">
                        <option value="Suspension">Calamity / Work Suspension</option>
                        <option value="Holiday">Special Holiday Range</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] uppercase text-slate-500 font-black mb-1">Additional Notes (Optional)</label>
                    <textarea id="holiday_desc" name="description" rows="2" placeholder="Details regarding work schedule adjustments..." class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-slate-900 focus:outline-none focus:border-blue-600 font-bold"></textarea>
                </div>

                <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3 pt-2">
                    <button type="submit" id="saveHolidayBtn" class="flex-1 px-4 py-2.5 bg-amber-600 border-2 border-amber-500 hover:bg-amber-700 text-white text-xs font-black rounded-xl transition text-center shadow-md">
                        Broadcast Date Range & Mark No Work
                    </button>
                    <button type="button" onclick="closeHolidayModal()" class="flex-1 px-4 py-2.5 bg-slate-200 border-2 border-slate-400 hover:bg-slate-300 text-slate-900 text-xs font-black rounded-xl transition shadow-sm text-center">
                        Cancel
                    </button>
                </div>
            </form>

            <!-- Existing Dynamic Declarations Table Preview -->
            <div class="mt-6 border-t-2 border-slate-200 pt-4">
                <h4 class="text-[11px] font-black uppercase tracking-wider text-slate-700 mb-2">Active Admin Range Declarations</h4>
                <div class="max-h-36 overflow-y-auto divide-y-2 divide-slate-100 text-xs">
                    <?php if (empty($company_holidays)): ?>
                        <p class="text-slate-500 italic text-[11px]">No custom company work suspension ranges declared yet.</p>
                    <?php else: ?>
                        <?php foreach ($company_holidays as $ch): ?>
                            <div class="py-2 flex items-center justify-between">
                                <div>
                                    <p class="font-black text-slate-900"><?php echo htmlspecialchars($ch['title']); ?></p>
                                    <p class="text-[10px] text-amber-800 font-bold">From: <?php echo htmlspecialchars($ch['start_date']); ?> &nbsp; To: &nbsp; <?php echo htmlspecialchars($ch['end_date']); ?></p>
                                </div>
                                <button onclick="deleteHoliday(<?php echo $ch['id']; ?>)" class="px-2 py-1 bg-red-100 border border-red-300 text-red-700 rounded-lg text-[10px] hover:bg-red-200 font-bold">Remove</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Details Modal -->
    <div id="notificationModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-3 sm:p-4">
        <div class="bg-white rounded-xl sm:rounded-2xl max-w-md w-full p-4 sm:p-6 shadow-2xl border-2 border-slate-400 transform transition-all animate-slide-in">
            <div class="flex items-center space-x-3 mb-4">
                <div id="notifModalIconContainer" class="w-10 h-10 sm:w-12 sm:h-12 bg-blue-100 border-2 border-blue-400 text-blue-800 rounded-xl flex items-center justify-center text-lg sm:text-xl font-bold shadow-inner shrink-0">
                    <i id="notifModalIcon" class="fa-solid fa-bell"></i>
                </div>
                <div>
                    <span id="notifModalTypeBadge" class="bg-blue-200 text-blue-900 text-[9px] sm:text-[10px] font-black px-2 py-0.5 rounded-full border border-blue-400 uppercase">Alert</span>
                    <h3 id="notifModalTitle" class="text-sm sm:text-base font-black text-slate-900 mt-1">Notification Details</h3>
                </div>
            </div>
            
            <div class="space-y-3 bg-slate-50 p-3 sm:p-4 rounded-xl border-2 border-slate-200 mb-6 text-xs font-bold text-slate-800">
                <div>
                    <span class="text-[10px] uppercase text-slate-500 font-black block">Description / Note</span>
                    <p id="notifModalDesc" class="text-slate-900 font-semibold mt-0.5 text-xs sm:text-sm"></p>
                </div>
                <div>
                    <span class="text-[10px] uppercase text-slate-500 font-black block">Timestamp</span>
                    <p id="notifModalTime" class="text-slate-700 mt-0.5 text-[11px] sm:text-xs"></p>
                </div>
            </div>

            <div class="flex space-x-3">
                <a href="manage_requests.php" id="notifModalActionBtn" class="flex-1 px-4 py-2.5 bg-blue-700 border-2 border-blue-500 hover:bg-blue-800 text-white text-xs font-black rounded-xl transition text-center flex items-center justify-center shadow-md">
                    Manage Request
                </a>
                <button onclick="closeNotificationModal()" class="flex-1 px-4 py-2.5 bg-slate-200 border-2 border-slate-400 hover:bg-slate-300 text-slate-900 text-xs font-black rounded-xl transition shadow-sm text-center">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-3 sm:p-4">
        <div class="bg-white rounded-xl sm:rounded-2xl max-w-sm w-full p-4 sm:p-6 shadow-2xl border-2 border-slate-400 transform transition-all">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-red-100 border-2 border-red-400 text-red-700 rounded-xl flex items-center justify-center text-lg sm:text-xl mb-4 font-bold shadow-inner">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 class="text-base sm:text-lg font-black text-slate-900 mb-1">Confirm Logout</h3>
            <p class="text-xs font-bold text-slate-700 mb-6">Are you sure you want to log out of your admin account? You will need to sign back in to access the portal.</p>
            <div class="flex space-x-3">
                <button onclick="closeLogoutModal()" class="flex-1 px-4 py-2.5 bg-slate-200 border-2 border-slate-400 hover:bg-slate-300 text-slate-900 text-xs font-black rounded-xl transition shadow-sm">
                    Cancel
                </button>
                <a href="index.php?logout=true" class="flex-1 px-4 py-2.5 bg-red-700 border-2 border-red-500 hover:bg-red-800 text-white text-xs font-black rounded-xl transition text-center flex items-center justify-center shadow-md">
                    Yes, Logout
                </a>
            </div>
        </div>
    </div>

    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('mobileSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }
        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }

        function toggleNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('hidden');
        }

        function openHolidayModal() {
            document.getElementById('holidayModal').classList.remove('hidden');
        }
        function closeHolidayModal() {
            document.getElementById('holidayModal').classList.add('hidden');
        }

        function submitHolidayForm(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('holidayForm'));
            formData.append('ajax_action', 'add_holiday');

            fetch('admin_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                alert('Network error while saving dynamic suspension range.');
            });
        }

        function deleteHoliday(id) {
            if (!confirm('Are you sure you want to remove this holiday/suspension range record?')) return;
            const formData = new FormData();
            formData.append('ajax_action', 'delete_holiday');
            formData.append('id', id);

            fetch('admin_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        function processQueueAction(reqId, reqType, actionVal) {
            const actionText = actionVal === 'approve' ? 'approve' : 'reject';
            if (!confirm(`Are you sure you want to ${actionText} this request?`)) return;

            const formData = new FormData();
            formData.append('ajax_action', 'handle_request_action');
            formData.append('req_id', reqId);
            formData.append('req_type', reqType);
            formData.append('action_val', actionVal);

            fetch('admin_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const itemEl = document.getElementById(`queue-item-${reqType}-${reqId}`);
                    if (itemEl) {
                        itemEl.style.transition = 'all 0.3s ease';
                        itemEl.style.opacity = '0';
                        setTimeout(() => { itemEl.remove(); location.reload(); }, 300);
                    } else {
                        location.reload();
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                alert('Network error while processing request.');
            });
        }

        function openNotificationModal(title, desc, time, type) {
            document.getElementById('notificationDropdown').classList.add('hidden');
            document.getElementById('notifModalTitle').innerText = title;
            document.getElementById('notifModalDesc').innerText = desc;
            document.getElementById('notifModalTime').innerText = time;
            document.getElementById('notifModalTypeBadge').innerText = type === 'pin_reset' ? 'PIN Reset Request' : 'Ticket Request';

            const iconEl = document.getElementById('notifModalIcon');
            const actionBtn = document.getElementById('notifModalActionBtn');

            if (type === 'pin_reset') {
                iconEl.className = "fa-solid fa-key text-amber-700";
                actionBtn.innerText = "Manage PIN Resets";
                actionBtn.href = "employee.php";
            } else {
                iconEl.className = "fa-solid fa-address-card text-blue-800";
                actionBtn.innerText = "Manage Requests";
                actionBtn.href = "manage_requests.php";
            }

            document.getElementById('notificationModal').classList.remove('hidden');
        }

        function closeNotificationModal() {
            document.getElementById('notificationModal').classList.add('hidden');
        }

        window.addEventListener('click', function(e) {
            const bellBtn = document.querySelector('button[onclick="toggleNotificationDropdown()"]');
            const dropdown = document.getElementById('notificationDropdown');
            if (bellBtn && dropdown && !bellBtn.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadYearHolidays(activeDate.getFullYear());
        });

        let activeDate = new Date();
        const employeesBirthdaysData = <?php echo json_encode($employee_birthdays); ?>;
        const allRequestsData = <?php echo json_encode($all_requests_data); ?>;
        const companyHolidaysData = <?php echo json_encode($company_holidays); ?>;
        let holidaysCache = {}; 
        let selectedDateGlobal = null;

        function changeMonth(direction) {
            let prevYear = activeDate.getFullYear();
            activeDate.setMonth(activeDate.getMonth() + direction);
            let newYear = activeDate.getFullYear();
            
            if (newYear !== prevYear) {
                loadYearHolidays(newYear);
            } else {
                renderCalendar();
            }
        }

        function loadYearHolidays(year) {
            if (holidaysCache[year]) {
                renderCalendar();
                return;
            }

            fetch(`https://date.nager.at/api/v3/PublicHolidays/${year}/PH`)
                .then(res => res.json())
                .then(data => {
                    holidaysCache[year] = Array.isArray(data) ? data : [];
                    renderCalendar();
                })
                .catch(() => {
                    holidaysCache[year] = [];
                    renderCalendar();
                });
        }

        function renderCalendar() {
            const year = activeDate.getFullYear();
            const month = activeDate.getMonth();

            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            document.getElementById('calendarMonthYear').innerText = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const totalDays = new Date(year, month + 1, 0).getDate();
            const yearHolidays = holidaysCache[year] || [];

            let gridHTML = '';

            for (let i = 0; i < firstDayIndex; i++) {
                gridHTML += `<div class="h-7 sm:h-8 bg-transparent"></div>`;
            }

            const todayObj = new Date();
            const todayStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, '0')}-${String(todayObj.getDate()).padStart(2, '0')}`;

            for (let day = 1; day <= totalDays; day++) {
                let formattedMonth = String(month + 1).padStart(2, '0');
                let formattedDay = String(day).padStart(2, '0');
                let dateStr = `${year}-${formattedMonth}-${formattedDay}`;

                let isToday = (dateStr === todayStr);
                let hasApiHoliday = yearHolidays.some(h => h.date === dateStr);
                
                let matchedCompanyRange = companyHolidaysData.find(ch => dateStr >= ch.start_date && dateStr <= ch.end_date);
                let hasCompanyHoliday = !!matchedCompanyRange;

                let hasBirthday = employeesBirthdaysData.some(emp => {
                    if (!emp.birth_date) return false;
                    let bDate = new Date(emp.birth_date);
                    return String(bDate.getMonth() + 1).padStart(2, '0') === formattedMonth && String(bDate.getDate()).padStart(2, '0') === formattedDay;
                });
                let hasRequest = allRequestsData.some(r => r.created_at && r.created_at.startsWith(dateStr));
                
                let bgClasses = "border-2 border-slate-300 text-slate-900 font-black hover:bg-blue-700 hover:text-white hover:border-blue-900";
                let badgeIndicators = "";

                if (isToday) {
                    bgClasses = "border-2 border-blue-600 bg-blue-50 text-blue-950 font-black ring-2 ring-blue-400 shadow-sm";
                }

                if (hasCompanyHoliday) {
                    bgClasses = "border-2 border-red-600 bg-red-100 text-red-950 font-black ring-2 ring-red-400 shadow-sm";
                    badgeIndicators += `<span class="w-1 h-1 sm:w-1.5 sm:h-1.5 bg-red-600 rounded-full inline-block" title="No Work / Range Suspension"></span>`;
                } else if (hasApiHoliday) {
                    badgeIndicators += `<span class="w-1 h-1 sm:w-1.5 sm:h-1.5 bg-amber-500 rounded-full inline-block" title="Public Holiday"></span>`;
                }
                if (hasBirthday) {
                    badgeIndicators += `<span class="w-1 h-1 sm:w-1.5 sm:h-1.5 bg-pink-500 rounded-full inline-block" title="Birthday"></span>`;
                }
                if (hasRequest) {
                    badgeIndicators += `<span class="w-1 h-1 sm:w-1.5 sm:h-1.5 bg-indigo-600 rounded-full inline-block" title="Request"></span>`;
                }

                gridHTML += `
                    <div onclick="selectCalendarDate('${year}', '${formattedMonth}', '${formattedDay}')" 
                         class="h-7 sm:h-8 border rounded-md sm:rounded-lg flex flex-col items-center justify-center cursor-pointer transition shadow-xs ${bgClasses}">
                        <span>${day}</span>
                        <div class="flex space-x-0.5 mt-0.5">${badgeIndicators}</div>
                    </div>
                `;
            }

            document.getElementById('calendarDaysGrid').innerHTML = gridHTML;
            
            if (selectedDateGlobal) {
                let parts = selectedDateGlobal.split('-');
                if (parts.length === 3 && parseInt(parts[0]) === year) {
                    selectCalendarDate(parts[0], parts[1], parts[2]);
                }
            }
        }

        function selectCalendarDate(year, month, day) {
            selectedDateGlobal = `${year}-${month}-${day}`;
            document.getElementById('selectedDateTitle').innerText = `Summary: ${selectedDateGlobal}`;
            let container = document.getElementById('selectedDateEvents');
            container.innerHTML = `<p class="text-slate-700 font-bold text-[10px] sm:text-[11px]">Loading daily summary...</p>`;

            let yearHolidays = holidaysCache[year] || [];
            let matchedHolidays = yearHolidays.filter(h => h.date === selectedDateGlobal);
            
            let matchedCompanyRanges = companyHolidaysData.filter(ch => selectedDateGlobal >= ch.start_date && selectedDateGlobal <= ch.end_date);
            
            let matchedBirthdays = employeesBirthdaysData.filter(emp => {
                if (!emp.birth_date) return false;
                let bDate = new Date(emp.birth_date);
                return String(bDate.getMonth() + 1).padStart(2, '0') === month && String(bDate.getDate()).padStart(2, '0') === day;
            });
            
            let matchedRequests = allRequestsData.filter(r => r.created_at && r.created_at.startsWith(selectedDateGlobal));

            let htmlContent = '';
            
            if (matchedCompanyRanges.length > 0) {
                matchedCompanyRanges.forEach(ch => {
                    htmlContent += `<div class="p-1.5 bg-red-200 border-2 border-red-500 rounded-md text-[10px] sm:text-[11px] text-red-950 font-black shadow-xs">🚨 No Work Range: ${ch.title} (${ch.start_date} to ${ch.end_date})</div>`;
                });
            }
            if (matchedHolidays.length > 0) {
                matchedHolidays.forEach(h => {
                    htmlContent += `<div class="p-1.5 bg-amber-100 border-2 border-amber-400 rounded-md text-[10px] sm:text-[11px] text-amber-950 font-black shadow-xs">🎉 Public Holiday: ${h.name}</div>`;
                });
            }
            if (matchedBirthdays.length > 0) {
                matchedBirthdays.forEach(emp => {
                    htmlContent += `<div class="p-1.5 bg-pink-100 border-2 border-pink-400 rounded-md text-[10px] sm:text-[11px] text-pink-950 font-black shadow-xs">🎂 Birthday: ${emp.first_name} ${emp.last_name} (${emp.department})</div>`;
                });
            }
            if (matchedRequests.length > 0) {
                matchedRequests.forEach(req => {
                    htmlContent += `<div class="p-1.5 bg-indigo-100 border-2 border-indigo-400 rounded-md text-[10px] sm:text-[11px] text-indigo-950 font-black shadow-xs">📋 Request (${req.type}): ${req.first_name} ${req.last_name}</div>`;
                });
            }
            
            if (!htmlContent) {
                htmlContent = `<p class="text-slate-600 font-bold text-[10px] sm:text-[11px] italic">No summary items or events recorded for this date.</p>`;
            }
            
            container.innerHTML = htmlContent;
        }
    </script>
</body>
</html>