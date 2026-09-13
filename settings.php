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

// ---------------------------------------------------------
// DATABASE SCHEMA AUTO-UPGRADE (ALTER TABLES IF NEEDED)
// ---------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS description TEXT NULL");
    $pdo->exec("ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS tardy_rate_per_minute DECIMAL(10,4) DEFAULT 0.0000");
    $pdo->exec("ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS early_out_rate_per_minute DECIMAL(10,4) DEFAULT 0.0000");
    
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS security_question VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS security_answer VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS fingerprint_registered TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS webauthn_credential TEXT NULL");

    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS reset_requested TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS reset_requested_at TIMESTAMP NULL");
    
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS fingerprint_template TEXT NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS fingerprint_registered TINYINT(1) DEFAULT 0");
    
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS custom_sss DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS custom_philhealth DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS custom_pagibig DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS paid_leave_balance DECIMAL(5,2) DEFAULT 0.00");

    // Address Columns Support for Employees
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS region VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS street_address VARCHAR(255) NULL");
    
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS tardy TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS tardy_minutes INT DEFAULT 0");
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS undertime INT DEFAULT 0");
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS workhours DECIMAL(5,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS overtime_hours DECIMAL(5,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS workdays INT DEFAULT 1");

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        description TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_records_backup (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_table VARCHAR(100) NOT NULL,
        record_id INT NOT NULL,
        record_data TEXT NOT NULL,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pin_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_government_deductions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        basic_salary DECIMAL(10,2) DEFAULT 0.00,
        sss DECIMAL(10,2) DEFAULT 0.00,
        philhealth DECIMAL(10,2) DEFAULT 0.00,
        pag_ibig DECIMAL(10,2) DEFAULT 0.00,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\PDOException $e) {}

$success_msg = '';
$error_msg = '';
$active_tab = $_GET['tab'] ?? 'admin';

// Handle Form Submissions & Database Saves
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_admin') {
            $username = trim($_POST['admin_username']);
            $email = trim($_POST['admin_email']);
            $full_name = trim($_POST['admin_full_name']);
            $password = trim($_POST['admin_password']);
            $fingerprint_registered = isset($_POST['fingerprint_registered']) ? 1 : 0;
            $webauthn_credential = trim($_POST['webauthn_credential'] ?? '');

            if ($fingerprint_registered && empty($webauthn_credential)) {
                $currAdmin = $pdo->query("SELECT webauthn_credential FROM admins WHERE id = 1")->fetch();
                if (empty($currAdmin['webauthn_credential'])) {
                    throw new Exception("Device recovery key registration is required from your device/phone before enabling recovery authentication.");
                }
            }

            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET username = ?, email = ?, full_name = ?, password = ?, fingerprint_registered = ?, webauthn_credential = COALESCE(NULLIF(?, ''), webauthn_credential) WHERE id = 1");
                $stmt->execute([$username, $email, $full_name, $hashed, $fingerprint_registered, $webauthn_credential]);
            } else {
                $stmt = $pdo->prepare("UPDATE admins SET username = ?, email = ?, full_name = ?, fingerprint_registered = ?, webauthn_credential = COALESCE(NULLIF(?, ''), webauthn_credential) WHERE id = 1");
                $stmt->execute([$username, $email, $full_name, $fingerprint_registered, $webauthn_credential]);
            }
            $success_msg = "Admin account credentials and recovery fingerprint settings updated successfully!";
            $active_tab = 'admin';
        }

        elseif ($action === 'update_attendance_rules') {
            $rules = [
                'standard_time_in' => trim($_POST['standard_time_in'] ?? '08:00:00'),
                'standard_time_out' => trim($_POST['standard_time_out'] ?? '17:00:00'),
                'standard_work_hours' => (float)($_POST['standard_work_hours'] ?? 8.00),
                'grace_period_minutes' => (int)($_POST['grace_period_minutes'] ?? 15),
                'tardy_deduction_active' => isset($_POST['tardy_deduction_active']) ? 1 : 0,
                'early_out_deduction_active' => isset($_POST['early_out_deduction_active']) ? 1 : 0,
                'tardy_rate_per_minute' => (float)($_POST['tardy_rate_per_minute'] ?? 0.0000),
                'early_out_rate_per_minute' => (float)($_POST['early_out_rate_per_minute'] ?? 0.0000),
                'overtime_minimum_minutes' => (int)($_POST['overtime_minimum_minutes'] ?? 30),
                'overtime_multiplier' => (float)($_POST['overtime_multiplier'] ?? 1.25),
                'holiday_overtime_multiplier' => (float)($_POST['holiday_overtime_multiplier'] ?? 2.00)
            ];

            $stmtUpsert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($rules as $key => $val) {
                $stmtUpsert->execute([$key, $val]);
            }
            $success_msg = "Attendance and overtime rules successfully updated!";
            $active_tab = 'attendance_rules';
        }

        elseif ($action === 'register_employee_portal') {
            $employee_id = $_POST['employee_id'];
            $portal_email = trim($_POST['portal_email']);
            $portal_password = trim($_POST['portal_password']);
            $fingerprint_template = trim($_POST['fingerprint_template'] ?? '');
            $emp_fingerprint_registered = isset($_POST['emp_fingerprint_registered']) ? 1 : 0;

            if (empty($employee_id)) {
                throw new Exception("Please select an employee from the dropdown list.");
            }

            $chk = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $chk->execute([$employee_id]);
            $empRecord = $chk->fetch();

            if (!$empRecord) {
                throw new Exception("Selected employee record does not exist in the database.");
            }

            if (!empty($portal_password)) {
                $hashed_password = password_hash($portal_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE employees SET email = ?, password = ?, fingerprint_template = COALESCE(NULLIF(?, ''), fingerprint_template), fingerprint_registered = ?, reset_requested = 0, reset_requested_at = NULL WHERE id = ?");
                $stmt->execute([$portal_email, $hashed_password, $fingerprint_template, $emp_fingerprint_registered, $employee_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE employees SET email = ?, fingerprint_template = COALESCE(NULLIF(?, ''), fingerprint_template), fingerprint_registered = ?, reset_requested = 0, reset_requested_at = NULL WHERE id = ?");
                $stmt->execute([$portal_email, $fingerprint_template, $emp_fingerprint_registered, $employee_id]);
            }

            $delPin = $pdo->prepare("UPDATE pin_reset_requests SET status = 'Resolved' WHERE employee_id = ? AND status = 'Pending'");
            $delPin->execute([$employee_id]);

            $success_msg = "Portal login credentials & fingerprint settings successfully updated!";
            $active_tab = 'employees';
        }

        elseif ($action === 'update_all_gov_deductions') {
            $deductions = $_POST['deductions'] ?? [];
            if (!empty($deductions) && is_array($deductions)) {
                $stmt = $pdo->prepare("UPDATE employees SET custom_sss = ?, custom_philhealth = ?, custom_pagibig = ? WHERE id = ?");
                $checkDed = $pdo->prepare("SELECT id FROM employee_government_deductions WHERE employee_id = ?");
                $updateDed = $pdo->prepare("UPDATE employee_government_deductions SET basic_salary = ?, sss = ?, philhealth = ?, pag_ibig = ? WHERE employee_id = ?");
                $insertDed = $pdo->prepare("INSERT INTO employee_government_deductions (employee_id, basic_salary, sss, philhealth, pag_ibig) VALUES (?, ?, ?, ?, ?)");
                
                $colCheck = $pdo->query("SHOW COLUMNS FROM employees");
                $columns = $colCheck->fetchAll(PDO::FETCH_COLUMN);
                $salCol = in_array('basic_salary', $columns) ? 'basic_salary' : (in_array('salary', $columns) ? 'salary' : null);
                
                $getSal = $salCol ? $pdo->prepare("SELECT `$salCol` as sal FROM employees WHERE id = ?") : null;

                foreach ($deductions as $empId => $vals) {
                    $sss = (float)($vals['sss'] ?? 0);
                    $philhealth = (float)($vals['philhealth'] ?? 0);
                    $pagibig = (float)($vals['pagibig'] ?? 0);
                    
                    $stmt->execute([$sss, $philhealth, $pagibig, $empId]);

                    $basicSal = 0.00;
                    if ($getSal) {
                        $getSal->execute([$empId]);
                        $salRow = $getSal->fetch();
                        $basicSal = $salRow['sal'] ?? 0.00;
                    }

                    $checkDed->execute([$empId]);
                    if ($checkDed->fetch()) {
                        $updateDed->execute([$basicSal, $sss, $philhealth, $pagibig, $empId]);
                    } else {
                        $insertDed->execute([$empId, $basicSal, $sss, $philhealth, $pagibig]);
                    }
                }
                $success_msg = "All employee government deductions successfully updated!";
            }
            $active_tab = 'gov_deductions';
        }

        elseif ($action === 'update_all_paid_leave_balances') {
            $leaves = $_POST['leaves'] ?? [];
            if (!empty($leaves) && is_array($leaves)) {
                $stmt = $pdo->prepare("UPDATE employees SET paid_leave_balance = ? WHERE id = ?");
                foreach ($leaves as $empId => $val) {
                    $leave_balance = (float)($val['balance'] ?? 0);
                    $stmt->execute([$leave_balance, $empId]);
                }
                $success_msg = "All employee paid leave days balances successfully updated!";
            }
            $active_tab = 'paid_leaves';
        }

        elseif ($action === 'apply_bulk_paid_leave') {
            $bulk_balance = (float)($_POST['bulk_paid_leave_balance'] ?? 0);
            $stmt = $pdo->prepare("UPDATE employees SET paid_leave_balance = ?");
            $stmt->execute([$bulk_balance]);
            $success_msg = "All employee paid leave balances updated to " . $bulk_balance . " days successfully!";
            $active_tab = 'paid_leaves';
        }

        elseif ($action === 'delete_employee') {
            $employee_id = $_POST['employee_id'];
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $empData = $stmt->fetch();

            if ($empData) {
                $backupStmt = $pdo->prepare("INSERT INTO deleted_records_backup (original_table, record_id, record_data) VALUES ('employees', ?, ?)");
                $backupStmt->execute([$employee_id, json_encode($empData)]);

                $delStmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                $delStmt->execute([$employee_id]);
                
                $pdo->prepare("DELETE FROM employee_government_deductions WHERE employee_id = ?")->execute([$employee_id]);

                $success_msg = "Employee record moved to trash/backup successfully!";
            }
            $active_tab = 'employees';
        }

        elseif ($action === 'restore_record') {
            $backup_id = $_POST['backup_id'];
            $stmt = $pdo->prepare("SELECT * FROM deleted_records_backup WHERE id = ?");
            $stmt->execute([$backup_id]);
            $record = $stmt->fetch();

            if ($record) {
                $targetTable = $record['original_table'];
                $payload = json_decode($record['record_data'], true);
                if ($payload) {
                    unset($payload['id']);
                    $columns = implode(", ", array_keys($payload));
                    $placeholders = implode(", ", array_fill(0, count($payload), "?"));
                    $values = array_values($payload);

                    $restoreStmt = $pdo->prepare("INSERT INTO `$targetTable` ($columns) VALUES ($placeholders)");
                    $restoreStmt->execute($values);

                    $pdo->prepare("DELETE FROM deleted_records_backup WHERE id = ?")->execute([$backup_id]);
                    $success_msg = "Record restored successfully!";
                }
            }
            $active_tab = 'backup';
        }

        elseif ($action === 'delete_permanently') {
            $backup_id = $_POST['backup_id'];
            $pdo->prepare("DELETE FROM deleted_records_backup WHERE id = ?")->execute([$backup_id]);
            $success_msg = "Record deleted permanently.";
            $active_tab = 'backup';
        }

    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

$admin = $pdo->query("SELECT * FROM admins WHERE id = 1")->fetch() ?: ['username'=>'superadmin', 'email'=>'admin@gigpay.com', 'full_name'=>'System Administrator', 'fingerprint_registered'=>0, 'webauthn_credential'=>''];
$employees = $pdo->query("SELECT * FROM employees ORDER BY last_name ASC")->fetchAll();

$sampleEmp = $employees[0] ?? [];
$salaryKey = array_key_exists('basic_salary', $sampleEmp) ? 'basic_salary' : (array_key_exists('salary', $sampleEmp) ? 'salary' : null);

$employees_map = [];
foreach($employees as $e) {
    if(!isset($e['salary']) && $salaryKey) $e['salary'] = $e[$salaryKey] ?? 0.00;
    if(!isset($e['paid_leave_balance'])) $e['paid_leave_balance'] = 0.00;
    $employees_map[$e['id']] = $e;
}

$settings = [];
$res = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
foreach($res as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$backups = [];
try {
    $backups = $pdo->query("SELECT * FROM deleted_records_backup ORDER BY deleted_at DESC")->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Settings & Configurations</title>
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
        body { color: #000000 !important; font-weight: 700 !important; }
        input, select, textarea { color: #000000 !important; font-weight: 800 !important; border-width: 2px !important; border-color: #cbd5e1 !important; background-color: #ffffff !important; }
        input:focus, select:focus, textarea:focus { border-color: #0f172a !important; outline: none; box-shadow: 0 0 0 2px rgba(15, 23, 42, 0.1) !important; }
        label { color: #334155 !important; font-weight: 800 !important; }
        th { color: #475569 !important; font-weight: 800 !important; }
        td { color: #0f172a !important; font-weight: 700 !important; }
        
        #mobileSidebar {
            background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
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

        /* ---- Sidebar ---- */
        html[data-app-dark="1"] #mainSidebar,
        html[data-app-dark="1"] #mobileSidebar {
            background-image: none !important;
            background-color: #05070f !important;
        }
        html[data-app-dark="1"] #mainSidebar nav a,
        html[data-app-dark="1"] #mobileSidebar nav a {
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        html[data-app-dark="1"] #mainSidebar nav a i,
        html[data-app-dark="1"] #mobileSidebar nav a i {
            color: #94a3b8 !important;
        }
        html[data-app-dark="1"] #mainSidebar nav a:hover,
        html[data-app-dark="1"] #mobileSidebar nav a:hover {
            background-color: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
        }
        html[data-app-dark="1"] #mainSidebar nav a.bg-blue-700,
        html[data-app-dark="1"] #mobileSidebar nav a.bg-blue-700 {
            background-color: #1d4ed8 !important;
            border-color: #1e40af !important;
        }
        html[data-app-dark="1"] #mainSidebar .border-b-2,
        html[data-app-dark="1"] #mainSidebar .border-t-2,
        html[data-app-dark="1"] #mobileSidebar .border-b-2,
        html[data-app-dark="1"] #mobileSidebar .border-t-2 {
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        html[data-app-dark="1"] #mainSidebar span.text-blue-400,
        html[data-app-dark="1"] #mobileSidebar span.text-blue-400 {
            color: #60a5fa !important;
        }

        /* ---- App shell (page background + top header bar) ---- */
        html[data-app-dark="1"] body,
        html[data-app-dark="1"] html {
            background-color: #0b0f1a !important;
        }
        html[data-app-dark="1"] .text-slate-900 { color: #f8fafc !important; }

        /* ---- Generic content surfaces: cards, panels, header, tabs, modals ----
           These target the same Tailwind utility classes used consistently
           across every dashboard page (Admin Dashboard, Manage Requests,
           Attendance, Employees, Biometrics, Hikvision, Payroll, Reports,
           Settings), so the "features" inside each page go dark too — not
           just the sidebar. The #mainSidebar/#mobileSidebar rules above are
           more specific (ID-scoped) and always win over these, so the
           sidebar's own look is untouched by the rules below. */
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

        /* Shared "info" banners / alerts keep readable text on dark surfaces */
        html[data-app-dark="1"] .bg-red-100    { background-color: #3b1420 !important; }
        html[data-app-dark="1"] .bg-emerald-100{ background-color: #0d2b22 !important; }
        html[data-app-dark="1"] .bg-red-50     { background-color: #3b1420 !important; }
    </style>
    <script>
        const employeesData = <?php echo json_encode($employees_map); ?>;

        function switchTab(tabId) {
            document.querySelectorAll('.settings-section').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('bg-blue-700', 'text-white', 'shadow-sm');
                el.classList.add('text-slate-700', 'hover:bg-slate-200');
            });

            document.getElementById('section_' + tabId).classList.remove('hidden');
            const activeBtn = document.getElementById('btn_' + tabId);
            if(activeBtn) {
                activeBtn.classList.add('bg-blue-700', 'text-white', 'shadow-sm');
                activeBtn.classList.remove('text-slate-700', 'hover:bg-slate-200');
            }
        }

        function togglePassword(fieldId, iconId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(iconId);
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        async function registerDeviceFingerprint() {
            try {
                if (window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
                    const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                    if (available) {
                        const challenge = new Uint8Array(32);
                        window.crypto.getRandomValues(challenge);
                        
                        const credential = await navigator.credentials.create({
                            publicKey: {
                                challenge: challenge,
                                rp: { name: "GigPay Admin Panel", id: window.location.hostname },
                                user: {
                                    id: Uint8Array.from("GigPayAdmin", c => c.charCodeAt(0)),
                                    name: "superadmin@gigpay",
                                    displayName: "System Administrator"
                                },
                                pubKeyCredParams: [{ alg: -7, type: "public-key" }, { alg: -257, type: "public-key" }],
                                timeout: 60000,
                                attestation: "direct"
                            }
                        });

                        if (credential) {
                            const credIdBase64 = btoa(String.fromCharCode(...new Uint8Array(credential.rawId)));
                            document.getElementById('webauthn_credential_input').value = "webauthn_hw_" + credIdBase64;
                            document.getElementById('fingerprint_registered').checked = true;
                            
                            document.getElementById('fingerprint_status_text').innerHTML = '<span class="text-emerald-600 font-bold"><i class="fa-solid fa-circle-check mr-1"></i> Hardware Biometric / TouchID / Windows Hello successfully linked to admin login! Click Update Admin Account to save.</span>';
                            return;
                        }
                    }
                }

                const customDevicePin = prompt("Hardware platform biometric unavailable. Enter a secure device backup PIN or biometric identifier string to identify login:");
                if (!customDevicePin || customDevicePin.length < 2) {
                    alert("Registration cancelled.");
                    return;
                }

                const simulatedCredId = "local_device_bio_" + btoa(customDevicePin).substring(0, 16);
                document.getElementById('webauthn_credential_input').value = simulatedCredId;
                document.getElementById('fingerprint_registered').checked = true;
                
                document.getElementById('fingerprint_status_text').innerHTML = '<span class="text-emerald-600 font-bold"><i class="fa-solid fa-circle-check mr-1"></i> Admin recovery fingerprint identifier successfully stored! Click Update Admin Account to save.</span>';
            } catch (err) {
                alert("Biometric device identification setup failed: " + err.message);
            }
        }

        function showEmployeeDetails(jsonData) {
            try {
                const data = JSON.parse(jsonData);
                let htmlContent = '';
                for (const [key, value] of Object.entries(data)) {
                    htmlContent += `
                        <div class="flex flex-col py-1.5 border-b border-slate-200">
                            <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">${key}</span>
                            <span class="text-xs font-semibold text-slate-800 break-words">${value !== null ? value : '<span class="text-slate-400 italic">NULL</span>'}</span>
                        </div>`;
                }
                document.getElementById('modalEmployeeDetailsBody').innerHTML = htmlContent;
                document.getElementById('employeeDetailsModal').classList.remove('hidden');
            } catch (err) {
                alert("Could not parse employee payload details.");
            }
        }

        function closeEmployeeDetailsModal() {
            document.getElementById('employeeDetailsModal').classList.add('hidden');
        }

        function handleEmployeeDropdownChange(selectElement) {
            const empId = selectElement.value;
            const emailInput = document.getElementById('portal_email_input');
            const passwordInput = document.getElementById('portal_password_input');
            const fingerprintInput = document.getElementById('employee_fingerprint_input');
            const empFingerprintCheckbox = document.getElementById('emp_fingerprint_registered');

            if (empId && employeesData[empId]) {
                const emp = employeesData[empId];
                if(emailInput) emailInput.value = emp.email || '';
                if(fingerprintInput) fingerprintInput.value = emp.fingerprint_template || '';
                if(empFingerprintCheckbox) empFingerprintCheckbox.checked = parseInt(emp.fingerprint_registered || 0) === 1;
                
                if(passwordInput) {
                    passwordInput.focus();
                    passwordInput.placeholder = "Enter new password / PIN...";
                }
            } else {
                if(emailInput) emailInput.value = '';
                if(fingerprintInput) emailInput.value = '';
                if(empFingerprintCheckbox) empFingerprintCheckbox.checked = false;
                if(passwordInput) passwordInput.placeholder = "••••";
            }
        }

        function prefillEmployeeReset(empId, empEmail) {
            switchTab('employees');
            const selectEl = document.getElementById('select_employee_portal');
            if(selectEl) {
                selectEl.value = empId;
                handleEmployeeDropdownChange(selectEl);
            }
        }

        function applyBulkLeaveToAllInputs() {
            const val = document.getElementById('bulk_apply_input').value;
            if (val === '') return;
            document.querySelectorAll('.leave-balance-input').forEach(input => {
                input.value = val;
            });
        }

        // ================= App-Wide Dark Mode =================
        function getAppDarkModePref() {
            try {
                return localStorage.getItem('gigpay_app_dark_mode') === '1';
            } catch (e) {
                return true;
            }
        }

        function applyAppDarkMode(isDark) {
            document.documentElement.setAttribute('data-app-dark', isDark ? '1' : '0');
        }

        function updateDarkModeStatusText(isDark) {
            const statusEl = document.getElementById('darkmode_status_text');
            if (!statusEl) return;
            statusEl.textContent = isDark
                ? 'Currently ON — the sidebar and every page\'s features are in dark mode.'
                : 'Currently OFF — every page is using the default light theme.';
        }

        function toggleAppDarkMode(checkboxEl) {
            const isDark = checkboxEl.checked;
            try {
                localStorage.setItem('gigpay_app_dark_mode', isDark ? '1' : '0');
            } catch (e) {}
            applyAppDarkMode(isDark);
            updateDarkModeStatusText(isDark);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const isDark = getAppDarkModePref();
            applyAppDarkMode(isDark);
            const toggleEl = document.getElementById('sidebar_dark_toggle');
            if (toggleEl) {
                toggleEl.checked = isDark;
                updateDarkModeStatusText(isDark);
            }
        });

        function toggleMobileSidebar() {
            const sidebar = document.getElementById('mobileSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            }
        }
    </script>
</head>
<body class="h-full font-sans antialiased flex text-slate-900 bg-slate-100 overflow-x-hidden">

    <!-- Mobile Backdrop Overlay -->
    <div id="sidebarBackdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-40 hidden md:hidden"></div>

    <!-- Sidebar Navigation -->
    <aside id="mainSidebar" class="w-64 bg-[#0c132b] text-slate-100 flex-col justify-between hidden md:flex shrink-0 select-none border-r-2 border-slate-900" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg'); background-size: cover; background-position: center;">
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
                <a href="reports.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-chart-line w-5 text-slate-300"></i><span>Reports & Analytics</span>
                </a>
                <a href="settings.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
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

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-100">
        
        <header class="h-20 bg-white border-b border-slate-200 flex items-center justify-between px-4 sm:px-8 shrink-0 shadow-xs">
            <div class="flex items-center space-x-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden p-2 rounded-xl text-slate-700 hover:bg-slate-100 transition focus:outline-none">
                    <i class="fa-solid fa-bars text-xl"></i>
                </button>
                <h1 class="text-base sm:text-xl font-extrabold text-slate-800 truncate">System Settings</h1>
            </div>
            <div class="flex items-center space-x-3 pl-2 sm:pl-4 border-l border-slate-200">
                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs sm:text-sm shadow-xs">AD</div>
                <div class="hidden sm:block text-left">
                    <p class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($admin['full_name']); ?></p>
                    <p class="text-[11px] font-semibold text-slate-500">Administrator</p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-8 space-y-6">
            
            <?php if($error_msg): ?>
                <div class="p-4 bg-red-100 text-red-700 border border-red-300 rounded-xl text-sm font-semibold flex items-center shadow-xs">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-red-600 text-lg"></i> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="p-4 bg-emerald-100 text-emerald-800 border border-emerald-300 rounded-xl text-sm font-semibold flex items-center shadow-xs">
                    <i class="fa-solid fa-circle-check mr-3 text-emerald-600 text-lg"></i> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white p-3 rounded-2xl border border-slate-200 shadow-sm flex flex-wrap gap-2 overflow-x-auto">
                <button onclick="switchTab('admin')" id="btn_admin" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='admin'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-user-shield"></i> <span>Admin Account</span>
                </button>
                <button onclick="switchTab('attendance_rules')" id="btn_attendance_rules" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='attendance_rules'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-business-time"></i> <span>Attendance Rules</span>
                </button>
                <button onclick="switchTab('employees')" id="btn_employees" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='employees'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-users-gear"></i> <span>Employee Management</span>
                </button>
                <button onclick="switchTab('gov_deductions')" id="btn_gov_deductions" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='gov_deductions'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-peso-sign"></i> <span>Government Deductions</span>
                </button>
                <button onclick="switchTab('paid_leaves')" id="btn_paid_leaves" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='paid_leaves'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-calendar-check"></i> <span>Paid Leaves</span>
                </button>
                <button onclick="switchTab('backup')" id="btn_backup" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='backup'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-database"></i> <span>Trash / Backup</span>
                </button>
                <button onclick="switchTab('darkmode')" id="btn_darkmode" class="tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center space-x-2 whitespace-nowrap <?php echo $active_tab=='darkmode'?'bg-blue-700 text-white shadow-sm':'text-slate-700 hover:bg-slate-200'; ?>">
                    <i class="fa-solid fa-moon"></i> <span>Dark Mode</span>
                </button>
            </div>

            <!-- TAB 1: Admin Account Update -->
            <div id="section_admin" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='admin'?'':'hidden'; ?>">
                <h3 class="text-sm font-extrabold text-slate-800 mb-4">Update Administrator Credentials & Fingerprint Recovery Identification</h3>
                <form method="POST" action="settings.php?tab=admin" class="max-w-lg space-y-4">
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" id="webauthn_credential_input" name="webauthn_credential" value="<?php echo htmlspecialchars($admin['webauthn_credential'] ?? ''); ?>">

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">FULL NAME</label>
                        <input type="text" name="admin_full_name" value="<?php echo htmlspecialchars($admin['full_name']); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">EMAIL ADDRESS</label>
                        <input type="email" name="admin_email" value="<?php echo htmlspecialchars($admin['email']); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">USERNAME</label>
                        <input type="text" name="admin_username" value="<?php echo htmlspecialchars($admin['username']); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">NEW PASSWORD (Leave blank to keep unchanged)</label>
                        <div class="relative">
                            <input type="password" id="admin_password_field" name="admin_password" placeholder="••••••••" class="w-full pl-3 pr-10 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                            <button type="button" onclick="togglePassword('admin_password_field', 'admin_password_eye')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none">
                                <i id="admin_password_eye" class="fa-solid fa-eye text-xs"></i>
                            </button>
                        </div>
                    </div>
                    <hr class="border-slate-200 my-2">
                    
                    <div class="space-y-3 pt-1">
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" id="fingerprint_registered" name="fingerprint_registered" value="1" <?php echo !empty($admin['fingerprint_registered']) ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                            <label for="fingerprint_registered" class="text-xs font-bold text-slate-700 cursor-pointer flex items-center">
                                <i class="fa-solid fa-fingerprint mr-2 text-blue-600 text-sm"></i> Enable Fingerprint / Device Biometric Identification for Log In & Password Recovery
                            </label>
                        </div>
                        
                        <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-2">
                            <div id="fingerprint_status_text" class="text-[11px] text-slate-600 font-medium">
                                <?php if(!empty($admin['webauthn_credential'])): ?>
                                    <span class="text-emerald-700 font-bold"><i class="fa-solid fa-circle-check mr-1"></i> Admin biometric/fingerprint recovery credential linked successfully.</span>
                                <?php else: ?>
                                    <span class="text-amber-700 font-bold"><i class="fa-solid fa-triangle-exclamation mr-1"></i> No biometric fingerprint linked yet. Register below to allow the login recovery tool to identify your fingerprint.</span>
                                <?php endif; ?>
                            </div>
                            <button type="button" onclick="registerDeviceFingerprint()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-xs font-bold shadow-xs transition inline-flex items-center">
                                <i class="fa-solid fa-fingerprint mr-1.5 text-blue-400"></i> Scan / Link Admin Fingerprint Now
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl transition shadow-sm mt-2">
                        <i class="fa-solid fa-floppy-disk mr-1.5"></i> Update Admin Account
                    </button>
                </form>
            </div>

            <!-- TAB 2: Attendance & Overtime Rules -->
            <div id="section_attendance_rules" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='attendance_rules'?'':'hidden'; ?>">
                <h3 class="text-sm font-extrabold text-slate-800 mb-1">Attendance & Overtime Rules Configuration</h3>
                <p class="text-xs font-semibold text-slate-500 mb-6">Manage standard shifts, required workhours, grace periods, per-minute penalties, and overtime calculation multipliers.</p>
                
                <form method="POST" action="settings.php?tab=attendance_rules" class="max-w-2xl space-y-5">
                    <input type="hidden" name="action" value="update_attendance_rules">
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">STANDARD TIME IN</label>
                            <input type="time" step="1" name="standard_time_in" value="<?php echo htmlspecialchars($settings['standard_time_in'] ?? '08:00:00'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">STANDARD TIME OUT</label>
                            <input type="time" step="1" name="standard_time_out" value="<?php echo htmlspecialchars($settings['standard_time_out'] ?? '17:00:00'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">STANDARD WORKHOURS</label>
                            <input type="number" step="0.5" min="1" max="24" name="standard_work_hours" value="<?php echo htmlspecialchars($settings['standard_work_hours'] ?? '8.00'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">GRACE PERIOD (Minutes)</label>
                            <input type="number" min="0" max="60" name="grace_period_minutes" value="<?php echo (int)($settings['grace_period_minutes'] ?? 15); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">MINIMUM OVERTIME THRESHOLD (Minutes)</label>
                            <input type="number" min="0" max="120" name="overtime_minimum_minutes" value="<?php echo (int)($settings['overtime_minimum_minutes'] ?? 30); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 bg-slate-50 border border-slate-200 rounded-xl">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">TARDY DEDUCTION RATE (₱ Per Minute)</label>
                            <input type="number" step="0.0001" min="0" name="tardy_rate_per_minute" value="<?php echo htmlspecialchars($settings['tardy_rate_per_minute'] ?? '0.0000'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">EARLY OUT / UNDERTIME RATE (₱ Per Minute)</label>
                            <input type="number" step="0.0001" min="0" name="early_out_rate_per_minute" value="<?php echo htmlspecialchars($settings['early_out_rate_per_minute'] ?? '0.0000'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">OVERTIME MULTIPLIER (Regular Day)</label>
                            <input type="number" step="0.05" min="1.0" max="4.0" name="overtime_multiplier" value="<?php echo htmlspecialchars($settings['overtime_multiplier'] ?? '1.25'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">HOLIDAY OVERTIME MULTIPLIER</label>
                            <input type="number" step="0.05" min="1.0" max="4.0" name="holiday_overtime_multiplier" value="<?php echo htmlspecialchars($settings['holiday_overtime_multiplier'] ?? '2.00'); ?>" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800 font-mono">
                        </div>
                    </div>

                    <div class="space-y-3 pt-2 border-t border-slate-200">
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" id="tardy_deduction_active" name="tardy_deduction_active" value="1" <?php echo !empty($settings['tardy_deduction_active']) ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                            <label for="tardy_deduction_active" class="text-xs font-bold text-slate-700 cursor-pointer">
                                Enable Automatic Tardy / Late Deductions during Payroll computation
                            </label>
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" id="early_out_deduction_active" name="early_out_deduction_active" value="1" <?php echo !empty($settings['early_out_deduction_active']) ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                            <label for="early_out_deduction_active" class="text-xs font-bold text-slate-700 cursor-pointer">
                                Enable Automatic Undertime / Early Out Deductions during Payroll computation
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl transition shadow-sm mt-4">
                        <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Attendance & Overtime Rules
                    </button>
                </form>
            </div>

            <!-- TAB 3: Employee Account & Fingerprint Management -->
            <div id="section_employees" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='employees'?'':'hidden'; ?>">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    <div class="lg:col-span-6 space-y-4">
                        <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 shadow-xs">
                            <h4 class="text-xs font-bold text-slate-700 uppercase mb-3 flex items-center">
                                <i class="fa-solid fa-user-gear mr-1.5 text-blue-600"></i> Configure Employee Portal & Fingerprint
                            </h4>
                            <form method="POST" action="settings.php?tab=employees" class="space-y-3">
                                <input type="hidden" name="action" value="register_employee_portal">
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">SELECT EMPLOYEE *</label>
                                    <select name="employee_id" id="select_employee_portal" required onchange="handleEmployeeDropdownChange(this)" class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800">
                                        <option value="">-- Choose Employee Record --</option>
                                        <?php foreach($employees as $e): ?>
                                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name'] . ' (' . ($e['department'] ?: 'No Dept') . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">PORTAL EMAIL *</label>
                                    <input type="email" id="portal_email_input" name="portal_email" required class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800" placeholder="Auto-filled on selection">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">NEW PASSWORD / PIN *</label>
                                    <div class="relative">
                                        <input type="password" id="portal_password_input" name="portal_password" class="w-full pl-3 pr-8 py-2 bg-white border border-slate-300 rounded-xl text-xs font-semibold text-slate-800" placeholder="••••">
                                        <button type="button" onclick="togglePassword('portal_password_input', 'portal_password_eye')" class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-none">
                                            <i id="portal_password_eye" class="fa-solid fa-eye text-xs"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="pt-2 border-t border-slate-200 space-y-2">
                                    <label class="block text-[11px] font-semibold text-slate-600">HIKVISION / DEVICE FINGERPRINT TEMPLATE OR ID</label>
                                    <input type="text" id="employee_fingerprint_input" name="fingerprint_template" class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs font-mono text-slate-800" placeholder="Paste existing Hikvision FP template string or ID...">
                                    <div class="flex items-center space-x-2 pt-1">
                                        <input type="checkbox" id="emp_fingerprint_registered" name="emp_fingerprint_registered" value="1" class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                                        <label for="emp_fingerprint_registered" class="text-[11px] font-bold text-slate-700 cursor-pointer">
                                            Enable Fingerprint Auto-Login (Phone & Biometric Terminal)
                                        </label>
                                    </div>
                                </div>

                                <button type="submit" class="w-full py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl shadow-sm transition mt-2">
                                    <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Credentials & Fingerprint
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-6 overflow-x-auto border border-slate-200 rounded-xl shadow-xs">
                        <table class="w-full text-left border-collapse text-xs min-w-[500px]">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 uppercase text-[10px] font-bold text-slate-500">
                                    <th class="py-3 px-3">Employee Name</th>
                                    <th class="py-3 px-3">Department</th>
                                    <th class="py-3 px-3">Fingerprint</th>
                                    <th class="py-3 px-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700 font-medium">
                                <?php if (empty($employees)): ?>
                                    <tr><td colspan="4" class="py-6 text-center text-slate-500 font-normal">No employees found.</td></tr>
                                <?php else: foreach ($employees as $e): 
                                    $isResetRequested = isset($e['reset_requested']) && $e['reset_requested'] == 1;
                                    $hasFP = !empty($e['fingerprint_template']) || !empty($e['fingerprint_registered']);
                                ?>
                                    <tr class="hover:bg-slate-50 transition <?php echo $isResetRequested ? 'bg-red-50/60' : ''; ?>">
                                        <td class="py-3 px-3 font-bold text-slate-800">
                                            <?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?>
                                            <?php if($isResetRequested): ?>
                                                <span class="block mt-0.5 w-fit px-1.5 py-0.5 rounded text-[9px] font-bold bg-red-100 text-red-700 border border-red-200">RESET REQ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 font-semibold text-slate-600"><?php echo htmlspecialchars($e['department'] ?? 'N/A'); ?></td>
                                        <td class="py-3 px-3 font-mono text-slate-600">
                                            <?php if($hasFP): ?>
                                                <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded font-bold text-[10px]"><i class="fa-solid fa-fingerprint mr-1"></i> Linked</span>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-[11px]">Not Linked</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 text-right space-x-1">
                                            <button type="button" onclick="prefillEmployeeReset('<?php echo $e['id']; ?>', '<?php echo htmlspecialchars($e['email'] ?? ''); ?>')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-900 text-white rounded text-[10px] font-semibold transition shadow-xs inline-flex items-center" title="Edit Portal / Fingerprint">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <form method="POST" action="settings.php?tab=employees" class="inline" onsubmit="return confirm('Move employee to trash?');">
                                                <input type="hidden" name="action" value="delete_employee">
                                                <input type="hidden" name="employee_id" value="<?php echo $e['id']; ?>">
                                                <button type="submit" class="px-2.5 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-[10px] font-semibold transition shadow-xs inline-flex items-center">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: Government Deductions -->
            <div id="section_gov_deductions" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='gov_deductions'?'':'hidden'; ?>">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 pb-4 border-b border-slate-200 gap-4">
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-800 mb-1">Government Deductions Management Table</h3>
                        <p class="text-xs font-semibold text-slate-500">View and edit SSS, PhilHealth, and Pag-IBIG contributions directly for all employees. Data saves directly into the <code class="text-blue-600 bg-slate-100 px-1 py-0.5 rounded">employee_government_deductions</code> table.</p>
                    </div>
                </div>

                <form method="POST" action="settings.php?tab=gov_deductions" class="space-y-4">
                    <input type="hidden" name="action" value="update_all_gov_deductions">

                    <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-xs">
                        <table class="w-full text-left border-collapse text-xs min-w-[700px]">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 uppercase text-[10px] font-bold text-slate-500">
                                    <th class="py-3 px-4">Employee Name</th>
                                    <th class="py-3 px-4">Department</th>
                                    <th class="py-3 px-4">Basic Salary</th>
                                    <th class="py-3 px-4 w-32">SSS (₱)</th>
                                    <th class="py-3 px-4 w-32">PhilHealth (₱)</th>
                                    <th class="py-3 px-4 w-32">Pag-IBIG (₱)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700 font-medium">
                                <?php if (empty($employees)): ?>
                                    <tr><td colspan="6" class="py-6 text-center text-slate-500 font-normal">No employee records available.</td></tr>
                                <?php else: foreach ($employees as $e): 
                                    $empId = $e['id'];
                                    $baseSal = $e['salary'] ?? $e['basic_salary'] ?? 0.00;
                                    $sssVal = $e['custom_sss'] ?? 0.00;
                                    $philVal = $e['custom_philhealth'] ?? 0.00;
                                    $pagVal = $e['custom_pagibig'] ?? 0.00;
                                ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-3 px-4 font-bold text-slate-800">
                                            <?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?>
                                            <span class="block text-[10px] text-slate-400 font-normal">ID: #<?php echo $empId; ?></span>
                                        </td>
                                        <td class="py-3 px-4 font-semibold text-slate-600"><?php echo htmlspecialchars($e['department'] ?? 'N/A'); ?></td>
                                        <td class="py-3 px-4 font-mono font-bold text-blue-600">₱<?php echo number_format($baseSal, 2); ?></td>
                                        <td class="py-3 px-4">
                                            <input type="number" step="0.01" min="0" name="deductions[<?php echo $empId; ?>][sss]" value="<?php echo htmlspecialchars($sssVal); ?>" class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-mono font-bold text-slate-800">
                                        </td>
                                        <td class="py-3 px-4">
                                            <input type="number" step="0.01" min="0" name="deductions[<?php echo $empId; ?>][philhealth]" value="<?php echo htmlspecialchars($philVal); ?>" class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-mono font-bold text-slate-800">
                                        </td>
                                        <td class="py-3 px-4">
                                            <input type="number" step="0.01" min="0" name="deductions[<?php echo $empId; ?>][pagibig]" value="<?php echo htmlspecialchars($pagVal); ?>" class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-mono font-bold text-slate-800">
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-5 py-2.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl transition shadow-sm inline-flex items-center">
                            <i class="fa-solid fa-floppy-disk mr-2"></i> Save All Government Deductions
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 5: Paid Leave Days Balance Management Table -->
            <div id="section_paid_leaves" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='paid_leaves'?'':'hidden'; ?>">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 pb-4 border-b border-slate-200 gap-4">
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-800 mb-1">Paid Leave Days Balance Management</h3>
                        <p class="text-xs font-semibold text-slate-500">View and dynamically adjust available paid leave days balances for each employee. Click save to store modifications.</p>
                    </div>
                </div>

                <!-- Bulk Apply Section Form -->
                <div class="mb-6 p-4 bg-slate-50 border border-slate-200 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <form method="POST" action="settings.php?tab=paid_leaves" class="flex flex-col sm:flex-row sm:items-center gap-3 w-full">
                        <input type="hidden" name="action" value="apply_bulk_paid_leave">
                        <div class="flex-1">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">SET PAID LEAVE BALANCE FOR ALL EMPLOYEES AT ONCE</label>
                            <div class="flex items-center space-x-2">
                                <input type="number" step="0.5" min="0" max="365" id="bulk_apply_input" name="bulk_paid_leave_balance" placeholder="e.g. 15" class="w-full sm:w-48 px-3 py-2 bg-white border border-slate-300 rounded-lg text-xs font-mono font-bold text-slate-800" required>
                                <button type="button" onclick="applyBulkLeaveToAllInputs()" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-lg transition shadow-xs whitespace-nowrap">
                                    <i class="fa-solid fa-bolt mr-1 text-amber-400"></i> Apply to All Rows Below
                                </button>
                                <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold rounded-lg transition shadow-xs whitespace-nowrap">
                                    <i class="fa-solid fa-cloud-arrow-up mr-1"></i> Save to All Employees
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <form method="POST" action="settings.php?tab=paid_leaves" class="space-y-4">
                    <input type="hidden" name="action" value="update_all_paid_leave_balances">

                    <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-xs">
                        <table class="w-full text-left border-collapse text-xs min-w-[600px]">
                            <thead>
                                <tr class="bg-slate-50 border-b border-slate-200 uppercase text-[10px] font-bold text-slate-500">
                                    <th class="py-3 px-4">Employee Name</th>
                                    <th class="py-3 px-4">Department</th>
                                    <th class="py-3 px-4">Position</th>
                                    <th class="py-3 px-4 w-48">Paid Leave Balance (Days)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700 font-medium">
                                <?php if (empty($employees)): ?>
                                    <tr><td colspan="4" class="py-6 text-center text-slate-500 font-normal">No employee records available.</td></tr>
                                <?php else: foreach ($employees as $e): 
                                    $empId = $e['id'];
                                    $leaveBal = $e['paid_leave_balance'] ?? 0.00;
                                ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-3 px-4 font-bold text-slate-800">
                                            <?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?>
                                            <span class="block text-[10px] text-slate-400 font-normal">ID: #<?php echo $empId; ?></span>
                                        </td>
                                        <td class="py-3 px-4 font-semibold text-slate-600"><?php echo htmlspecialchars($e['department'] ?? 'N/A'); ?></td>
                                        <td class="py-3 px-4 font-semibold text-slate-600"><?php echo htmlspecialchars($e['position'] ?? 'N/A'); ?></td>
                                        <td class="py-3 px-4">
                                            <div class="flex items-center space-x-2">
                                                <input type="number" step="0.5" min="0" max="365" name="leaves[<?php echo $empId; ?>][balance]" value="<?php echo htmlspecialchars($leaveBal); ?>" class="leave-balance-input w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-mono font-bold text-slate-800">
                                                <span class="text-[11px] font-bold text-slate-500 whitespace-nowrap">Days</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-5 py-2.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl transition shadow-sm inline-flex items-center">
                            <i class="fa-solid fa-floppy-disk mr-2"></i> Save Paid Leave Balances
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 6: Trash / Backup Bin -->
            <div id="section_backup" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='backup'?'':'hidden'; ?>">
                <h3 class="text-sm font-extrabold text-slate-800 mb-4">Deleted Records Backup & Trash Bin</h3>
                <div class="overflow-x-auto border border-slate-200 rounded-xl shadow-xs">
                    <table class="w-full text-left border-collapse text-xs min-w-[550px]">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200 uppercase text-[10px] font-bold text-slate-500">
                                <th class="py-3 px-4">Table</th><th class="py-3 px-4">ID</th><th class="py-3 px-4">Payload Preview</th><th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700 font-medium">
                            <?php if (empty($backups)): ?>
                                <tr><td colspan="4" class="py-6 text-center text-slate-500 font-normal">No backup logs found.</td></tr>
                            <?php else: foreach ($backups as $b): 
                                $isEmployee = ($b['original_table'] === 'employees');
                                $escapedPayload = htmlspecialchars($b['record_data'], ENT_QUOTES, 'UTF-8');
                            ?>
                                <tr <?php echo $isEmployee ? "onclick=\"showEmployeeDetails('" . $escapedPayload . "')\" class='hover:bg-slate-50 cursor-pointer transition'" : "class='hover:bg-slate-50'"; ?>>
                                    <td class="py-3 px-4 font-bold text-slate-800"><?php echo $b['original_table']; ?></td>
                                    <td class="py-3 px-4 font-semibold text-slate-600">#<?php echo $b['record_id']; ?></td>
                                    <td class="py-3 px-4 font-mono truncate max-w-xs text-slate-500"><?php echo $b['record_data']; ?></td>
                                    <td class="py-3 px-4 text-right space-x-1" onclick="event.stopPropagation();">
                                        <form method="POST" action="settings.php?tab=backup" class="inline">
                                            <input type="hidden" name="action" value="restore_record">
                                            <input type="hidden" name="backup_id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-[11px] font-semibold transition shadow-xs">Restore</button>
                                        </form>
                                        <form method="POST" action="settings.php?tab=backup" class="inline" onsubmit="return confirm('Permanently delete?');">
                                            <input type="hidden" name="action" value="delete_permanently">
                                            <input type="hidden" name="backup_id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="px-2.5 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-[11px] font-semibold transition shadow-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 7: Dark Mode -->
            <div id="section_darkmode" class="settings-section bg-white border border-slate-200 rounded-2xl p-6 shadow-sm <?php echo $active_tab=='darkmode'?'':'hidden'; ?>">
                <h3 class="text-sm font-extrabold text-slate-800 mb-1">Dark Mode</h3>
                <p class="text-xs font-semibold text-slate-500 mb-6">Switch the whole app — the sidebar navigation AND the features inside every page (Dashboard, Manage Requests, Attendance, Employees, Biometrics, Hikvision, Payroll, Reports, Settings) — into a dark theme. This applies instantly and is remembered on every page — it stays on from the Admin Dashboard all the way through Settings.</p>

                <div class="max-w-lg p-4 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-moon"></i>
                        </div>
                        <div>
                            <p class="text-xs font-extrabold text-slate-800">App-Wide Dark Mode</p>
                            <p id="darkmode_status_text" class="text-[11px] font-semibold text-slate-500">Loading preference...</p>
                        </div>
                    </div>

                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                        <input type="checkbox" id="sidebar_dark_toggle" onchange="toggleAppDarkMode(this)" class="sr-only peer">
                        <div class="w-12 h-7 bg-slate-300 peer-focus:outline-none rounded-full peer peer-checked:bg-blue-700 transition-colors"></div>
                        <div class="absolute left-1 top-1 w-5 h-5 bg-white rounded-full shadow-xs transition-transform peer-checked:translate-x-5"></div>
                    </label>
                </div>

                <p class="max-w-lg text-[11px] font-semibold text-slate-400 mt-3">
                    <i class="fa-solid fa-circle-info mr-1"></i> This preference is saved on this device/browser (it is turned ON by default) and controls the theme — sidebar and content — across every admin page, not just Settings.
                </p>
            </div>

        </main>
    </div>

    <!-- Employee Details Modal -->
    <div id="employeeDetailsModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-lg w-full max-h-[85vh] flex flex-col shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h3 class="text-sm font-extrabold text-slate-800">Archived Employee Details</h3>
                <button onclick="closeEmployeeDetailsModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div id="modalEmployeeDetailsBody" class="p-6 overflow-y-auto space-y-1 text-xs"></div>
            <div class="px-6 py-3 border-t border-slate-200 bg-slate-50 text-right">
                <button onclick="closeEmployeeDetailsModal()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl">Close</button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-xl border border-slate-200">
            <h3 class="text-base font-extrabold text-slate-800 mb-1">Confirm Logout</h3>
            <p class="text-xs font-medium text-slate-500 mb-6">Are you sure you want to log out?</p>
            <div class="flex space-x-3">
                <button onclick="closeMobileSidebar(); closeLogoutModal();" class="flex-1 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl">Cancel</button>
                <a href="index.php?logout=true" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-bold rounded-xl text-center flex items-center justify-center shadow-xs">Logout</a>
            </div>
        </div>
    </div>

    <script>
        function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); }
        function closeLogoutModal() { document.getElementById('logoutModal').classList.add('hidden'); }
    </script>
</body>
</html>