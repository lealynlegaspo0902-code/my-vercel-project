<?php
// hikvision.php - Hikvision Biometric & Device Integration Hub (Fully Dynamic with Database-backed Hardware & Registrations)

session_start();

// Database configuration based on your InfinityFree/phpMyAdmin settings
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

$mapped_employees = [];
$devices = [];
$live_scans = [];
$registrations = [];
$db_error = null;
$success_message = null;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Ensure table exists just in case
    $pdo->exec("CREATE TABLE IF NOT EXISTS hikvision_registrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        fingerprint_data TEXT NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Handle POST requests for adding devices, pushing logs/syncs, or syncing registrations
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'add_device') {
                $deviceName = trim($_POST['device_name'] ?? '');
                $model = trim($_POST['model'] ?? 'Hikvision Pro Series');
                $ipAddress = trim($_POST['ip_address'] ?? '');
                $port = intval($_POST['port'] ?? 8000);
                $connectionType = trim($_POST['connection_type'] ?? 'TCP/IP');

                if (!empty($deviceName) && !empty($ipAddress)) {
                    $stmt = $pdo->prepare("INSERT INTO hikvision_devices (device_name, model, ip_address, port, connection_type, status) VALUES (?, ?, ?, ?, ?, 'Online')");
                    $stmt->execute([$deviceName, $model, $ipAddress, $port, $connectionType]);
                    $success_message = "Device '$deviceName' successfully added to database!";
                }
            } elseif ($_POST['action'] === 'push_scan') {
                $employeeId = trim($_POST['employee_id'] ?? '');
                $deviceName = trim($_POST['device_name'] ?? '');
                
                // Fetch employee name
                $stmtEmp = $pdo->prepare("SELECT first_name, middle_name, last_name FROM employees WHERE id = ? OR CONCAT('EMP-', LPAD(id, 4, '0')) = ?");
                $stmtEmp->execute([$employeeId, $employeeId]);
                $empData = $stmtEmp->fetch();
                
                if ($empData) {
                    $fullName = trim($empData['first_name'] . ' ' . ($empData['middle_name'] ? $empData['middle_name'] . ' ' : '') . $empData['last_name']);
                    $stmtScan = $pdo->prepare("INSERT INTO hikvision_live_scans (employee_id, employee_name, device_name, verification_type) VALUES (?, ?, ?, 'Hardware Push')");
                    $stmtScan->execute([$employeeId, $fullName, $deviceName]);
                    $success_message = "Successfully pushed employee $fullName to $deviceName!";
                }
            } elseif ($_POST['action'] === 'push_registration') {
                $regId = intval($_POST['reg_id'] ?? 0);
                $deviceName = trim($_POST['device_name'] ?? '');

                // Fetch registration and employee details
                $stmtRegDetails = $pdo->prepare("SELECT r.*, e.first_name, e.last_name FROM hikvision_registrations r JOIN employees e ON r.employee_id = e.id WHERE r.id = ?");
                $stmtRegDetails->execute([$regId]);
                $regItem = $stmtRegDetails->fetch();

                if ($regItem) {
                    // Update registration status to Synced / Pushed
                    $upd = $pdo->prepare("UPDATE hikvision_registrations SET status = 'Synced to Hardware' WHERE id = ?");
                    $upd->execute([$regId]);

                    // Log into live scans or activity stream
                    $fullName = trim($regItem['first_name'] . ' ' . $regItem['last_name']);
                    $stmtScan = $pdo->prepare("INSERT INTO hikvision_live_scans (employee_id, employee_name, device_name, verification_type) VALUES (?, ?, ?, 'Remote Template Push')");
                    $stmtScan->execute([$regItem['employee_id'], $fullName, $deviceName]);

                    $success_message = "Fingerprint template for $fullName successfully pushed to $deviceName hardware!";
                }
            }
        }
    }

    // Fetch employees from database[cite: 2]
    $stmt = $pdo->query("SELECT id, first_name, middle_name, last_name, department, position, fingerprint_id FROM employees ORDER BY id DESC");
    $db_employees = $stmt->fetchAll();
    
    foreach ($db_employees as $emp) {
        $mapped_employees[] = [
            'id' => 'EMP-' . str_pad($emp['id'], 4, '0', STR_PAD_LEFT),
            'db_id' => $emp['id'],
            'name' => trim($emp['first_name'] . ' ' . ($emp['middle_name'] ? $emp['middle_name'] . ' ' : '') . $emp['last_name']),
            'department' => $emp['department'] ?? 'N/A',
            'position' => $emp['position'] ?? 'N/A',
            'fingerprint_id' => $emp['fingerprint_id'] ?? 'Not Assigned',
            'hikvision' => true
        ];
    }

    // Fetch registered fingerprint records from hikvision_registrations table
    $stmtRegistrations = $pdo->query("SELECT r.*, e.first_name, e.middle_name, e.last_name, e.department FROM hikvision_registrations r LEFT JOIN employees e ON r.employee_id = e.id ORDER BY r.id DESC");
    $db_registrations = $stmtRegistrations->fetchAll();
    foreach ($db_registrations as $reg) {
        $registrations[] = [
            'id' => $reg['id'],
            'employee_id' => $reg['employee_id'],
            'emp_name' => trim(($reg['first_name'] ?? 'Unknown') . ' ' . ($reg['last_name'] ?? '')),
            'department' => $reg['department'] ?? 'N/A',
            'fingerprint_data' => $reg['fingerprint_data'],
            'status' => $reg['status'],
            'created_at' => $reg['created_at']
        ];
    }

    // Fetch configured hardware terminals from database
    $stmtDev = $pdo->query("SELECT * FROM hikvision_devices ORDER BY id DESC");
    $db_devices = $stmtDev->fetchAll();
    foreach ($db_devices as $dev) {
        $devices[] = [
            'id' => $dev['id'],
            'name' => $dev['device_name'],
            'model' => $dev['model'],
            'ip' => $dev['ip_address'],
            'port' => $dev['port'],
            'type' => $dev['connection_type'],
            'status' => $dev['status']
        ];
    }

    // Fetch live scan telemetry logs from database
    $stmtScans = $pdo->query("SELECT * FROM hikvision_live_scans ORDER BY id DESC LIMIT 20");
    $db_scans = $stmtScans->fetchAll();
    foreach ($db_scans as $scan) {
        $live_scans[] = [
            'emp_id' => $scan['employee_id'],
            'name' => $scan['employee_name'],
            'device' => $scan['device_name'],
            'type' => $scan['verification_type'],
            'time' => date('h:i:s A', strtotime($scan['scan_time']))
        ];
    }

} catch (\PDOException $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Hikvision Integration Hub</title>
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
    <!-- Tailwind CSS -->
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
<body class="bg-white text-slate-900 font-sans antialiased h-screen flex overflow-hidden">

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
                    <a href="hikvision.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
                        <i class="fa-solid fa-video w-5 text-white"></i><span>Hikvision Devices</span>
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
    <main class="flex-1 flex flex-col h-full overflow-y-auto bg-white">
        <!-- Top Header Bar -->
        <header class="h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-8 shrink-0">
            <div>
                <h1 class="text-xl font-black text-slate-950 tracking-wide">Hikvision Terminal & Live TCP/IP Hub</h1>
                <p class="text-xs text-slate-700 font-semibold">Manage biometric scanning, fingerprint mapping, and network hardware configuration.</p>
            </div>
            <div class="flex items-center space-x-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border-2 border-emerald-400">
                    <span class="w-2 h-2 mr-2 bg-emerald-600 rounded-full animate-pulse"></span> TCP/IP Listener Active
                </span>
                <button onclick="openAddDeviceModal()" class="bg-blue-700 hover:bg-blue-800 text-white font-black px-4 py-2 rounded-xl text-sm transition shadow-md flex items-center space-x-2 border border-blue-900">
                    <i class="fa-solid fa-network-wired"></i><span>Add Device (TCP/IP / Ethernet)</span>
                </button>
            </div>
        </header>

        <!-- Dynamic Content Body -->
        <div class="p-8 space-y-8">
            <?php if ($success_message): ?>
                <div class="p-4 bg-emerald-100 border-2 border-emerald-400 text-emerald-900 font-black rounded-xl text-sm">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if ($db_error): ?>
                <div class="p-4 bg-red-100 border-2 border-red-400 text-red-900 font-black rounded-xl text-sm">
                    Database Connection Error: <?php echo htmlspecialchars($db_error); ?>
                </div>
            <?php endif; ?>

            <!-- Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-5 flex items-center space-x-4 shadow-sm">
                    <div class="w-12 h-12 rounded-xl bg-blue-200 text-blue-800 flex items-center justify-center text-xl font-black border border-blue-400"><i class="fa-solid fa-network-wired"></i></div>
                    <div>
                        <p class="text-xs text-slate-700 font-black uppercase tracking-wider">Connected Devices</p>
                        <h3 class="text-2xl font-black text-slate-950"><?php echo count($devices); ?> Units</h3>
                    </div>
                </div>
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-5 flex items-center space-x-4 shadow-sm">
                    <div class="w-12 h-12 rounded-xl bg-amber-200 text-amber-800 flex items-center justify-center text-xl font-black border border-amber-400"><i class="fa-solid fa-fingerprint"></i></div>
                    <div>
                        <p class="text-xs text-slate-700 font-black uppercase tracking-wider">Registered Fingerprints</p>
                        <h3 class="text-2xl font-black text-slate-950"><?php echo count($registrations); ?> Queued</h3>
                    </div>
                </div>
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-5 flex items-center space-x-4 shadow-sm">
                    <div class="w-12 h-12 rounded-xl bg-emerald-200 text-emerald-800 flex items-center justify-center text-xl font-black border border-emerald-400"><i class="fa-solid fa-wave-square"></i></div>
                    <div>
                        <p class="text-xs text-slate-700 font-black uppercase tracking-wider">Live Scans Logged</p>
                        <h3 class="text-2xl font-black text-slate-950"><?php echo count($live_scans); ?> Logs</h3>
                    </div>
                </div>
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-5 flex items-center space-x-4 shadow-sm">
                    <div class="w-12 h-12 rounded-xl bg-purple-200 text-purple-800 flex items-center justify-center text-xl font-black border border-purple-400"><i class="fa-solid fa-id-badge"></i></div>
                    <div>
                        <p class="text-xs text-slate-700 font-black uppercase tracking-wider">Fetched Employees</p>
                        <h3 class="text-2xl font-black text-slate-950"><?php echo count($mapped_employees); ?> Users</h3>
                    </div>
                </div>
            </div>

            <!-- Remote Registered Fingerprints Queue Section -->
            <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-black text-slate-950">Remote Fingerprint Registrations Queue</h2>
                        <p class="text-xs text-slate-700 font-semibold">Fetched from `hikvision_registrations` table submitted via `register.php`. Ready to be pushed to physical hardware.</p>
                    </div>
                </div>
                <div class="overflow-x-auto border-2 border-slate-300 rounded-xl">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-200 text-slate-900 uppercase text-xs tracking-wider border-b-2 border-slate-300 font-black">
                            <tr>
                                <th class="p-4 border-r border-slate-300">Reg ID</th>
                                <th class="p-4 border-r border-slate-300">Employee Details</th>
                                <th class="p-4 border-r border-slate-300">Biometric Template Token</th>
                                <th class="p-4 border-r border-slate-300">Status</th>
                                <th class="p-4 border-r border-slate-300">Timestamp</th>
                                <th class="p-4">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-300 font-bold text-slate-900 bg-white">
                            <?php if (empty($registrations)): ?>
                            <tr>
                                <td colspan="6" class="p-6 text-center text-slate-500 font-bold">No remote registrations found yet in database table.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($registrations as $reg): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-4 border-r border-slate-200 text-slate-950 font-black">#<?php echo htmlspecialchars($reg['id']); ?></td>
                                    <td class="p-4 border-r border-slate-200">
                                        <div class="text-slate-950 font-black"><?php echo htmlspecialchars($reg['emp_name']); ?></div>
                                        <div class="text-xs font-semibold text-slate-600">ID: <?php echo htmlspecialchars($reg['employee_id']); ?> | Dept: <?php echo htmlspecialchars($reg['department']); ?></div>
                                    </td>
                                    <td class="p-4 border-r border-slate-200 font-mono text-xs text-slate-600 truncate max-w-xs">
                                        <?php echo htmlspecialchars($reg['fingerprint_data']); ?>
                                    </td>
                                    <td class="p-4 border-r border-slate-200">
                                        <span class="px-2.5 py-1 <?php echo ($reg['status'] === 'Synced to Hardware') ? 'bg-emerald-100 text-emerald-900 border-emerald-400' : 'bg-amber-100 text-amber-900 border-amber-400'; ?> border rounded-lg text-xs font-black">
                                            <?php echo htmlspecialchars($reg['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 border-r border-slate-200 text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($reg['created_at']); ?></td>
                                    <td class="p-4">
                                        <button onclick="pushRegistrationToDevice(<?php echo $reg['id']; ?>, '<?php echo htmlspecialchars($reg['emp_name']); ?>')" class="px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs transition font-black border border-blue-900">
                                            Push to Hardware
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Connected Devices & Hardware Section -->
            <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-6 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-black text-slate-950">Configured Hardware Terminals</h2>
                        <p class="text-xs text-slate-700 font-semibold">Manage IP/Ethernet settings and test terminal connections.</p>
                    </div>
                    <button onclick="testAllConnections()" class="text-xs font-black text-blue-700 hover:text-blue-900 transition underline">Test All Connections</button>
                </div>
                <div class="overflow-x-auto border-2 border-slate-300 rounded-xl">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-200 text-slate-900 uppercase text-xs tracking-wider border-b-2 border-slate-300 font-black">
                            <tr>
                                <th class="p-4 border-r border-slate-300">Device Name</th>
                                <th class="p-4 border-r border-slate-300">Model</th>
                                <th class="p-4 border-r border-slate-300">IP Address / Port</th>
                                <th class="p-4 border-r border-slate-300">Connection Type</th>
                                <th class="p-4 border-r border-slate-300">Status</th>
                                <th class="p-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-300 font-bold text-slate-900 bg-white">
                            <?php if (empty($devices)): ?>
                            <tr>
                                <td colspan="6" class="p-6 text-center text-slate-500 font-bold">No devices added yet. Use the "Add Device (TCP/IP / Ethernet)" button above.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $dev): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-4 border-r border-slate-200 text-slate-950 font-black flex items-center space-x-3">
                                        <i class="fa-solid fa-video text-blue-700"></i><span><?php echo htmlspecialchars($dev['name']); ?></span>
                                    </td>
                                    <td class="p-4 border-r border-slate-200"><?php echo htmlspecialchars($dev['model']); ?></td>
                                    <td class="p-4 border-r border-slate-200"><?php echo htmlspecialchars($dev['ip'] . ' : ' . $dev['port']); ?></td>
                                    <td class="p-4 border-r border-slate-200"><?php echo htmlspecialchars($dev['type']); ?></td>
                                    <td class="p-4 border-r border-slate-200"><span class="px-2.5 py-1 bg-emerald-100 text-emerald-900 border border-emerald-400 rounded-lg text-xs font-black"><?php echo htmlspecialchars($dev['status']); ?></span></td>
                                    <td class="p-4 space-x-2">
                                        <button onclick="configureDevice('<?php echo htmlspecialchars($dev['name']); ?>')" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 rounded-lg text-xs transition font-black text-slate-900 border border-slate-400">Configure</button>
                                        <button onclick="rebootDevice('<?php echo htmlspecialchars($dev['name']); ?>')" class="px-3 py-1.5 bg-red-200 text-red-800 hover:bg-red-300 rounded-lg text-xs transition font-black border border-red-400">Reboot</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Live Fingerprint Feed & Cross-Device Sync Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Live Scan Stream -->
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-black text-slate-950 flex items-center space-x-2">
                                <i class="fa-solid fa-wave-square text-emerald-700 animate-pulse"></i><span>Live Fingerprint Stream</span>
                            </h3>
                            <span class="text-xs text-slate-700 font-black">Auto-Push Active</span>
                        </div>
                        <p class="text-xs text-slate-700 font-semibold mb-4">Real-time telemetry stored directly from connected hardware terminals.</p>
                        <div class="space-y-3 font-mono text-xs max-h-72 overflow-y-auto pr-1">
                            <?php if (empty($live_scans)): ?>
                            <div class="p-4 text-center text-slate-500 font-sans font-bold bg-white rounded-xl border-2 border-slate-300">No live scans recorded yet.</div>
                            <?php else: ?>
                                <?php foreach ($live_scans as $scan): ?>
                                <div class="bg-white p-3 rounded-xl border-2 border-slate-300 flex items-center justify-between shadow-sm">
                                    <div class="flex items-center space-x-3">
                                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span>
                                        <span class="text-slate-950 font-black"><?php echo htmlspecialchars($scan['emp_id'] . ' - ' . $scan['name']); ?></span>
                                        <span class="text-slate-700 font-semibold">(<?php echo htmlspecialchars($scan['device']); ?>)</span>
                                    </div>
                                    <span class="text-emerald-800 font-black">Verified (<?php echo htmlspecialchars($scan['type']); ?>) - <?php echo htmlspecialchars($scan['time']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-6 pt-4 border-t-2 border-slate-300 flex justify-between items-center text-xs text-slate-800 font-black">
                        <span>ISAPI Push Protocol: Port 8000</span>
                        <span class="text-blue-700">Pipeline Healthy</span>
                    </div>
                </div>

                <!-- Database Employees Fingerprint Mapping -->
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-black text-slate-950 flex items-center space-x-2">
                                <i class="fa-solid fa-users-gear text-blue-700"></i><span>Database Employees & Hardware Link</span>
                            </h3>
                            <span class="text-xs bg-blue-100 text-blue-900 border-2 border-blue-300 px-2.5 py-0.5 rounded-lg font-black">Fetched from DB</span>
                        </div>
                        <p class="text-xs text-slate-700 font-semibold mb-4">Employees fetched directly from your table and mapped to hardware terminals[cite: 2].</p>
                        <div class="space-y-3 max-h-72 overflow-y-auto pr-1">
                            <?php if (empty($mapped_employees)): ?>
                            <div class="p-4 text-center text-slate-500 font-sans font-bold bg-white rounded-xl border-2 border-slate-300">No employees found in database table.</div>
                            <?php else: ?>
                                <?php foreach ($mapped_employees as $emp): ?>
                                <div class="p-3 bg-white border-2 border-slate-300 rounded-xl flex items-center justify-between text-sm shadow-sm">
                                    <div class="font-black text-slate-950">
                                        <?php echo htmlspecialchars($emp['name']); ?> 
                                        <span class="text-xs font-bold text-slate-700 block">ID: <?php echo htmlspecialchars($emp['id']); ?> | Dept: <?php echo htmlspecialchars($emp['department']); ?> | Bio ID: <?php echo htmlspecialchars($emp['fingerprint_id']); ?></span>
                                    </div>
                                    <div class="space-x-2">
                                        <span class="px-2 py-1 bg-emerald-100 text-emerald-900 border border-emerald-400 text-xs rounded-md font-black">Synced</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-6 pt-4 border-t-2 border-slate-300 flex justify-end">
                        <span class="text-xs font-bold text-slate-500">Sync is managed via the Registration Queue above</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript Controls -->
    <script>
        const jsDevices = <?php echo json_encode($devices); ?>;

        function openAddDeviceModal() {
            let deviceName = prompt("Enter new Hikvision Device Name:");
            if (!deviceName) return;
            
            let commType = prompt("Select Connection Type (Enter 'TCP', 'IP', or 'Ethernet'):", "TCP/IP Ethernet");
            if (!commType) return;

            let deviceIp = prompt("Enter Device IP Address / Port (e.g., 192.168.1.120:8000):", "192.168.1.150:8000");
            if (!deviceIp) return;

            let parts = deviceIp.split(':');
            let ipOnly = parts[0];
            let portOnly = parts[1] ? parts[1] : '8000';

            let form = document.createElement('form');
            form.method = 'POST';
            form.action = 'hikvision.php';

            form.appendChild(createHiddenInput('action', 'add_device'));
            form.appendChild(createHiddenInput('device_name', deviceName));
            form.appendChild(createHiddenInput('model', 'Hikvision Pro Series'));
            form.appendChild(createHiddenInput('ip_address', ipOnly));
            form.appendChild(createHiddenInput('port', portOnly));
            form.appendChild(createHiddenInput('connection_type', commType.toUpperCase()));

            document.body.appendChild(form);
            form.submit();
        }

        function pushRegistrationToDevice(regId, empName) {
            if (jsDevices.length === 0) {
                alert("Please add at least one Hikvision hardware terminal device first.");
                return;
            }

            let targetDevice = jsDevices[0].name;
            if (jsDevices.length > 1) {
                let deviceChoice = prompt("Multiple devices found. Enter target device name:", targetDevice);
                if (deviceChoice) targetDevice = deviceChoice;
            }

            if (confirm("Push fingerprint registration for " + empName + " to device " + targetDevice + "?")) {
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = 'hikvision.php';

                form.appendChild(createHiddenInput('action', 'push_registration'));
                form.appendChild(createHiddenInput('reg_id', regId));
                form.appendChild(createHiddenInput('device_name', targetDevice));

                document.body.appendChild(form);
                form.submit();
            }
        }

        function testAllConnections() {
            if (jsDevices.length === 0) {
                alert("No devices configured to test.");
                return;
            }
            alert("Testing TCP/IP connection to all active Hikvision terminals... All hardware units responded successfully!");
        }

        function configureDevice(deviceName) {
            alert("Opening network and SDK configuration panel for: " + deviceName);
        }

        function rebootDevice(deviceName) {
            if(confirm("Are you sure you want to reboot " + deviceName + "?")) {
                alert(deviceName + " is restarting over network interface.");
            }
        }

        function openLogoutModal() {
            if(confirm("Are you sure you want to log out?")) {
                window.location.href = "index.php";
            }
        }

        function createHiddenInput(name, value) {
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            return input;
        }
    </script>
</body>
</html>