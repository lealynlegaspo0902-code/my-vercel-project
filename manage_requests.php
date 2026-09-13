<?php
session_start();

// Temporarily enable error reporting to diagnose HTTP 500 issues
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    die("Database connection failed: " . $e->getMessage());
}

$success_msg = '';
$error_msg = '';

// Ensure dynamic paid leave balance column exists in employees table safely
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS paid_leave_balance DECIMAL(5,2) DEFAULT 12.00");
} catch (Exception $ex) {}

// Ensure attendance correction / missed scan structured columns exist on requests table safely
try {
    $pdo->exec("ALTER TABLE requests ADD COLUMN IF NOT EXISTS scan_type VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE requests ADD COLUMN IF NOT EXISTS correction_date DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE requests ADD COLUMN IF NOT EXISTS requested_time TIME DEFAULT NULL");
} catch (Exception $ex) {}

// Request type labels treated as Attendance Correction / Missed Scan for approval routing
$attendance_correction_types = ['attendance correction', 'missed scan'];

// Handle Status Updates, Cash Advance Amortization, and Direct Leave Balance Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_type = $_POST['action'];

    try {
        if ($action_type === 'update_balance') {
            // Handle direct updating and saving of employee paid leave balance
            $empId = (int)$_POST['employee_id'];
            $new_balance = (float)$_POST['leave_balance'];

            $stmt_bal = $pdo->prepare("UPDATE employees SET paid_leave_balance = ? WHERE id = ?");
            $stmt_bal->execute([$new_balance, $empId]);

            $success_msg = "Paid leave balance successfully updated and stored for employee #$empId.";
        } else {
            $request_id = (int)$_POST['request_id'];

            // Fetch request details to check its current status and type
            $stmt_req = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
            $stmt_req->execute([$request_id]);
            $req_data = $stmt_req->fetch();

            if (!$req_data) {
                throw new Exception("Selected request not found.");
            }

            $empId = $req_data['employee_id'];

            if ($action_type === 'reset') {
                // Reset status back to Pending and restore balance if it was a paid leave
                $pdo->beginTransaction();
                
                if (isset($req_data['status']) && strstr($req_data['status'], 'Leave with Pay')) {
                    $startDateStr = $req_data['start_date'];
                    $endDateStr = !empty($req_data['end_date']) ? $req_data['end_date'] : $startDateStr;
                    $daysCount = 1;
                    if (!empty($startDateStr) && !empty($endDateStr)) {
                        $diffTime = strtotime($endDateStr) - strtotime($startDateStr);
                        $daysCount = max(1, floor($diffTime / (60 * 60 * 24)) + 1);
                    }
                    $stmtRef = $pdo->prepare("UPDATE employees SET paid_leave_balance = paid_leave_balance + ? WHERE id = ?");
                    $stmtRef->execute([$daysCount, $empId]);
                }

                $stmt_upd = $pdo->prepare("UPDATE requests SET status = 'Pending', ca_from = NULL, ca_to = NULL, installments_count = NULL WHERE id = ?");
                $stmt_upd->execute([$request_id]);

                $stmt_del_sched = $pdo->prepare("DELETE FROM payroll_deductions WHERE request_id = ?");
                $stmt_del_sched->execute([$request_id]);

                $pdo->commit();
                $success_msg = "Request #$request_id has been reset to Pending status and leave balances adjusted accordingly.";

            } elseif ($action_type === 'approve_nopay' || $action_type === 'approve_paid' || $action_type === 'approve') {
                if (isset($req_data['status']) && (strtolower($req_data['status']) === 'approved' || strstr($req_data['status'], 'Approved'))) {
                    throw new Exception("This request has already been approved and cannot be accepted again.");
                }

                $reqTypeLower = strtolower(trim($req_data['type']));

                if ($reqTypeLower === 'cash advance') {
                    $ca_from = $_POST['ca_from'] ?? null;
                    $ca_to = $_POST['ca_to'] ?? null;
                    $manual_amount = isset($_POST['manual_deduction_amount']) && $_POST['manual_deduction_amount'] !== '' ? (float)$_POST['manual_deduction_amount'] : null;

                    if (!$ca_from || !$ca_to) {
                        throw new Exception("Please specify both 'From' and 'To' billing dates for the cash advance deduction schedule.");
                    }

                    $startDate = new DateTime($ca_from);
                    $endDate = new DateTime($ca_to);

                    if ($startDate > $endDate) {
                        throw new Exception("The 'From' date cannot be later than the 'To' date.");
                    }

                    $total_amount = (float)$req_data['amount'];
                    
                    $deduction_periods = [];
                    $tempDate = clone $startDate;
                    $tempDate->modify('first day of this month');

                    while ($tempDate <= $endDate) {
                        $y = $tempDate->format('Y');
                        $m = $tempDate->format('m');

                        $date15 = new DateTime("$y-$m-15");
                        if ($date15 >= $startDate && $date15 <= $endDate) {
                            $deduction_periods[] = $date15->format('Y-m-d');
                        }

                        $date30 = new DateTime("$y-$m-30");
                        if ($date30 >= $startDate && $date30 <= $endDate) {
                            $deduction_periods[] = $date30->format('Y-m-d');
                        }

                        $tempDate->modify('+1 month');
                    }

                    $num_installments = count($deduction_periods);
                    if ($num_installments === 0) {
                        throw new Exception("No valid 15th/30th deduction cycles found between the selected dates.");
                    }

                    $pdo->beginTransaction();

                    $stmt_upd = $pdo->prepare("UPDATE requests SET status = 'Approved', ca_from = ?, ca_to = ?, installments_count = ? WHERE id = ?");
                    $stmt_upd->execute([$ca_from, $ca_to, $num_installments, $request_id]);

                    $stmt_sched = $pdo->prepare("INSERT INTO payroll_deductions (employee_id, request_id, deduction_date, amount, status) VALUES (?, ?, ?, ?, 'Pending')");
                    
                    if ($manual_amount !== null && $manual_amount > 0) {
                        $remaining = $total_amount;
                        for ($i = 0; $i < $num_installments; $i++) {
                            if ($i === $num_installments - 1) {
                                $per_period_amount = round($remaining, 2);
                            } else {
                                $per_period_amount = ($manual_amount > $remaining) ? round($remaining, 2) : round($manual_amount, 2);
                            }
                            $remaining -= $per_period_amount;
                            
                            try {
                                $stmt_sched->execute([$req_data['employee_id'], $request_id, $deduction_periods[$i], $per_period_amount]);
                            } catch (Exception $ex) {}
                            
                            if ($remaining <= 0) break;
                        }
                    } else {
                        $per_period_amount = round($total_amount / $num_installments, 2);
                        foreach ($deduction_periods as $d_date) {
                            try {
                                $stmt_sched->execute([$req_data['employee_id'], $request_id, $d_date, $per_period_amount]);
                            } catch (Exception $ex) {}
                        }
                    }

                    $pdo->commit();
                    $success_msg = "Cash Advance request #$request_id approved successfully! Divided into $num_installments payroll deductions.";

                } elseif (in_array($reqTypeLower, ['leave', 'vacation', 'sick leave', 'maternity leave', 'paternity leave', 'emergency leave'])) {
                    $isPaid = ($action_type === 'approve_paid') ? 1 : 0;
                    $isAbsent = ($action_type === 'approve_nopay') ? 1 : 0;
                    
                    $statusLabel = $isPaid ? 'Approved (Leave with Pay)' : 'Approved (Absent / No Pay)';

                    $startDateStr = $req_data['start_date'];
                    $endDateStr = !empty($req_data['end_date']) ? $req_data['end_date'] : $startDateStr;
                    
                    $daysCount = 1;
                    if (!empty($startDateStr) && !empty($endDateStr)) {
                        $diffTime = strtotime($endDateStr) - strtotime($startDateStr);
                        $daysCount = max(1, floor($diffTime / (60 * 60 * 24)) + 1);
                    }

                    $pdo->beginTransaction();

                    if ($isPaid) {
                        $stmtCheckBal = $pdo->prepare("SELECT paid_leave_balance FROM employees WHERE id = ? FOR UPDATE");
                        $stmtCheckBal->execute([$empId]);
                        $currentBal = $stmtCheckBal->fetchColumn();

                        if ($currentBal === false || $currentBal < $daysCount) {
                            throw new Exception("Insufficient paid leave balance. Available: " . ($currentBal !== false ? $currentBal : 0) . " days, Requested: $daysCount days.");
                        }

                        $stmtDeduct = $pdo->prepare("UPDATE employees SET paid_leave_balance = paid_leave_balance - ? WHERE id = ?");
                        $stmtDeduct->execute([$daysCount, $empId]);
                    }

                    $stmt = $pdo->prepare("UPDATE requests SET status = ? WHERE id = ?");
                    $stmt->execute([$statusLabel, $request_id]);

                    if (!empty($startDateStr)) {
                        $currentDate = strtotime($startDateStr);
                        $lastDate = strtotime($endDateStr);

                        while ($currentDate <= $lastDate) {
                            $dateStr = date('Y-m-d', $currentDate);
                            $timestampStr = $dateStr . ' 00:00:00'; 

                            $chkAtt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND (DATE(check_in) = ? OR DATE(check_out) = ?) LIMIT 1");
                            $chkAtt->execute([$empId, $dateStr, $dateStr]);
                            $existingAtt = $chkAtt->fetch();

                            if ($existingAtt) {
                                $stmtUpAtt = $pdo->prepare("UPDATE attendance SET check_in = COALESCE(check_in, ?), absent = ?, absent_with_pay = ?, workdays = 0, workhours = 0.00, overtime_hours = 0.00, tardy = 0, undertime = 0 WHERE id = ?");
                                $stmtUpAtt->execute([$timestampStr, $isAbsent, $isPaid, $existingAtt['id']]);
                            } else {
                                $stmtInsAtt = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, check_out, workdays, workhours, overtime_hours, absent, absent_with_pay, tardy, undertime) VALUES (?, ?, NULL, 0, 0.00, 0.00, ?, ?, 0, 0)");
                                $stmtInsAtt->execute([$empId, $timestampStr, $isAbsent, $isPaid]);
                            }

                            $currentDate = strtotime("+1 day", $currentDate);
                        }
                    }

                    $pdo->commit();
                    $success_msg = "Leave request #$request_id approved successfully as " . ($isPaid ? "Leave with Pay ($daysCount days deducted)" : "Absent (No Pay Leave)") . "!";

                } elseif (in_array($reqTypeLower, $attendance_correction_types)) {
                    // Attendance Correction / Missed Scan: store the corrected time directly into the attendance table
                    $scan_type = trim($_POST['scan_type'] ?? '');
                    $correction_date = trim($_POST['correction_date'] ?? '');
                    $requested_time_raw = trim($_POST['requested_time'] ?? '');

                    if (!in_array($scan_type, ['Check In', 'Check Out'])) {
                        throw new Exception("Please specify whether this correction is for Check In or Check Out.");
                    }
                    if (empty($correction_date)) {
                        throw new Exception("Please specify the attendance date this correction applies to.");
                    }
                    if (empty($requested_time_raw)) {
                        throw new Exception("Please specify the corrected time to record.");
                    }

                    $timeObj = DateTime::createFromFormat('H:i', $requested_time_raw) ?: DateTime::createFromFormat('H:i:s', $requested_time_raw);
                    if (!$timeObj) {
                        throw new Exception("Invalid time format supplied for the correction.");
                    }
                    $requested_time = $timeObj->format('H:i:s');
                    $fullTimestamp = $correction_date . ' ' . $requested_time;

                    $pdo->beginTransaction();

                    // Save the structured correction details on the request itself for record-keeping/transparency
                    $stmt_upd = $pdo->prepare("UPDATE requests SET status = 'Approved', scan_type = ?, correction_date = ?, requested_time = ? WHERE id = ?");
                    $stmt_upd->execute([$scan_type, $correction_date, $requested_time, $request_id]);

                    // Find an existing attendance row for that date, if any
                    $chkAtt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND (DATE(check_in) = ? OR DATE(check_out) = ?) LIMIT 1");
                    $chkAtt->execute([$empId, $correction_date, $correction_date]);
                    $existingAtt = $chkAtt->fetch();

                    if ($scan_type === 'Check In') {
                        if ($existingAtt) {
                            $stmtUpAtt = $pdo->prepare("UPDATE attendance SET check_in = ? WHERE id = ?");
                            $stmtUpAtt->execute([$fullTimestamp, $existingAtt['id']]);
                        } else {
                            $stmtInsAtt = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, check_out, workdays, workhours, overtime_hours, absent, absent_with_pay, tardy, undertime) VALUES (?, ?, NULL, 0, 0.00, 0.00, 0, 0, 0, 0)");
                            $stmtInsAtt->execute([$empId, $fullTimestamp]);
                        }
                    } else { // Check Out
                        if ($existingAtt) {
                            $stmtUpAtt = $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?");
                            $stmtUpAtt->execute([$fullTimestamp, $existingAtt['id']]);
                        } else {
                            $stmtInsAtt = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, check_out, workdays, workhours, overtime_hours, absent, absent_with_pay, tardy, undertime) VALUES (?, NULL, ?, 0, 0.00, 0.00, 0, 0, 0, 0)");
                            $stmtInsAtt->execute([$empId, $fullTimestamp]);
                        }
                    }

                    $pdo->commit();
                    $success_msg = "Attendance Correction request #$request_id approved! $scan_type recorded on " . date('M d, Y', strtotime($correction_date)) . " at " . date('g:i A', strtotime($requested_time)) . ".";

                } else {
                    $approved_hours = isset($_POST['modified_value']) && $_POST['modified_value'] !== '' ? (float)$_POST['modified_value'] : (float)($req_data['hours'] ?? 0);

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE requests SET status = 'Approved', hours = ? WHERE id = ?");
                    $stmt->execute([$approved_hours, $request_id]);
                    $pdo->commit();

                    $success_msg = "Request #$request_id has been successfully marked as Approved!";
                }
            } else {
                $stmt = $pdo->prepare("UPDATE requests SET status = 'Rejected' WHERE id = ?");
                $stmt->execute([$request_id]);
                $success_msg = "Request #$request_id has been successfully marked as Rejected!";
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = "Error updating database record: " . $e->getMessage();
    }
}

// Fetch active employees with paid leave balances
$query_employees = "
    SELECT id AS employee_id, first_name, last_name, department, COALESCE(paid_leave_balance, 12.00) AS leave_balance
    FROM employees
    ORDER BY last_name ASC, first_name ASC
";
$all_active_employees = $pdo->query($query_employees)->fetchAll(PDO::FETCH_ASSOC);

// Fetch all requests joined with employee info
$query_requests = "
    SELECT r.*, e.first_name, e.last_name, e.department, COALESCE(e.paid_leave_balance, 12.00) AS leave_balance
    FROM requests r
    JOIN employees e ON r.employee_id = e.id
    ORDER BY r.created_at DESC
";
$raw_requests = $pdo->query($query_requests)->fetchAll(PDO::FETCH_ASSOC);

// Group requests by employee_id and ensure every active employee is listed
$grouped_employees = [];
foreach ($all_active_employees as $emp) {
    $emp_id = $emp['employee_id'];
    $grouped_employees[$emp_id] = [
        'employee_id' => $emp_id,
        'first_name' => $emp['first_name'],
        'last_name' => $emp['last_name'],
        'department' => $emp['department'] ?? 'Unassigned',
        'leave_balance' => (float)$emp['leave_balance'],
        'requests' => []
    ];
}

foreach ($raw_requests as $req) {
    $emp_id = $req['employee_id'];
    if (isset($grouped_employees[$emp_id])) {
        $grouped_employees[$emp_id]['requests'][] = $req;
    } else {
        $grouped_employees[$emp_id] = [
            'employee_id' => $emp_id,
            'first_name' => $req['first_name'],
            'last_name' => $req['last_name'],
            'department' => $req['department'],
            'leave_balance' => (float)($req['leave_balance'] ?? 12.00),
            'requests' => [$req]
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-200">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GigPay - Manage Requests</title>
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
            background-color: rgba(3, 7, 18, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
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
<body class="h-full font-sans antialiased flex flex-col md:flex-row text-slate-950 overflow-x-hidden">

    <!-- Mobile Header Bar -->
    <div class="md:hidden fixed top-0 left-0 right-0 h-14 bg-[#050b14] border-b-2 border-slate-900 z-40 flex items-center justify-between px-3 text-white">
        <div class="flex items-center space-x-2.5">
            <button onclick="toggleMobileSidebar()" class="p-1.5 rounded-lg bg-slate-800 text-slate-200 focus:outline-none">
                <i class="fa-solid fa-bars text-base"></i>
            </button>
            <div class="flex items-center space-x-2">
                <div class="flex items-center justify-center w-7 h-7">
                    <img src="pay.png" alt="Pay Logo" class="h-5 w-auto object-contain">
                </div>
                <span class="text-base font-black tracking-tight text-white">Gig<span class="text-blue-400 font-black">Pay</span></span>
            </div>
        </div>
        <div class="w-7 h-7 rounded-full bg-blue-200 text-blue-950 flex items-center justify-center font-black text-[11px] border border-blue-400">
            <?php echo isset($_SESSION['admin_name']) ? substr($_SESSION['admin_name'], 0, 2) : 'AD'; ?>
        </div>
    </div>

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div id="mobileSidebarBackdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- Sidebar Navigation -->
    <aside id="sidebarNav" class="w-64 bg-[#0c132b] text-slate-100 flex flex-col justify-between fixed md:relative inset-y-0 left-0 z-50 transform -translate-x-full md:translate-x-0 transition-transform duration-300 shrink-0 select-none border-r-2 border-slate-900 overflow-y-auto" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg'); background-size: cover; background-position: center;">
        <div class="h-full flex flex-col justify-between">
            <div>
                <div class="h-16 md:h-20 flex items-center px-4 md:px-6 space-x-3 border-b-2 border-slate-800">
                    <div class="flex items-center justify-center">
                        <img src="pay.png" alt="Pay Icon" class="w-10 h-8 md:w-12 md:h-10 object-contain">
                    </div>
                    <span class="text-lg md:text-xl font-black tracking-tight text-white">Gig<span class="text-blue-400">Pay</span></span>
                </div>
                <nav class="p-3 md:p-4 space-y-1.5 text-xs md:text-sm font-bold">
                    <a href="admin_dashboard.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-chart-pie w-5 text-slate-300"></i><span>Dashboard</span>
                    </a>
                    <a href="manage_requests.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
                        <i class="fa-solid fa-clock-rotate-left w-5 text-slate-300"></i><span>Manage Requests</span>
                    </a>
                    <a href="attendance.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-calendar-days w-5 text-slate-300"></i><span>Attendance</span>
                    </a>
                    <a href="employee.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-users w-5 text-slate-300"></i><span>Employees</span>
                    </a>
                    <a href="biometric.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-fingerprint w-5 text-slate-300"></i><span>F01H Biometrics</span>
                    </a>
                    <a href="hikvision.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-video w-5 text-slate-300"></i><span>Hikvision Biometrics</span>
                    </a>
                    <a href="payroll.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-wallet w-5 text-slate-300"></i><span>Payroll Computation</span>
                    </a>
                    <a href="reports.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-chart-line w-5 text-slate-300"></i><span>Reports & Analytics</span>
                    </a>
                    <a href="settings.php" class="flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                        <i class="fa-solid fa-gear w-5 text-slate-300"></i><span>Settings</span>
                    </a>
                </nav>
            </div>
            <div class="p-3 md:p-4 border-t-2 border-slate-800">
                <button onclick="openLogoutModal()" class="w-full flex items-center space-x-3 px-3 py-2.5 md:px-4 md:py-3 rounded-xl text-red-300 hover:bg-red-500/20 hover:text-red-200 transition text-xs md:text-sm font-black text-left border border-red-500/30">
                    <i class="fa-solid fa-arrow-right-from-bracket w-5"></i><span>Logout</span>
                </button>
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden pt-14 md:pt-0 w-full">
        
        <!-- Header -->
        <header class="h-16 md:h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-3 sm:px-8 shrink-0 shadow-sm">
            <div class="flex items-center space-x-3">
                <div>
                    <h1 class="text-xs sm:text-xl font-black text-slate-950 tracking-tight truncate max-w-[210px] sm:max-w-none">Manage Requests</h1>
                </div>
            </div>
            
            <div class="flex items-center space-x-3 sm:space-x-4">
                <div class="flex items-center space-x-2 sm:space-x-3 border-l-2 border-slate-300 pl-3 sm:pl-4">
                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-blue-200 text-blue-950 flex items-center justify-center font-black text-xs sm:text-sm border-2 border-blue-400 shadow-sm shrink-0">
                        <?php echo isset($_SESSION['admin_name']) ? substr($_SESSION['admin_name'], 0, 2) : 'AD'; ?>
                    </div>
                    <div class="hidden sm:block text-left">
                        <p class="text-xs font-black text-slate-950"><?php echo isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'System Administrator'; ?></p>
                        <p class="text-[11px] font-bold text-slate-700">Administrator</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Body -->
        <main class="flex-1 overflow-y-auto p-3 sm:p-8 space-y-4 sm:space-y-6 bg-slate-100">

            <?php if($error_msg): ?>
                <div class="p-3 sm:p-4 bg-red-100 text-red-950 border-2 border-red-400 rounded-xl text-xs font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation mr-2.5 text-red-700 font-bold shrink-0 text-sm"></i> <span class="break-words"><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="p-3 sm:p-4 bg-emerald-100 text-emerald-950 border-2 border-emerald-400 rounded-xl text-xs font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-check mr-2.5 text-emerald-700 font-bold shrink-0 text-sm"></i> <span class="break-words"><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- Table Card -->
            <div class="bg-white border-2 border-slate-300 rounded-2xl shadow-md overflow-hidden">
                <div class="p-3.5 sm:p-5 border-b-2 border-slate-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2.5 bg-slate-50">
                    <div>
                        <h2 class="text-xs sm:text-sm font-black text-slate-950">Click any employee row to review requests.</h2>
                    </div>
                    <span class="text-[11px] sm:text-xs bg-slate-200 text-slate-900 font-black px-2.5 py-1 rounded-full border-2 border-slate-400 shadow-sm">
                        Total Employees: <?php echo count($grouped_employees); ?>
                    </span>
                </div>

                <div class="overflow-x-auto w-full">
                    <table class="w-full text-left border-collapse text-xs whitespace-nowrap sm:whitespace-normal">
                        <thead>
                            <tr class="bg-slate-200 border-b-2 border-slate-300 text-slate-950 uppercase text-[10px] sm:text-[11px] font-black tracking-wider">
                                <th class="py-3 px-3 sm:px-6">Employee</th>
                                <th class="py-3 px-3 sm:px-6">Department</th>
                                <th class="py-3 px-3 sm:px-6">Paid Leave Bal.</th>
                                <th class="py-3 px-3 sm:px-6">Requests</th>
                                <th class="py-3 px-3 sm:px-6 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-200 text-slate-900 font-semibold">
                            <?php if(empty($grouped_employees)): ?>
                                <tr>
                                    <td colspan="5" class="py-10 text-center text-slate-600 font-bold text-xs">No employee records found in database.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($grouped_employees as $emp): ?>
                                    <tr onclick='openEmployeeModal(<?php echo json_encode($emp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' 
                                        class="hover:bg-blue-50 cursor-pointer transition-colors group">
                                        <td class="py-3.5 px-3 sm:px-6 font-black text-slate-950">
                                            <div class="text-xs sm:text-sm font-black text-slate-950"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                        </td>
                                        <td class="py-3.5 px-3 sm:px-6 text-slate-800 font-bold text-xs">
                                            <?php echo htmlspecialchars($emp['department']); ?>
                                        </td>
                                        <td class="py-3.5 px-3 sm:px-6">
                                            <span class="inline-flex items-center px-2 py-0.5 sm:px-2.5 sm:py-1 rounded-full text-[11px] sm:text-xs font-black bg-emerald-100 text-emerald-950 border-2 border-emerald-400 shadow-sm">
                                                <i class="fa-solid fa-wallet mr-1 text-[9px] text-emerald-700"></i> <?php echo number_format($emp['leave_balance'], 2); ?>d
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-3 sm:px-6">
                                            <span class="inline-flex items-center px-2.5 py-0.5 sm:px-3 sm:py-1 rounded-full text-[11px] sm:text-xs font-black <?php echo count($emp['requests']) > 0 ? 'bg-blue-100 text-blue-950 border-2 border-blue-400' : 'bg-slate-100 text-slate-600 border-2 border-slate-300'; ?> shadow-sm">
                                                <i class="fa-solid fa-bell mr-1 text-[9px] font-bold"></i> <?php echo count($emp['requests']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-3 sm:px-6 text-right">
                                            <button class="px-2.5 py-1 sm:px-3 sm:py-1.5 bg-blue-700 group-hover:bg-blue-800 text-white rounded-xl text-[11px] sm:text-xs font-black shadow-sm transition border border-blue-500">
                                                View <i class="fa-solid fa-arrow-right ml-0.5 font-bold"></i>
                                            </button>
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

    <!-- Modal for Individual Employee Requests & Leave Balance Management -->
    <div id="employeeModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop hidden transition-opacity duration-200 p-2 sm:p-4">
        <div class="bg-white border-2 border-slate-400 shadow-2xl rounded-2xl sm:rounded-3xl max-w-3xl w-full p-3.5 sm:p-6 relative text-slate-950 max-h-[92vh] flex flex-col">
            <div class="flex justify-between items-start border-b-2 border-slate-200 pb-3 mb-3 shrink-0">
                <div>
                    <span id="modalEmpBadge" class="px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] font-black bg-blue-100 text-blue-950 uppercase tracking-wider border border-blue-400">Employee Profile</span>
                    <h3 class="text-sm sm:text-xl font-black text-slate-950 mt-1 truncate max-w-[220px] sm:max-w-none" id="modalEmpName">Employee Name</h3>
                    <p class="text-[11px] sm:text-xs font-bold text-slate-700" id="modalEmpDept">Department</p>
                </div>
                <button onclick="closeEmployeeModal()" class="text-slate-800 hover:text-slate-950 p-1.5 sm:p-2 rounded-xl hover:bg-slate-200 transition font-bold border-2 border-slate-300 shrink-0">
                    <i class="fa-solid fa-xmark text-base sm:text-lg font-bold"></i>
                </button>
            </div>

            <input type="hidden" id="modalEmpIdInput">
            <input type="hidden" id="modalEmpBalanceInput">

            <div class="overflow-y-auto space-y-2.5 sm:space-y-3 flex-1 pr-0.5 sm:pr-1" id="modalRequestsList"></div>

            <div class="mt-4 sm:mt-6 pt-3 sm:pt-4 border-t-2 border-slate-200 flex items-center justify-end shrink-0">
                <button type="button" onclick="closeEmployeeModal()" class="px-4 py-2 sm:px-5 sm:py-2.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-950 font-black text-xs transition border-2 border-slate-400 shadow-sm w-full sm:w-auto">
                    Close Window
                </button>
            </div>
        </div>
    </div>

    <!-- Single Request Action Sub-Modal / Form Handler -->
    <div id="actionSubModal" class="fixed inset-0 z-[60] flex items-center justify-center modal-backdrop hidden p-2 sm:p-4 overflow-y-auto">
        <div class="bg-white border-2 border-slate-400 shadow-2xl rounded-2xl max-w-lg w-full p-3.5 sm:p-6 relative text-slate-950 max-h-[92vh] flex flex-col my-auto">
            <div class="flex justify-between items-start border-b-2 border-slate-200 pb-2.5 mb-2.5 shrink-0">
                <h4 class="font-black text-slate-950 text-xs sm:text-sm">Process Item #<span id="subReqId"></span></h4>
                <button onclick="closeActionSubModal()" class="text-slate-800 hover:text-slate-950 font-bold p-1"><i class="fa-solid fa-xmark text-base font-bold"></i></button>
            </div>
            
            <div class="overflow-y-auto flex-1 pr-1 space-y-2 text-[11px] sm:text-xs font-bold mb-3 text-slate-900">
                <p><strong>Type:</strong> <span id="subReqType" class="text-slate-950"></span></p>
                <p><strong>Value/Duration:</strong> <span id="subReqValue" class="text-slate-950"></span></p>
                <p><strong>Details / Notes:</strong> <span id="subReqDetails" class="text-slate-950 block bg-slate-100 p-2 sm:p-2.5 rounded-lg mt-1 border-2 border-slate-300 break-words text-[11px] sm:text-xs"></span></p>
                
                <div id="leaveBalanceTransparencyBox" class="hidden p-2.5 sm:p-3 bg-blue-50 border-2 border-blue-300 rounded-xl space-y-1 text-blue-950">
                    <p class="font-black uppercase text-[10px] text-blue-800"><i class="fa-solid fa-wallet mr-1"></i> Paid Leave Transparency Check:</p>
                    <p>Employee Available Stored Balance: <span id="transparencyBalanceVal" class="font-black">0</span> Days</p>
                    <p>Requested Leave Duration: <span id="transparencyRequestedVal" class="font-black">0</span> Days</p>
                    <p id="transparencyWarningMsg" class="text-[11px] font-bold text-red-600 hidden mt-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Warning: Stored balance is 0 or insufficient! Paid Leave button is locked.</p>
                </div>

                <div id="sitePhotoPreviewContainer" class="hidden mt-2.5 space-y-1">
                    <p class="font-black text-slate-900">Submitted Site Photo:</p>
                    <div class="border-2 border-slate-300 rounded-xl overflow-hidden bg-slate-950 flex justify-center p-1.5">
                        <img id="subPhotoImg" src="" alt="Site Photo" class="max-h-48 sm:max-h-64 object-contain rounded-lg">
                    </div>
                </div>

                <div id="approvedScheduleDetails" class="hidden mt-2 p-2.5 sm:p-3 bg-emerald-50 border-2 border-emerald-300 rounded-lg text-emerald-950 space-y-1">
                    <p class="font-black uppercase text-[10px] text-emerald-800"><i class="fa-solid fa-circle-check mr-1"></i> Status Information:</p>
                    <p id="approvedInfoText" class="break-words"></p>
                    <div id="caScheduleTextContainer" class="hidden">
                        <p>Deduction Window: <span id="displayCaWindow" class="font-black"></span></p>
                        <p>Total Installment Periods: <span id="displayInstallments" class="font-black"></span></p>
                    </div>
                    <div id="acScheduleTextContainer" class="hidden">
                        <p>Scan Type: <span id="displayAcScanType" class="font-black"></span></p>
                        <p>Attendance Date: <span id="displayAcDate" class="font-black"></span></p>
                        <p>Recorded Time: <span id="displayAcTime" class="font-black"></span></p>
                    </div>
                </div>
            </div>

            <!-- Overtime / Numeric Modification Section -->
            <div id="overtimeConfigSection" class="hidden mb-3 p-3 sm:p-4 bg-indigo-50 border-2 border-indigo-300 rounded-xl space-y-2 shrink-0">
                <h5 class="text-[11px] sm:text-xs font-black text-indigo-950 uppercase tracking-wide flex items-center">
                    <i class="fa-solid fa-clock-rotate-left mr-1.5"></i> Adjust Approved Hours (Overtime)
                </h5>
                <div>
                    <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">Approved Hours:</label>
                    <input type="number" step="0.5" id="modifiedValueInput" name="modified_value" placeholder="e.g. 1.0" class="w-full bg-white border-2 border-slate-300 rounded-lg px-2.5 py-1.5 sm:px-3 sm:py-2 text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-600">
                </div>
            </div>

            <!-- Attendance Correction / Missed Scan Config -->
            <div id="attendanceCorrectionSection" class="hidden mb-3 p-3 sm:p-4 bg-teal-50 border-2 border-teal-300 rounded-xl space-y-2.5 shrink-0">
                <h5 class="text-[11px] sm:text-xs font-black text-teal-950 uppercase tracking-wide flex items-center">
                    <i class="fa-solid fa-fingerprint mr-1.5"></i> Attendance Correction Details
                </h5>
                <div>
                    <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">This correction is for:</label>
                    <div class="flex gap-2">
                        <label class="flex-1 flex items-center justify-center gap-1.5 bg-white border-2 border-slate-300 rounded-lg px-2.5 py-2 text-xs font-bold text-slate-950 cursor-pointer has-[:checked]:bg-teal-600 has-[:checked]:text-white has-[:checked]:border-teal-700">
                            <input type="radio" name="acScanTypeRadio" id="acScanTypeIn" value="Check In" onchange="onAcScanTypeChange()" class="accent-teal-700">
                            <i class="fa-solid fa-right-to-bracket"></i> Check In
                        </label>
                        <label class="flex-1 flex items-center justify-center gap-1.5 bg-white border-2 border-slate-300 rounded-lg px-2.5 py-2 text-xs font-bold text-slate-950 cursor-pointer has-[:checked]:bg-teal-600 has-[:checked]:text-white has-[:checked]:border-teal-700">
                            <input type="radio" name="acScanTypeRadio" id="acScanTypeOut" value="Check Out" onchange="onAcScanTypeChange()" class="accent-teal-700">
                            <i class="fa-solid fa-right-from-bracket"></i> Check Out
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">Attendance Date:</label>
                        <input type="date" id="acCorrectionDate" class="w-full bg-white border-2 border-slate-300 rounded-lg p-2 text-xs font-bold text-slate-950 focus:outline-none focus:border-teal-600">
                    </div>
                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">Corrected Time:</label>
                        <input type="time" id="acRequestedTime" class="w-full bg-white border-2 border-slate-300 rounded-lg p-2 text-xs font-bold text-slate-950 focus:outline-none focus:border-teal-600">
                    </div>
                </div>
                <p class="text-[10px] sm:text-[11px] font-bold text-teal-800 bg-teal-100/70 border border-teal-300 rounded-lg p-2"><i class="fa-solid fa-circle-info mr-1"></i> Approving will write this time directly into the employee's attendance record for the date selected above.</p>
            </div>

            <!-- Cash Advance Repayment Schedule Config -->
            <div id="cashAdvanceConfigSection" class="hidden mb-3 p-3 sm:p-4 bg-blue-50 border-2 border-blue-300 rounded-xl space-y-2.5 shrink-0">
                <h5 class="text-[11px] sm:text-xs font-black text-blue-950 uppercase tracking-wide flex items-center">
                    <i class="fa-solid fa-calculator mr-1.5"></i> Cash Advance Repayment Schedule Config
                </h5>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">Deduction From:</label>
                        <input type="date" id="caFromDate" name="ca_from" onchange="calculateAmortization()" class="w-full bg-white border-2 border-slate-300 rounded-lg p-2 text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-600">
                    </div>
                    <div>
                        <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">Deduction To:</label>
                        <input type="date" id="caToDate" name="ca_to" onchange="calculateAmortization()" class="w-full bg-white border-2 border-slate-300 rounded-lg p-2 text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-600">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] sm:text-[11px] font-black text-slate-800 mb-1">Manual Amount Per Payroll (Leave blank to auto-calculate):</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center text-xs font-bold text-slate-500">₱</span>
                        <input type="number" step="0.01" id="manualDeductionAmount" name="manual_deduction_amount" oninput="calculateAmortization()" placeholder="e.g. 1500.00" class="w-full bg-white border-2 border-slate-300 rounded-lg pl-6 pr-2.5 py-1.5 sm:py-2 text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-600">
                    </div>
                </div>

                <div id="amortizationResultBox" class="bg-white p-2.5 rounded-lg border-2 border-blue-200 text-[11px] sm:text-xs font-bold space-y-1 hidden">
                    <p class="text-slate-800">Total Deduction Cycles (15th & 30th): <span id="resTotalCycles" class="font-black text-blue-900">0</span></p>
                    <p class="text-slate-800">Deduction Amount Per Period: <span id="resPerCycleAmount" class="font-black text-emerald-700">₱0.00</span></p>
                    <p class="text-slate-800">Remaining Balance for Final Payroll: <span id="resRemainingBalance" class="font-black text-indigo-700">₱0.00</span></p>
                </div>
            </div>

            <form method="POST" action="manage_requests.php" id="approvalForm" class="flex flex-col sm:flex-row gap-2 shrink-0 pt-2 border-t border-slate-200">
                <input type="hidden" name="request_id" id="subInputReqId">
                <input type="hidden" id="hiddenCaFrom" name="ca_from">
                <input type="hidden" id="hiddenCaTo" name="ca_to">
                <input type="hidden" id="hiddenManualAmount" name="manual_deduction_amount">
                <input type="hidden" id="hiddenModifiedValue" name="modified_value">
                <input type="hidden" id="hiddenAcScanType" name="scan_type">
                <input type="hidden" id="hiddenAcCorrectionDate" name="correction_date">
                <input type="hidden" id="hiddenAcRequestedTime" name="requested_time">

                <button type="button" onclick="closeActionSubModal()" class="px-3.5 py-2 bg-slate-200 hover:bg-slate-300 font-black rounded-xl text-xs text-slate-950 border-2 border-slate-400 shadow-sm order-last sm:order-first">Back</button>
                
                <div id="actionButtonsContainer" class="flex flex-1 gap-2 flex-col sm:flex-row">
                    <button type="submit" name="action" value="reject" class="flex-1 px-3.5 py-2 bg-red-100 hover:bg-red-200 text-red-950 font-black rounded-xl text-xs border-2 border-red-400 shadow-sm text-center">Reject</button>
                    <button type="submit" name="action" value="approve" id="standardApproveBtn" onclick="prepareApprovalSubmission(event)" class="flex-1 px-3.5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-black rounded-xl text-xs shadow-md border border-emerald-500 text-center">Approve</button>
                </div>

                <div id="leaveActionButtonsContainer" class="hidden flex flex-1 gap-1.5 flex-col sm:flex-row">
                    <button type="submit" name="action" value="reject" class="px-2.5 py-2 bg-red-100 hover:bg-red-200 text-red-950 font-black rounded-xl text-xs border-2 border-red-400 shadow-sm text-center">Reject</button>
                    <button type="submit" name="action" value="approve_nopay" class="flex-1 px-2.5 py-2 bg-amber-600 hover:bg-amber-700 text-white font-black rounded-xl text-[10px] sm:text-[11px] shadow-md border border-amber-500 text-center" title="Mark as Absent (No Pay)">No Pay Leave</button>
                    <button type="submit" name="action" value="approve_paid" id="paidLeaveSubmitBtn" class="flex-1 px-2.5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-black rounded-xl text-[10px] sm:text-[11px] shadow-md border border-emerald-500 text-center" title="Mark as Leave with Pay">Paid Leave</button>
                </div>

                <div id="resetButtonContainer" class="hidden flex-1">
                    <button type="submit" name="action" value="reset" onclick="return confirm('Are you sure you want to reset this request status back to Pending and restore leave balance if applicable?');" class="w-full px-3.5 py-2 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black rounded-xl text-xs shadow-md border border-amber-600 flex items-center justify-center space-x-1">
                        <i class="fa-solid fa-pen-to-square"></i><span>Edit / Reset Status</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-5 sm:p-6 shadow-2xl border-2 border-slate-400 transform transition-all text-slate-950">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-red-100 border-2 border-red-400 text-red-700 rounded-xl flex items-center justify-center text-lg sm:text-xl mb-3 sm:mb-4 font-bold shadow-inner">
                <i class="fa-solid fa-triangle-exclamation font-bold"></i>
            </div>
            <h3 class="text-base sm:text-lg font-black text-slate-950 mb-1">Confirm Logout</h3>
            <p class="text-xs font-bold text-slate-700 mb-5 sm:mb-6">Are you sure you want to log out of your admin account?</p>
            <div class="flex space-x-3">
                <button onclick="closeLogoutModal()" class="flex-1 px-3.5 py-2.5 bg-slate-200 border-2 border-slate-400 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition shadow-sm">
                    Cancel
                </button>
                <a href="index.php?logout=true" class="flex-1 px-3.5 py-2.5 bg-red-700 border-2 border-red-500 hover:bg-red-800 text-white text-xs font-black rounded-xl transition text-center flex items-center justify-center shadow-md">
                    Yes, Logout
                </a>
            </div>
        </div>
    </div>

    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebarNav');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        let currentEmployeeData = null;
        let currentActiveRequest = null;
        const groupedEmployeesData = <?php echo json_encode($grouped_employees); ?>;

        function openEmployeeModal(emp) {
            currentEmployeeData = emp;
            document.getElementById('modalEmpName').innerText = emp.first_name + ' ' + emp.last_name;
            document.getElementById('modalEmpDept').innerText = emp.department;
            document.getElementById('modalEmpIdInput').value = emp.employee_id;
            document.getElementById('modalEmpBalanceInput').value = parseFloat(emp.leave_balance).toFixed(2);
            renderRequestsList(emp.requests, emp.leave_balance);
            document.getElementById('employeeModal').classList.remove('hidden');
        }

        function renderRequestsList(requests, empBalance) {
            let container = document.getElementById('modalRequestsList');
            container.innerHTML = '';

            if(requests.length === 0) {
                container.innerHTML = '<p class="text-center text-slate-600 font-bold py-8 text-xs">No requests or photo submissions found for this employee.</p>';
                return;
            }

            requests.forEach(req => {
                let isAttCorrectionCard = req.type && ['attendance correction', 'missed scan'].includes(req.type.toLowerCase());

                let valStr = 'N/A';
                if (req.amount) valStr = '₱' + parseFloat(req.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
                else if (req.hours) valStr = req.hours + ' Hours';
                else if (req.start_date && req.end_date) valStr = req.start_date + ' to ' + req.end_date;
                else if (req.type && req.type.toLowerCase() === 'site photo' && req.details) valStr = 'Photo Submission';
                else if (isAttCorrectionCard) valStr = (req.scan_type && req.correction_date) ? `${req.scan_type} — ${req.correction_date} ${req.requested_time || ''}` : 'Pending Review';

                let isApproved = req.status && req.status.toLowerCase().includes('approved');
                let isRejected = req.status && req.status.toLowerCase() === 'rejected';
                let badgeColor = isApproved ? 'bg-emerald-100 text-emerald-950 border-2 border-emerald-400' : (isRejected ? 'bg-red-100 text-red-950 border-2 border-red-400' : 'bg-amber-100 text-amber-950 border-2 border-amber-400');
                let typeColor = req.type=='Leave'?'bg-blue-100 text-blue-950 border border-blue-400':(req.type=='Overtime'?'bg-indigo-100 text-indigo-950 border border-indigo-400':(isAttCorrectionCard?'bg-teal-100 text-teal-950 border border-teal-400':'bg-emerald-100 text-emerald-950 border border-emerald-400'));

                let card = document.createElement('div');
                card.className = 'p-3 sm:p-4 rounded-xl border-2 border-slate-300 bg-slate-50 hover:bg-white transition flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2.5 shadow-xs';
                let encodedReq = JSON.stringify(req).replace(/"/g, '&quot;');

                card.innerHTML = `
                    <div class="space-y-1 w-full sm:w-auto overflow-hidden">
                        <div class="flex items-center space-x-1.5 sm:space-x-2 flex-wrap gap-y-1">
                            <span class="px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] font-black ${typeColor}">${req.type}</span>
                            <span class="font-mono text-[11px] sm:text-xs text-slate-700 font-bold">#${req.id}</span>
                            <span class="px-2 py-0.5 rounded-full text-[9px] sm:text-[10px] font-black ${badgeColor}">${req.status}</span>
                        </div>
                        <p class="text-[11px] sm:text-xs font-black text-slate-900 truncate">Details: <span class="font-mono text-blue-800">${valStr}</span></p>
                        <p class="text-[10px] sm:text-[11px] font-bold text-slate-700">Submitted: ${new Date(req.created_at).toLocaleString()}</p>
                    </div>
                    <button onclick='openActionSubModal(${encodedReq})' class="w-full sm:w-auto px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white font-black rounded-lg text-[11px] sm:text-xs shadow-sm transition border border-blue-500 text-center shrink-0">
                        Review & Process
                    </button>
                `;
                container.appendChild(card);
            });
        }

        function closeEmployeeModal() { document.getElementById('employeeModal').classList.add('hidden'); }

        function openActionSubModal(req) {
            currentActiveRequest = req;
            document.getElementById('subReqId').innerText = req.id;
            document.getElementById('subInputReqId').value = req.id;
            document.getElementById('subReqType').innerText = req.type;
            
            let valStr = 'N/A';
            let leaveDaysCount = 1;
            let isAttendanceCorrectionType = req.type && ['attendance correction', 'missed scan'].includes(req.type.toLowerCase());

            if (req.amount) valStr = '₱' + parseFloat(req.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
            else if (req.hours) valStr = req.hours + ' Hours';
            else if (req.start_date && req.end_date) {
                valStr = req.start_date + ' to ' + req.end_date;
                let diff = new Date(req.end_date) - new Date(req.start_date);
                leaveDaysCount = Math.max(1, Math.floor(diff / (1000 * 60 * 60 * 24)) + 1);
            } else if (isAttendanceCorrectionType) {
                valStr = req.scan_type && req.correction_date ? `${req.scan_type} — ${req.correction_date} ${req.requested_time || ''}` : 'Awaiting corrected time entry';
            }
            
            document.getElementById('subReqValue').innerText = valStr;
            document.getElementById('subReqDetails').innerText = req.details || 'No additional details provided.';
            
            const photoPreviewContainer = document.getElementById('sitePhotoPreviewContainer');
            const subPhotoImg = document.getElementById('subPhotoImg');
            let isSitePhoto = req.type && req.type.toLowerCase() === 'site photo';

            if (isSitePhoto && req.details) {
                subPhotoImg.src = req.details; 
                photoPreviewContainer.classList.remove('hidden');
            } else {
                photoPreviewContainer.classList.add('hidden');
            }

            const caSection = document.getElementById('cashAdvanceConfigSection');
            const overtimeSection = document.getElementById('overtimeConfigSection');
            const acSection = document.getElementById('attendanceCorrectionSection');
            const scheduleDetailsBox = document.getElementById('approvedScheduleDetails');
            const actionButtonsContainer = document.getElementById('actionButtonsContainer');
            const leaveActionButtonsContainer = document.getElementById('leaveActionButtonsContainer');
            const resetButtonContainer = document.getElementById('resetButtonContainer');
            const leaveTransparencyBox = document.getElementById('leaveBalanceTransparencyBox');

            let isLeaveType = req.type && ['leave', 'vacation', 'sick leave', 'maternity leave', 'paternity leave', 'emergency leave'].includes(req.type.toLowerCase());
            let isOvertimeType = req.type && req.type.toLowerCase() === 'overtime';
            let isPending = !req.status || req.status.toLowerCase() === 'pending';

            let empBalance = 12.00;
            if (groupedEmployeesData[req.employee_id]) {
                empBalance = parseFloat(groupedEmployeesData[req.employee_id].leave_balance);
            } else if (req.leave_balance !== undefined) {
                empBalance = parseFloat(req.leave_balance);
            }

            if (!isPending) {
                caSection.classList.add('hidden');
                overtimeSection.classList.add('hidden');
                acSection.classList.add('hidden');
                actionButtonsContainer.classList.add('hidden');
                leaveActionButtonsContainer.classList.add('hidden');
                resetButtonContainer.classList.remove('hidden');
                leaveTransparencyBox.classList.add('hidden');

                document.getElementById('approvedInfoText').innerText = `Current Status: ${req.status}`;

                if (req.type && req.type.toLowerCase() === 'cash advance' && req.ca_from && req.ca_to) {
                    document.getElementById('caScheduleTextContainer').classList.remove('hidden');
                    document.getElementById('displayCaWindow').innerText = `${req.ca_from} to ${req.ca_to}`;
                    document.getElementById('displayInstallments').innerText = `${req.installments_count || 'N/A'} payroll cycles (15th & 30th)`;
                } else {
                    document.getElementById('caScheduleTextContainer').classList.add('hidden');
                }

                if (isAttendanceCorrectionType && req.scan_type) {
                    document.getElementById('acScheduleTextContainer').classList.remove('hidden');
                    document.getElementById('displayAcScanType').innerText = req.scan_type;
                    document.getElementById('displayAcDate').innerText = req.correction_date || 'N/A';
                    document.getElementById('displayAcTime').innerText = req.requested_time || 'N/A';
                } else {
                    document.getElementById('acScheduleTextContainer').classList.add('hidden');
                }
                scheduleDetailsBox.classList.remove('hidden');
            } else {
                resetButtonContainer.classList.add('hidden');
                scheduleDetailsBox.classList.add('hidden');

                if (isLeaveType) {
                    actionButtonsContainer.classList.add('hidden');
                    leaveActionButtonsContainer.classList.remove('hidden');
                    caSection.classList.add('hidden');
                    overtimeSection.classList.add('hidden');
                    acSection.classList.add('hidden');

                    leaveTransparencyBox.classList.remove('hidden');
                    document.getElementById('transparencyBalanceVal').innerText = empBalance.toFixed(2);
                    document.getElementById('transparencyRequestedVal').innerText = leaveDaysCount;

                    const paidBtn = document.getElementById('paidLeaveSubmitBtn');
                    const warningMsg = document.getElementById('transparencyWarningMsg');

                    if (empBalance <= 0 || empBalance < leaveDaysCount) {
                        paidBtn.disabled = true;
                        paidBtn.classList.add('opacity-50', 'cursor-not-allowed', 'bg-slate-400');
                        paidBtn.classList.remove('bg-emerald-700', 'hover:bg-emerald-800');
                        warningMsg.classList.remove('hidden');
                    } else {
                        paidBtn.disabled = false;
                        paidBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'bg-slate-400');
                        paidBtn.classList.add('bg-emerald-700', 'hover:bg-emerald-800');
                        warningMsg.classList.add('hidden');
                    }
                } else {
                    leaveTransparencyBox.classList.add('hidden');
                    if (isOvertimeType) {
                        actionButtonsContainer.classList.remove('hidden');
                        leaveActionButtonsContainer.classList.add('hidden');
                        caSection.classList.add('hidden');
                        overtimeSection.classList.remove('hidden');
                        acSection.classList.add('hidden');
                        document.getElementById('modifiedValueInput').value = req.hours || '';
                    } else if (req.type && req.type.toLowerCase() === 'cash advance') {
                        actionButtonsContainer.classList.remove('hidden');
                        leaveActionButtonsContainer.classList.add('hidden');
                        caSection.classList.remove('hidden');
                        overtimeSection.classList.add('hidden');
                        acSection.classList.add('hidden');
                        document.getElementById('caFromDate').value = '';
                        document.getElementById('caToDate').value = '';
                        document.getElementById('manualDeductionAmount').value = '';
                        document.getElementById('amortizationResultBox').classList.add('hidden');
                    } else if (isAttendanceCorrectionType) {
                        actionButtonsContainer.classList.remove('hidden');
                        leaveActionButtonsContainer.classList.add('hidden');
                        caSection.classList.add('hidden');
                        overtimeSection.classList.add('hidden');
                        acSection.classList.remove('hidden');

                        document.getElementById('acScanTypeIn').checked = false;
                        document.getElementById('acScanTypeOut').checked = false;
                        if (req.scan_type === 'Check In') document.getElementById('acScanTypeIn').checked = true;
                        if (req.scan_type === 'Check Out') document.getElementById('acScanTypeOut').checked = true;

                        // Pre-fill the date: prefer a structured correction_date from the request; otherwise fall
                        // back to the date the request was submitted, since these requests are usually filed same-day.
                        let defaultDate = req.correction_date || (req.created_at ? req.created_at.substring(0, 10) : '');
                        document.getElementById('acCorrectionDate').value = defaultDate;
                        document.getElementById('acRequestedTime').value = req.requested_time ? req.requested_time.substring(0, 5) : '';
                    } else {
                        actionButtonsContainer.classList.remove('hidden');
                        leaveActionButtonsContainer.classList.add('hidden');
                        caSection.classList.add('hidden');
                        overtimeSection.classList.add('hidden');
                        acSection.classList.add('hidden');
                    }
                }
            }

            document.getElementById('actionSubModal').classList.remove('hidden');
        }

        function calculateAmortization() {
            if (!currentActiveRequest || !currentActiveRequest.amount) return;
            const fromVal = document.getElementById('caFromDate').value;
            const toVal = document.getElementById('caToDate').value;
            const manualAmtVal = document.getElementById('manualDeductionAmount').value;
            const resBox = document.getElementById('amortizationResultBox');

            if (!fromVal || !toVal) {
                resBox.classList.add('hidden');
                return;
            }

            const startDate = new Date(fromVal);
            const endDate = new Date(toVal);

            if (startDate > endDate) {
                resBox.classList.remove('hidden');
                document.getElementById('resTotalCycles').innerText = 'Invalid range';
                document.getElementById('resPerCycleAmount').innerText = '₱0.00';
                document.getElementById('resRemainingBalance').innerText = '₱0.00';
                return;
            }

            const totalAmount = parseFloat(currentActiveRequest.amount);
            let cyclesCount = 0;
            let tempDate = new Date(startDate.getFullYear(), startDate.getMonth(), 1);

            while (tempDate <= endDate) {
                let y = tempDate.getFullYear();
                let m = tempDate.getMonth();
                let date15 = new Date(y, m, 15);
                if (date15 >= startDate && date15 <= endDate) cyclesCount++;
                let date30 = new Date(y, m, 30);
                if (date30 >= startDate && date30 <= endDate) cyclesCount++;
                tempDate.setMonth(tempDate.getMonth() + 1);
            }

            if (cyclesCount > 0) {
                let perCycle = (manualAmtVal !== '' && !isNaN(manualAmtVal)) ? parseFloat(manualAmtVal) : (totalAmount / cyclesCount);
                
                let remainingAfterFirst = totalAmount - perCycle;
                if (remainingAfterFirst < 0) remainingAfterFirst = 0;

                document.getElementById('resTotalCycles').innerText = cyclesCount + ' payroll deduction periods';
                document.getElementById('resPerCycleAmount').innerText = '₱' + perCycle.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' per 15th & 30th';
                document.getElementById('resRemainingBalance').innerText = '₱' + remainingAfterFirst.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                resBox.classList.remove('hidden');
            } else {
                resBox.classList.remove('hidden');
                document.getElementById('resTotalCycles').innerText = '0 cycles in selected range';
                document.getElementById('resPerCycleAmount').innerText = '₱0.00';
                document.getElementById('resRemainingBalance').innerText = '₱0.00';
            }
        }

        function prepareApprovalSubmission(event) {
            if (currentActiveRequest && currentActiveRequest.type) {
                let typeLower = currentActiveRequest.type.toLowerCase();
                if (typeLower === 'cash advance') {
                    const fromVal = document.getElementById('caFromDate').value;
                    const toVal = document.getElementById('caToDate').value;
                    const manualAmtVal = document.getElementById('manualDeductionAmount').value;

                    if (!fromVal || !toVal) {
                        event.preventDefault();
                        alert('Please select both deduction From and To dates for this Cash Advance before approving.');
                        return false;
                    }
                    document.getElementById('hiddenCaFrom').value = fromVal;
                    document.getElementById('hiddenCaTo').value = toVal;
                    document.getElementById('hiddenManualAmount').value = manualAmtVal;
                } else if (typeLower === 'overtime') {
                    const modifiedVal = document.getElementById('modifiedValueInput').value;
                    document.getElementById('hiddenModifiedValue').value = modifiedVal;
                } else if (typeLower === 'attendance correction' || typeLower === 'missed scan') {
                    const scanTypeChecked = document.querySelector('input[name="acScanTypeRadio"]:checked');
                    const dateVal = document.getElementById('acCorrectionDate').value;
                    const timeVal = document.getElementById('acRequestedTime').value;

                    if (!scanTypeChecked) {
                        event.preventDefault();
                        alert('Please select whether this correction is for Check In or Check Out.');
                        return false;
                    }
                    if (!dateVal) {
                        event.preventDefault();
                        alert('Please select the attendance date this correction applies to.');
                        return false;
                    }
                    if (!timeVal) {
                        event.preventDefault();
                        alert('Please enter the corrected time to record.');
                        return false;
                    }

                    document.getElementById('hiddenAcScanType').value = scanTypeChecked.value;
                    document.getElementById('hiddenAcCorrectionDate').value = dateVal;
                    document.getElementById('hiddenAcRequestedTime').value = timeVal;
                }
            }
        }

        function onAcScanTypeChange() { /* visual state handled via CSS :checked — hook kept for future validation */ }

        function closeActionSubModal() { document.getElementById('actionSubModal').classList.add('hidden'); }

        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                closeActionSubModal();
                closeEmployeeModal();
                closeLogoutModal();
            }
        });

        function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); }
        function closeLogoutModal() { document.getElementById('logoutModal').classList.add('hidden'); }
    </script>
</body>
</html>