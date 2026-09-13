<?php
session_start();

// Authentication Guard: Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: landing.php");
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
    
    // Ensure job_positions table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        position_name VARCHAR(255) NOT NULL,
        daily_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (\PDOException $e) {
    $db_error = $e->getMessage();
}

$message = '';
$error = '';

// Handle Form Submissions (Departments, Job Positions, Employees, Delete Records)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo)) {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_department') {
            try {
                $stmt = $pdo->prepare("INSERT INTO departments (department_name) VALUES (?)");
                $stmt->execute([$_POST['department_name']]);
                $message = "New department successfully added!";
            } catch (\Exception $e) {
                $error = "Error adding department (Name might already exist): " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'edit_department') {
            try {
                $stmt = $pdo->prepare("UPDATE departments SET department_name = ? WHERE id = ?");
                $stmt->execute([$_POST['department_name'], $_POST['department_id']]);
                $message = "Department name updated successfully!";
            } catch (\Exception $e) {
                $error = "Error updating department: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'add_job_position') {
            try {
                $stmt = $pdo->prepare("INSERT INTO job_positions (department_id, position_name, daily_salary) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['department_id'], $_POST['position_name'], $_POST['daily_salary']]);
                $message = "Job position and salary successfully added to department!";
            } catch (\Exception $e) {
                $error = "Error adding job position: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'delete_job_position') {
            try {
                $stmt = $pdo->prepare("DELETE FROM job_positions WHERE id = ?");
                $stmt->execute([$_POST['position_id']]);
                $message = "Job position successfully removed!";
            } catch (\Exception $e) {
                $error = "Error deleting job position: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'delete_department') {
            try {
                $dept_id = $_POST['department_id'];
                
                $pdo->beginTransaction();

                $stmtFetch = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
                $stmtFetch->execute([$dept_id]);
                $deptData = $stmtFetch->fetch();

                if ($deptData) {
                    $stmtBackup = $pdo->prepare("INSERT INTO deleted_records_backup (
                        original_table, record_id, record_data, deleted_at
                    ) VALUES (?, ?, ?, NOW())");
                    
                    $stmtBackup->execute([
                        'departments', 
                        $deptData['id'], 
                        json_encode($deptData)
                    ]);

                    $stmtDelete = $pdo->prepare("DELETE FROM departments WHERE id = ?");
                    $stmtDelete->execute([$dept_id]);

                    $pdo->commit();
                    $message = "Department successfully removed and archived!";
                } else {
                    $pdo->rollBack();
                    $error = "Department record not found.";
                }
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error deleting department: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'add_employee') {
            try {
                $status = isset($_POST['status']) ? $_POST['status'] : 'Active';
                $employment_status = isset($_POST['employment_status']) ? $_POST['employment_status'] : 'Regular';
                $contract_months = ($employment_status === 'Probationary/Contractual' && !empty($_POST['contract_months'])) ? $_POST['contract_months'] : null;

                $stmt = $pdo->prepare("INSERT INTO employees (
                    first_name, middle_name, last_name, email, phone, department, position, 
                    street_address, barangay, city, province, postal_code, dob, gender, 
                    sss_no, philhealth_no, pagibig_no, tin_no, daily_salary, 
                    emergency_name, emergency_relation, emergency_phone, status, employment_status, contract_months
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $_POST['first_name'], $_POST['middle_name'], $_POST['last_name'], 
                    $_POST['email'], $_POST['phone'], $_POST['department'], $_POST['position'], 
                    $_POST['street_address'], $_POST['barangay'], $_POST['city'], $_POST['province'], $_POST['postal_code'], 
                    $_POST['dob'], $_POST['gender'], $_POST['sss_no'], $_POST['philhealth_no'], 
                    $_POST['pagibig_no'], $_POST['tin_no'], $_POST['daily_salary'], 
                    $_POST['emergency_name'], $_POST['emergency_relation'], $_POST['emergency_phone'], $status, $employment_status, $contract_months
                ]);

                if ($status === 'Inactive' || $status === 'Suspended') {
                    $empId = $pdo->lastInsertId();
                    $stmtFetch = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                    $stmtFetch->execute([$empId]);
                    $empData = $stmtFetch->fetch();
                    if ($empData) {
                        $stmtBackup = $pdo->prepare("INSERT INTO deleted_records_backup (original_table, record_id, record_data, deleted_at) VALUES ('employees', ?, ?, NOW())");
                        $stmtBackup->execute([$empData['id'], json_encode($empData)]);
                        $stmtDel = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                        $stmtDel->execute([$empId]);
                    }
                }

                $message = "Employee payroll record and statutory details successfully registered!";
            } catch (\Exception $e) {
                $error = "Error adding employee: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'edit_employee') {
            try {
                $employee_id = $_POST['employee_id'];
                $new_status = $_POST['status'];
                $employment_status = isset($_POST['employment_status']) ? $_POST['employment_status'] : 'Regular';
                $contract_months = ($employment_status === 'Probationary/Contractual' && !empty($_POST['contract_months'])) ? $_POST['contract_months'] : null;

                $stmt = $pdo->prepare("UPDATE employees SET 
                    first_name=?, middle_name=?, last_name=?, email=?, phone=?, department=?, position=?, 
                    street_address=?, barangay=?, city=?, province=?, postal_code=?, dob=?, gender=?, 
                    sss_no=?, philhealth_no=?, pagibig_no=?, tin_no=?, daily_salary=?, 
                    emergency_name=?, emergency_relation=?, emergency_phone=?, status=?, employment_status=?, contract_months=? WHERE id=?");
                
                $stmt->execute([
                    $_POST['first_name'], $_POST['middle_name'], $_POST['last_name'], 
                    $_POST['email'], $_POST['phone'], $_POST['department'], $_POST['position'], 
                    $_POST['street_address'], $_POST['barangay'], $_POST['city'], $_POST['province'], $_POST['postal_code'], 
                    $_POST['dob'], $_POST['gender'], $_POST['sss_no'], $_POST['philhealth_no'], 
                    $_POST['pagibig_no'], $_POST['tin_no'], $_POST['daily_salary'], 
                    $_POST['emergency_name'], $_POST['emergency_relation'], $_POST['emergency_phone'], 
                    $new_status, $employment_status, $contract_months, $employee_id
                ]);

                if ($new_status === 'Inactive' || $new_status === 'Suspended') {
                    $pdo->beginTransaction();
                    $stmtFetch = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                    $stmtFetch->execute([$employee_id]);
                    $empData = $stmtFetch->fetch();

                    if ($empData) {
                        $stmtBackup = $pdo->prepare("INSERT INTO deleted_records_backup (original_table, record_id, record_data, deleted_at) VALUES ('employees', ?, ?, NOW())");
                        $stmtBackup->execute([$empData['id'], json_encode($empData)]);

                        $stmtDelete = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                        $stmtDelete->execute([$employee_id]);
                        $pdo->commit();
                        $message = "Employee updated and moved to Settings trash!";
                    } else {
                        $pdo->rollBack();
                        $message = "Employee payroll and address records updated successfully!";
                    }
                } else {
                    $message = "Employee payroll and address records updated successfully!";
                }
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error updating employee: " . $e->getMessage();
            }
        }
    }
}

// Fetch Departments and Job Positions for Dynamic Dropdowns & Management
$departments = [];
$departmentPositions = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT * FROM departments ORDER BY department_name ASC");
        $departments = $stmt->fetchAll();

        $posStmt = $pdo->query("SELECT * FROM job_positions ORDER BY position_name ASC");
        $allPositions = $posStmt->fetchAll();

        foreach ($allPositions as $pos) {
            $departmentPositions[$pos['department_id']][] = [
                'id' => $pos['id'],
                'position_name' => $pos['position_name'],
                'daily_salary' => $pos['daily_salary']
            ];
        }
    } catch (\Exception $e) {
        // Fallback
    }
}

// Fetch employees with filtering & search support
$employees = [];
if (isset($pdo)) {
    try {
        $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%';
        $dept = isset($_GET['department']) ? $_GET['department'] : '%';
        
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE status = 'Active' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) AND department LIKE ? ORDER BY id DESC");
        $stmt->execute([$search, $search, $search, $dept]);
        $employees = $stmt->fetchAll();
    } catch (\Exception $e) {
        $error = "Database query error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Employees Management</title>
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
    <script>
        // Department and Job Positions mapping for dynamic fields and salary autofill
        const departmentDataMap = {
            <?php foreach ($departments as $d): ?>
            "<?php echo $d['id']; ?>": {
                name: "<?php echo htmlspecialchars($d['department_name'], ENT_QUOTES); ?>",
                positions: [
                    <?php if (isset($departmentPositions[$d['id']])): ?>
                        <?php foreach ($departmentPositions[$d['id']] as $p): ?>
                        { id: "<?php echo $p['id']; ?>", name: "<?php echo htmlspecialchars($p['position_name'], ENT_QUOTES); ?>", salary: "<?php echo $p['daily_salary']; ?>" },
                        <?php endforeach; ?>
                    <?php endif; ?>
                ]
            },
            <?php endforeach; ?>
        };

        function updatePositionsAndSalary(deptSelectId, posSelectId, salaryInputId) {
            const deptSelect = document.getElementById(deptSelectId);
            const posSelect = document.getElementById(posSelectId);
            const salaryInput = document.getElementById(salaryInputId);
            
            const selectedDeptId = deptSelect.value;
            posSelect.innerHTML = '<option value="">-- Select Job Position --</option>';
            salaryInput.value = '';

            if (selectedDeptId && departmentDataMap[selectedDeptId]) {
                const positions = departmentDataMap[selectedDeptId].positions;
                positions.forEach(pos => {
                    const option = document.createElement('option');
                    option.value = pos.name;
                    option.textContent = pos.name + ' (₱' + Number(pos.salary).toLocaleString('en-US', {minimumFractionDigits: 2}) + '/day)';
                    option.setAttribute('data-salary', pos.salary);
                    posSelect.appendChild(option);
                });
            }
        }

        function autofillSalaryFromPosition(posSelectId, salaryInputId) {
            const posSelect = document.getElementById(posSelectId);
            const salaryInput = document.getElementById(salaryInputId);
            const selectedOption = posSelect.options[posSelect.selectedIndex];
            
            if (selectedOption && selectedOption.hasAttribute('data-salary')) {
                salaryInput.value = selectedOption.getAttribute('data-salary');
            } else {
                salaryInput.value = '';
            }
        }

        function toggleContractField(selectId, containerId) {
            const selectElement = document.getElementById(selectId);
            const containerElement = document.getElementById(containerId);
            if (selectElement.value === 'Probationary/Contractual') {
                containerElement.classList.remove('hidden');
            } else {
                containerElement.classList.add('hidden');
            }
        }
    </script>
</head>
<body class="h-full font-sans antialiased flex text-slate-900 font-semibold overflow-hidden">

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
                <a href="employee.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
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

    <!-- Mobile Slide-out Drawer Navigation -->
    <div id="mobileDrawer" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm hidden md:hidden flex">
        <div class="w-64 bg-[#0c132b] text-slate-100 flex flex-col justify-between h-full select-none border-r-2 border-slate-900 shadow-2xl relative" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.82)), url('w.jpg'); background-size: cover; background-position: center;">
            <div class="h-full flex flex-col justify-between">
                <div>
                    <div class="h-20 flex items-center justify-between px-6 border-b-2 border-slate-800">
                        <div class="flex items-center space-x-3">
                            <div class="flex items-center justify-center">
                                <img src="pay.png" alt="Pay Icon" class="w-9 h-8 object-contain">
                            </div>
                            <span class="text-xl font-black tracking-tight text-white">Gig<span class="text-blue-400">Pay</span></span>
                        </div>
                        <button onclick="closeMobileMenu()" class="text-slate-400 hover:text-white text-lg font-black"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <nav class="p-4 space-y-1.5 text-sm font-bold overflow-y-auto max-h-[calc(100vh-160px)]">
                        <a href="admin_dashboard.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                            <i class="fa-solid fa-chart-pie w-5 text-slate-300"></i><span>Dashboard</span>
                        </a>
                        <a href="manage_requests.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                            <i class="fa-solid fa-clock-rotate-left w-5 text-slate-300"></i><span>Manage Requests</span>
                        </a>
                        <a href="attendance.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                            <i class="fa-solid fa-calendar-days w-5 text-slate-300"></i><span>Attendance</span>
                        </a>
                        <a href="employee.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
                            <i class="fa-solid fa-users w-5 text-white"></i><span>Employees</span>
                        </a>
                        <a href="biometric.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                            <i class="fa-solid fa-fingerprint w-5 text-slate-300"></i><span>F01H Biometrics</span>
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
        <div class="flex-1" onclick="closeMobileMenu()"></div>
    </div>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden h-full">
        
        <!-- Top Header Bar -->
        <header class="h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-4 sm:px-8 shrink-0">
            <div class="flex items-center space-x-3 sm:space-x-6 flex-wrap">
                <button onclick="openMobileMenu()" class="md:hidden text-slate-800 hover:text-blue-700 p-2 text-xl focus:outline-none">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h1 class="text-base sm:text-xl font-black text-slate-950">Employees Management</h1>
                
                <div class="hidden lg:flex items-center gap-2">
                    <button onclick="openDepartmentsHubModal()" class="px-3 py-2 bg-amber-700 hover:bg-amber-800 text-white rounded-xl text-xs font-black shadow-sm border border-amber-900 transition flex items-center space-x-1.5">
                        <i class="fa-solid fa-sitemap"></i>
                        <span>Departments & Positions</span>
                    </button>
                    <button onclick="openAddEmployeeModal()" class="px-3 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-xl text-xs font-black shadow-sm border border-blue-900 transition flex items-center space-x-1.5">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Add Employee</span>
                    </button>
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-3 pl-2 sm:pl-4 border-l-2 border-slate-300">
                    <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-blue-200 text-blue-900 flex items-center justify-center font-black text-xs sm:text-sm border-2 border-blue-700">
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
        <main class="flex-1 overflow-y-auto p-4 sm:p-8 space-y-6">
            
            <?php if (!empty($message)): ?>
                <div class="bg-emerald-100 border-2 border-emerald-400 text-emerald-950 p-4 rounded-xl text-sm font-black flex items-center justify-between shadow-sm">
                    <span><i class="fa-solid fa-circle-check mr-2 text-emerald-700"></i> <?php echo $message; ?></span>
                    <button onclick="this.parentElement.remove()" class="text-emerald-800 font-black text-lg">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border-2 border-red-400 text-red-950 p-4 rounded-xl text-sm font-black flex items-center justify-between shadow-sm">
                    <span><i class="fa-solid fa-triangle-exclamation mr-2 text-red-700"></i> <?php echo $error; ?></span>
                    <button onclick="this.parentElement.remove()" class="text-red-800 font-black text-lg">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Responsive Buttons View for Smaller Screens -->
            <div class="flex lg:hidden items-center gap-2 flex-wrap">
                <button onclick="openDepartmentsHubModal()" class="px-3.5 py-2.5 bg-amber-700 hover:bg-amber-800 text-white rounded-xl text-xs font-black shadow-md border border-amber-900 transition flex items-center space-x-1.5">
                    <i class="fa-solid fa-sitemap"></i>
                    <span>Departments & Add</span>
                </button>
                <button onclick="openAddEmployeeModal()" class="px-3.5 py-2.5 bg-blue-700 hover:bg-blue-800 text-white rounded-xl text-xs font-black shadow-md border border-blue-900 transition flex items-center space-x-1.5">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Add Employee</span>
                </button>
            </div>

            <!-- Search and Filter Bar -->
            <form method="GET" action="employee.php" class="bg-white p-4 rounded-2xl border-2 border-slate-300 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="w-full sm:w-96 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-600">
                        <i class="fa-solid fa-magnifying-glass text-sm"></i>
                    </span>
                    <input type="text" name="search" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" placeholder="Search employee name or email..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border-2 border-slate-300 rounded-xl text-sm focus:outline-none focus:border-blue-700 text-slate-900 font-bold placeholder:text-slate-500 placeholder:font-normal">
                </div>
                <div class="w-full sm:w-auto">
                    <select name="department" onchange="this.form.submit()" class="w-full sm:w-auto bg-slate-50 border-2 border-slate-300 rounded-xl px-4 py-2 text-sm text-slate-900 font-bold focus:outline-none focus:border-blue-700">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['department_name']); ?>" <?php echo (isset($_GET['department']) && $_GET['department'] === $d['department_name']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <!-- Employees Table -->
            <div class="bg-white rounded-2xl border-2 border-slate-300 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm min-w-[700px]">
                        <thead>
                            <tr class="bg-slate-200 text-slate-900 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                <th class="py-3.5 px-6">Employee Name</th>
                                <th class="py-3.5 px-6">Department / Position</th>
                                <th class="py-3.5 px-6">Employment Status & Countdown</th>
                                <th class="py-3.5 px-6">Daily Salary Rate</th>
                                <th class="py-3.5 px-6 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="5" class="py-12 text-center text-slate-700 text-xs font-black">
                                    No active employee records found.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $emp): ?>
                                <tr class="hover:bg-slate-100 transition cursor-pointer" onclick='openViewModal(<?php echo json_encode($emp); ?>)'>
                                    <td class="py-4 px-6 font-black text-slate-950">
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . ($emp['middle_name'] ? $emp['middle_name'] . ' ' : '') . $emp['last_name']); ?>
                                        <div class="text-xs font-bold text-slate-600">ID: #<?php echo $emp['id']; ?> | <?php echo htmlspecialchars($emp['city']); ?></div>
                                    </td>
                                    <td class="py-4 px-6">
                                        <div class="font-black text-slate-900"><?php echo htmlspecialchars($emp['department']); ?></div>
                                        <div class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($emp['position']); ?></div>
                                    </td>
                                    <td class="py-4 px-6 font-black text-slate-900">
                                        <?php 
                                            $emp_status = isset($emp['employment_status']) ? $emp['employment_status'] : 'Regular';
                                            echo htmlspecialchars($emp_status);
                                            if ($emp_status === 'Probationary/Contractual' && !empty($emp['contract_months'])) {
                                                echo '<div class="text-xs font-bold text-blue-700">Contract: ' . htmlspecialchars($emp['contract_months']) . ' Mos</div>';
                                                // Calculate contract end date & countdown based on registration or updated time (assuming created_at exists or defaulting placeholder countdown)
                                                $created_at = isset($emp['created_at']) ? $emp['created_at'] : date('Y-m-d');
                                                $expiry_date = date('Y-m-d', strtotime("+$emp[contract_months] months", strtotime($created_at)));
                                                echo '<div class="text-[11px] font-black text-amber-700 mt-0.5 contract-countdown" data-expiry="' . $expiry_date . '">Calculated Expiry: ' . $expiry_date . '</div>';
                                            }
                                        ?>
                                    </td>
                                    <td class="py-4 px-6 font-black text-slate-950">
                                        ₱<?php echo number_format($emp['daily_salary'], 2); ?> <span class="text-[10px] text-slate-700 font-bold">/day</span>
                                    </td>
                                    <td class="py-4 px-6 text-right space-x-1" onclick="event.stopPropagation()">
                                        <button onclick='openEditModal(<?php echo json_encode($emp); ?>)' class="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white border border-blue-800 rounded-lg text-xs font-black transition shadow-sm">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit Info
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

    <!-- View Employee Details Modal -->
    <div id="viewEmployeeModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-3 overflow-y-auto">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border-2 border-slate-400 my-6 max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between pb-3 border-b-2 border-slate-300 mb-4 shrink-0">
                <h3 class="text-base font-black text-slate-950 flex items-center space-x-2">
                    <i class="fa-solid fa-id-card text-blue-700"></i>
                    <span>Employee Details</span>
                </h3>
                <button onclick="closeViewModal()" class="text-slate-600 hover:text-slate-950 font-black"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <div class="overflow-y-auto flex-1 space-y-4 pr-1 text-sm font-bold text-slate-900">
                <div class="bg-slate-50 p-4 rounded-xl border-2 border-slate-200 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Full Name</span>
                        <span id="view_full_name" class="text-base font-black text-slate-950"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Employee ID</span>
                        <span id="view_id" class="font-mono"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Email Address</span>
                        <span id="view_email"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Phone Number</span>
                        <span id="view_phone"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Department</span>
                        <span id="view_department"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Position</span>
                        <span id="view_position"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Employment Status</span>
                        <span id="view_employment_status"></span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-500 uppercase block font-black">Daily Salary Rate</span>
                        <span id="view_daily_salary" class="text-emerald-700 font-black"></span>
                    </div>
                </div>

                <div class="bg-slate-50 p-4 rounded-xl border-2 border-slate-200 space-y-2">
                    <h4 class="text-xs font-black uppercase text-blue-800">Address & Personal Info</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                        <div><span class="text-slate-500">Address:</span> <span id="view_address"></span></div>
                        <div><span class="text-slate-500">Gender / DOB:</span> <span id="view_gender_dob"></span></div>
                    </div>
                </div>

                <div class="bg-slate-50 p-4 rounded-xl border-2 border-slate-200 space-y-2">
                    <h4 class="text-xs font-black uppercase text-blue-800">Statutory IDs & Emergency Contact</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                        <div><span class="text-slate-500">SSS:</span> <span id="view_sss"></span></div>
                        <div><span class="text-slate-500">PhilHealth:</span> <span id="view_philhealth"></span></div>
                        <div><span class="text-slate-500">Pag-IBIG:</span> <span id="view_pagibig"></span></div>
                        <div><span class="text-slate-500">TIN:</span> <span id="view_tin"></span></div>
                        <div class="sm:col-span-2 pt-2 border-t border-slate-200"><span class="text-slate-500">Emergency Contact:</span> <span id="view_emergency"></span></div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t-2 border-slate-300 mt-4 shrink-0 flex justify-between items-center">
                <button type="button" onclick="closeViewModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-950 border border-slate-400 text-xs font-black rounded-xl transition">Close</button>
                <button type="button" id="view_edit_btn" class="px-5 py-2 bg-blue-700 hover:bg-blue-800 text-white border border-blue-900 text-xs font-black rounded-xl transition shadow-sm flex items-center space-x-1.5">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Edit This Employee</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Combined Departments Hub Modal (Show Departments & Add Department in 1 Modal with Tabs/Sections) -->
    <div id="departmentsHubModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-3xl w-full p-6 shadow-2xl border-2 border-slate-400 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between pb-4 border-b-2 border-slate-300 mb-4 shrink-0">
                <h3 class="text-lg font-black text-slate-950 flex items-center space-x-2">
                    <i class="fa-solid fa-sitemap text-amber-700"></i>
                    <span>Departments Management Hub</span>
                </h3>
                <button onclick="closeDepartmentsHubModal()" class="text-slate-600 hover:text-slate-950 font-black"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            
            <div class="overflow-y-auto flex-1 space-y-6">
                <!-- Inline Add Department Form Section -->
                <div class="bg-emerald-50/70 p-4 rounded-xl border-2 border-emerald-200">
                    <h4 class="text-xs font-black text-emerald-950 uppercase mb-2 flex items-center space-x-1.5">
                        <i class="fa-solid fa-plus-circle text-emerald-700"></i>
                        <span>Add New Department</span>
                    </h4>
                    <form method="POST" action="employee.php" class="flex flex-col sm:flex-row gap-2">
                        <input type="hidden" name="action" value="add_department">
                        <input type="text" name="department_name" required placeholder="e.g. Quality Assurance" class="flex-1 px-3 py-2 bg-white border-2 border-emerald-300 rounded-xl text-sm font-bold text-slate-950 focus:outline-none focus:border-emerald-700 placeholder:text-slate-500 placeholder:font-normal">
                        <button type="submit" class="px-5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white border border-emerald-900 text-xs font-black rounded-xl transition shadow-sm">Save Department</button>
                    </form>
                </div>

                <!-- Departments Directory List Section -->
                <div>
                    <h4 class="text-xs font-black text-slate-900 uppercase mb-2 flex items-center justify-between">
                        <span>Department Directory & Job Positions List</span>
                    </h4>
                    <p class="text-xs font-bold text-slate-600 mb-3"><i class="fa-solid fa-circle-info mr-1 text-blue-600"></i> Click any department row below to edit its name or add/manage its job positions and salaries.</p>
                    <table class="w-full text-left border-collapse text-sm min-w-[500px]">
                        <thead>
                            <tr class="bg-slate-200 text-slate-900 uppercase text-[11px] font-black tracking-wider border-b-2 border-slate-300">
                                <th class="py-3 px-4">ID</th>
                                <th class="py-3 px-4">Department Name</th>
                                <th class="py-3 px-4">Job Positions Count</th>
                                <th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-200 text-slate-900">
                            <?php if (empty($departments)): ?>
                            <tr>
                                <td colspan="4" class="py-8 text-center text-slate-700 text-xs font-black">
                                    No departments registered yet.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($departments as $d): ?>
                                <tr class="hover:bg-slate-100 transition cursor-pointer" onclick='openEditDepartmentModal(<?php echo json_encode($d); ?>)'>
                                    <td class="py-3.5 px-4 font-mono text-xs font-bold text-slate-700">#<?php echo $d['id']; ?></td>
                                    <td class="py-3.5 px-4 font-black text-slate-950"><?php echo htmlspecialchars($d['department_name']); ?></td>
                                    <td class="py-3.5 px-4 font-bold text-slate-700">
                                        <?php 
                                            $count = isset($departmentPositions[$d['id']]) ? count($departmentPositions[$d['id']]) : 0;
                                            echo $count . ' position(s)';
                                        ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-right space-x-2" onclick="event.stopPropagation()">
                                        <button onclick='openEditDepartmentModal(<?php echo json_encode($d); ?>)' class="px-3 py-1.5 bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 rounded-lg text-xs font-black transition">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit & Positions
                                        </button>
                                        <button onclick='openDeleteDepartmentModal(<?php echo $d['id']; ?>, "<?php echo htmlspecialchars($d['department_name'], ENT_QUOTES); ?>")' class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-900 border border-red-300 rounded-lg text-xs font-black transition">
                                            <i class="fa-solid fa-trash-can mr-1"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="pt-4 border-t-2 border-slate-300 mt-4 shrink-0 flex justify-end">
                <button type="button" onclick="closeDepartmentsHubModal()" class="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 border border-slate-400 text-xs font-black rounded-xl transition">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Department & Manage Positions Modal -->
    <div id="editDepartmentModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[60] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border-2 border-slate-400 max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between pb-4 border-b-2 border-slate-300 mb-4 shrink-0">
                <h3 class="text-lg font-black text-slate-950 flex items-center space-x-2">
                    <i class="fa-solid fa-pen-to-square text-teal-700"></i>
                    <span>Edit Department & Positions</span>
                </h3>
                <button onclick="closeEditDepartmentModal()" class="text-slate-600 hover:text-slate-950 font-black"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            
            <div class="overflow-y-auto flex-1 space-y-4 pr-1">
                <!-- Edit Department Name Form -->
                <form method="POST" action="employee.php" class="space-y-3 bg-slate-50 p-4 rounded-xl border-2 border-slate-200">
                    <input type="hidden" name="action" value="edit_department">
                    <input type="hidden" name="department_id" id="edit_dept_id">
                    <div>
                        <label class="block text-xs font-black text-slate-900 uppercase mb-1">Department Name *</label>
                        <div class="flex gap-2">
                            <input type="text" name="department_name" id="edit_dept_name" required class="flex-1 px-3 py-2 bg-white border-2 border-slate-300 rounded-xl text-sm font-bold text-slate-950 focus:outline-none focus:border-teal-700">
                            <button type="submit" class="px-4 py-2 bg-teal-700 hover:bg-teal-800 text-white border border-teal-900 text-xs font-black rounded-xl transition shadow-sm">Update Name</button>
                        </div>
                    </div>
                </form>

                <!-- Job Positions Section -->
                <div>
                    <h4 class="text-xs font-black text-slate-900 uppercase mb-2 flex items-center justify-between">
                        <span>Job Positions & Salary Rates</span>
                    </h4>
                    
                    <!-- Add Position Form -->
                    <form method="POST" action="employee.php" class="bg-blue-50/70 p-3 rounded-xl border-2 border-blue-200 mb-3 space-y-3">
                        <input type="hidden" name="action" value="add_job_position">
                        <input type="hidden" name="department_id" id="add_pos_dept_id">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-black text-blue-900 uppercase mb-1">Position Name *</label>
                                <input type="text" name="position_name" required placeholder="e.g. Senior Developer" class="w-full px-2.5 py-1.5 bg-white border-2 border-blue-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            </div>
                            <div>
                                <label class="block text-[11px] font-black text-emerald-900 uppercase mb-1">Daily Salary (₱) *</label>
                                <input type="number" step="0.01" name="daily_salary" required placeholder="0.00" class="w-full px-2.5 py-1.5 bg-white border-2 border-emerald-300 rounded-lg text-xs font-black text-emerald-950 focus:outline-none focus:border-emerald-700">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-3.5 py-1.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-black rounded-lg transition shadow-sm">
                                <i class="fa-solid fa-plus mr-1"></i> Add Position
                            </button>
                        </div>
                    </form>

                    <!-- Positions List Table -->
                    <div class="bg-white rounded-xl border-2 border-slate-200 overflow-hidden">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-100 text-slate-900 uppercase text-[10px] font-black border-b-2 border-slate-200">
                                    <th class="py-2.5 px-3">Position Name</th>
                                    <th class="py-2.5 px-3">Daily Salary Rate</th>
                                    <th class="py-2.5 px-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="department_positions_tbody" class="divide-y divide-slate-200 font-bold text-slate-900">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t-2 border-slate-300 mt-4 shrink-0 flex justify-end">
                <button type="button" onclick="closeEditDepartmentModal()" class="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 border border-slate-400 text-xs font-black rounded-xl transition">Done</button>
            </div>
        </div>
    </div>

    <!-- Delete Department Confirmation Modal -->
    <div id="deleteDepartmentModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border-2 border-slate-400 text-center">
            <div class="w-12 h-12 bg-red-100 text-red-700 border-2 border-red-300 rounded-2xl flex items-center justify-center text-xl mx-auto mb-4 font-black">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 class="text-lg font-black text-slate-950 mb-1">Archive & Delete Department</h3>
            <p class="text-xs font-bold text-slate-700 mb-6">Are you sure you want to remove <span id="delete_dept_name_display" class="font-black text-slate-950"></span>? This will archive the department and its job positions.</p>
            
            <form method="POST" action="employee.php">
                <input type="hidden" name="action" value="delete_department">
                <input type="hidden" name="department_id" id="delete_dept_id_input">
                <div class="flex space-x-3">
                    <button type="button" onclick="closeDeleteDepartmentModal()" class="flex-1 px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 border border-slate-400 text-xs font-black rounded-xl transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-red-700 hover:bg-red-800 text-white border border-red-900 text-xs font-black rounded-xl transition shadow-md">
                        Yes, Delete & Backup
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Register New Employee Modal -->
    <div id="addEmployeeModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-3 overflow-y-auto">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-5 shadow-2xl border-2 border-slate-400 my-6 max-h-[90vh] flex flex-col">
            
            <div class="flex items-center justify-between pb-3 border-b-2 border-slate-300 mb-3 shrink-0">
                <h3 class="text-sm sm:text-base font-black text-slate-950 flex items-center space-x-2">
                    <i class="fa-solid fa-user-plus text-blue-700 text-sm"></i>
                    <span>Register New Employee</span>
                </h3>
                <button onclick="closeAddEmployeeModal()" class="text-slate-600 hover:text-slate-950 p-1 font-black"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" action="employee.php" class="space-y-3 overflow-y-auto pr-1 flex-1 font-bold">
                <input type="hidden" name="action" value="add_employee">
                
                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-1">1. Personal Information & Employment Status</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">First Name *</label>
                        <input type="text" name="first_name" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Middle Name</label>
                        <input type="text" name="middle_name" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Last Name *</label>
                        <input type="text" name="last_name" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Email Address *</label>
                        <input type="email" name="email" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Phone Number *</label>
                        <input type="text" name="phone" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Date of Birth</label>
                        <input type="date" name="dob" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <!-- Employment Status & Contract Duration Field -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-blue-900 uppercase mb-0.5">Employment Status *</label>
                        <select name="employment_status" id="add_employment_status" onchange="toggleContractField('add_employment_status', 'add_contract_months_container')" required class="w-full px-2.5 py-1.5 bg-blue-50 border-2 border-blue-300 rounded-lg text-xs font-bold text-blue-950 focus:outline-none focus:border-blue-700">
                            <option value="Regular">Regular</option>
                            <option value="Probationary/Contractual">Probationary/Contractual</option>
                            <option value="Freelancer/On-call">Freelancer/On-call</option>
                        </select>
                    </div>
                    <div id="add_contract_months_container" class="hidden">
                        <label class="block text-[11px] font-black text-amber-900 uppercase mb-0.5">Contract Renewal Duration (Months) *</label>
                        <input type="number" name="contract_months" id="add_contract_months" min="1" placeholder="e.g., 6" class="w-full px-2.5 py-1.5 bg-amber-50 border-2 border-amber-300 rounded-lg text-xs font-bold text-amber-950 focus:outline-none focus:border-amber-700">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Gender</label>
                        <select name="gender" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Department *</label>
                        <select name="department_id_selector" id="add_department_id_selector" onchange="updatePositionsAndSalary('add_department_id_selector', 'add_position_select', 'add_daily_salary'); document.getElementById('add_department_name_hidden').value = this.options[this.selectedIndex].text;" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="department" id="add_department_name_hidden">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Job Position *</label>
                        <select name="position" id="add_position_select" onchange="autofillSalaryFromPosition('add_position_select', 'add_daily_salary')" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            <option value="">-- Select Department First --</option>
                        </select>
                    </div>
                </div>

                <!-- Complete Residential Address changed from dropdowns to text input fields as requested -->
                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-2">2. Complete Residential Address</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Region *</label>
                        <input type="text" name="province" required placeholder="e.g. Region XII (SOCCSKSARGEN)" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Province / State *</label>
                        <input type="text" name="province" required placeholder="e.g. South Cotabato" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">City / Municipality *</label>
                        <input type="text" name="city" required placeholder="e.g. General Santos City" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Barangay *</label>
                        <input type="text" name="barangay" required placeholder="e.g. Dadiangas East" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Street Address / House No.</label>
                        <input type="text" name="street_address" placeholder="e.g. Blk 3 Lot 5" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-1 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">ZIP Code</label>
                        <input type="text" name="postal_code" placeholder="9500" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-2">3. Statutory Contributions & Salary Details</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">SSS Number</label>
                        <input type="text" name="sss_no" placeholder="XX-XXXX-X" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">PhilHealth Number</label>
                        <input type="text" name="philhealth_no" placeholder="XX-XXXXXXXX-X" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Pag-IBIG MID</label>
                        <input type="text" name="pagibig_no" placeholder="XXXX-XXXX" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">TIN (Tax ID Number)</label>
                        <input type="text" name="tin_no" placeholder="XXX-XXX-XXX" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-emerald-900 uppercase mb-0.5">Basic Salary Per Day (₱) * [Automatic from Position]</label>
                        <input type="number" step="0.01" name="daily_salary" id="add_daily_salary" required readonly placeholder="0.00" class="w-full px-2.5 py-1.5 bg-emerald-50 border-2 border-emerald-400 rounded-lg text-xs font-black text-emerald-950 focus:outline-none focus:border-emerald-700 cursor-not-allowed">
                    </div>
                </div>

                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-2">4. Emergency Contact Details</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Contact Person Name</label>
                        <input type="text" name="emergency_name" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Relationship</label>
                        <input type="text" name="emergency_relation" placeholder="e.g. Spouse" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Emergency Phone</label>
                        <input type="text" name="emergency_phone" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <div class="pt-3 border-t-2 border-slate-300 flex space-x-2.5 shrink-0 mt-2">
                    <button type="button" onclick="closeAddEmployeeModal()" class="flex-1 px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-950 border border-slate-400 text-xs font-black rounded-xl transition">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white border border-blue-900 text-xs font-black rounded-xl transition shadow-sm">Save Payroll Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div id="editEmployeeModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-3 overflow-y-auto">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-5 shadow-2xl border-2 border-slate-400 my-6 max-h-[90vh] flex flex-col">
            
            <div class="flex items-center justify-between pb-3 border-b-2 border-slate-300 mb-3 shrink-0">
                <h3 class="text-sm sm:text-base font-black text-slate-950 flex items-center space-x-2">
                    <i class="fa-solid fa-pen-to-square text-blue-700 text-sm"></i>
                    <span>Edit Employee Payroll & Status</span>
                </h3>
                <button onclick="closeEditModal()" class="text-slate-600 hover:text-slate-950 p-1 font-black"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <form method="POST" action="employee.php" class="space-y-3 overflow-y-auto pr-1 flex-1 font-bold">
                <input type="hidden" name="action" value="edit_employee">
                <input type="hidden" name="employee_id" id="edit_id">
                
                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-1">1. Personal Information & Status</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">First Name *</label>
                        <input type="text" name="first_name" id="edit_first_name" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Middle Name</label>
                        <input type="text" name="middle_name" id="edit_middle_name" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Last Name *</label>
                        <input type="text" name="last_name" id="edit_last_name" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Email Address *</label>
                        <input type="email" name="email" id="edit_email" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Phone Number *</label>
                        <input type="text" name="phone" id="edit_phone" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Date of Birth</label>
                        <input type="date" name="dob" id="edit_dob" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <!-- Employment Status & Contract Field for Edit Modal -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-blue-900 uppercase mb-0.5">Employment Status *</label>
                        <select name="employment_status" id="edit_employment_status" onchange="toggleContractField('edit_employment_status', 'edit_contract_months_container')" required class="w-full px-2.5 py-1.5 bg-blue-50 border-2 border-blue-300 rounded-lg text-xs font-bold text-blue-950 focus:outline-none focus:border-blue-700">
                            <option value="Regular">Regular</option>
                            <option value="Probationary/Contractual">Probationary/Contractual</option>
                            <option value="Freelancer/On-call">Freelancer/On-call</option>
                        </select>
                    </div>
                    <div id="edit_contract_months_container" class="hidden">
                        <label class="block text-[11px] font-black text-amber-900 uppercase mb-0.5">Contract Renewal Duration (Months) *</label>
                        <input type="number" name="contract_months" id="edit_contract_months" min="1" placeholder="e.g., 6" class="w-full px-2.5 py-1.5 bg-amber-50 border-2 border-amber-300 rounded-lg text-xs font-bold text-amber-950 focus:outline-none focus:border-amber-700">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Gender</label>
                        <select name="gender" id="edit_gender" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Department *</label>
                        <select name="department_id_selector" id="edit_department_id_selector" onchange="updatePositionsAndSalary('edit_department_id_selector', 'edit_position_select', 'edit_daily_salary'); document.getElementById('edit_department_name_hidden').value = this.options[this.selectedIndex].text;" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="department" id="edit_department_name_hidden">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-amber-900 uppercase mb-0.5">Account Status *</label>
                        <select name="status" id="edit_status" class="w-full px-2.5 py-1.5 bg-amber-100 border-2 border-amber-400 rounded-lg text-xs font-black text-amber-950 focus:outline-none focus:border-amber-700">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive (Sends to Trash)</option>
                            <option value="Suspended">Suspended (Sends to Trash)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Job Position *</label>
                        <select name="position" id="edit_position_select" onchange="autofillSalaryFromPosition('edit_position_select', 'edit_daily_salary')" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                            <!-- Populated via JS -->
                        </select>
                    </div>
                </div>

                <!-- Complete Residential Address inputs for edit -->
                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-2">2. Complete Residential Address</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Region *</label>
                        <input type="text" name="region" id="edit_region" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Province / State *</label>
                        <input type="text" name="province" id="edit_province" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">City / Municipality *</label>
                        <input type="text" name="city" id="edit_city" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Barangay *</label>
                        <input type="text" name="barangay" id="edit_barangay" required class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Street Address / House No.</label>
                        <input type="text" name="street_address" id="edit_street_address" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-1 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Postal / ZIP Code</label>
                        <input type="text" name="postal_code" id="edit_postal_code" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-2">3. Statutory Contributions & Salary Details</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">SSS Number</label>
                        <input type="text" name="sss_no" id="edit_sss_no" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">PhilHealth Number</label>
                        <input type="text" name="philhealth_no" id="edit_philhealth_no" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Pag-IBIG MID / RTN</label>
                        <input type="text" name="pagibig_no" id="edit_pagibig_no" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">TIN (Tax ID Number)</label>
                        <input type="text" name="tin_no" id="edit_tin_no" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-emerald-900 uppercase mb-0.5">Basic Salary Per Day (₱) * [Automatic]</label>
                        <input type="number" step="0.01" name="daily_salary" id="edit_daily_salary" required readonly class="w-full px-2.5 py-1.5 bg-emerald-50 border-2 border-emerald-400 rounded-lg text-xs font-black text-emerald-950 focus:outline-none focus:border-emerald-700 cursor-not-allowed">
                    </div>
                </div>

                <h4 class="text-[11px] font-black uppercase tracking-wider text-blue-800 pt-2">4. Emergency Contact Details</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Contact Person Name</label>
                        <input type="text" name="emergency_name" id="edit_emergency_name" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Relationship</label>
                        <input type="text" name="emergency_relation" id="edit_emergency_relation" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-black text-slate-900 uppercase mb-0.5">Emergency Phone</label>
                        <input type="text" name="emergency_phone" id="edit_emergency_phone" class="w-full px-2.5 py-1.5 bg-slate-50 border-2 border-slate-300 rounded-lg text-xs font-bold text-slate-950 focus:outline-none focus:border-blue-700">
                    </div>
                </div>

                <div class="pt-3 border-t-2 border-slate-300 flex space-x-2.5 shrink-0 mt-2">
                    <button type="button" onclick="closeEditModal()" class="flex-1 px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-950 border border-slate-400 text-xs font-black rounded-xl transition">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white border border-blue-900 text-xs font-black rounded-xl transition shadow-sm">Save & Apply Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Handlers -->
    <script>
        function openMobileMenu() { document.getElementById('mobileDrawer').classList.remove('hidden'); }
        function closeMobileMenu() { document.getElementById('mobileDrawer').classList.add('hidden'); }

        function openDepartmentsHubModal() { document.getElementById('departmentsHubModal').classList.remove('hidden'); }
        function closeDepartmentsHubModal() { document.getElementById('departmentsHubModal').classList.add('hidden'); }

        function openEditDepartmentModal(dept) {
            document.getElementById('edit_dept_id').value = dept.id;
            document.getElementById('edit_dept_name').value = dept.department_name;
            document.getElementById('add_pos_dept_id').value = dept.id;
            
            // Populate positions table
            const tbody = document.getElementById('department_positions_tbody');
            tbody.innerHTML = '';
            
            if (departmentDataMap[dept.id] && departmentDataMap[dept.id].positions.length > 0) {
                departmentDataMap[dept.id].positions.forEach(pos => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-50';
                    tr.innerHTML = `
                        <td class="py-2.5 px-3 text-slate-950 font-black">${pos.name}</td>
                        <td class="py-2.5 px-3 text-emerald-900 font-black">₱${Number(pos.salary).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td class="py-2.5 px-3 text-right">
                            <form method="POST" action="employee.php" onsubmit="return confirm('Delete this position?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_job_position">
                                <input type="hidden" name="position_id" value="${pos.id}">
                                <button type="submit" class="px-2 py-1 bg-red-100 hover:bg-red-200 text-red-900 rounded text-[10px] font-black transition">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="3" class="py-4 text-center text-slate-500 text-xs">No job positions added yet.</td></tr>';
            }

            document.getElementById('editDepartmentModal').classList.remove('hidden');
        }
        function closeEditDepartmentModal() { document.getElementById('editDepartmentModal').classList.add('hidden'); }

        function openDeleteDepartmentModal(deptId, deptName) {
            document.getElementById('delete_dept_id_input').value = deptId;
            document.getElementById('delete_dept_name_display').textContent = deptName;
            document.getElementById('deleteDepartmentModal').classList.remove('hidden');
        }
        function closeDeleteDepartmentModal() {
            document.getElementById('deleteDepartmentModal').classList.add('hidden');
        }

        function openAddEmployeeModal() { 
            document.getElementById('add_department_id_selector').value = '';
            document.getElementById('add_position_select').innerHTML = '<option value="">-- Select Department First --</option>';
            document.getElementById('add_daily_salary').value = '';
            document.getElementById('addEmployeeModal').classList.remove('hidden'); 
        }
        function closeAddEmployeeModal() { document.getElementById('addEmployeeModal').classList.add('hidden'); }
        
        function closeEditModal() { document.getElementById('editEmployeeModal').classList.add('hidden'); }
        
        // View Details Modal Functions
        function openViewModal(emp) {
            document.getElementById('view_full_name').textContent = `${emp.first_name} ${emp.middle_name ? emp.middle_name + ' ' : ''}${emp.last_name}`;
            document.getElementById('view_id').textContent = `#${emp.id}`;
            document.getElementById('view_email').textContent = emp.email || 'N/A';
            document.getElementById('view_phone').textContent = emp.phone || 'N/A';
            document.getElementById('view_department').textContent = emp.department || 'N/A';
            document.getElementById('view_position').textContent = emp.position || 'N/A';
            
            let empStatusText = emp.employment_status || 'Regular';
            if (empStatusText === 'Probationary/Contractual' && emp.contract_months) {
                empStatusText += ` (${emp.contract_months} Months)`;
            }
            document.getElementById('view_employment_status').textContent = empStatusText;
            document.getElementById('view_daily_salary').textContent = `₱${Number(emp.daily_salary || 0).toLocaleString('en-US', {minimumFractionDigits: 2})} /day`;
            
            document.getElementById('view_address').textContent = `${emp.street_address || ''}, ${emp.barangay || ''}, ${emp.city || ''}, ${emp.province || ''} ${emp.postal_code || ''}`.replace(/^, /, '');
            document.getElementById('view_gender_dob').textContent = `${emp.gender || 'N/A'} / ${emp.dob || 'N/A'}`;
            
            document.getElementById('view_sss').textContent = emp.sss_no || 'N/A';
            document.getElementById('view_philhealth').textContent = emp.philhealth_no || 'N/A';
            document.getElementById('view_pagibig').textContent = emp.pagibig_no || 'N/A';
            document.getElementById('view_tin').textContent = emp.tin_no || 'N/A';
            document.getElementById('view_emergency').textContent = `${emp.emergency_name || 'N/A'} (${emp.emergency_relation || 'N/A'}) - ${emp.emergency_phone || 'N/A'}`;

            // Wire up the edit button inside the view modal
            const editBtn = document.getElementById('view_edit_btn');
            editBtn.onclick = function() {
                closeViewModal();
                openEditModal(emp);
            };

            document.getElementById('viewEmployeeModal').classList.remove('hidden');
        }
        function closeViewModal() { document.getElementById('viewEmployeeModal').classList.add('hidden'); }

        function openEditModal(emp) {
            document.getElementById('edit_id').value = emp.id;
            document.getElementById('edit_first_name').value = emp.first_name || '';
            document.getElementById('edit_middle_name').value = emp.middle_name || '';
            document.getElementById('edit_last_name').value = emp.last_name || '';
            document.getElementById('edit_email').value = emp.email || '';
            document.getElementById('edit_phone').value = emp.phone || '';
            document.getElementById('edit_dob').value = emp.dob || '';
            document.getElementById('edit_gender').value = emp.gender || 'Male';
            
            // Match department selector by name
            const deptSelect = document.getElementById('edit_department_id_selector');
            let matchedDeptId = '';
            for (let i = 0; i < deptSelect.options.length; i++) {
                if (deptSelect.options[i].text === emp.department) {
                    matchedDeptId = deptSelect.options[i].value;
                    break;
                }
            }
            deptSelect.value = matchedDeptId;
            document.getElementById('edit_department_name_hidden').value = emp.department || '';

            // Populate position options for this department
            updatePositionsAndSalary('edit_department_id_selector', 'edit_position_select', 'edit_daily_salary');
            document.getElementById('edit_position_select').value = emp.position || '';
            
            const empStatus = emp.employment_status || 'Regular';
            document.getElementById('edit_employment_status').value = empStatus;
            document.getElementById('edit_contract_months').value = emp.contract_months || '';
            toggleContractField('edit_employment_status', 'edit_contract_months_container');

            document.getElementById('edit_region').value = emp.province || '';
            document.getElementById('edit_province').value = emp.province || '';
            document.getElementById('edit_city').value = emp.city || '';
            document.getElementById('edit_barangay').value = emp.barangay || '';
            document.getElementById('edit_street_address').value = emp.street_address || '';
            document.getElementById('edit_postal_code').value = emp.postal_code || '';

            document.getElementById('edit_sss_no').value = emp.sss_no || '';
            document.getElementById('edit_philhealth_no').value = emp.philhealth_no || '';
            document.getElementById('edit_pagibig_no').value = emp.pagibig_no || '';
            document.getElementById('edit_tin_no').value = emp.tin_no || '';
            document.getElementById('edit_daily_salary').value = emp.daily_salary || '';
            
            document.getElementById('edit_emergency_name').value = emp.emergency_name || '';
            document.getElementById('edit_emergency_relation').value = emp.emergency_relation || '';
            document.getElementById('edit_emergency_phone').value = emp.emergency_phone || '';
            document.getElementById('edit_status').value = emp.status || 'Active';

            document.getElementById('editEmployeeModal').classList.remove('hidden');
        }

        // Live Dynamic Script for Contract Countdown Calculation
        function updateCountdowns() {
            const countdownElements = document.querySelectorAll('.contract-countdown');
            countdownElements.forEach(el => {
                const expiryStr = el.getAttribute('data-expiry');
                if (!expiryStr) return;
                const expiryDate = new Date(expiryStr).getTime();
                const now = new Date().getTime();
                const distance = expiryDate - now;

                if (distance < 0) {
                    el.textContent = "Contract Expired";
                    el.classList.add('text-red-600');
                } else {
                    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    el.textContent = `Countdown: ${days}d ${hours}h remaining`;
                }
            });
        }
        setInterval(updateCountdowns, 1000);
        window.onload = updateCountdowns;
    </script>
</body>
</html>