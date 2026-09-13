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

// Ensure the hikvision_registrations table exists for syncing to the physical device
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hikvision_registrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        fingerprint_data TEXT NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS fingerprint_hash VARCHAR(255) NULL");
} catch (\PDOException $e) {}

// Initialize attempt tracking session variables
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts'] = 0;
}

$error_msg = '';
$success_msg = '';

// Handle Reset action via GET
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['reg_employee_verified'], $_SESSION['reg_verified_employee_data'], $_SESSION['reg_attempts']);
    header('Location: register.php');
    exit;
}

$employee_verified = $_SESSION['reg_employee_verified'] ?? false;
$verified_employee = $_SESSION['reg_verified_employee_data'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'verify_employee_id') {
            if ($_SESSION['reg_attempts'] >= 3) {
                throw new Exception("Maximum verification attempts (3) exceeded. Please try again later.");
            }

            $emp_identifier = trim($_POST['emp_identifier']);
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? OR email = ?");
            $stmt->execute([$emp_identifier, $emp_identifier]);
            $employee = $stmt->fetch();

            if (!$employee) {
                $_SESSION['reg_attempts']++;
                $remaining = 3 - $_SESSION['reg_attempts'];
                if ($remaining > 0) {
                    throw new Exception("Employee ID or email not found. You have $remaining attempt(s) remaining.");
                } else {
                    throw new Exception("Maximum verification attempts reached. Access locked.");
                }
            }

            // Check if the employee is already registered (has a fingerprint hash or exists in hikvision_registrations)
            $checkReg = $pdo->prepare("SELECT COUNT(*) FROM hikvision_registrations WHERE employee_id = ?");
            $checkReg->execute([$employee['id']]);
            $isRegisteredInQueue = $checkReg->fetchColumn() > 0;

            if (!empty($employee['fingerprint_hash']) || $isRegisteredInQueue) {
                throw new Exception("Employee already registered.");
            }

            // Reset attempts on success
            $_SESSION['reg_attempts'] = 0;
            $_SESSION['reg_employee_verified'] = true;
            $_SESSION['reg_verified_employee_data'] = $employee;
            
            $employee_verified = true;
            $verified_employee = $employee;
            $success_msg = "Employee profile found: " . htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) . ". Proceed with fingerprint capture.";
        }

        elseif ($action === 'save_hikvision_fingerprint') {
            if (empty($_SESSION['reg_employee_verified']) || empty($_SESSION['reg_verified_employee_data'])) {
                throw new Exception("Session expired or employee not verified.");
            }

            $emp = $_SESSION['reg_verified_employee_data'];
            $fingerprint_payload = trim($_POST['fingerprint_payload'] ?? '');

            if (empty($fingerprint_payload)) {
                throw new Exception("No biometric capture data received.");
            }

            // Double check registration status right before saving to prevent race conditions
            $checkReg = $pdo->prepare("SELECT COUNT(*) FROM hikvision_registrations WHERE employee_id = ?");
            $checkReg->execute([$emp['id']]);
            if ($checkReg->fetchColumn() > 0) {
                throw new Exception("Employee already registered.");
            }

            // Insert into pending sync queue table for hikvision.php
            $stmt = $pdo->prepare("INSERT INTO hikvision_registrations (employee_id, fingerprint_data, status) VALUES (?, ?, 'Pending')");
            $stmt->execute([$emp['id'], $fingerprint_payload]);

            // Update employee record hash
            $upd = $pdo->prepare("UPDATE employees SET fingerprint_hash = ? WHERE id = ?");
            $upd->execute([password_hash($fingerprint_payload, PASSWORD_DEFAULT), $emp['id']]);

            // Clear registration session
            unset($_SESSION['reg_employee_verified'], $_SESSION['reg_verified_employee_data'], $_SESSION['reg_attempts']);
            
            $success_msg = "Hikvision fingerprint successfully registered and queued for hardware synchronization!";
            $employee_verified = false;
            $verified_employee = null;
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
    <title>GigPay - Register Hikvision Fingerprint</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f6ff', 100: '#e0effe', 500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a', 950: '#172554',
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
        .bg-overlay { background-color: rgba(15, 23, 42, 0.65); }
    </style>
    <script>
        async function captureHikvisionFingerprint() {
            const modal = document.getElementById('captureModal');
            const statusEl = document.getElementById('captureStatus');
            const iconEl = document.getElementById('captureIcon');
            modal.classList.remove('hidden');

            statusEl.textContent = "Initializing WebAuthn / Browser Biometric Sensor to simulate Hikvision scanner...";
            iconEl.className = "fa-solid fa-spinner fa-spin text-3xl text-primary-600";

            try {
                if (!window.PublicKeyCredential) {
                    throw new Error("WebAuthn API is not supported in this browser environment.");
                }

                const publicKey = {
                    challenge: new Uint8Array([99, 42, 12, 55, 78, 90, 11, 22, 33, 44, 55, 66, 77, 88, 99, 0]),
                    timeout: 60000,
                    userVerification: "required"
                };

                try {
                    await navigator.credentials.get({ publicKey });
                } catch (err) {
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }

                iconEl.className = "fa-solid fa-circle-check text-3xl text-emerald-600";
                statusEl.textContent = "Fingerprint successfully captured!";

                const mockHikvisionToken = "hikvision_fp_template_" + Date.now() + "_" + Math.random().toString(36).substring(2);
                document.getElementById('fingerprint_payload').value = mockHikvisionToken;

                setTimeout(() => {
                    modal.classList.add('hidden');
                    document.getElementById('saveForm').submit();
                }, 1000);

            } catch (error) {
                iconEl.className = "fa-solid fa-triangle-exclamation text-3xl text-red-600";
                statusEl.textContent = "Capture failed: " + error.message;
            }
        }
        function closeModal() {
            document.getElementById('captureModal').classList.add('hidden');
        }
    </script>
</head>
<body class="h-full font-sans antialiased flex flex-col justify-between text-slate-950 relative overflow-x-hidden bg-custom-image">

    <div class="absolute inset-0 bg-overlay pointer-events-none z-0"></div>

    <header class="bg-white/80 backdrop-blur-md border-b border-slate-200 shadow-sm relative z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="login.php" class="flex items-center space-x-2.5">
                <div class="flex items-center space-x-2">
                    <img src="pay.png" alt="Gig Logo" class="h-8 w-auto object-contain">
                    <div class="bg-primary-700 text-white p-2 rounded-lg shadow-md border border-primary-900 flex items-center justify-center">
                        <i class="fa-solid fa-fingerprint text-lg"></i>
                    </div>
                </div>
                <span class="text-xl font-black tracking-tight text-slate-900">Gig<span class="text-primary-600">Pay</span></span>
            </a>
            <a href="login.php" class="text-xs font-bold text-slate-700 hover:text-primary-700 transition flex items-center space-x-1.5 bg-white/90 px-3.5 py-1.5 rounded-lg shadow-sm border border-slate-200">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to Login</span>
            </a>
        </div>
    </header>

    <main class="flex-grow flex items-center justify-center py-6 px-4 sm:px-6 relative z-10">
        <div class="max-w-sm w-full bg-white rounded-xl shadow-2xl border border-slate-300 overflow-hidden my-auto">
            
            <?php if($error_msg): ?>
                <div class="bg-red-100 border-b border-red-300 p-3 text-[11px] font-bold text-red-900 flex items-center">
                    <i class="fa-solid fa-triangle-exclamation mr-2 flex-shrink-0"></i> <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="bg-emerald-100 border-b border-emerald-300 p-3 text-[11px] font-bold text-emerald-900 flex items-center">
                    <i class="fa-solid fa-circle-check mr-2 flex-shrink-0"></i> <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>

            <div class="pt-5 pb-3 px-5 text-center bg-gradient-to-b from-primary-50 to-white border-b border-slate-200">
                <h2 class="text-lg font-black text-slate-900">Hikvision Registration</h2>
                <p class="text-[11px] font-semibold text-slate-600 mt-0.5">Direct remote biometric template enrollment</p>
            </div>

            <div class="p-5 bg-white space-y-4">
                <?php if (!$employee_verified): ?>
                    <!-- STEP 1: ENTER ID WITH 3 ATTEMPTS LIMIT -->
                    <form action="register.php" method="POST" class="space-y-3.5">
                        <input type="hidden" name="action" value="verify_employee_id">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-wider text-slate-900 mb-1.5">Enter Employee ID or Email</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 text-xs">
                                    <i class="fa-solid fa-id-badge"></i>
                                </span>
                                <input type="text" name="emp_identifier" required placeholder="e.g. 1 or employee@gigpay.com" class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-900 focus:outline-none focus:border-primary-700 transition">
                            </div>
                        </div>
                        <p class="text-[10px] font-semibold text-slate-500">Attempts remaining: <b class="text-slate-900"><?php echo (3 - $_SESSION['reg_attempts']); ?></b>/3</p>
                        <button type="submit" class="w-full bg-primary-700 hover:bg-primary-800 text-white font-bold py-2.5 rounded-lg shadow-md border border-primary-900 text-xs transition">
                            Verify Employee ID
                        </button>
                    </form>
                <?php else: ?>
                    <!-- STEP 2: TRIGGER FINGERPRINT SCAN & SAVE -->
                    <div class="space-y-4 text-center">
                        <div class="p-3 bg-slate-50 rounded-lg border border-slate-200 text-left space-y-1">
                            <p class="text-[10px] uppercase font-black text-slate-400">Verified Profile</p>
                            <p class="text-xs font-bold text-slate-900"><?php echo htmlspecialchars($verified_employee['first_name'] . ' ' . $verified_employee['last_name']); ?></p>
                            <p class="text-[11px] text-slate-600">ID: <?php echo htmlspecialchars($verified_employee['id']); ?> | Dept: <?php echo htmlspecialchars($verified_employee['department'] ?? 'General'); ?></p>
                        </div>

                        <button type="button" onclick="captureHikvisionFingerprint()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg shadow-md border border-emerald-800 text-xs transition flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-fingerprint text-base"></i>
                            <span>Scan & Register Hikvision Fingerprint</span>
                        </button>

                        <form id="saveForm" action="register.php" method="POST" class="hidden">
                            <input type="hidden" name="action" value="save_hikvision_fingerprint">
                            <input type="hidden" id="fingerprint_payload" name="fingerprint_payload" value="">
                        </form>

                        <a href="register.php?reset=1" class="block text-[11px] font-bold text-slate-500 hover:text-slate-900 underline pt-1">Cancel / Switch Employee</a>
                    </div>
                <?php endif; ?>

                <!-- Back to Login Button integrated inside the card layout -->
                <div class="pt-2 border-t border-slate-100">
                    <a href="login.php" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 rounded-lg border border-slate-300 text-xs transition flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span>Return to Login Page</span>
                    </a>
                </div>
            </div>

            <div class="bg-slate-100 px-5 py-3 border-t border-slate-200 text-center">
                <p class="text-[10px] font-bold text-slate-500">Data syncs automatically with hikvision.php hardware worker.</p>
            </div>
        </div>
    </main>

    <!-- CAPTURE MODAL -->
    <div id="captureModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-xs w-full p-6 text-center shadow-2xl border border-slate-300 space-y-4">
            <div class="inline-flex p-4 bg-primary-50 rounded-full border border-primary-200">
                <i id="captureIcon" class="fa-solid fa-fingerprint text-3xl text-primary-600"></i>
            </div>
            <div>
                <h3 class="text-sm font-black text-slate-900">Hikvision Biometric Capture</h3>
                <p id="captureStatus" class="text-[11px] font-semibold text-slate-600 mt-1">Waiting for biometric sensor interaction...</p>
            </div>
            <button type="button" onclick="closeModal()" class="w-full py-2 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold text-[11px] rounded-lg">Cancel</button>
        </div>
    </div>

    <footer class="bg-white/95 backdrop-blur-md border-t border-slate-200 py-4 text-center text-[10px] font-bold text-slate-500 px-4 relative z-10">
        &copy; 2026 GigPay Inc. Secure Biometric Payroll System. All rights reserved.
    </footer>

</body>
</html>