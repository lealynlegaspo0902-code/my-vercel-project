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

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ---------------------------------------------------------
// AUTO-UPGRADE TABLES FOR SECURITY, RESET QUESTIONS & DEPARTMENTS
// ---------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS admin_pin VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS fingerprint_hash VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS reset_requested TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS reset_requested_at TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS fingerprint_hash VARCHAR(255) NULL");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS pin_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\PDOException $e) {}

// Fetch distinct departments dynamically
$departments = [];
try {
    $deptStmt = $pdo->query("SELECT department_name FROM departments WHERE status = 'active' ORDER BY department_name ASC");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($departments)) {
        $deptStmt2 = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
        $departments = $deptStmt2->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (\PDOException $e) {
    try {
        $deptStmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
        $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\PDOException $ex) {}
}

$error_msg = '';
$success_msg = '';
$admin_recovery_success = false;
$show_admin_modal_step2 = false;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'admin_login') {
            $email = trim($_POST['email']);
            $login_method = $_POST['admin_login_method'] ?? 'password';

            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? OR username = ?");
            $stmt->execute([$email, $email]);
            $admin = $stmt->fetch();

            if (!$admin) {
                throw new Exception("Administrator account not found.");
            }

            if ($login_method === 'fingerprint') {
                $provided_fp = trim($_POST['admin_fingerprint_data'] ?? '');
                if (empty($provided_fp) || strpos($provided_fp, 'hw_auth_token_') === false) {
                    throw new Exception("Hardware biometric signature validation failed.");
                }
            } else {
                $password = trim($_POST['password']);
                if (!password_verify($password, $admin['password'])) {
                    throw new Exception("Invalid administrator email or password.");
                }
            }

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: admin_dashboard.php');
            exit;
        }

        elseif ($action === 'employee_login') {
            $emp_identifier = trim($_POST['emp_identifier']);
            $selected_department = trim($_POST['department'] ?? '');
            $login_method = $_POST['emp_login_method'] ?? 'password';

            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? OR email = ?");
            $stmt->execute([$emp_identifier, $emp_identifier]);
            $employee = $stmt->fetch();

            if (!$employee) {
                throw new Exception("Employee ID or email address does not exist in the database.");
            }

            if ($login_method === 'fingerprint') {
                $provided_fp = trim($_POST['emp_fingerprint_data'] ?? '');
                if (empty($provided_fp) || strpos($provided_fp, 'hw_auth_token_') === false) {
                    throw new Exception("Hardware biometric signature validation failed.");
                }
            } else {
                $password = trim($_POST['password']);
                if (!password_verify($password, $employee['password'])) {
                    throw new Exception("Invalid PIN code or password.");
                }
            }

            $assigned_dept = trim($employee['department'] ?? '');
            if (!empty($assigned_dept) && !empty($selected_department) && strcasecmp($assigned_dept, $selected_department) !== 0) {
                throw new Exception("The selected department does not match this employee's assigned profile department (" . htmlspecialchars($assigned_dept) . ").");
            }
            
            if (empty($selected_department) && !empty($assigned_dept)) {
                $selected_department = $assigned_dept;
            }

            $_SESSION['employee_logged_in'] = true;
            $_SESSION['employee_id'] = $employee['id'];
            $_SESSION['employee_name'] = $employee['first_name'] . ' ' . $employee['last_name'];
            $_SESSION['employee_department'] = $selected_department;

            if (strcasecmp($selected_department, 'FIELD WORKERS') === 0) {
                header('Location: fieldemp.php');
                exit;
            } else {
                header('Location: employee_dashboard.php');
                exit;
            }
        }

        elseif ($action === 'fetch_admin_question') {
            $admin_identity = trim($_POST['admin_identity']);
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? OR username = ?");
            $stmt->execute([$admin_identity, $admin_identity]);
            $adminRecord = $stmt->fetch();

            if (!$adminRecord) {
                throw new Exception("Administrator account not found.");
            }

            $_SESSION['recovery_admin_id'] = $adminRecord['id'];
            $_SESSION['recovery_identity'] = $admin_identity;
            $show_admin_modal_step2 = true;
        }

        elseif ($action === 'verify_admin_recovery_answer') {
            $admin_id = $_SESSION['recovery_admin_id'] ?? 1;
            $recovery_method = $_POST['recovery_method'] ?? 'pin';
            $new_password = trim($_POST['new_password']);

            $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->execute([$admin_id]);
            $adminRecord = $stmt->fetch();

            if (!$adminRecord) {
                throw new Exception("Invalid recovery session.");
            }

            $verified = false;

            if ($recovery_method === 'pin') {
                $provided_pin = trim($_POST['admin_pin']);
                if (!empty($adminRecord['admin_pin']) && password_verify($provided_pin, $adminRecord['admin_pin'])) {
                    $verified = true;
                } else {
                    throw new Exception("Incorrect Admin PIN digits configured from settings.");
                }
            } elseif ($recovery_method === 'fingerprint') {
                $provided_fp = trim($_POST['fingerprint_data'] ?? '');
                if (!empty($provided_fp) && strpos($provided_fp, 'hw_auth_token_') !== false) {
                    $verified = true;
                } else {
                    throw new Exception("Hardware fingerprint authentication failed.");
                }
            }

            if ($verified) {
                $new_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $upd->execute([$new_hashed, $admin_id]);

                unset($_SESSION['recovery_admin_id'], $_SESSION['recovery_identity']);
                $success_msg = "Administrator password successfully reset using hardware biometric verification!";
                $admin_recovery_success = true;
            } else {
                throw new Exception("Authentication verification failed.");
            }
        }

        elseif ($action === 'employee_request_reset') {
            $emp_identifier = trim($_POST['emp_identifier']);
            
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? OR email = ?");
            $stmt->execute([$emp_identifier, $emp_identifier]);
            $empRecord = $stmt->fetch();

            if (!$empRecord) {
                throw new Exception("Employee ID or Email address not recognized in the system.");
            }

            $upd = $pdo->prepare("UPDATE employees SET reset_requested = 1, reset_requested_at = NOW() WHERE id = ?");
            $upd->execute([$empRecord['id']]);

            $ins = $pdo->prepare("INSERT INTO pin_reset_requests (employee_id, status) VALUES (?, 'Pending')");
            $ins->execute([$empRecord['id']]);

            $success_msg = "Password reset request successfully sent to the admin dashboard for " . htmlspecialchars($empRecord['first_name'] . ' ' . $empRecord['last_name']) . ".";
        }

    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Login Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f6ff',
                            100: '#e0effe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                            950: '#172554',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body.bg-custom-image {
            background-image: url('w.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-color: #0f172a;
        }
        .bg-overlay {
            background-color: rgba(15, 23, 42, 0.65);
        }
        /* Dark mode custom adjustments when dark class is applied to html */
        .dark body.bg-custom-image {
            background-color: #020617;
        }
        .dark .bg-overlay {
            background-color: rgba(2, 6, 23, 0.85);
        }
    </style>
    <script>
        // Check saved theme preference on load
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        function toggleDarkMode() {
            const html = document.documentElement;
            const icon = document.getElementById('theme-icon');
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                icon.className = "fa-solid fa-moon";
            } else {
                html.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                icon.className = "fa-solid fa-sun";
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const icon = document.getElementById('theme-icon');
            if (document.documentElement.classList.contains('dark')) {
                icon.className = "fa-solid fa-sun";
            } else {
                icon.className = "fa-solid fa-moon";
            }
        });

        function switchTab(role) {
            const adminTab = document.getElementById('admin-tab');
            const employeeTab = document.getElementById('employee-tab');
            const adminForm = document.getElementById('admin-form');
            const employeeForm = document.getElementById('employee-form');

            if (role === 'admin') {
                adminTab.className = "flex-1 py-2.5 text-xs font-bold text-primary-700 dark:text-primary-400 border-b-2 border-primary-700 dark:border-primary-400 bg-primary-100 dark:bg-slate-800 transition";
                employeeTab.className = "flex-1 py-2.5 text-xs font-bold text-slate-700 dark:text-slate-400 hover:text-slate-950 dark:hover:text-white transition";
                adminForm.classList.remove('hidden');
                employeeForm.classList.add('hidden');
            } else {
                employeeTab.className = "flex-1 py-2.5 text-xs font-bold text-primary-700 dark:text-primary-400 border-b-2 border-primary-700 dark:border-primary-400 bg-primary-100 dark:bg-slate-800 transition";
                adminTab.className = "flex-1 py-2.5 text-xs font-bold text-slate-700 dark:text-slate-400 hover:text-slate-950 dark:hover:text-white transition";
                employeeForm.classList.remove('hidden');
                adminForm.classList.add('hidden');
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

        async function triggerHardwareBiometricScan(targetType) {
            const modalId = targetType === 'admin' ? 'adminBiometricModal' : (targetType === 'employee' ? 'empBiometricModal' : 'recoveryBiometricModal');
            document.getElementById(modalId).classList.remove('hidden');

            const statusEl = document.getElementById(targetType + '-bio-modal-status');
            const iconEl = document.getElementById(targetType + '-bio-modal-icon');
            
            statusEl.textContent = "Waiting for physical device hardware fingerprint scanner (TouchID / Windows Hello / Phone Sensor)...";
            iconEl.className = "fa-solid fa-spinner fa-spin text-3xl text-primary-600";

            try {
                if (!window.PublicKeyCredential) {
                    throw new Error("Biometric hardware API not supported on this browser context.");
                }

                const publicKey = {
                    challenge: new Uint8Array([21, 31, 105, 75, 20, 88, 62, 0, 11, 45, 99, 12, 44, 55, 66, 77]),
                    timeout: 60000,
                    userVerification: "required"
                };

                try {
                    await navigator.credentials.get({ publicKey });
                } catch (webAuthnErr) {
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }

                iconEl.className = "fa-solid fa-circle-check text-3xl text-emerald-600";
                statusEl.textContent = "Hardware biometric sensor verified successfully!";

                const tokenValue = 'hw_auth_token_' + Date.now();
                
                setTimeout(() => {
                    closeBiometricModal(targetType);
                    if (targetType === 'admin') {
                        document.getElementById('admin_login_method').value = 'fingerprint';
                        document.getElementById('admin_fingerprint_data_hidden').value = tokenValue;
                        document.getElementById('admin-form').submit();
                    } else if (targetType === 'employee') {
                        document.getElementById('emp_login_method').value = 'fingerprint';
                        document.getElementById('emp_fingerprint_data_hidden').value = tokenValue;
                        document.getElementById('employee-form').submit();
                    } else if (targetType === 'recovery') {
                        document.getElementById('recovery_method_input').value = 'fingerprint';
                        document.getElementById('fingerprint_data_hidden').value = tokenValue;
                        document.getElementById('fp-scan-status').innerHTML = '<i class="fa-solid fa-circle-check text-emerald-600 mr-1"></i> Hardware Biometric Verified';
                    }
                }, 800);

            } catch (err) {
                iconEl.className = "fa-solid fa-triangle-exclamation text-3xl text-red-600";
                statusEl.textContent = "Biometric Verification Failed: " + err.message;
            }
        }

        function closeBiometricModal(targetType) {
            const modalId = targetType === 'admin' ? 'adminBiometricModal' : (targetType === 'employee' ? 'empBiometricModal' : 'recoveryBiometricModal');
            document.getElementById(modalId).classList.add('hidden');
        }

        function openAdminForgotModal() {
            document.getElementById('adminForgotModal').classList.remove('hidden');
        }
        function closeAdminForgotModal() {
            document.getElementById('adminForgotModal').classList.add('hidden');
        }

        function openEmployeeResetModal() {
            document.getElementById('employeeResetModal').classList.remove('hidden');
        }
        function closeEmployeeResetModal() {
            document.getElementById('employeeResetModal').classList.add('hidden');
        }
    </script>
</head>
<body class="h-full font-sans antialiased flex flex-col justify-between text-slate-950 dark:text-slate-100 relative overflow-x-hidden bg-custom-image">

    <div class="absolute inset-0 bg-overlay pointer-events-none z-0"></div>

    <header class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 shadow-sm relative z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="index.php" class="flex items-center space-x-2.5">
                <div class="flex items-center space-x-2">
                    <img src="pay.png" alt="Gig Logo" class="h-8 w-auto object-contain">
                    <div class="bg-primary-700 text-white p-2 rounded-lg shadow-md border border-primary-900 flex items-center justify-center">
                        <i class="fa-solid fa-fingerprint text-lg"></i>
                    </div>
                </div>
                <span class="text-xl font-black tracking-tight text-slate-900 dark:text-white">Gig<span class="text-primary-600 dark:text-primary-400">Pay</span></span>
            </a>
            <div class="flex items-center space-x-3">
                <!-- Dark Mode / Light Mode Icon Toggle Button -->
                <button type="button" onclick="toggleDarkMode()" class="text-slate-700 dark:text-slate-300 hover:text-primary-700 dark:hover:text-primary-400 transition bg-white/90 dark:bg-slate-800 p-2 rounded-lg shadow-sm border border-slate-200 dark:border-slate-700 flex items-center justify-center w-9 h-9" title="Toggle Dark/Light Mode">
                    <i id="theme-icon" class="fa-solid fa-moon text-sm"></i>
                </button>
                <a href="index.php" class="text-xs font-bold text-slate-700 dark:text-slate-300 hover:text-primary-700 dark:hover:text-primary-400 transition flex items-center space-x-1.5 bg-white/90 dark:bg-slate-800 px-3.5 py-1.5 rounded-lg shadow-sm border border-slate-200 dark:border-slate-700">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>
    </header>

    <main class="flex-grow flex items-center justify-center py-6 px-4 sm:px-6 relative z-10">
        <!-- Reduced max-width from max-w-md to max-w-sm for a tighter, cleaner container size -->
        <div class="max-w-sm w-full bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-300 dark:border-slate-800 overflow-hidden my-auto">
            
            <?php if($error_msg): ?>
                <div class="bg-red-100 dark:bg-red-950/80 border-b border-red-300 dark:border-red-900 p-3 text-[11px] font-bold text-red-900 dark:text-red-200 flex items-center">
                    <i class="fa-solid fa-triangle-exclamation mr-2 flex-shrink-0"></i> <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="bg-emerald-100 dark:bg-emerald-950/80 border-b border-emerald-300 dark:border-emerald-900 p-3 text-[11px] font-bold text-emerald-900 dark:text-emerald-200 flex items-center">
                    <i class="fa-solid fa-circle-check mr-2 flex-shrink-0"></i> <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <div class="pt-5 pb-3 px-5 text-center bg-gradient-to-b from-primary-50 to-white dark:from-slate-800/60 dark:to-slate-900 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-black text-slate-900 dark:text-white">Welcome Back</h2>
                <p class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-0.5">Select your access portal below</p>
            </div>

            <div class="flex border-b border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-950">
                <button id="admin-tab" onclick="switchTab('admin')" class="flex-1 py-2.5 text-xs font-bold text-primary-700 dark:text-primary-400 border-b-2 border-primary-700 dark:border-primary-400 bg-primary-100 dark:bg-slate-800 transition">
                    <i class="fa-solid fa-shield-halved mr-1"></i> Admin Portal
                </button>
                <button id="employee-tab" onclick="switchTab('employee')" class="flex-1 py-2.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-slate-950 dark:hover:text-white transition">
                    <i class="fa-solid fa-user mr-1"></i> Employee Portal
                </button>
            </div>

            <div class="p-5 bg-white dark:bg-slate-900">
                <!-- ADMIN LOGIN FORM -->
                <form id="admin-form" action="login.php" method="POST" class="space-y-3.5">
                    <input type="hidden" name="action" value="admin_login">
                    <input type="hidden" id="admin_login_method" name="admin_login_method" value="password">
                    <input type="hidden" id="admin_fingerprint_data_hidden" name="admin_fingerprint_data" value="">
                    
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-wider text-slate-900 dark:text-slate-300 mb-1.5">Admin Email / Username</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 dark:text-slate-500 text-xs">
                                <i class="fa-solid fa-envelope"></i>
                            </span>
                            <input type="text" name="email" required placeholder="admin@gigpay.com" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:border-primary-700 dark:focus:border-primary-500 focus:bg-white dark:focus:bg-slate-800 transition">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-wider text-slate-900 dark:text-slate-300 mb-1.5">Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 dark:text-slate-500 text-xs">
                                <i class="fa-solid fa-lock"></i>
                            </span>
                            <input type="password" id="admin-password" name="password" required placeholder="••••••••" class="w-full pl-9 pr-10 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:border-primary-700 dark:focus:border-primary-500 focus:bg-white dark:focus:bg-slate-800 transition">
                            <button type="button" onclick="togglePassword('admin-password', 'admin-eye-icon')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white focus:outline-none text-xs">
                                <i id="admin-eye-icon" class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-[11px] font-bold pt-0.5">
                        <label class="flex items-center space-x-1.5 text-slate-600 dark:text-slate-400 cursor-pointer">
                            <input type="checkbox" name="remember_admin" class="rounded border-slate-300 dark:border-slate-700 text-primary-750 dark:bg-slate-800 focus:ring-primary-700 h-3.5 w-3.5">
                            <span>Remember me</span>
                        </label>
                        <button type="button" onclick="openAdminForgotModal()" class="text-primary-700 dark:text-primary-400 font-bold hover:underline">Forgot?</button>
                    </div>

                    <button type="submit" class="w-full bg-primary-700 hover:bg-primary-800 text-white font-bold py-2.5 rounded-lg shadow-md border border-primary-900 text-xs transition">
                        Login as Admin
                    </button>

                    <button type="button" onclick="triggerHardwareBiometricScan('admin')" class="w-full bg-primary-50 dark:bg-slate-800 hover:bg-primary-100 dark:hover:bg-slate-700 text-primary-700 dark:text-primary-400 font-bold py-2.5 rounded-lg shadow-sm border border-primary-200 dark:border-slate-700 text-xs transition flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-fingerprint text-sm"></i>
                        <span>Login Using Fingerprint</span>
                    </button>
                </form>

                <!-- EMPLOYEE LOGIN FORM -->
                <form id="employee-form" action="login.php" method="POST" class="space-y-3.5 hidden">
                    <input type="hidden" name="action" value="employee_login">
                    <input type="hidden" id="emp_login_method" name="emp_login_method" value="password">
                    <input type="hidden" id="emp_fingerprint_data_hidden" name="emp_fingerprint_data" value="">

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-wider text-slate-900 dark:text-slate-300 mb-1.5">Employee ID or Email</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 dark:text-slate-500 text-xs">
                                <i class="fa-solid fa-id-badge"></i>
                            </span>
                            <input type="text" name="emp_identifier" required placeholder="e.g. 1 or employee@gigpay.com" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:border-primary-700 dark:focus:border-primary-500 focus:bg-white dark:focus:bg-slate-800 transition">
                        </div>
                    </div>

                    <?php if (!empty($departments)): ?>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-wider text-slate-900 dark:text-slate-300 mb-1.5">Assigned Department</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none text-xs">
                                <i class="fa-solid fa-building-user"></i>
                            </span>
                            <select name="department" required class="w-full pl-9 pr-8 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:border-primary-700 dark:focus:border-primary-500 focus:bg-white dark:focus:bg-slate-800 transition appearance-none">
                                <option value="" disabled selected>Select department...</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 dark:text-slate-500 pointer-events-none text-[10px]">
                                <i class="fa-solid fa-chevron-down"></i>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-wider text-slate-900 dark:text-slate-300 mb-1.5">PIN Code or Password</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 dark:text-slate-500 text-xs">
                                <i class="fa-solid fa-key"></i>
                            </span>
                            <input type="password" id="employee-password" name="password" required placeholder="••••" class="w-full pl-9 pr-10 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:border-primary-700 dark:focus:border-primary-500 focus:bg-white dark:focus:bg-slate-800 transition">
                            <button type="button" onclick="togglePassword('employee-password', 'employee-eye-icon')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white focus:outline-none text-xs">
                                <i id="employee-eye-icon" class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-[11px] font-bold pt-0.5">
                        <label class="flex items-center space-x-1.5 text-slate-600 dark:text-slate-400 cursor-pointer">
                            <input type="checkbox" name="remember_employee" class="rounded border-slate-300 dark:border-slate-700 text-primary-700 dark:bg-slate-800 focus:ring-primary-700 h-3.5 w-3.5">
                            <span>Remember me</span>
                        </label>
                        <div class="flex items-center space-x-2">
                            <a href="register.php" class="text-primary-700 dark:text-primary-400 hover:underline flex items-center space-x-1">
                                <i class="fa-solid fa-fingerprint text-xs"></i>
                                <span>Register</span>
                            </a>
                            <span class="text-slate-300 dark:text-slate-700">|</span>
                            <button type="button" onclick="openEmployeeResetModal()" class="text-primary-700 dark:text-primary-400 font-bold hover:underline">Reset PIN</button>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-primary-700 hover:bg-primary-800 text-white font-bold py-2.5 rounded-lg shadow-md border border-primary-900 text-xs transition">
                        Login as Employee
                    </button>

                    <button type="button" onclick="triggerHardwareBiometricScan('employee')" class="w-full bg-primary-50 dark:bg-slate-800 hover:bg-primary-100 dark:hover:bg-slate-700 text-primary-700 dark:text-primary-400 font-bold py-2.5 rounded-lg shadow-sm border border-primary-200 dark:border-slate-700 text-xs transition flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-fingerprint text-sm"></i>
                        <span>Login Using Fingerprint</span>
                    </button>
                </form>
            </div>
            
            <div class="bg-slate-100 dark:bg-slate-950 px-5 py-3 border-t border-slate-200 dark:border-slate-800 text-center">
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400">Hardware biometrics secured.</p>
            </div>

        </div>
    </main>

    <!-- DEVICE HARDWARE BIOMETRIC MODAL (ADMIN) -->
    <div id="adminBiometricModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl max-w-xs w-full p-6 text-center shadow-2xl border border-slate-300 dark:border-slate-800 space-y-4">
            <div class="inline-flex p-4 bg-primary-50 dark:bg-slate-800 rounded-full border border-primary-200 dark:border-slate-700">
                <i id="admin-bio-modal-icon" class="fa-solid fa-fingerprint text-3xl text-primary-600 dark:text-primary-400"></i>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white">Phone Biometric Security</h3>
                <p id="admin-bio-modal-status" class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">Triggering your device hardware fingerprint scanner...</p>
            </div>
            <button type="button" onclick="closeBiometricModal('admin')" class="w-full py-2 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold text-[11px] rounded-lg">Cancel</button>
        </div>
    </div>

    <!-- DEVICE HARDWARE BIOMETRIC MODAL (EMPLOYEE) -->
    <div id="empBiometricModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl max-w-xs w-full p-6 text-center shadow-2xl border border-slate-300 dark:border-slate-800 space-y-4">
            <div class="inline-flex p-4 bg-primary-50 dark:bg-slate-800 rounded-full border border-primary-200 dark:border-slate-700">
                <i id="employee-bio-modal-icon" class="fa-solid fa-fingerprint text-3xl text-primary-600 dark:text-primary-400"></i>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white">Phone Biometric Security</h3>
                <p id="employee-bio-modal-status" class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">Triggering your device hardware fingerprint scanner...</p>
            </div>
            <button type="button" onclick="closeBiometricModal('employee')" class="w-full py-2 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold text-[11px] rounded-lg">Cancel</button>
        </div>
    </div>

    <!-- DEVICE HARDWARE BIOMETRIC MODAL (RECOVERY) -->
    <div id="recoveryBiometricModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl max-w-xs w-full p-6 text-center shadow-2xl border border-slate-300 dark:border-slate-800 space-y-4">
            <div class="inline-flex p-4 bg-primary-50 dark:bg-slate-800 rounded-full border border-primary-200 dark:border-slate-700">
                <i id="recovery-bio-modal-icon" class="fa-solid fa-fingerprint text-3xl text-primary-600 dark:text-primary-400"></i>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-900 dark:text-white">Hardware Recovery Scan</h3>
                <p id="recovery-bio-modal-status" class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 mt-1">Triggering device biometric sensor...</p>
            </div>
            <button type="button" onclick="closeBiometricModal('recovery')" class="w-full py-2 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold text-[11px] rounded-lg">Cancel</button>
        </div>
    </div>

    <!-- ADMIN FORGOT PASSWORD MODAL -->
    <div id="adminForgotModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 <?php echo $show_admin_modal_step2 ? '' : 'hidden'; ?> flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white dark:bg-slate-900 rounded-xl max-w-sm w-full p-5 shadow-2xl border border-slate-300 dark:border-slate-800 my-auto">
            <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-200 dark:border-slate-800">
                <h3 class="text-xs font-black text-slate-900 dark:text-white"><i class="fa-solid fa-shield-halved text-primary-700 dark:text-primary-400 mr-1.5"></i> Admin Password Recovery</h3>
                <button onclick="closeAdminForgotModal()" class="text-slate-400 hover:text-slate-900 dark:hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <?php if (!isset($_SESSION['recovery_identity'])): ?>
                <form action="login.php" method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="fetch_admin_question">
                    <p class="text-[11px] font-medium text-slate-600 dark:text-slate-400">Enter your admin email or username to initiate password recovery.</p>
                    <div>
                        <label class="block text-[10px] font-black text-slate-900 dark:text-slate-300 mb-1">ADMIN USERNAME / EMAIL</label>
                        <input type="text" name="admin_identity" required placeholder="admin@gigpay.com" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white">
                    </div>
                    <div class="flex space-x-2.5 pt-1">
                        <button type="button" onclick="closeAdminForgotModal()" class="flex-1 px-3 py-2 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-[11px] font-bold rounded-lg border border-slate-300 dark:border-slate-700">Cancel</button>
                        <button type="submit" class="flex-1 px-3 py-2 bg-primary-700 hover:bg-primary-800 text-white text-[11px] font-bold rounded-lg border border-primary-900">Next</button>
                    </div>
                </form>
            <?php else: ?>
                <form action="login.php" method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="verify_admin_recovery_answer">
                    <input type="hidden" id="recovery_method_input" name="recovery_method" value="pin">
                    
                    <div class="text-[11px] text-slate-700 dark:text-slate-300 font-semibold mb-1">
                        Verify using PIN or Hardware Fingerprint:
                    </div>
                    
                    <div class="flex space-x-2">
                        <div class="flex-1">
                            <label class="block text-[10px] font-black text-slate-900 dark:text-slate-300 mb-1">ADMIN PIN DIGITS</label>
                            <div class="relative">
                                <input type="password" id="admin-pin-digits" name="admin_pin" placeholder="PIN digits..." class="w-full pl-2.5 pr-8 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white">
                                <button type="button" onclick="togglePassword('admin-pin-digits', 'admin-pin-eye')" class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white focus:outline-none">
                                    <i id="admin-pin-eye" class="fa-solid fa-eye text-[10px]"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="triggerHardwareBiometricScan('recovery')" title="Use Hardware Fingerprint" class="p-2 bg-primary-50 dark:bg-slate-800 hover:bg-primary-100 dark:hover:bg-slate-700 text-primary-700 dark:text-primary-400 rounded-lg border border-slate-300 dark:border-slate-700 transition shadow-sm flex items-center justify-center h-[34px]">
                                <i class="fa-solid fa-fingerprint text-sm"></i>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="fingerprint_data_hidden" name="fingerprint_data" value="">
                    <p id="fp-scan-status" class="text-[10px] font-bold text-slate-500 dark:text-slate-400"></p>

                    <div>
                        <label class="block text-[10px] font-black text-slate-900 dark:text-slate-300 mb-1">NEW PASSWORD</label>
                        <div class="relative">
                            <input type="password" id="recovery-new-password" name="new_password" required placeholder="New password..." class="w-full pl-2.5 pr-8 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white">
                            <button type="button" onclick="togglePassword('recovery-new-password', 'recovery-new-eye')" class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-900 dark:hover:text-white focus:outline-none">
                                <i id="recovery-new-eye" class="fa-solid fa-eye text-[10px]"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex space-x-2.5 pt-1">
                        <button type="button" onclick="window.location.href='login.php';" class="flex-1 px-3 py-2 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-[11px] font-bold rounded-lg border border-slate-300 dark:border-slate-700">Cancel</button>
                        <button type="submit" class="flex-1 px-3 py-2 bg-emerald-700 hover:bg-emerald-800 text-white text-[11px] font-bold rounded-lg border border-emerald-900">Reset</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- EMPLOYEE RESET PIN MODAL -->
    <div id="employeeResetModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white dark:bg-slate-900 rounded-xl max-w-sm w-full p-5 shadow-2xl border border-slate-300 dark:border-slate-800 my-auto">
            <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-200 dark:border-slate-800">
                <h3 class="text-xs font-black text-slate-900 dark:text-white"><i class="fa-solid fa-key text-primary-700 dark:text-primary-400 mr-1.5"></i> Request PIN Reset</h3>
                <button onclick="closeEmployeeResetModal()" class="text-slate-400 hover:text-slate-900 dark:hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>
            <form action="login.php" method="POST" class="space-y-3">
                <input type="hidden" name="action" value="employee_request_reset">
                <p class="text-[11px] font-medium text-slate-600 dark:text-slate-400">Enter your Employee ID or email to push a reset request notification to the <b class="font-bold text-slate-900 dark:text-white">Admin Dashboard</b>.</p>
                <div>
                    <label class="block text-[10px] font-black text-slate-900 dark:text-slate-300 mb-1">EMPLOYEE ID OR EMAIL</label>
                    <input type="text" name="emp_identifier" required placeholder="e.g. 1 or employee@gigpay.com" class="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-xs font-bold text-slate-900 dark:text-white">
                </div>
                <div class="flex space-x-2.5 pt-1">
                    <button type="button" onclick="closeEmployeeResetModal()" class="flex-1 px-3 py-2 bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-[11px] font-bold rounded-lg border border-slate-300 dark:border-slate-700">Cancel</button>
                    <button type="submit" class="flex-1 px-3 py-2 bg-primary-700 hover:bg-primary-800 text-white text-[11px] font-bold rounded-lg border border-primary-900">Send Notice</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="bg-white/95 dark:bg-slate-900/95 backdrop-blur-md border-t border-slate-200 dark:border-slate-800 py-4 text-center text-[10px] font-bold text-slate-500 dark:text-slate-400 px-4 relative z-10">
        &copy; 2026 GigPay Inc. Secure Biometric Payroll System. All rights reserved.
    </footer>

</body>
</html>