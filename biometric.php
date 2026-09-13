<?php
session_start();

// Authentication Guard: Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.php");
    exit();
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
    $db_error = $e->getMessage();
}

$message = '';
$error = '';

// Handle Biometric Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo)) {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'generate_biometric_id') {
            try {
                $employee_id = $_POST['employee_id'];
                
                // Generate format XXX (3 digits changing dynamically from 000 to 999)[cite: 1]
                $biometric_id = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
                
                // Ensure absolute uniqueness across existing fingerprint_id values in database[cite: 1]
                $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE fingerprint_id = ?");
                $checkStmt->execute([$biometric_id]);
                while ($checkStmt->rowCount() > 0) {
                    $biometric_id = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
                    $checkStmt->execute([$biometric_id]);
                }
                
                // Update employee record with the new biometric ID[cite: 1]
                $stmt = $pdo->prepare("UPDATE employees SET fingerprint_id = ? WHERE id = ?");
                $stmt->execute([$biometric_id, $employee_id]);
                
                $message = "Biometric ID <strong>{$biometric_id}</strong> successfully generated and linked to employee!";
            } catch (\Exception $e) {
                $error = "Error generating biometric ID: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'unlink_biometric') {
            try {
                $employee_id = $_POST['employee_id'];
                $stmt = $pdo->prepare("UPDATE employees SET fingerprint_id = NULL WHERE id = ?");
                $stmt->execute([$employee_id]);
                
                $message = "Biometric link successfully removed from employee record.";
            } catch (\Exception $e) {
                $error = "Error unlinking biometric ID: " . $e->getMessage();
            }
        }
    }
}

// Fetch Employees list for directory and assignment filtering
$employees = [];
if (isset($pdo)) {
    try {
        $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%';
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
        
        $sql = "SELECT * FROM employees WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        if ($filter === 'linked') {
            $sql .= " AND fingerprint_id IS NOT NULL AND fingerprint_id != ''";
        } elseif ($filter === 'unlinked') {
            $sql .= " AND (fingerprint_id IS NULL OR fingerprint_id = '')";
        }
        $sql .= " ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$search, $search, $search]);
        $employees = $stmt->fetchAll();
    } catch (\Exception $e) {
        $error = "Database query error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-200">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - F01H Biometric Management</title>
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
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
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
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="h-full font-sans antialiased flex text-slate-900 overflow-hidden font-semibold">

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
                <a href="biometric.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
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

    <!-- Mobile Navigation Drawer & Backdrop -->
    <div id="mobileDrawerBackdrop" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-40 hidden md:hidden transition-opacity" onclick="closeMobileDrawer()"></div>
    <div id="mobileDrawer" class="fixed inset-y-0 left-0 w-72 bg-[#0c132b] text-slate-100 flex flex-col justify-between z-50 transform -translate-x-full transition-transform duration-300 ease-in-out md:hidden shadow-2xl border-r-2 border-slate-900" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg'); background-size: cover; background-position: center;">
        <div class="h-full flex flex-col justify-between">
            <div>
                <div class="h-20 flex items-center justify-between px-6 border-b-2 border-slate-800">
                    <div class="flex items-center space-x-3">
                        <div class="flex items-center justify-center">
                            <img src="pay.png" alt="Pay Icon" class="w-9 h-8 object-contain">
                        </div>
                        <span class="text-xl font-black tracking-tight text-white">Gig<span class="text-blue-400">Pay</span></span>
                    </div>
                    <button onclick="closeMobileDrawer()" class="text-slate-400 hover:text-white text-xl font-black p-1"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <nav class="p-4 space-y-2 text-sm font-bold overflow-y-auto max-h-[calc(100vh-10rem)]">
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
                    <a href="biometric.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
                        <i class="fa-solid fa-fingerprint w-5 text-white"></i><span>F01H Biometrics</span>
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
    </div>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <!-- Top Header Bar -->
        <header class="h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-4 sm:px-8 shrink-0">
            <div class="flex items-center space-x-3">
                <!-- Mobile Hamburger Toggle Button -->
                <button onclick="openMobileDrawer()" class="md:hidden p-2.5 rounded-xl bg-slate-100 border-2 border-slate-300 text-slate-900 hover:bg-slate-200 transition focus:outline-none font-black">
                    <i class="fa-solid fa-bars text-lg"></i>
                </button>
                <h1 class="text-base sm:text-xl font-black text-slate-950 truncate">F01H Biometric Device Integration</h1>
            </div>
            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-3 pl-3 sm:pl-4 border-l-2 border-slate-300">
                    <div class="w-10 h-10 rounded-full bg-blue-200 text-blue-900 flex items-center justify-center font-black text-sm border-2 border-blue-700 shrink-0">
                        <?php echo isset($_SESSION['admin_name']) ? substr($_SESSION['admin_name'], 0, 2) : 'AD'; ?>
                    </div>
                    <div class="hidden sm:block text-left">
                        <p class="text-xs font-black text-slate-950"><?php echo isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'System Admin'; ?></p>
                        <p class="text-[11px] font-bold text-slate-700">Administrator</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Viewport Content -->
        <main class="flex-1 overflow-y-auto p-4 sm:p-8 space-y-6 sm:space-y-8 bg-slate-100">
            
            <?php if (!empty($message)): ?>
                <div class="bg-emerald-100 border-2 border-emerald-400 text-emerald-950 p-4 rounded-xl text-sm font-black flex items-center justify-between shadow-sm">
                    <span><i class="fa-solid fa-circle-check mr-2 text-emerald-700"></i> <?php echo $message; ?></span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-900 font-black text-lg">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border-2 border-red-400 text-red-950 p-4 rounded-xl text-sm font-black flex items-center justify-between shadow-sm">
                    <span><i class="fa-solid fa-triangle-exclamation mr-2 text-red-700"></i> <?php echo $error; ?></span>
                    <button onclick="this.parentElement.remove()" class="text-red-900 font-black text-lg">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Search and Filter Bar -->
            <form method="GET" action="biometric.php" class="bg-white p-4 rounded-2xl border-2 border-slate-300 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="w-full sm:w-96 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-600">
                        <i class="fa-solid fa-magnifying-glass text-sm font-black"></i>
                    </span>
                    <input type="text" name="search" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" placeholder="Search employee name..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-sm font-bold focus:outline-none focus:border-blue-700 text-slate-950 placeholder:text-slate-500 placeholder:font-normal">
                </div>
                <div class="flex items-center space-x-3 w-full sm:w-auto justify-end">
                    <select name="filter" onchange="this.form.submit()" class="w-full sm:w-auto bg-slate-50 border-2 border-slate-300 rounded-xl px-4 py-2 text-sm text-slate-950 font-bold focus:outline-none focus:border-blue-700">
                        <option value="all" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'all') ? 'selected' : ''; ?>>All Employees</option>
                        <option value="linked" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'linked') ? 'selected' : ''; ?>>Linked to Device ID</option>
                        <option value="unlinked" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'unlinked') ? 'selected' : ''; ?>>Not Linked Yet</option>
                    </select>
                </div>
            </form>

            <!-- Biometric ID Table -->
            <div class="bg-white rounded-2xl border-2 border-slate-300 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-200 text-slate-900 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                <th class="py-4 px-6">F01H Biometric ID</th>
                                <th class="py-4 px-6">Employee Name</th>
                                <th class="py-4 px-6">Department & Position</th>
                                <th class="py-4 px-6">Hardware Status</th>
                                <th class="py-4 px-6 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-200 text-slate-900 font-bold">
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="5" class="py-12 text-center text-slate-700 text-xs font-black">
                                    No records found matching criteria.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $emp): ?>
                                <tr class="hover:bg-slate-100 transition">
                                    <td class="py-4 px-6">
                                        <?php if (!empty($emp['fingerprint_id'])): ?>
                                            <span class="font-mono bg-indigo-100 text-indigo-950 px-3 py-1.5 rounded-xl text-xs font-black border-2 border-indigo-400 select-all shadow-sm">
                                                <i class="fa-solid fa-fingerprint mr-1.5 text-indigo-700"></i> <?php echo htmlspecialchars($emp['fingerprint_id']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="font-mono text-xs text-slate-500 font-bold italic">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6 font-black text-slate-950">
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . ($emp['middle_name'] ? $emp['middle_name'] . ' ' : '') . $emp['last_name']); ?>
                                        <div class="text-xs font-bold text-slate-600">System ID: #<?php echo $emp['id']; ?></div>
                                    </td>
                                    <td class="py-4 px-6">
                                        <div class="font-black text-slate-950"><?php echo htmlspecialchars($emp['department']); ?></div>
                                        <div class="text-xs font-bold text-slate-600"><?php echo htmlspecialchars($emp['position']); ?></div>
                                    </td>
                                    <td class="py-4 px-6">
                                        <?php if (!empty($emp['fingerprint_id'])): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-950 border-2 border-emerald-400 shadow-sm">
                                                <i class="fa-solid fa-circle-check mr-1.5 text-emerald-700"></i> Synced / Ready
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-950 border-2 border-amber-400 shadow-sm">
                                                <i class="fa-solid fa-triangle-exclamation mr-1.5 text-amber-700"></i> Pending Assignment
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-6 text-right space-x-1 whitespace-nowrap">
                                        <?php if (empty($emp['fingerprint_id'])): ?>
                                            <button onclick='openAssignModal(<?php echo $emp['id']; ?>, "<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'], ENT_QUOTES); ?>")' class="px-3 py-1.5 bg-indigo-100 hover:bg-indigo-200 text-indigo-950 rounded-lg text-xs font-black transition border border-indigo-300">
                                                <i class="fa-solid fa-shuffle mr-1 text-indigo-700"></i> Generate ID
                                            </button>
                                        <?php else: ?>
                                            <button onclick='openUnlinkModal(<?php echo $emp['id']; ?>, "<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'], ENT_QUOTES); ?>")' class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-950 rounded-lg text-xs font-black transition border border-red-300">
                                                <i class="fa-solid fa-unlink mr-1 text-red-700"></i> Unlink
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Generate / Assign Biometric ID Modal -->
    <div id="assignModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border-2 border-slate-400">
            <div class="flex items-center justify-between pb-4 border-b-2 border-slate-200 mb-4">
                <h3 class="text-lg font-black text-slate-950 flex items-center space-x-2">
                    <i class="fa-solid fa-fingerprint text-indigo-700 text-xl"></i>
                    <span>Generate F01H Biometric ID</span>
                </h3>
                <button onclick="closeAssignModal()" class="text-slate-600 hover:text-slate-950 font-black"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            <form method="POST" action="biometric.php" class="space-y-4">
                <input type="hidden" name="action" value="generate_biometric_id">
                <div>
                    <label class="block text-xs font-black text-slate-950 uppercase mb-1">Select Employee *</label>
                    <select name="employee_id" id="assign_employee_id" required class="w-full px-3.5 py-2.5 bg-slate-50 border-2 border-slate-300 rounded-xl text-sm font-bold text-slate-950 focus:outline-none focus:border-indigo-700">
                        <option value="">-- Choose Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>">
                                <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['department'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
              
                <div class="pt-2 flex space-x-3">
                    <button type="button" onclick="closeAssignModal()" class="flex-1 px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border border-slate-400">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-indigo-700 hover:bg-indigo-800 text-white text-xs font-black rounded-xl transition shadow-md shadow-indigo-900/30 border border-indigo-900">Generate & Save ID</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unlink Biometric Confirmation Modal -->
    <div id="unlinkModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border-2 border-slate-400 text-center">
            <div class="w-12 h-12 bg-red-100 text-red-700 rounded-2xl flex items-center justify-center text-xl mx-auto mb-4 border-2 border-red-300 shadow-sm font-black">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 class="text-lg font-black text-slate-950 mb-1">Unlink Biometric ID</h3>
            <p class="text-xs font-bold text-slate-800 mb-6">Are you sure you want to remove the F01H device mapping for <span id="unlink_emp_name_display" class="font-black text-slate-950 underline"></span>?</p>
            
            <form method="POST" action="biometric.php">
                <input type="hidden" name="action" value="unlink_biometric">
                <input type="hidden" name="employee_id" id="unlink_emp_id_input">
                <div class="flex space-x-3">
                    <button type="button" onclick="closeUnlinkModal()" class="flex-1 px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border border-slate-400">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-red-700 hover:bg-red-800 text-white text-xs font-black rounded-xl transition shadow-md shadow-red-900/30 border border-red-900">Yes, Unlink</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[60] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border-2 border-slate-400 transform transition-all text-center">
            <div class="w-12 h-12 bg-red-100 text-red-700 rounded-2xl flex items-center justify-center text-xl mx-auto mb-4 border-2 border-red-300 shadow-sm font-black">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 class="text-lg font-black text-slate-950 mb-1">Confirm Logout</h3>
            <p class="text-xs font-bold text-slate-800 mb-6">Are you sure you want to log out of your admin account?</p>
            <div class="flex space-x-3">
                <button onclick="closeLogoutModal()" class="flex-1 px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border border-slate-400">Cancel</button>
                <a href="index.php?logout=true" class="flex-1 px-4 py-2.5 bg-red-700 hover:bg-red-800 text-white text-xs font-black rounded-xl transition text-center flex items-center justify-center shadow-md shadow-red-900/30 border border-red-900">Yes, Logout</a>
            </div>
        </div>
    </div>

    <!-- JavaScript Handlers -->
    <script>
        // Mobile Drawer Toggles
        function openMobileDrawer() {
            document.getElementById('mobileDrawer').classList.remove('-translate-x-full');
            document.getElementById('mobileDrawerBackdrop').classList.remove('hidden');
        }
        function closeMobileDrawer() {
            document.getElementById('mobileDrawer').classList.add('-translate-x-full');
            document.getElementById('mobileDrawerBackdrop').classList.add('hidden');
        }

        // Modals Toggles
        function openAssignModal(empId, empName) {
            document.getElementById('assign_employee_id').value = empId;
            document.getElementById('assignModal').classList.remove('hidden');
        }
        function closeAssignModal() { document.getElementById('assignModal').classList.add('hidden'); }

        function openUnlinkModal(empId, empName) {
            document.getElementById('unlink_emp_id_input').value = empId;
            document.getElementById('unlink_emp_name_display').textContent = empName;
            document.getElementById('unlinkModal').classList.remove('hidden');
        }
        function closeUnlinkModal() { document.getElementById('unlinkModal').classList.add('hidden'); }

        function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); }
        function closeLogoutModal() { document.getElementById('logoutModal').classList.add('hidden'); }
    </script>
</body>
</html>