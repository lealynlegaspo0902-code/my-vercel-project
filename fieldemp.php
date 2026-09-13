<?php
session_start();

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

$current_script = basename($_SERVER['PHP_SELF']);

// Small reusable "Refresh" button. $variant 'dark' is for the emerald gradient
// banners, 'light' is for plain white cards. Clicking it reloads the page on
// the same tab, which re-runs every PHP database fetch for that tab.
function refresh_button_html(string $tab, string $variant = 'dark'): string {
    $tabAttr = htmlspecialchars($tab, ENT_QUOTES);
    if ($variant === 'light') {
        $classes = 'bg-slate-100 hover:bg-slate-200 text-slate-950 border-2 border-slate-400';
    } else {
        $classes = 'bg-white/15 hover:bg-white/25 text-white border-2 border-white/40';
    }
    return '<button type="button" onclick="refreshTab(\'' . $tabAttr . '\')" '
        . 'class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 ' . $classes . ' text-[10px] sm:text-xs font-black rounded-xl transition">'
        . '<i class="fa-solid fa-rotate"></i><span>Refresh</span></button>';
}

// FIXED LOGOUT HANDLER (must be before any output)
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit();
}

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}

$employee_id = $_SESSION['employee_id'];

$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    session_destroy();
    header("Location: index.php?error=employee_not_found");
    exit();
}

$success_msg = '';
$error_msg = '';
$active_tab = $_GET['tab'] ?? 'dashboard';

// ---------------------------------------------------------------------
// SCHEMA HELPERS
// These make table creation/updates self-healing: if a table already
// existed from an older version of this app (missing columns like
// `hours`, `amount`, `start_date`, etc.), inserts into `requests` or
// `field_worker_updates` would silently fail with a "database error"
// because the column simply doesn't exist yet. ensure_column() checks
// INFORMATION_SCHEMA and adds any missing column automatically, so
// Leave / Overtime / Cash Advance requests always have somewhere to be
// written to, even on a pre-existing table.
// ---------------------------------------------------------------------
function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $check->execute([$table, $column]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        error_log("[GigPay schema] Could not ensure column {$table}.{$column}: " . $e->getMessage());
    }
}

// table_exists() / column_exists() let us READ from tables that are owned
// and managed by the separate Admin / Biometric Device system (like
// `attendance`) without assuming their exact shape. That table's schema can
// vary (different employee-linking column name, different timestamp column)
// depending on how the admin side / biometric sync was set up, so instead of
// hardcoding column names in the SELECT and ORDER BY (which throws a fatal
// "Unknown column" PDO error and silently empties the whole records list the
// moment one guessed column doesn't exist), we detect what's really there
// first and build the query around it.
function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log("[GigPay schema] Could not check table {$table}: " . $e->getMessage());
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log("[GigPay schema] Could not check column {$table}.{$column}: " . $e->getMessage());
        return false;
    }
}

// Works out a single, consistent attendance status (label + color classes)
// for one `attendance` row, used by both the Official Attendance Records
// table and the calendar/date-details badges so "Late" behaves exactly like
// the existing "Present" / "Absent" feature instead of being a one-off.
// Priority: an explicit admin-set `status` value wins; otherwise we infer
// Absent > Late > Present from the `absent` / `tardy` / `tardy_minutes` flags.
function attendance_status_meta(array $rec): array {
    $isAbsent = !empty($rec['absent']);
    $tardyMinutes = isset($rec['tardy_minutes']) ? (int)$rec['tardy_minutes'] : 0;
    $isLate = !$isAbsent && (!empty($rec['tardy']) || $tardyMinutes > 0);

    $label = (isset($rec['status']) && trim((string)$rec['status']) !== '')
        ? (string)$rec['status']
        : ($isAbsent ? 'Absent' : ($isLate ? 'Late' : 'Present'));

    $key = strtolower($label);
    if (strpos($key, 'absent') !== false) {
        $classes = 'bg-red-100 text-red-950 border-2 border-red-600';
    } elseif (strpos($key, 'late') !== false || strpos($key, 'tardy') !== false) {
        $classes = 'bg-amber-100 text-amber-950 border-2 border-amber-600';
    } else {
        $classes = 'bg-emerald-100 text-emerald-950 border-2 border-emerald-600';
    }

    return ['label' => $label, 'classes' => $classes, 'is_absent' => $isAbsent, 'is_late' => $isLate];
}

// Ensure database tables exist safely with complete schema
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        details TEXT,
        image_path VARCHAR(255) DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        hours DECIMAL(5,2) DEFAULT NULL,
        amount DECIMAL(10,2) DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ca_from DATE DEFAULT NULL,
        ca_to DATE DEFAULT NULL,
        installments_count INT DEFAULT NULL
    )");
} catch (Throwable $e) {
    error_log("[GigPay schema] requests table create failed: " . $e->getMessage());
}
// Self-heal: guarantee every column `submit_request` writes to actually exists,
// even if `requests` was created earlier by an older version of this script.
ensure_column($pdo, 'requests', 'details', 'TEXT NULL');
ensure_column($pdo, 'requests', 'start_date', 'DATE DEFAULT NULL');
ensure_column($pdo, 'requests', 'end_date', 'DATE DEFAULT NULL');
ensure_column($pdo, 'requests', 'hours', 'DECIMAL(5,2) DEFAULT NULL');
ensure_column($pdo, 'requests', 'amount', 'DECIMAL(10,2) DEFAULT NULL');
ensure_column($pdo, 'requests', 'status', "VARCHAR(50) DEFAULT 'Pending'");
ensure_column($pdo, 'requests', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
ensure_column($pdo, 'requests', 'ca_from', 'DATE DEFAULT NULL');
ensure_column($pdo, 'requests', 'ca_to', 'DATE DEFAULT NULL');
ensure_column($pdo, 'requests', 'installments_count', 'INT DEFAULT NULL');
// Attendance Correction / Missed Scan support: the missed scan date reuses
// `start_date`, but the scan type (Time In/Out, Break In/Out) and the
// corrected time the employee is requesting need their own columns.
ensure_column($pdo, 'requests', 'scan_type', 'VARCHAR(50) DEFAULT NULL');
ensure_column($pdo, 'requests', 'correct_time', 'TIME DEFAULT NULL');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS field_worker_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        update_date DATE DEFAULT NULL,
        check_in_time TIME DEFAULT NULL,
        image VARCHAR(255) DEFAULT NULL,
        notes TEXT,
        status VARCHAR(50) DEFAULT 'Pending',
        is_paid TINYINT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    error_log("[GigPay schema] field_worker_updates table create failed: " . $e->getMessage());
}
ensure_column($pdo, 'field_worker_updates', 'image', 'VARCHAR(255) DEFAULT NULL');
ensure_column($pdo, 'field_worker_updates', 'notes', 'TEXT NULL');
ensure_column($pdo, 'field_worker_updates', 'status', "VARCHAR(50) DEFAULT 'Pending'");
ensure_column($pdo, 'field_worker_updates', 'is_paid', 'TINYINT DEFAULT 0');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT DEFAULT 0
    )");
} catch (Throwable $e) {
    error_log("[GigPay schema] admin_notifications table create failed: " . $e->getMessage());
}

// IMPORTANT: the admin panel/database actually uses `company_holidays` (with
// start_date/end_date range columns) and `attendance` (the official biometric
// check-in/check-out ledger) — NOT `holidays` / `field_worker_updates`. The
// employee portal was previously reading/writing the wrong table names, so it
// never saw anything the admin side stored. These CREATE TABLE calls only run
// if the table is genuinely missing (fresh install); on your live database
// they are no-ops since both tables already exist with real data.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        holiday_date DATE DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        type VARCHAR(50) DEFAULT 'Holiday',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    error_log("[GigPay schema] company_holidays table create failed: " . $e->getMessage());
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        timestamp DATETIME NOT NULL,
        device VARCHAR(100) DEFAULT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'Check-In',
        workdays INT DEFAULT 1,
        absent TINYINT(1) DEFAULT 0,
        absent_with_pay TINYINT(1) DEFAULT 0,
        tardy TINYINT(1) DEFAULT 0,
        undertime INT DEFAULT 0,
        remarks VARCHAR(255) DEFAULT NULL,
        check_in DATETIME DEFAULT NULL,
        check_out DATETIME DEFAULT NULL,
        workhours DECIMAL(5,2) DEFAULT 0.00,
        overtime_hours DECIMAL(5,2) DEFAULT 0.00,
        tardy_minutes INT DEFAULT 0,
        status VARCHAR(100) DEFAULT NULL,
        photo_proof VARCHAR(255) DEFAULT NULL,
        notes TEXT,
        site_picture VARCHAR(255) DEFAULT NULL,
        picture_mime VARCHAR(50) DEFAULT NULL
    )");
} catch (Throwable $e) {
    error_log("[GigPay schema] attendance table create failed: " . $e->getMessage());
}

// Handle Action Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'submit_field_attendance') {
            $notes = trim($_POST['notes'] ?? 'Field site check-in');
            
            if (isset($_POST['captured_image_data']) && !empty($_POST['captured_image_data'])) {
                $dataUri = $_POST['captured_image_data'];
                if (preg_match('/^data:image\/(\w+);base64,/', $dataUri, $type)) {
                    $data = substr($dataUri, strpos($dataUri, ',') + 1);
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $fileExtension = strtolower($type[1]);
                        if (!in_array($fileExtension, ['jpg', 'jpeg', 'png', 'webp'])) {
                            $fileExtension = 'jpg';
                        }
                        $newFileName = 'field_' . $employee_id . '_' . time() . '.' . $fileExtension;
                        $uploadFileDir = 'uploads/field_photos/';
                        if (!is_dir($uploadFileDir)) {
                            mkdir($uploadFileDir, 0755, true);
                        }
                        $dest_path = $uploadFileDir . $newFileName;
                        if (file_put_contents($dest_path, $data)) {
                            $storedPath = $uploadFileDir . $newFileName;
                            $updateStmt = $pdo->prepare("INSERT INTO field_worker_updates (employee_id, update_date, check_in_time, image, notes, status, is_paid, created_at) VALUES (?, CURDATE(), CURTIME(), ?, ?, 'Pending', 0, NOW())");
                            $updateStmt->execute([$employee_id, $storedPath, $notes]);
                            $success_msg = "Success! Your live camera photo proof and attendance update have been securely sent and stored in the database.";
                        } else {
                            $error_msg = "Error saving captured camera image.";
                        }
                    } else {
                        $error_msg = "Failed to decode captured image data.";
                    }
                } else {
                    $error_msg = "Invalid image data format received.";
                }
            } elseif (isset($_FILES['site_photo']) && $_FILES['site_photo']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['site_photo']['tmp_name'];
                $fileName = $_FILES['site_photo']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($fileExtension, $allowedExtensions)) {
                    $newFileName = 'field_' . $employee_id . '_' . time() . '.' . $fileExtension;
                    $uploadFileDir = 'uploads/field_photos/';
                    
                    if (!is_dir($uploadFileDir)) {
                        mkdir($uploadFileDir, 0755, true);
                    }
                    
                    $dest_path = $uploadFileDir . $newFileName;
                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        $storedPath = $uploadFileDir . $newFileName;
                        
                        $updateStmt = $pdo->prepare("INSERT INTO field_worker_updates (employee_id, update_date, check_in_time, image, notes, status, is_paid, created_at) VALUES (?, CURDATE(), CURTIME(), ?, ?, 'Pending', 0, NOW())");
                        $updateStmt->execute([$employee_id, $storedPath, $notes]);
                        
                        $success_msg = "Success! Your site image proof and attendance update have been securely sent and stored in the database.";
                    } else {
                        $error_msg = "Error moving the uploaded file to destination folder.";
                    }
                } else {
                    $error_msg = "Invalid image format. Allowed formats: JPG, JPEG, PNG, WEBP.";
                }
            } else {
                $error_msg = "Please capture a live photo or browse/upload a valid image proof from the site.";
            }
            $active_tab = 'overview';
        } elseif ($action === 'delete_update') {
            $update_id = intval($_POST['update_id'] ?? 0);
            if ($update_id > 0) {
                $fetchR = $pdo->prepare("SELECT * FROM field_worker_updates WHERE id = ? AND employee_id = ?");
                $fetchR->execute([$update_id, $employee_id]);
                $record = $fetchR->fetch();

                if ($record) {
                    $imgCol = $record['image'] ?? ($record['site_picture'] ?? '');
                    if (!empty($imgCol)) {
                        $fullPath = (strpos($imgCol, 'uploads/field_photos/') === false) ? 'uploads/field_photos/' . $imgCol : $imgCol;
                        if (file_exists($fullPath)) {
                            @unlink($fullPath);
                        }
                    }

                    $delStmt = $pdo->prepare("DELETE FROM field_worker_updates WHERE id = ? AND employee_id = ?");
                    $delStmt->execute([$update_id, $employee_id]);
                    $success_msg = "Attendance update successfully deleted from history.";
                } else {
                    $error_msg = "Update record not found or unauthorized.";
                }
            } else {
                $error_msg = "Invalid update ID.";
            }
            $active_tab = 'sent_photos';
        } elseif ($action === 'submit_request') {
            $req_category = trim($_POST['request_category'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $req_id = intval($_POST['request_id'] ?? 0);
            
            $leave_type = null;
            $start_date = null;
            $end_date = null;
            $hours = null;
            $amount = null;
            $scan_type = null;
            $correct_time = null;

            if ($req_category === 'Leave') {
                $leave_type = trim($_POST['leave_type'] ?? '');
                $start_date = trim($_POST['start_date'] ?? '');
                $end_date = trim($_POST['end_date'] ?? '');
                
                if (!empty($leave_type) && !empty($start_date) && !empty($end_date)) {
                    $detailsText = "Leave Type: " . $leave_type . ($notes ? " - " . $notes : "");
                    if ($req_id > 0) {
                        $upd = $pdo->prepare("UPDATE requests SET type = ?, start_date = ?, end_date = ?, hours = NULL, amount = NULL, scan_type = NULL, correct_time = NULL, details = ?, status = 'Pending' WHERE id = ? AND employee_id = ?");
                        $upd->execute([$req_category, $start_date, $end_date, $detailsText, $req_id, $employee_id]);
                        $success_msg = "Leave request successfully updated!";
                    } else {
                        $ins = $pdo->prepare("INSERT INTO requests (employee_id, type, start_date, end_date, hours, amount, scan_type, correct_time, details, status, created_at) VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, 'Pending', NOW())");
                        $ins->execute([$employee_id, $req_category, $start_date, $end_date, $detailsText]);
                        $success_msg = "Leave request successfully submitted!";
                    }
                } else {
                    $error_msg = "Please fill in all required fields including the specific leave type, start date, and end date.";
                }
            } elseif ($req_category === 'Overtime (OT)') {
                $start_date = trim($_POST['ot_start_date'] ?? $_POST['start_date'] ?? '');
                $hours = floatval($_POST['hours'] ?? 0);
                if (!empty($start_date) && $hours > 0) {
                    if ($req_id > 0) {
                        $upd = $pdo->prepare("UPDATE requests SET type = ?, start_date = ?, end_date = NULL, hours = ?, amount = NULL, scan_type = NULL, correct_time = NULL, details = ?, status = 'Pending' WHERE id = ? AND employee_id = ?");
                        $upd->execute([$req_category, $start_date, $hours, $notes, $req_id, $employee_id]);
                        $success_msg = "Overtime request successfully updated!";
                    } else {
                        $ins = $pdo->prepare("INSERT INTO requests (employee_id, type, start_date, end_date, hours, amount, scan_type, correct_time, details, status, created_at) VALUES (?, ?, ?, NULL, ?, NULL, NULL, NULL, ?, 'Pending', NOW())");
                        $ins->execute([$employee_id, $req_category, $start_date, $hours, $notes]);
                        $success_msg = "Overtime request successfully submitted!";
                    }
                } else {
                    $error_msg = "Please fill in the overtime date and valid hours.";
                }
            } elseif ($req_category === 'Cash Advance') {
                $amount = floatval($_POST['amount'] ?? 0);
                if ($amount > 0) {
                    if ($req_id > 0) {
                        $upd = $pdo->prepare("UPDATE requests SET type = ?, start_date = NULL, end_date = NULL, hours = NULL, amount = ?, scan_type = NULL, correct_time = NULL, details = ?, status = 'Pending' WHERE id = ? AND employee_id = ?");
                        $upd->execute([$req_category, $amount, $notes, $req_id, $employee_id]);
                        $success_msg = "Cash advance request successfully updated!";
                    } else {
                        $ins = $pdo->prepare("INSERT INTO requests (employee_id, type, start_date, end_date, hours, amount, scan_type, correct_time, details, status, created_at) VALUES (?, ?, NULL, NULL, NULL, ?, NULL, NULL, ?, 'Pending', NOW())");
                        $ins->execute([$employee_id, $req_category, $amount, $notes]);
                        $success_msg = "Cash advance request successfully submitted!";
                    }
                } else {
                    $error_msg = "Please enter a valid amount for cash advance.";
                }
            } elseif ($req_category === 'Attendance Correction') {
                $start_date = trim($_POST['missed_scan_date'] ?? '');
                $scan_type = trim($_POST['scan_type'] ?? '');
                $correct_time = trim($_POST['correct_time'] ?? '');

                if (!empty($start_date) && !empty($scan_type) && !empty($correct_time)) {
                    if ($req_id > 0) {
                        $upd = $pdo->prepare("UPDATE requests SET type = ?, start_date = ?, end_date = NULL, hours = NULL, amount = NULL, scan_type = ?, correct_time = ?, details = ?, status = 'Pending' WHERE id = ? AND employee_id = ?");
                        $upd->execute([$req_category, $start_date, $scan_type, $correct_time, $notes, $req_id, $employee_id]);
                        $success_msg = "Attendance correction request successfully updated!";
                    } else {
                        $ins = $pdo->prepare("INSERT INTO requests (employee_id, type, start_date, end_date, hours, amount, scan_type, correct_time, details, status, created_at) VALUES (?, ?, ?, NULL, NULL, NULL, ?, ?, ?, 'Pending', NOW())");
                        $ins->execute([$employee_id, $req_category, $start_date, $scan_type, $correct_time, $notes]);
                        $success_msg = "Attendance correction request successfully submitted!";
                    }
                } else {
                    $error_msg = "Please fill in the date of the missed scan, the scan type, and the correct time.";
                }
            } else {
                $error_msg = "Invalid request category chosen.";
            }
            $active_tab = 'requests';
        } elseif ($action === 'delete_request') {
            $req_id = intval($_POST['request_id'] ?? 0);
            if ($req_id > 0) {
                $del = $pdo->prepare("DELETE FROM requests WHERE id = ? AND employee_id = ?");
                $del->execute([$req_id, $employee_id]);
                $success_msg = "Request successfully deleted.";
            }
            $active_tab = 'requests';
        } elseif ($action === 'update_account_settings') {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
            $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
            $relationship = trim($_POST['relationship'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $new_password = $_POST['new_password'] ?? '';

            if (!empty($first_name) && !empty($last_name)) {
                if (!empty($new_password)) {
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $updAcc = $pdo->prepare("UPDATE employees SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, gender = ?, emergency_contact_name = ?, emergency_contact_phone = ?, relationship = ?, username = ?, password = ? WHERE id = ?");
                    $updAcc->execute([$first_name, $last_name, $email, $phone, $address, $gender, $emergency_contact_name, $emergency_contact_phone, $relationship, $username, $password_hash, $employee_id]);
                } else {
                    $updAcc = $pdo->prepare("UPDATE employees SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, gender = ?, emergency_contact_name = ?, emergency_contact_phone = ?, relationship = ?, username = ? WHERE id = ?");
                    $updAcc->execute([$first_name, $last_name, $email, $phone, $address, $gender, $emergency_contact_name, $emergency_contact_phone, $relationship, $username, $employee_id]);
                }

                try {
                    $notifStmt = $pdo->prepare("INSERT INTO admin_notifications (employee_id, message, created_at, is_read) VALUES (?, ?, NOW(), 0)");
                    $notifStmt->execute([$employee_id, "Employee {$first_name} {$last_name} updated their account settings & personal info."]);
                } catch (Throwable $e) {
                    error_log("[GigPay notify] " . $e->getMessage());
                }

                $success_msg = "Account settings successfully updated! Admin has been notified.";
                
                $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt->execute([$employee_id]);
                $employee = $stmt->fetch();
            } else {
                $error_msg = "First name and Last name are required.";
            }
            $active_tab = 'account_settings';
        }
    } catch (Throwable $e) {
        // Catching Throwable (not just Exception) means real PDO/SQL errors
        // (wrong column, wrong type, etc.) always surface to the employee
        // instead of failing silently and looking like nothing happened.
        $error_msg = "Database Error: " . $e->getMessage();
        error_log("[GigPay action:{$action}] " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// DATA FETCHING
// Every read below is wrapped so a single failed query can't take down
// the whole page, but failures are now logged (error_log) AND surfaced
// as a small non-blocking notice via $fetch_warnings, instead of being
// swallowed silently and leaving the employee wondering why their
// history/requests/payslips list looks empty.
// ---------------------------------------------------------------------
$fetch_warnings = [];

$attendance_logs = [];
try {
    $attStmt = $pdo->prepare("SELECT * FROM field_worker_updates WHERE employee_id = ? ORDER BY created_at DESC");
    $attStmt->execute([$employee_id]);
    $attendance_logs = $attStmt->fetchAll();
} catch (Throwable $e) {
    error_log("[GigPay fetch:field_worker_updates] " . $e->getMessage());
    $fetch_warnings[] = "Attendance history could not be loaded.";
}

$employee_requests = [];
try {
    $reqStmt = $pdo->prepare("SELECT * FROM requests WHERE employee_id = ? ORDER BY created_at DESC");
    $reqStmt->execute([$employee_id]);
    $employee_requests = $reqStmt->fetchAll();
} catch (Throwable $e) {
    error_log("[GigPay fetch:requests] " . $e->getMessage());
    $fetch_warnings[] = "Leave / Overtime / Cash Advance requests could not be loaded.";
}

$holidays = [];
try {
    // Real table is `company_holidays` (range-based: start_date/end_date,
    // holiday_date is often left as 0000-00-00), not `holidays`.
    $holStmt = $pdo->query("SELECT * FROM company_holidays ORDER BY start_date ASC");
    $holidays = $holStmt->fetchAll();
} catch (Throwable $e) {
    error_log("[GigPay fetch:company_holidays] " . $e->getMessage());
    $fetch_warnings[] = "Company holidays could not be loaded.";
}

$attendance_records = [];
try {
    // Real table is `attendance` (the official biometric/admin-managed
    // check-in/check-out ledger), not `field_worker_updates`. This table is
    // owned by the admin/biometric-sync side, so its exact columns can vary
    // between deployments. The old query hardcoded `employee_id` and
    // `ORDER BY timestamp DESC, check_in DESC` - if the admin's version of
    // this table didn't have a `timestamp` column (or used a different
    // employee-linking column), PDO would throw "Unknown column" and this
    // whole fetch would silently come back empty. We now detect the real
    // columns first and build the query to match what's actually there.
    if (table_exists($pdo, 'attendance')) {
        $empCol = null;
        foreach (['employee_id', 'emp_id', 'employeeID', 'staff_id', 'employee'] as $candidate) {
            if (column_exists($pdo, 'attendance', $candidate)) {
                $empCol = $candidate;
                break;
            }
        }

        $orderCol = null;
        foreach (['check_in', 'timestamp', 'date', 'created_at', 'id'] as $candidate) {
            if (column_exists($pdo, 'attendance', $candidate)) {
                $orderCol = $candidate;
                break;
            }
        }

        if ($empCol !== null) {
            $sql = "SELECT * FROM `attendance` WHERE `$empCol` = ?";
            if ($orderCol !== null) {
                $sql .= " ORDER BY `$orderCol` DESC";
            }
            $attRecStmt = $pdo->prepare($sql);
            $attRecStmt->execute([$employee_id]);
            $attendance_records = $attRecStmt->fetchAll();
        } else {
            error_log("[GigPay fetch:attendance] Could not find an employee-linking column on `attendance`.");
            $fetch_warnings[] = "Official attendance records could not be matched to your account yet.";
        }
    } else {
        error_log("[GigPay fetch:attendance] `attendance` table does not exist yet.");
        $fetch_warnings[] = "Official attendance records haven't been synced by the admin system yet.";
    }
} catch (Throwable $e) {
    error_log("[GigPay fetch:attendance] " . $e->getMessage());
    $fetch_warnings[] = "Official attendance records could not be loaded.";
}

$payslips = [];
try {
    $payStmt = $pdo->prepare("SELECT * FROM payrolls WHERE employee_id = ? ORDER BY created_at DESC");
    $payStmt->execute([$employee_id]);
    $payslips = $payStmt->fetchAll();
} catch (Throwable $e) {
    try {
        $payStmt = $pdo->prepare("SELECT * FROM payslips WHERE employee_id = ? ORDER BY created_at DESC");
        $payStmt->execute([$employee_id]);
        $payslips = $payStmt->fetchAll();
    } catch (Throwable $ex) {
        error_log("[GigPay fetch:payrolls/payslips] " . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 font-bold text-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Field Worker Portal</title>
    <script>
        // Runs immediately, before Tailwind/CSS/body load, so a returning
        // user who chose dark mode never sees a flash of the light theme.
        (function() {
            try {
                if (localStorage.getItem('gigpay_dark_mode') === '1') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) { /* localStorage unavailable (private mode, etc.) - default to light */ }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal-backdrop {
            background-color: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        /* ------------------------------------------------------------------
           DARK MODE
           This app is built entirely on Tailwind's utility classes (bg-white,
           text-slate-950, border-slate-400, etc.) with no build step, so
           rather than hand-adding a `dark:` variant to every single element
           (thousands of class attributes across the page, including ones
           generated dynamically in JS template literals for the calendar and
           modals), we flip the whole theme with one class on <html> and let
           plain CSS overrides re-point those same utility classes wherever
           they appear - including elements JS injects later. `!important` is
           required because our override and Tailwind's own utility rule have
           identical selector specificity, so without it the winner would
           depend on unpredictable stylesheet injection order.
           ------------------------------------------------------------------ */
        html.dark { color-scheme: dark; }
        html.dark body { background-color: #0b1220 !important; }

        /* Neutral surfaces */
        html.dark .bg-white { background-color: #1e293b !important; }
        html.dark .bg-slate-50 { background-color: #0f172a !important; }
        html.dark .bg-slate-100 { background-color: #1e293b !important; }
        html.dark .bg-slate-100\/50 { background-color: rgba(30, 41, 59, 0.5) !important; }
        html.dark .bg-slate-200 { background-color: #263449 !important; }
        html.dark .bg-slate-300 { background-color: #334155 !important; }

        /* Neutral borders / dividers */
        html.dark .border-slate-100,
        html.dark .border-slate-200,
        html.dark .border-slate-300,
        html.dark .border-slate-400 { border-color: #334155 !important; }
        html.dark .divide-slate-300 > :not([hidden]) ~ :not([hidden]) { border-color: #334155 !important; }

        /* Neutral text */
        html.dark .text-slate-950,
        html.dark .text-slate-900,
        html.dark .text-slate-800 { color: #f1f5f9 !important; }
        html.dark .text-slate-700,
        html.dark .text-slate-600 { color: #94a3b8 !important; }

        /* Status / accent cards (leave/OT/holiday/attendance badges) -
           darken the light tint backgrounds and lighten their matching text
           so the same colored-card language still reads clearly at night. */
        html.dark .bg-emerald-50 { background-color: rgba(6, 78, 59, 0.35) !important; }
        html.dark .bg-emerald-100 { background-color: rgba(6, 78, 59, 0.55) !important; }
        html.dark .bg-emerald-200 { background-color: rgba(6, 95, 70, 0.7) !important; }
        html.dark .text-emerald-950,
        html.dark .text-emerald-900,
        html.dark .text-emerald-800,
        html.dark .text-emerald-700 { color: #a7f3d0 !important; }

        html.dark .bg-red-50 { background-color: rgba(127, 29, 29, 0.35) !important; }
        html.dark .bg-red-100 { background-color: rgba(127, 29, 29, 0.55) !important; }
        html.dark .text-red-950,
        html.dark .text-red-900,
        html.dark .text-red-800,
        html.dark .text-red-700,
        html.dark .text-red-200 { color: #fecaca !important; }

        html.dark .bg-amber-50 { background-color: rgba(120, 53, 15, 0.35) !important; }
        html.dark .bg-amber-100 { background-color: rgba(120, 53, 15, 0.55) !important; }
        html.dark .text-amber-950,
        html.dark .text-amber-700 { color: #fde68a !important; }

        html.dark .bg-teal-50 { background-color: rgba(19, 78, 74, 0.35) !important; }
        html.dark .bg-teal-100 { background-color: rgba(19, 78, 74, 0.55) !important; }
        html.dark .text-teal-950,
        html.dark .text-teal-800 { color: #99f6e4 !important; }

        html.dark .bg-blue-50 { background-color: rgba(23, 37, 84, 0.35) !important; }
        html.dark .bg-blue-100 { background-color: rgba(23, 37, 84, 0.55) !important; }
        html.dark .text-blue-950 { color: #bfdbfe !important; }

        html.dark .bg-purple-50 { background-color: rgba(88, 28, 135, 0.35) !important; }
        html.dark .bg-purple-100 { background-color: rgba(88, 28, 135, 0.55) !important; }
        html.dark .bg-purple-200 { background-color: rgba(107, 33, 168, 0.6) !important; }
        html.dark .text-purple-950,
        html.dark .text-purple-900,
        html.dark .text-purple-800 { color: #e9d5ff !important; }

        /* Smooth the switch instead of an abrupt flash */
        body, .bg-white, .bg-slate-50, .bg-slate-100, .bg-slate-200 {
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
    </style>
    <script>
        const currentScriptUrl = "<?php echo $current_script; ?>";

        // Full page reload targeting a specific tab, so every "Refresh" button
        // pulls fresh data straight from the database (this app is server-rendered,
        // so a real DB refresh means re-running the PHP fetches, not a JS-only redraw).
        function refreshTab(tabId) {
            window.location.href = currentScriptUrl + '?tab=' + encodeURIComponent(tabId);
        }

        function switchTab(tabId) {
            document.querySelectorAll('.dashboard-section').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('bg-emerald-700', 'text-white', 'shadow-sm', 'border-slate-900');
                el.classList.add('text-slate-100', 'hover:bg-slate-800', 'hover:text-white', 'border-2', 'border-slate-800');
            });

            const targetSection = document.getElementById('section_' + tabId);
            if(targetSection) {
                targetSection.classList.remove('hidden');
                if(tabId === 'my_attendance') {
                    renderLiveCalendar();
                }
            }
            const activeBtn = document.getElementById('btn_' + tabId);
            if(activeBtn) {
                activeBtn.classList.add('bg-emerald-700', 'text-white', 'shadow-sm', 'border-emerald-950');
                activeBtn.classList.remove('text-slate-100', 'hover:bg-slate-800', 'hover:text-white');
            }
            
            const sidebar = document.getElementById('app-sidebar');
            if(sidebar && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 1024) {
                toggleMobileMenu();
            }
        }

        function toggleMobileMenu() {
            const sidebar = document.getElementById('app-sidebar');
            const backdrop = document.getElementById('sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        function confirmSubmitAttendance(event) {
            event.preventDefault();
            const form = document.getElementById('attendanceForm');
            
            const dialog = document.createElement('div');
            dialog.className = "fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4";
            dialog.innerHTML = `
                <div class="bg-white border-2 border-emerald-700 shadow-2xl rounded-3xl max-w-sm w-full p-4 sm:p-6 text-center font-bold">
                    <div class="w-12 h-12 bg-emerald-100 text-emerald-800 rounded-full flex items-center justify-center mx-auto mb-3 text-xl border-2 border-emerald-700">
                        <i class="fa-solid fa-database"></i>
                    </div>
                    <h3 class="text-lg font-black text-slate-950 mb-2">Send Attendance to Database?</h3>
                    <p class="text-xs text-slate-800 mb-6">Your site image proof and attendance record will be securely stored for admin review.</p>
                    <div class="flex space-x-3">
                        <button type="button" onclick="this.closest('.fixed').remove()" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-600 text-xs font-black">Cancel</button>
                        <button type="button" id="proceedUploadBtn" class="flex-1 px-4 py-2.5 rounded-xl bg-emerald-700 text-white text-xs border-2 border-emerald-950 font-black">Confirm & Send</button>
                    </div>
                </div>
            `;
            document.body.appendChild(dialog);

            document.getElementById('proceedUploadBtn').onclick = function() {
                dialog.remove();
                form.submit();
            };
        }

        function confirmDeleteUpdate(updateId) {
            const dialog = document.createElement('div');
            dialog.className = "fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4";
            dialog.innerHTML = `
                <div class="bg-white border-2 border-red-700 shadow-2xl rounded-3xl max-w-sm w-full p-4 sm:p-6 text-center font-bold">
                    <div class="w-12 h-12 bg-red-100 text-red-800 rounded-full flex items-center justify-center mx-auto mb-3 text-xl border-2 border-red-700">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <h3 class="text-lg font-black text-slate-950 mb-2">Delete Attendance Record?</h3>
                    <p class="text-xs text-slate-800 mb-6">This action cannot be undone. The record and image proof will be permanently removed.</p>
                    <form method="POST" action="${currentScriptUrl}?tab=sent_photos">
                        <input type="hidden" name="action" value="delete_update">
                        <input type="hidden" name="update_id" value="${updateId}">
                        <div class="flex space-x-3">
                            <button type="button" onclick="this.closest('.fixed').remove()" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-600 text-xs font-black">Cancel</button>
                            <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-red-700 text-white text-xs border-2 border-red-950 font-black">Delete</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(dialog);
        }

        function confirmDeleteRequest(requestId) {
            const dialog = document.createElement('div');
            dialog.className = "fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4";
            dialog.innerHTML = `
                <div class="bg-white border-2 border-red-700 shadow-2xl rounded-3xl max-w-sm w-full p-4 sm:p-6 text-center font-bold">
                    <div class="w-12 h-12 bg-red-100 text-red-800 rounded-full flex items-center justify-center mx-auto mb-3 text-xl border-2 border-red-700">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <h3 class="text-lg font-black text-slate-950 mb-2">Delete This Request?</h3>
                    <p class="text-xs text-slate-800 mb-6">This request will be permanently removed.</p>
                    <form method="POST" action="${currentScriptUrl}?tab=requests">
                        <input type="hidden" name="action" value="delete_request">
                        <input type="hidden" name="request_id" value="${requestId}">
                        <div class="flex space-x-3">
                            <button type="button" onclick="this.closest('.fixed').remove()" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-600 text-xs font-black">Cancel</button>
                            <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-red-700 text-white text-xs border-2 border-red-950 font-black">Delete</button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(dialog);
        }

        function updateRequestFormFields() {
            const category = document.getElementById('requestCategorySelect').value;
            const leaveFields = document.getElementById('leave_fields_container');
            const otFields = document.getElementById('ot_fields_container');
            const cashFields = document.getElementById('cash_fields_container');
            const attendanceFields = document.getElementById('attendance_fields_container');

            leaveFields.classList.add('hidden');
            otFields.classList.add('hidden');
            cashFields.classList.add('hidden');
            attendanceFields.classList.add('hidden');

            const leaveTypeSel = document.getElementById('leaveTypeSelect');
            const leaveStart = document.getElementById('requestStartDateInput');
            const leaveEnd = document.getElementById('requestEndDateInput');
            const otDate = document.getElementById('requestOtDateInput');
            const otHours = document.getElementById('requestHoursInput');
            const cashAmt = document.getElementById('requestAmountInput');
            const missedScanDate = document.getElementById('requestMissedScanDateInput');
            const scanTypeSel = document.getElementById('requestScanTypeSelect');
            const correctTime = document.getElementById('requestCorrectTimeInput');

            if (leaveTypeSel) leaveTypeSel.required = false;
            if (leaveStart) leaveStart.required = false;
            if (leaveEnd) leaveEnd.required = false;
            if (otDate) otDate.required = false;
            if (otHours) otHours.required = false;
            if (cashAmt) cashAmt.required = false;
            if (missedScanDate) missedScanDate.required = false;
            if (scanTypeSel) scanTypeSel.required = false;
            if (correctTime) correctTime.required = false;

            if (category === 'Leave') {
                leaveFields.classList.remove('hidden');
                if (leaveTypeSel) leaveTypeSel.required = true;
                if (leaveStart) leaveStart.required = true;
                if (leaveEnd) leaveEnd.required = true;
            } else if (category === 'Overtime (OT)') {
                otFields.classList.remove('hidden');
                if (otDate) otDate.required = true;
                if (otHours) otHours.required = true;
            } else if (category === 'Cash Advance') {
                cashFields.classList.remove('hidden');
                if (cashAmt) cashAmt.required = true;
            } else if (category === 'Attendance Correction') {
                attendanceFields.classList.remove('hidden');
                if (missedScanDate) missedScanDate.required = true;
                if (scanTypeSel) scanTypeSel.required = true;
                if (correctTime) correctTime.required = true;
            }
        }

        function editRequest(id, category, details, startDate, endDate, hours, amount, scanType, correctTime) {
            document.getElementById('requestIdInput').value = id;
            document.getElementById('requestCategorySelect').value = category;
            updateRequestFormFields();

            if (category === 'Leave') {
                document.getElementById('requestStartDateInput').value = startDate || '';
                document.getElementById('requestEndDateInput').value = endDate || '';
                if(details && details.includes('Leave Type:')) {
                    const parts = details.split(' - ');
                    const typePart = parts[0].replace('Leave Type: ', '').trim();
                    const leaveSel = document.getElementById('leaveTypeSelect');
                    if(leaveSel) leaveSel.value = typePart;
                    if(parts.length > 1) {
                        document.getElementById('requestNotesInput').value = parts.slice(1).join(' - ');
                    }
                }
            } else if (category === 'Overtime (OT)') {
                const otDateInput = document.getElementById('requestOtDateInput');
                if(otDateInput) otDateInput.value = startDate || '';
                const otHrsInput = document.getElementById('requestHoursInput');
                if(otHrsInput) otHrsInput.value = hours || '';
                document.getElementById('requestNotesInput').value = details || '';
            } else if (category === 'Cash Advance') {
                const cashAmtInput = document.getElementById('requestAmountInput');
                if(cashAmtInput) cashAmtInput.value = amount || '';
                document.getElementById('requestNotesInput').value = details || '';
            } else if (category === 'Attendance Correction') {
                const missedScanDateInput = document.getElementById('requestMissedScanDateInput');
                if(missedScanDateInput) missedScanDateInput.value = startDate || '';
                const scanTypeSel = document.getElementById('requestScanTypeSelect');
                if(scanTypeSel) scanTypeSel.value = scanType || '';
                const correctTimeInput = document.getElementById('requestCorrectTimeInput');
                if(correctTimeInput) correctTimeInput.value = correctTime || '';
                document.getElementById('requestNotesInput').value = details || '';
            }

            document.getElementById('requestFormTitle').textContent = "Edit Request (" + category + ")";
            document.getElementById('requestSubmitBtn').textContent = "Update Request";
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function resetRequestForm() {
            document.getElementById('requestIdInput').value = '';
            document.getElementById('requestCategorySelect').selectedIndex = 0;
            updateRequestFormFields();
            const leaveSel = document.getElementById('leaveTypeSelect');
            if(leaveSel) leaveSel.selectedIndex = 0;
            
            const reqStart = document.getElementById('requestStartDateInput');
            if(reqStart) reqStart.value = '';
            const reqEnd = document.getElementById('requestEndDateInput');
            if(reqEnd) reqEnd.value = '';
            const otDateInput = document.getElementById('requestOtDateInput');
            if(otDateInput) otDateInput.value = '';
            const otHrsInput = document.getElementById('requestHoursInput');
            if(otHrsInput) otHrsInput.value = '';
            const cashAmtInput = document.getElementById('requestAmountInput');
            if(cashAmtInput) cashAmtInput.value = '';
            const missedScanDateInput = document.getElementById('requestMissedScanDateInput');
            if(missedScanDateInput) missedScanDateInput.value = '';
            const scanTypeSel = document.getElementById('requestScanTypeSelect');
            if(scanTypeSel) scanTypeSel.selectedIndex = 0;
            const correctTimeInput = document.getElementById('requestCorrectTimeInput');
            if(correctTimeInput) correctTimeInput.value = '';
            
            document.getElementById('requestNotesInput').value = '';
            document.getElementById('requestFormTitle').textContent = "Submit New Request (Leave, OT, Cash Advance, Attendance Correction)";
            document.getElementById('requestSubmitBtn').textContent = "Submit Request";
        }

        function openPayslipModal(payslipData) {
            document.getElementById('modal_period').textContent = (payslipData.pay_period_start || '') + ' to ' + (payslipData.pay_period_end || payslipData.period || 'N/A');
            document.getElementById('modal_status').textContent = payslipData.status || 'Paid Payout';
            document.getElementById('modal_days_worked').textContent = payslipData.days_worked || '0';
            document.getElementById('modal_absent_days').textContent = payslipData.absent_days || '0';
            document.getElementById('modal_tardy_count').textContent = payslipData.tardy_count || '0';
            document.getElementById('modal_tardy_late').textContent = '₱' + parseFloat(payslipData.tardy_late || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_basic_pay').textContent = '₱' + parseFloat(payslipData.basic_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_allowance').textContent = '₱' + parseFloat(payslipData.allowance || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_overtime_pay').textContent = '₱' + parseFloat(payslipData.overtime_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_holiday_pay').textContent = '₱' + parseFloat(payslipData.holiday_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_sss_deduction').textContent = '₱' + parseFloat(payslipData.sss_deduction || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_philhealth_deduction').textContent = '₱' + parseFloat(payslipData.philhealth_deduction || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_pagibig_deduction').textContent = '₱' + parseFloat(payslipData.pagibig_deduction || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_cash_advance').textContent = '₱' + parseFloat(payslipData.cash_advance || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_total_deductions').textContent = '₱' + parseFloat(payslipData.total_deductions || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('modal_net_pay').textContent = '₱' + parseFloat(payslipData.net_pay || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

            document.getElementById('payslipSummaryModal').classList.remove('hidden');
        }

        function closePayslipModal() {
            document.getElementById('payslipSummaryModal').classList.add('hidden');
        }

        // FIXED LOGOUT REDIRECTION TRIGGER WITH CONFIRMATION MODAL
        function confirmLogout(e) {
            e.preventDefault();
            const dialog = document.createElement('div');
            dialog.className = "fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4";
            dialog.innerHTML = `
                <div class="bg-white border-2 border-red-700 shadow-2xl rounded-3xl max-w-sm w-full p-4 sm:p-6 text-center font-bold">
                    <div class="w-12 h-12 bg-red-100 text-red-800 rounded-full flex items-center justify-center mx-auto mb-3 text-xl border-2 border-red-700">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    </div>
                    <h3 class="text-lg font-black text-slate-950 mb-2">Are you sure you want to log out?</h3>
                    <p class="text-xs text-slate-800 mb-6">You will be redirected to index.php and your active session will be terminated.</p>
                    <div class="flex space-x-3">
                        <button type="button" onclick="this.closest('.fixed').remove()" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-600 text-xs font-black">Cancel</button>
                        <button type="button" id="confirmLogoutBtn" class="flex-1 px-4 py-2.5 rounded-xl bg-red-700 text-white text-xs border-2 border-red-950 font-black">Yes, Logout</button>
                    </div>
                </div>
            `;
            document.body.appendChild(dialog);

            document.getElementById('confirmLogoutBtn').onclick = function() {
                window.location.href = currentScriptUrl + "?logout=true";
            };
        }

        let mediaStream = null;
        let capturedBlob = null;
        let useFrontCamera = false;

        function openCameraModal() {
            document.getElementById('cameraModal').classList.remove('hidden');
            document.getElementById('camera-view-container').classList.remove('hidden');
            document.getElementById('preview-view-container').classList.add('hidden');
            document.getElementById('capture-action-btn').classList.remove('hidden');
            document.getElementById('retake-check-action-btns').classList.add('hidden');

            startCameraStream();
        }

        function startCameraStream() {
            if (mediaStream) {
                mediaStream.getTracks().forEach(track => track.stop());
            }

            const video = document.getElementById('camera-preview');
            const constraints = {
                video: {
                    facingMode: useFrontCamera ? 'user' : 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            };

            navigator.mediaDevices.getUserMedia(constraints)
                .then(stream => {
                    mediaStream = stream;
                    video.srcObject = stream;
                })
                .catch(err => {
                    navigator.mediaDevices.getUserMedia({ video: true, audio: false })
                        .then(stream => {
                            mediaStream = stream;
                            video.srcObject = stream;
                        })
                        .catch(e => {
                            alert('Unable to access camera. Please check camera permissions or use file browsing.');
                            closeCameraModal();
                        });
                });
        }

        function closeCameraModal() {
            if (mediaStream) {
                mediaStream.getTracks().forEach(track => track.stop());
                mediaStream = null;
            }
            document.getElementById('cameraModal').classList.add('hidden');
        }

        function capturePhoto() {
            const video = document.getElementById('camera-preview');
            const canvas = document.getElementById('camera-canvas');
            
            const width = video.videoWidth || 640;
            const height = video.videoHeight || 480;
            canvas.width = width;
            canvas.height = height;
            
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, width, height);

            canvas.toBlob(blob => {
                capturedBlob = blob;
                const imageUrl = URL.createObjectURL(blob);
                document.getElementById('photo-preview-img').src = imageUrl;

                if (mediaStream) {
                    mediaStream.getTracks().forEach(track => track.stop());
                    mediaStream = null;
                }

                document.getElementById('camera-view-container').classList.add('hidden');
                document.getElementById('preview-view-container').classList.remove('hidden');
                document.getElementById('capture-action-btn').classList.add('hidden');
                document.getElementById('retake-check-action-btns').classList.remove('hidden');
            }, 'image/jpeg', 0.9);
        }

        function retakePhoto() {
            capturedBlob = null;
            document.getElementById('camera-view-container').classList.remove('hidden');
            document.getElementById('preview-view-container').classList.add('hidden');
            document.getElementById('capture-action-btn').classList.remove('hidden');
            document.getElementById('retake-check-action-btns').classList.add('hidden');
            startCameraStream();
        }

        function confirmCapturedPhoto() {
            if (!capturedBlob) return;
            const reader = new FileReader();
            reader.onloadend = function() {
                const base64data = reader.result;
                
                let hiddenInput = document.getElementById('capturedImageHiddenInput');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'captured_image_data';
                    hiddenInput.id = 'capturedImageHiddenInput';
                    document.getElementById('attendanceForm').appendChild(hiddenInput);
                }
                hiddenInput.value = base64data;

                const label = document.getElementById('photo-status-label');
                const thumbContainer = document.getElementById('form-thumbnail-preview');
                const thumbImg = document.getElementById('form-thumbnail-img');

                label.textContent = "Selected file: Live_Camera_Capture.jpg";
                label.classList.remove("text-slate-600");
                label.classList.add("text-emerald-800", "font-black");

                thumbImg.src = base64data;
                thumbContainer.classList.remove('hidden');

                closeCameraModal();
            }
            reader.readAsDataURL(capturedBlob);
        }

        function handleFileBrowse(input) {
            const label = document.getElementById('photo-status-label');
            const thumbContainer = document.getElementById('form-thumbnail-preview');
            const thumbImg = document.getElementById('form-thumbnail-img');

            if (input.files && input.files[0]) {
                label.textContent = "Selected file: " + input.files[0].name;
                label.classList.remove("text-slate-600");
                label.classList.add("text-emerald-800", "font-black");

                thumbImg.src = URL.createObjectURL(input.files[0]);
                thumbContainer.classList.remove('hidden');
                
                const hiddenInput = document.getElementById('capturedImageHiddenInput');
                if (hiddenInput) hiddenInput.value = '';
            }
        }

        function openPhotoModal(imageUrl, caption, dateTime) {
            document.getElementById('modalPhotoImg').src = imageUrl;
            document.getElementById('modalPhotoCaption').textContent = caption || 'No notes provided';
            document.getElementById('modalPhotoDateTime').textContent = dateTime || 'N/A';
            document.getElementById('viewPhotoModal').classList.remove('hidden');
        }

        function closePhotoModal() {
            document.getElementById('viewPhotoModal').classList.add('hidden');
        }

        // `company_holidays` rows are date RANGES (start_date/end_date); holiday_date
        // is frequently left as 0000-00-00 by the admin form, so match on the range
        // first and only fall back to holiday_date when it's a real date.
        function isHolidayOnDate(h, dateStr) {
            if (h.holiday_date && h.holiday_date !== '0000-00-00' && h.holiday_date === dateStr) return true;
            if (h.start_date && h.end_date) return dateStr >= h.start_date && dateStr <= h.end_date;
            return false;
        }

        // Mirrors the PHP attendance_status_meta() helper so the calendar
        // badges, the date-details modal, and the Official Attendance
        // Records table all show the exact same Present / Late / Absent
        // label and color for the same record. "Late" is derived the same
        // way "Present" / "Absent" already were - it is not a separate,
        // bolted-on feature.
        function getAttendanceStatusMeta(r) {
            const isAbsent = r.absent == 1 || r.absent === true;
            const tardyMinutes = parseInt(r.tardy_minutes || 0, 10) || 0;
            const isLate = !isAbsent && (r.tardy == 1 || r.tardy === true || tardyMinutes > 0);

            const label = (r.status && String(r.status).trim() !== '')
                ? String(r.status)
                : (isAbsent ? 'Absent' : (isLate ? 'Late' : 'Present'));

            const key = label.toLowerCase();
            let rowClass, calClass, icon;
            if (key.includes('absent')) {
                rowClass = "bg-red-50 border-red-500 text-red-950";
                calClass = "bg-red-100 text-red-950 border-red-600";
                icon = "fa-fingerprint";
            } else if (key.includes('late') || key.includes('tardy')) {
                rowClass = "bg-amber-50 border-amber-500 text-amber-950";
                calClass = "bg-amber-100 text-amber-950 border-amber-600";
                icon = "fa-clock";
            } else {
                rowClass = "bg-teal-50 border-teal-500 text-teal-950";
                calClass = "bg-teal-100 text-teal-950 border-teal-600";
                icon = "fa-fingerprint";
            }
            return { label, isAbsent, isLate, rowClass, calClass, icon };
        }

        function openDateDetailsModal(dateStr) {
            const logs = <?php echo json_encode($attendance_logs); ?>;
            const requests = <?php echo json_encode($employee_requests); ?>;
            const holidays = <?php echo json_encode($holidays); ?>;
            const officialRecords = <?php echo json_encode($attendance_records); ?>;

            document.getElementById('modal_date_title').textContent = `Updates & Records for ${dateStr}`;
            const container = document.getElementById('modal_date_content');
            container.innerHTML = '';

            let hasContent = false;

            const dayHolidays = holidays.filter(h => isHolidayOnDate(h, dateStr));
            if (dayHolidays.length > 0) {
                hasContent = true;
                dayHolidays.forEach(h => {
                    const div = document.createElement('div');
                    div.className = "bg-purple-50 border-2 border-purple-600 rounded-2xl p-3 text-purple-950 space-y-1";
                    div.innerHTML = `
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black uppercase tracking-wide text-purple-900"><i class="fa-solid fa-star mr-1"></i> Admin Holiday</span>
                            <span class="text-[10px] bg-purple-200 px-2 py-0.5 rounded border border-purple-400 font-black">Holiday</span>
                        </div>
                        <div class="text-sm font-black">${h.title}</div>
                        <p class="text-[11px] text-purple-800">${h.description || 'No description provided.'}</p>
                    `;
                    container.appendChild(div);
                });
            }

            const dayOfficial = officialRecords.filter(r => {
                const d = (r.check_in ? r.check_in.split(' ')[0] : '') || (r.timestamp ? r.timestamp.split(' ')[0] : '');
                return d === dateStr;
            });
            if (dayOfficial.length > 0) {
                hasContent = true;
                dayOfficial.forEach(r => {
                    const div = document.createElement('div');
                    const meta = getAttendanceStatusMeta(r);
                    const hrs = r.workhours ? parseFloat(r.workhours).toFixed(2) : '0.00';
                    const ot = r.overtime_hours && parseFloat(r.overtime_hours) > 0 ? ` • OT: ${parseFloat(r.overtime_hours).toFixed(2)} hrs` : '';
                    const tardy = meta.isLate ? ` • Tardy: ${r.tardy_minutes || 0} min` : '';
                    div.className = `${meta.rowClass} border-2 rounded-2xl p-3 space-y-1`;
                    div.innerHTML = `
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black uppercase"><i class="fa-solid ${meta.icon} mr-1"></i> Official Attendance Record</span>
                            <span class="text-[10px] px-2 py-0.5 rounded border font-black">${meta.label}</span>
                        </div>
                        <div class="text-xs font-bold">Check-In: ${r.check_in || '—'} • Check-Out: ${r.check_out || '—'}</div>
                        <div class="text-[10px] text-slate-700">Work Hours: ${hrs} hrs${ot}${tardy}</div>
                        ${r.remarks ? `<div class="text-[10px] text-slate-600">Remarks: ${r.remarks}</div>` : ''}
                    `;
                    container.appendChild(div);
                });
            }

            const dayLogs = logs.filter(l => {
                const d = l.update_date || (l.created_at ? l.created_at.split(' ')[0] : '');
                return d === dateStr;
            });
            if (dayLogs.length > 0) {
                hasContent = true;
                dayLogs.forEach(l => {
                    const div = document.createElement('div');
                    let statusColor = "bg-amber-50 border-amber-500 text-amber-950";
                    if (l.status === 'Approved') statusColor = "bg-emerald-50 border-emerald-500 text-emerald-950";
                    if (l.status === 'Rejected') statusColor = "bg-red-50 border-red-500 text-red-950";

                    div.className = `${statusColor} border-2 rounded-2xl p-3 space-y-1`;
                    div.innerHTML = `
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black uppercase"><i class="fa-solid fa-camera mr-1"></i> Site Attendance Update</span>
                            <span class="text-[10px] px-2 py-0.5 rounded border font-black">${l.status || 'Pending'}</span>
                        </div>
                        <div class="text-xs font-bold">${l.notes || 'Check-in'}</div>
                        <div class="text-[10px] text-slate-600">Time: ${l.created_at}</div>
                    `;
                    container.appendChild(div);
                });
            }

            const dayReqs = requests.filter(r => r.start_date === dateStr);
            if (dayReqs.length > 0) {
                hasContent = true;
                dayReqs.forEach(r => {
                    const div = document.createElement('div');
                    let reqColor = "bg-blue-50 border-blue-500 text-blue-950";
                    if (r.status === 'Approved') reqColor = "bg-emerald-50 border-emerald-500 text-emerald-950";
                    if (r.status === 'Rejected') reqColor = "bg-red-50 border-red-500 text-red-950";

                    div.className = `${reqColor} border-2 rounded-2xl p-3 space-y-1`;
                    div.innerHTML = `
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black uppercase"><i class="fa-solid fa-file-invoice mr-1"></i> ${r.type}</span>
                            <span class="text-[10px] px-2 py-0.5 rounded border font-black">${r.status}</span>
                        </div>
                        <div class="text-xs font-bold">${r.details || 'No details'}</div>
                    `;
                    container.appendChild(div);
                });
            }

            if (!hasContent) {
                container.innerHTML = `
                    <div class="text-center py-8 text-slate-500 font-black">
                        <i class="fa-solid fa-calendar-xmark text-3xl mb-2 text-slate-400"></i>
                        <p>No attendance updates, requests, or holidays recorded for this date.</p>
                    </div>
                `;
            }

            document.getElementById('dateDetailsModal').classList.remove('hidden');
        }

        function closeDateDetailsModal() {
            document.getElementById('dateDetailsModal').classList.add('hidden');
        }

        let currentCalendarDate = new Date();

        function renderLiveCalendar() {
            const year = currentCalendarDate.getFullYear();
            const month = currentCalendarDate.getMonth();

            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            const bigMonthYearEl = document.getElementById('big-calendar-month-year');
            if (bigMonthYearEl) bigMonthYearEl.textContent = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const lastDay = new Date(year, month + 1, 0).getDate();

            const bigGridEl = document.getElementById('big-calendar-days-grid');
            if (bigGridEl) {
                bigGridEl.innerHTML = '';
                const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                const daysOfWeekShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                daysOfWeek.forEach((d, idx) => {
                    const dayHeader = document.createElement('div');
                    dayHeader.className = "text-center text-[9px] sm:text-xs font-black text-slate-900 uppercase py-1 sm:py-2 px-0.5 bg-slate-200 rounded-lg sm:rounded-xl border-2 border-slate-300 truncate";
                    dayHeader.textContent = daysOfWeekShort[idx];
                    dayHeader.title = d;
                    bigGridEl.appendChild(dayHeader);
                });

                for (let i = 0; i < firstDayIndex; i++) {
                    const emptyCell = document.createElement('div');
                    emptyCell.className = "min-h-[64px] sm:min-h-[90px] lg:min-h-[110px] bg-slate-100/50 rounded-xl sm:rounded-2xl border-2 border-slate-300";
                    bigGridEl.appendChild(emptyCell);
                }

                const today = new Date();
                for (let day = 1; day <= lastDay; day++) {
                    const dayCell = document.createElement('div');
                    dayCell.className = "min-h-[64px] sm:min-h-[90px] lg:min-h-[110px] bg-white border-2 border-slate-400 rounded-xl sm:rounded-2xl p-1 sm:p-2.5 flex flex-col justify-between shadow-sm relative overflow-hidden";
                    
                    const isToday = day === today.getDate() && month === today.getMonth() && year === today.getFullYear();
                    if (isToday) {
                        dayCell.className += " border-emerald-700 bg-emerald-50/40";
                    }

                    const dayNumFormatted = String(day).padStart(2, '0');
                    const monthNumFormatted = String(month + 1).padStart(2, '0');
                    const currentDateStr = `${year}-${monthNumFormatted}-${dayNumFormatted}`;

                    dayCell.innerHTML = `
                        <div class="flex items-center justify-between">
                            <button type="button" onclick="openDateDetailsModal('${currentDateStr}')" class="text-[10px] sm:text-xs font-black ${isToday ? 'bg-emerald-700 text-white px-1.5 sm:px-2 py-0.5 rounded-lg border border-emerald-950 hover:bg-emerald-800 shadow-sm' : 'text-slate-950 hover:text-emerald-700 underline decoration-slate-400 cursor-pointer'}">${day}</button>
                            <span class="hidden sm:inline text-[9px] font-black text-slate-600">${currentDateStr}</span>
                        </div>
                        <div id="att_records_${currentDateStr}" class="space-y-1 mt-1 sm:mt-2 text-[8px] sm:text-[10px] overflow-y-auto max-h-[40px] sm:max-h-[75px] cursor-pointer" onclick="openDateDetailsModal('${currentDateStr}')">
                            <span class="hidden sm:inline text-slate-400 italic font-black">No updates</span>
                        </div>
                    `;
                    bigGridEl.appendChild(dayCell);
                }

                fetchAttendanceUpdatesForCalendar(year, month + 1);
            }
        }

        function changeCalendarMonth(direction) {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + direction);
            renderLiveCalendar();
        }

        function addCalendarBadge(dateStr, className, html) {
            const container = document.getElementById('att_records_' + dateStr);
            if (!container) return;
            if (container.innerHTML.includes('No updates')) {
                container.innerHTML = '';
            }
            const badge = document.createElement('div');
            badge.className = className;
            badge.innerHTML = html;
            container.appendChild(badge);
        }

        function fetchAttendanceUpdatesForCalendar(year, month) {
            const logs = <?php echo json_encode($attendance_logs); ?>;
            const requests = <?php echo json_encode($employee_requests); ?>;
            const holidays = <?php echo json_encode($holidays); ?>;
            const officialRecords = <?php echo json_encode($attendance_records); ?>;

            // Holidays are date RANGES, so walk every day of the visible month and
            // test each one against isHolidayOnDate() instead of relying on a
            // single exact holiday_date match (which is usually 0000-00-00).
            const lastDay = new Date(year, month, 0).getDate();
            for (let day = 1; day <= lastDay; day++) {
                const dStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                holidays.forEach(h => {
                    if (isHolidayOnDate(h, dStr)) {
                        addCalendarBadge(dStr, "bg-purple-100 text-purple-950 border-2 border-purple-600 rounded-lg px-2 py-1 font-black truncate",
                            `<i class="fa-solid fa-star text-[9px] mr-1"></i> Holiday: ${h.title}`);
                    }
                });
            }

            officialRecords.forEach(r => {
                const d = (r.check_in ? r.check_in.split(' ')[0] : '') || (r.timestamp ? r.timestamp.split(' ')[0] : '') || (r.date ? r.date.split(' ')[0] : '');
                if (!d) return;
                const meta = getAttendanceStatusMeta(r);
                addCalendarBadge(d, `${meta.calClass} border-2 rounded-lg px-2 py-1 font-black truncate`,
                    `<i class="fa-solid ${meta.icon} text-[9px] mr-1"></i> ${meta.label}`);
            });

            logs.forEach(log => {
                const updateDate = log.update_date || (log.created_at ? log.created_at.split(' ')[0] : '');
                if (!updateDate) return;
                let statusColor = "bg-amber-100 text-amber-950 border-amber-600";
                if (log.status === 'Approved') statusColor = "bg-emerald-100 text-emerald-950 border-emerald-600";
                if (log.status === 'Rejected') statusColor = "bg-red-100 text-red-950 border-red-600";
                addCalendarBadge(updateDate, `${statusColor} border-2 rounded-lg px-2 py-1 font-black truncate`,
                    `<i class="fa-solid fa-circle-check text-[9px] mr-1"></i> ${log.notes || 'Check-in'} (${log.status || 'Pending'})`);
            });

            requests.forEach(req => {
                const reqDate = req.start_date;
                if (!reqDate) return;
                let reqColor = "bg-blue-100 text-blue-950 border-blue-600";
                if (req.status === 'Approved') reqColor = "bg-emerald-100 text-emerald-950 border-emerald-600";
                if (req.status === 'Rejected') reqColor = "bg-red-100 text-red-950 border-red-600";
                addCalendarBadge(reqDate, `${reqColor} border-2 rounded-lg px-2 py-1 font-black truncate`,
                    `<i class="fa-solid fa-file-invoice text-[9px] mr-1"></i> ${req.type}: ${req.status}`);
            });
        }

        function updateRealtimeClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const strTime = `${String(hours).padStart(2, '0')}:${minutes}:${seconds} ${ampm}`;
            
            const clockEl = document.getElementById('realtime-clock');
            if (clockEl) clockEl.textContent = strTime;

            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const dateEl = document.getElementById('realtime-date');
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', options);
        }

        // Keeps the header icon (moon = currently light, click to go dark /
        // sun = currently dark, click to go light) in sync with whatever
        // theme is actually applied - called on load and after every toggle.
        function syncDarkModeIcon() {
            const icon = document.getElementById('darkModeToggleIcon');
            if (!icon) return;
            const isDark = document.documentElement.classList.contains('dark');
            icon.classList.toggle('fa-moon', !isDark);
            icon.classList.toggle('fa-sun', isDark);
        }

        function toggleDarkMode() {
            const isDark = document.documentElement.classList.toggle('dark');
            try {
                localStorage.setItem('gigpay_dark_mode', isDark ? '1' : '0');
            } catch (e) { /* localStorage unavailable - theme just won't persist */ }
            syncDarkModeIcon();
        }

        window.addEventListener('DOMContentLoaded', () => {
            syncDarkModeIcon();
            updateRealtimeClock();
            setInterval(updateRealtimeClock, 1000);
            renderLiveCalendar();
            updateRequestFormFields();
        });
    </script>
</head>
<body class="h-full font-sans antialiased flex flex-col lg:flex-row text-slate-950 font-bold bg-slate-100 overflow-x-hidden">

    <div id="sidebar-backdrop" onclick="toggleMobileMenu()" class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm hidden lg:hidden"></div>

    <aside id="app-sidebar" class="fixed lg:static inset-y-0 left-0 z-50 w-64 text-white flex flex-col justify-between shrink-0 select-none border-r-4 border-slate-900 relative bg-cover bg-center transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.95), rgba(15, 23, 42, 0.98)), url('w.jpg');">
        <div class="relative z-10">
            <div class="h-16 lg:h-20 flex items-center justify-between px-4 sm:px-6 space-x-3 border-b-4 border-slate-900">
                <div class="flex items-center space-x-3">
                    <img src="pay.png" alt="GigPay Logo" class="w-10 h-10 object-contain rounded-xl shadow-lg border-2 border-emerald-500 bg-emerald-700/20 p-1">
                    <span class="text-xl font-black tracking-tight text-white">Gig<span class="text-emerald-400">Pay</span></span>
                </div>
                <button type="button" onclick="toggleMobileMenu()" class="lg:hidden text-slate-300 hover:text-white p-2">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <nav class="p-4 space-y-2 text-sm font-black">
                <button onclick="switchTab('dashboard')" id="btn_dashboard" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='dashboard'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-chart-pie w-5"></i><span>Dashboard</span>
                </button>
                <button onclick="switchTab('overview')" id="btn_overview" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='overview'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-paper-plane w-5"></i><span>Send Site Attendance</span>
                </button>
                <button onclick="switchTab('sent_photos')" id="btn_sent_photos" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='sent_photos'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-images w-5"></i><span>Attendance History</span>
                </button>
                <button onclick="switchTab('my_attendance')" id="btn_my_attendance" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='my_attendance'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-calendar-days w-5"></i><span>My Attendance</span>
                </button>
                <button onclick="switchTab('requests')" id="btn_requests" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='requests'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-file-invoice-dollar w-5"></i><span>Leave, OT, Cash Advance & Attendance Correction</span>
                </button>
                <button onclick="switchTab('payslip')" id="btn_payslip" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='payslip'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-receipt w-5"></i><span>Payslip</span>
                </button>
                <button onclick="switchTab('account_settings')" id="btn_account_settings" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='account_settings'?'bg-emerald-700 text-white shadow-sm border-2 border-emerald-950':'text-slate-100 hover:bg-slate-800 border-2 border-slate-800'; ?> transition text-left">
                    <i class="fa-solid fa-user-gear w-5"></i><span>Account Settings</span>
                </button>
            </nav>
        </div>
        <div class="p-4 border-t-4 border-slate-900 relative z-10">
            <a href="#" onclick="confirmLogout(event)" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-red-200 hover:bg-red-950/60 border-2 border-red-800 transition text-sm font-black">
                <i class="fa-solid fa-arrow-right-from-bracket w-5"></i><span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="h-16 lg:h-20 bg-white border-b-4 border-slate-300 flex items-center justify-between px-3 sm:px-4 lg:px-8 shrink-0 shadow-sm">
            <div class="flex items-center space-x-3">
                <button type="button" onclick="toggleMobileMenu()" class="lg:hidden p-2 rounded-xl bg-slate-100 border-2 border-slate-400 text-slate-900 focus:outline-none">
                    <i class="fa-solid fa-bars text-lg"></i>
                </button>
                <h1 class="text-base lg:text-xl font-black text-slate-950 truncate">Employee Workspace</h1>
            </div>
            <div class="flex items-center space-x-3">
                <button type="button" onclick="toggleDarkMode()" id="darkModeToggleBtn" title="Toggle dark mode" aria-label="Toggle dark mode" class="w-9 h-9 sm:w-10 sm:h-10 shrink-0 inline-flex items-center justify-center rounded-xl bg-slate-100 hover:bg-slate-200 border-2 border-slate-400 text-slate-900 transition">
                    <i id="darkModeToggleIcon" class="fa-solid fa-moon text-sm sm:text-base"></i>
                </button>
                <div class="w-10 h-10 rounded-full bg-emerald-200 text-emerald-950 flex items-center justify-center font-black text-sm border-2 border-emerald-600 shrink-0">
                    <?php echo strtoupper(substr($employee['first_name'] ?? 'F', 0, 1) . substr($employee['last_name'] ?? 'W', 0, 1)); ?>
                </div>
                <div class="hidden sm:block">
                    <p class="text-xs font-black text-slate-950"><?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?></p>
                    <p class="text-[11px] font-black text-slate-700"><?php echo htmlspecialchars(strtoupper($employee['position'] ?? 'INFORMATION TECHNOLOGY')); ?></p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-3 sm:p-4 lg:p-8 space-y-4 sm:space-y-6">
            <?php if($error_msg): ?>
                <div class="p-4 bg-red-100 text-red-950 border-2 border-red-600 rounded-xl text-sm font-black flex items-center">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg shrink-0"></i> <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="p-4 bg-emerald-100 text-emerald-950 border-2 border-emerald-600 rounded-xl text-sm font-black flex items-center">
                    <i class="fa-solid fa-circle-check mr-3 text-lg shrink-0"></i> <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if(!empty($fetch_warnings)): ?>
                <div class="p-4 bg-amber-100 text-amber-950 border-2 border-amber-600 rounded-xl text-sm font-black flex items-start">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg shrink-0 mt-0.5"></i>
                    <span><?php echo htmlspecialchars(implode(' ', $fetch_warnings)); ?> Please refresh the page; if this keeps happening, contact admin.</span>
                </div>
            <?php endif; ?>

            <!-- DASHBOARD TAB -->
            <div id="section_dashboard" class="dashboard-section space-y-6 <?php echo $active_tab=='dashboard'?'':'hidden'; ?>">
                <div class="bg-gradient-to-r from-emerald-700 to-teal-800 rounded-2xl p-4 sm:p-5 lg:p-6 text-white shadow-lg border-2 border-emerald-950 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h2 class="text-lg sm:text-xl lg:text-2xl font-black text-white">WELCOME BACK, <?php echo htmlspecialchars($employee['first_name'] ?? 'Worker'); ?>! 👋</h2>
                        <p class="text-xs text-emerald-100 mt-1">Here is your comprehensive work performance, biometric mapping, and attendance records from the admin database.</p>
                    </div>
                    <div class="flex flex-row md:flex-col items-center md:items-end gap-2 w-full md:w-auto">
                        <div class="bg-black/40 backdrop-blur-md px-4 py-2.5 rounded-xl border-2 border-white/40 text-left md:text-right flex-1 md:flex-none md:w-auto">
                            <div class="text-xs font-black text-white font-mono"><?php echo date('h:i:s A'); ?></div>
                            <div class="text-[10px] font-black text-emerald-200"><?php echo date('l, F j, Y'); ?></div>
                        </div>
                        <?php echo refresh_button_html('dashboard'); ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-5 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-700 uppercase">Total Workdays</p>
                            <h3 class="text-2xl font-black text-slate-950 mt-1"><?php echo htmlspecialchars($employee['workdays'] ?? 0); ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center text-lg border-2 border-emerald-600">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                    </div>
                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-5 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-700 uppercase">Total Absents</p>
                            <h3 class="text-2xl font-black text-red-700 mt-1"><?php echo htmlspecialchars($employee['total_absents'] ?? 0); ?></h3>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-red-100 text-red-800 flex items-center justify-center text-lg border-2 border-red-600">
                            <i class="fa-solid fa-calendar-xmark"></i>
                        </div>
                    </div>
                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-5 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-700 uppercase">Total Workhours</p>
                            <h3 class="text-2xl font-black text-slate-950 mt-1"><?php echo htmlspecialchars($employee['workhours'] ?? 0); ?> hrs</h3>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-teal-100 text-teal-800 flex items-center justify-center text-lg border-2 border-teal-600">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                    </div>
                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-5 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-slate-700 uppercase">Paid Leave Balance</p>
                            <h3 class="text-2xl font-black text-emerald-800 mt-1"><?php echo htmlspecialchars($employee['paid_leave_balance'] ?? 0); ?> days</h3>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center text-lg border-2 border-emerald-600">
                            <i class="fa-solid fa-umbrella-beach"></i>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                        <h3 class="text-base font-black text-slate-950 mb-3"><i class="fa-solid fa-fingerprint text-emerald-700 mr-2"></i>Biometric & Salary Configurations</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="bg-slate-50 border-2 border-slate-300 rounded-xl p-4">
                                <span class="text-[10px] font-black uppercase text-slate-700 block mb-1">Biometric ID (F01H)</span>
                                <span class="text-sm font-black text-slate-950 font-mono"><?php echo htmlspecialchars($employee['fingerprint_id'] ?? ($employee['f01h'] ?? 'Not Assigned')); ?></span>
                            </div>
                            <div class="bg-slate-50 border-2 border-slate-300 rounded-xl p-4">
                                <span class="text-[10px] font-black uppercase text-slate-700 block mb-1">Biometric ID (Hikvision)</span>
                                <span class="text-sm font-black text-slate-950 font-mono"><?php echo htmlspecialchars($employee['fingerprint_id'] ?? ($employee['hikvision'] ?? 'Not Assigned')); ?></span>
                            </div>
                            <div class="bg-slate-50 border-2 border-slate-300 rounded-xl p-4">
                                <span class="text-[10px] font-black uppercase text-slate-700 block mb-1">Daily Salary Rate</span>
                                <span class="text-sm font-black text-emerald-800 font-mono"><?php echo isset($employee['daily_salary']) ? '₱' . number_format($employee['daily_salary'], 2) : '₱0.00'; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                        <h3 class="text-base font-black text-slate-950 mb-2">Quick Navigation</h3>
                        <p class="text-xs font-black text-slate-700 mb-4">Use the buttons below to manage your site attendance and requests.</p>
                        <div class="flex flex-wrap gap-3">
                            <button onclick="switchTab('overview')" class="px-4 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-emerald-950 flex items-center space-x-2">
                                <i class="fa-solid fa-paper-plane"></i><span>Send Site Attendance</span>
                            </button>
                            <button onclick="switchTab('my_attendance')" class="px-4 py-2.5 bg-teal-700 hover:bg-teal-800 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-teal-950 flex items-center space-x-2">
                                <i class="fa-solid fa-calendar-days"></i><span>My Attendance</span>
                            </button>
                            <button onclick="switchTab('requests')" class="px-4 py-2.5 bg-slate-950 hover:bg-slate-850 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-file-invoice-dollar"></i><span>Leave, OT, Cash Advance & Attendance Correction</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- OVERVIEW / SEND ATTENDANCE TAB -->
            <div id="section_overview" class="dashboard-section space-y-6 <?php echo $active_tab=='overview'?'':'hidden'; ?>">
                <div class="bg-gradient-to-r from-emerald-700 to-teal-800 rounded-2xl p-4 sm:p-5 lg:p-6 text-white shadow-lg border-2 border-emerald-950 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h2 class="text-lg sm:text-xl lg:text-2xl font-black text-white">SEND SITE ATTENDANCE, <?php echo htmlspecialchars($employee['first_name'] ?? 'Worker'); ?>! 🏗️</h2>
                        <p class="text-xs text-emerald-100 mt-1">Capture or browse your site image proof to submit your attendance update directly to the admin database.</p>
                    </div>
                    <div class="flex flex-row md:flex-col items-center md:items-end gap-2 w-full md:w-auto">
                        <div class="bg-black/40 backdrop-blur-md px-4 py-2.5 rounded-xl border-2 border-white/40 text-left md:text-right flex-1 md:flex-none md:w-auto">
                            <div id="realtime-clock" class="text-xs font-black text-white font-mono">00:00:00 AM</div>
                            <div id="realtime-date" class="text-[10px] font-black text-emerald-200">Loading date...</div>
                        </div>
                        <?php echo refresh_button_html('overview'); ?>
                    </div>
                </div>

                <div class="bg-white border-2 border-emerald-600 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                    <h3 class="text-base font-black text-slate-950 mb-1"><i class="fa-solid fa-camera-retro text-emerald-700 mr-2"></i>Send Site Attendance Image Proof</h3>
                    <p class="text-xs font-black text-slate-800 mb-4">Capture a live photo using your camera or browse existing image files to submit attendance.</p>

                    <form id="attendanceForm" method="POST" action="<?php echo $current_script; ?>?tab=overview" enctype="multipart/form-data" class="space-y-4 max-w-xl" onsubmit="confirmSubmitAttendance(event)">
                        <input type="hidden" name="action" value="submit_field_attendance">
                        
                        <div>
                            <label class="block text-xs font-black text-slate-950 mb-2">CAPTURE OR BROWSE SITE IMAGE PROOF</label>
                            <input type="file" name="site_photo" id="sitePhotoInput" accept="image/*" class="hidden" onchange="handleFileBrowse(this)">

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" onclick="openCameraModal()" class="flex-1 sm:flex-none px-4 py-2.5 bg-slate-950 hover:bg-slate-850 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-slate-800 flex items-center justify-center space-x-2">
                                    <i class="fa-solid fa-camera"></i><span>Open Camera</span>
                                </button>
                                <button type="button" onclick="document.getElementById('sitePhotoInput').click()" class="flex-1 sm:flex-none px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-950 text-xs font-black rounded-xl transition border-2 border-slate-400 flex items-center justify-center space-x-2">
                                    <i class="fa-solid fa-folder-open"></i><span>Browse File</span>
                                </button>
                            </div>
                            <p id="photo-status-label" class="text-xs text-slate-700 font-black mt-2">No image selected or captured yet.</p>

                            <div id="form-thumbnail-preview" class="mt-3 hidden">
                                <span class="block text-[10px] font-black uppercase text-slate-800 mb-1">Image Preview Ready:</span>
                                <div class="w-48 h-32 rounded-xl overflow-hidden border-2 border-emerald-600 bg-slate-950 shadow-sm">
                                    <img id="form-thumbnail-img" src="" alt="Selected Preview" class="w-full h-full object-cover">
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-black text-slate-950 mb-1">ATTENDANCE NOTES / LOCATION DETAILS</label>
                            <textarea name="notes" rows="3" placeholder="e.g. Present at Sector 4 Foundation Site..." required class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950"></textarea>
                        </div>
                        <button type="submit" class="w-full sm:w-auto px-5 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-emerald-950 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-paper-plane"></i><span>Submit Attendance Update</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- SENT PHOTOS HISTORY TAB -->
            <div id="section_sent_photos" class="dashboard-section space-y-6 <?php echo $active_tab=='sent_photos'?'':'hidden'; ?>">
                <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h3 class="text-base font-black text-slate-950"><i class="fa-solid fa-images text-emerald-700 mr-2"></i>Attendance & Updates History</h3>
                        <?php echo refresh_button_html('sent_photos', 'light'); ?>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                            <thead>
                                <tr class="bg-slate-200 border-b-4 border-slate-400 text-slate-950 uppercase text-[10px] font-black">
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Thumbnail</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Description / Notes</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Date & Time Sent</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-300 font-bold">
                                <?php if(empty($attendance_logs)): ?>
                                    <tr><td colspan="4" class="py-6 text-center text-slate-700 font-black">No attendance updates found.</td></tr>
                                <?php else: foreach($attendance_logs as $log): 
                                    $updateId = $log['id'] ?? 0;
                                    $rawImageCol = $log['image'] ?? ($log['site_picture'] ?? '');
                                    
                                    if (!empty($rawImageCol) && strpos($rawImageCol, 'uploads/field_photos/') === false) {
                                        $photoPath = 'uploads/field_photos/' . htmlspecialchars($rawImageCol);
                                    } else {
                                        $photoPath = htmlspecialchars($rawImageCol);
                                    }
                                    
                                    $notesText = htmlspecialchars($log['notes'] ?? 'Field site check-in');
                                    $dateTimeText = htmlspecialchars($log['created_at']);
                                ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200">
                                            <img src="<?php echo $photoPath; ?>" class="w-12 h-12 object-cover rounded-xl border-2 border-slate-400 cursor-pointer" onclick="openPhotoModal('<?php echo $photoPath; ?>', '<?php echo addslashes($notesText); ?>', '<?php echo $dateTimeText; ?>')">
                                        </td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $notesText; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $dateTimeText; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 text-center space-x-1 whitespace-nowrap">
                                            <button type="button" onclick="openPhotoModal('<?php echo $photoPath; ?>', '<?php echo addslashes($notesText); ?>', '<?php echo $dateTimeText; ?>')" class="px-3 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl text-[10px] font-black border-2 border-emerald-950 transition inline-flex items-center">
                                                <i class="fa-solid fa-eye mr-1"></i> View
                                            </button>
                                            <button type="button" onclick="confirmDeleteUpdate(<?php echo $updateId; ?>)" class="px-3 py-1.5 bg-red-700 hover:bg-red-800 text-white rounded-xl text-[10px] font-black border-2 border-red-950 transition inline-flex items-center">
                                                <i class="fa-solid fa-trash mr-1"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- MY ATTENDANCE TAB -->
            <div id="section_my_attendance" class="dashboard-section space-y-6 <?php echo $active_tab=='my_attendance'?'':'hidden'; ?>">
                <div class="bg-gradient-to-r from-emerald-700 to-teal-800 rounded-2xl p-4 sm:p-5 lg:p-6 text-white shadow-lg border-2 border-emerald-950 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h2 class="text-lg sm:text-xl lg:text-2xl font-black text-white">MY ATTENDANCE</h2>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap justify-end">
                        <div class="flex items-center space-x-2 bg-black/40 backdrop-blur-md px-4 py-2.5 rounded-xl border-2 border-white/40">
                            <span id="big-calendar-month-year" class="text-sm font-black text-white">Month Year</span>
                            <div class="flex space-x-1 ml-4">
                                <button type="button" onclick="changeCalendarMonth(-1)" class="w-7 h-7 bg-white/20 hover:bg-white/30 rounded-lg text-xs flex items-center justify-center font-black border border-white/40"><i class="fa-solid fa-chevron-left"></i></button>
                                <button type="button" onclick="changeCalendarMonth(1)" class="w-7 h-7 bg-white/20 hover:bg-white/30 rounded-lg text-xs flex items-center justify-center font-black border border-white/40"><i class="fa-solid fa-chevron-right"></i></button>
                            </div>
                        </div>
                        <?php echo refresh_button_html('my_attendance'); ?>
                    </div>
                </div>

                <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                    <div id="big-calendar-days-grid" class="grid grid-cols-7 gap-1 sm:gap-2"></div>
                </div>

                <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                    <h3 class="text-base font-black text-slate-950 mb-4"><i class="fa-solid fa-fingerprint text-emerald-700 mr-2"></i>Official Attendance Records (from Admin / Biometric Device)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                            <thead>
                                <tr class="bg-slate-200 border-b-4 border-slate-400 text-slate-950 uppercase text-[10px] font-black">
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Date</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Check-In</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Check-Out</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Work Hours</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Overtime</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Tardy</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-300 font-bold">
                                <?php if(empty($attendance_records)): ?>
                                    <tr><td colspan="7" class="py-6 text-center text-slate-700 font-black">No official attendance records found yet. These are populated by the admin / biometric device sync.</td></tr>
                                <?php else: foreach($attendance_records as $rec):
                                    $recDate = htmlspecialchars(substr($rec['check_in'] ?? $rec['timestamp'] ?? $rec['date'] ?? '', 0, 10));
                                    $recIn = htmlspecialchars($rec['check_in'] ?? '') ?: '—';
                                    $recOut = htmlspecialchars($rec['check_out'] ?? '') ?: '—';
                                    $recHours = isset($rec['workhours']) ? number_format((float)$rec['workhours'], 2) . ' hrs' : '—';
                                    $recOT = isset($rec['overtime_hours']) && (float)$rec['overtime_hours'] > 0 ? number_format((float)$rec['overtime_hours'], 2) . ' hrs' : '—';
                                    $recTardy = !empty($rec['tardy']) ? (($rec['tardy_minutes'] ?? 0) . ' min') : 'On time';
                                    // Same Present / Late / Absent logic used by the attendance calendar
                                    // badges below, so the table and the calendar always agree.
                                    $statusMeta = attendance_status_meta($rec);
                                    $recStatus = htmlspecialchars($statusMeta['label']);
                                ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 font-black text-slate-950"><?php echo $recDate ?: 'N/A'; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $recIn; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $recOut; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $recHours; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $recOT; ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo htmlspecialchars($recTardy); ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4">
                                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-black <?php echo $statusMeta['classes']; ?>">
                                                <?php echo $recStatus; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- LEAVE, OT & CASH ADVANCE TAB -->
            <div id="section_requests" class="dashboard-section space-y-6 <?php echo $active_tab=='requests'?'':'hidden'; ?>">
                <div class="bg-gradient-to-r from-emerald-700 to-teal-800 rounded-2xl p-4 sm:p-5 lg:p-6 text-white shadow-lg border-2 border-emerald-950 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <h2 class="text-lg sm:text-xl lg:text-2xl font-black text-white">LEAVE, OVERTIME & CASH ADVANCE REQUESTS</h2>
                        <p class="text-xs text-emerald-100 mt-1">Submit or edit your requests for admin approval.</p>
                    </div>
                    <?php echo refresh_button_html('requests'); ?>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                        <h3 id="requestFormTitle" class="text-base font-black text-slate-950 mb-4"><i class="fa-solid fa-file-pen text-emerald-700 mr-2"></i>Submit New Request (Leave, OT, Cash Advance, Attendance Correction)</h3>
                        <form method="POST" action="<?php echo $current_script; ?>?tab=requests" class="space-y-4">
                            <input type="hidden" name="action" value="submit_request">
                            <input type="hidden" name="request_id" id="requestIdInput" value="">

                            <div>
                                <label class="block text-xs font-black text-slate-950 mb-1">REQUEST CATEGORY</label>
                                <select name="request_category" id="requestCategorySelect" required onchange="updateRequestFormFields()" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                    <option value="Leave">Leave Request</option>
                                    <option value="Overtime (OT)">Overtime (OT)</option>
                                    <option value="Cash Advance">Cash Advance</option>
                                    <option value="Attendance Correction">Attendance Correction / Missed Scan</option>
                                </select>
                            </div>

                            <div id="leave_fields_container" class="space-y-3">
                                <div>
                                    <label class="block text-xs font-black text-slate-950 mb-1">LEAVE TYPE</label>
                                    <select name="leave_type" id="leaveTypeSelect" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                        <option value="" disabled selected>Select leave type</option>
                                        <option value="Sick Leave">Sick Leave</option>
                                        <option value="Vacation Leave">Vacation Leave</option>
                                        <option value="Emergency Leave">Emergency Leave</option>
                                    </select>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-black text-slate-950 mb-1">START DATE</label>
                                        <input type="date" name="start_date" id="requestStartDateInput" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-black text-slate-950 mb-1">END DATE</label>
                                        <input type="date" name="end_date" id="requestEndDateInput" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                    </div>
                                </div>
                            </div>

                            <div id="ot_fields_container" class="grid grid-cols-1 sm:grid-cols-2 gap-3 hidden">
                                <div>
                                    <label class="block text-xs font-black text-slate-950 mb-1">OT DATE</label>
                                    <input type="date" name="ot_start_date" id="requestOtDateInput" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                </div>
                                <div>
                                    <label class="block text-xs font-black text-slate-950 mb-1">HOW MANY HOURS</label>
                                    <input type="number" step="0.5" min="0.5" name="hours" id="requestHoursInput" placeholder="e.g. 2" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                </div>
                            </div>

                            <div id="cash_fields_container" class="hidden">
                                <label class="block text-xs font-black text-slate-950 mb-1">HOW MUCH MONEY (₱)</label>
                                <input type="number" step="0.01" min="1" name="amount" id="requestAmountInput" placeholder="e.g. 1500.00" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                            </div>

                            <div id="attendance_fields_container" class="space-y-3 hidden">
                                <div>
                                    <label class="block text-xs font-black text-slate-950 mb-1">DATE OF MISSED SCAN</label>
                                    <input type="date" name="missed_scan_date" id="requestMissedScanDateInput" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-black text-slate-950 mb-1">SCAN TYPE</label>
                                        <select name="scan_type" id="requestScanTypeSelect" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                            <option value="" disabled selected>Select scan type</option>
                                            <option value="Check In">Check In</option>
                                            <option value="Check Out">Check Out</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-black text-slate-950 mb-1">CORRECT TIME</label>
                                        <input type="time" name="correct_time" id="requestCorrectTimeInput" class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-black text-slate-950 mb-1">NOTES / DETAILS</label>
                                <textarea name="notes" id="requestNotesInput" rows="3" placeholder="Additional details... (e.g. biometric device offline, forgotten card)" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950"></textarea>
                            </div>

                            <div class="flex space-x-2">
                                <button type="submit" id="requestSubmitBtn" class="flex-1 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-emerald-950">Submit Request</button>
                                <button type="button" onclick="resetRequestForm()" class="px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border-2 border-slate-500">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <div class="lg:col-span-2 bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                        <h3 class="text-base font-black text-slate-950 mb-4"><i class="fa-solid fa-list-check text-emerald-700 mr-2"></i>My Requests History & Status</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                                <thead>
                                    <tr class="bg-slate-200 border-b-4 border-slate-400 text-slate-950 uppercase text-[10px] font-black">
                                        <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Category</th>
                                        <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Details / Value</th>
                                        <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Notes / Details</th>
                                        <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Status</th>
                                        <th class="py-2 px-2 sm:py-3 sm:px-4 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y-2 divide-slate-300 font-bold">
                                    <?php if(empty($employee_requests)): ?>
                                        <tr><td colspan="5" class="py-6 text-center text-slate-700 font-black">No requests submitted yet.</td></tr>
                                    <?php else: foreach($employee_requests as $req): 
                                        $reqId = $req['id'];
                                        $cat = htmlspecialchars($req['type']);
                                        $sDate = htmlspecialchars($req['start_date'] ?? '');
                                        $eDate = htmlspecialchars($req['end_date'] ?? '');
                                        $hrs = htmlspecialchars($req['hours'] ?? '');
                                        $amt = htmlspecialchars($req['amount'] ?? '');
                                        $scanType = htmlspecialchars($req['scan_type'] ?? '');
                                        $correctTime = htmlspecialchars($req['correct_time'] ?? '');
                                        $details = htmlspecialchars($req['details'] ?? '');
                                        $stat = htmlspecialchars($req['status']);
                                    ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 font-black text-emerald-800">
                                                <?php echo $cat; ?>
                                            </td>
                                            <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950">
                                                <?php 
                                                    if ($cat === 'Leave') {
                                                        echo $sDate . ' to ' . $eDate;
                                                    } elseif ($cat === 'Overtime (OT)') {
                                                        echo $sDate . ' (' . $hrs . ' hrs)';
                                                    } elseif ($cat === 'Cash Advance') {
                                                        echo '₱' . number_format((float)$amt, 2);
                                                    } elseif ($cat === 'Attendance Correction') {
                                                        echo $sDate . ' - ' . $scanType . ' &rarr; ' . $correctTime;
                                                    } else {
                                                        echo $sDate ? $sDate : 'N/A';
                                                    }
                                                ?>
                                            </td>
                                            <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950"><?php echo $details ?: '<span class="text-slate-400 italic">No notes</span>'; ?></td>
                                            <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200">
                                                <span class="px-2.5 py-1 rounded-lg text-[10px] font-black <?php echo $stat=='Approved'?'bg-emerald-100 text-emerald-950 border-2 border-emerald-600':($stat=='Rejected'?'bg-red-100 text-red-950 border-2 border-red-600':'bg-amber-100 text-amber-950 border-2 border-amber-600'); ?>">
                                                    <?php echo $stat; ?>
                                                </span>
                                            </td>
                                            <td class="py-2 px-2 sm:py-3 sm:px-4 text-center space-x-1 whitespace-nowrap">
                                                <button type="button" onclick="editRequest(<?php echo $reqId; ?>, '<?php echo addslashes($cat); ?>', '<?php echo addslashes($details); ?>', '<?php echo $sDate; ?>', '<?php echo $eDate; ?>', '<?php echo $hrs; ?>', '<?php echo $amt; ?>', '<?php echo addslashes($scanType); ?>', '<?php echo $correctTime; ?>')" class="px-3 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl text-[10px] font-black border-2 border-emerald-950 transition inline-flex items-center">
                                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                                </button>
                                                <button type="button" onclick="confirmDeleteRequest(<?php echo $reqId; ?>)" class="px-3 py-1.5 bg-red-700 hover:bg-red-800 text-white rounded-xl text-[10px] font-black border-2 border-red-950 transition inline-flex items-center">
                                                    <i class="fa-solid fa-trash mr-1"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PAYSLIP TAB -->
            <div id="section_payslip" class="dashboard-section space-y-6 <?php echo $active_tab=='payslip'?'':'hidden'; ?>">
                <div class="bg-gradient-to-r from-emerald-700 to-teal-800 rounded-2xl p-4 sm:p-5 lg:p-6 text-white shadow-lg border-2 border-emerald-950 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <h2 class="text-lg sm:text-xl lg:text-2xl font-black text-white">MY PAYSLIPS</h2>
                        <p class="text-xs text-emerald-100 mt-1">Fetched automatically when Admin marks payroll as paid. Click any row to view full transparency summary.</p>
                    </div>
                    <?php echo refresh_button_html('payslip'); ?>
                </div>

                <div class="bg-white border-2 border-slate-400 rounded-2xl p-4 sm:p-5 lg:p-6 shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                            <thead>
                                <tr class="bg-slate-200 border-b-4 border-slate-400 text-slate-950 uppercase text-[10px] font-black">
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Payroll Period</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Gross Pay</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Deductions</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Net Pay</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-300">Status</th>
                                    <th class="py-2 px-2 sm:py-3 sm:px-4 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-300 font-bold">
                                <?php if(empty($payslips)): ?>
                                    <tr><td colspan="6" class="py-6 text-center text-slate-700 font-black">No paid payroll records available yet. Once the admin marks your payroll as paid, it will appear here.</td></tr>
                                <?php else: foreach($payslips as $pay): 
                                    $periodText = trim(($pay['pay_period_start'] ?? '') . ' to ' . ($pay['pay_period_end'] ?? '')) ?: ($pay['period'] ?? 'N/A');
                                    $statusText = $pay['status'] ?? 'Paid Payout';
                                    $jsonPayload = htmlspecialchars(json_encode($pay), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr class="hover:bg-emerald-50/60 cursor-pointer transition" onclick='openPayslipModal(<?php echo $jsonPayload; ?>)'>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950 underline decoration-emerald-600 underline-offset-2"><?php echo htmlspecialchars($periodText); ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950">₱<?php echo number_format($pay['gross_pay'] ?? 0, 2); ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-slate-950">₱<?php echo number_format($pay['total_deductions'] ?? 0, 2); ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200 text-emerald-800 font-black">₱<?php echo number_format($pay['net_pay'] ?? 0, 2); ?></td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 border-r-2 border-slate-200">
                                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-black bg-emerald-100 text-emerald-950 border-2 border-emerald-600">
                                                <?php echo htmlspecialchars($statusText); ?>
                                            </span>
                                        </td>
                                        <td class="py-2 px-2 sm:py-3 sm:px-4 text-center" onclick="event.stopPropagation()">
                                            <?php if(!empty($pay['file_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($pay['file_path']); ?>" target="_blank" class="px-3 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl text-[10px] font-black border-2 border-emerald-950 transition inline-flex items-center">
                                                    <i class="fa-solid fa-download mr-1"></i> Download
                                                </a>
                                            <?php else: ?>
                                                <button type="button" onclick='openPayslipModal(<?php echo $jsonPayload; ?>)' class="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-[10px] font-black border-2 border-slate-950 transition inline-flex items-center">
                                                    <i class="fa-solid fa-file-lines mr-1"></i> View Summary
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ACCOUNT SETTINGS TAB -->
            <div id="section_account_settings" class="dashboard-section space-y-6 <?php echo $active_tab=='account_settings'?'':'hidden'; ?>">
                <div class="bg-white border-4 border-slate-900 rounded-3xl p-6 lg:p-8 shadow-sm">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center text-lg shadow-sm border-2 border-blue-950 shrink-0">
                                <i class="fa-solid fa-id-card"></i>
                            </div>
                            <div>
                                <h2 class="text-base font-black text-slate-950">Edit Personal & Account Information</h2>
                                <p class="text-xs text-slate-800 font-bold">Make changes directly to your personal details below. Saving will update your profile and notify the admin automatically.</p>
                            </div>
                        </div>
                        <?php echo refresh_button_html('account_settings', 'light'); ?>
                    </div>

                    <form method="POST" action="<?php echo $current_script; ?>?tab=account_settings" class="mt-6 space-y-6">
                        <input type="hidden" name="action" value="update_account_settings">

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            
                            <div class="bg-white border-2 border-slate-800 rounded-2xl p-4 space-y-4 shadow-xs">
                                <h3 class="text-[11px] font-black text-slate-950 tracking-wider uppercase border-b-2 border-slate-800 pb-2">Personal Information</h3>
                                
                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Full Name (Read-Only)</label>
                                    <div class="w-full px-3 py-2 bg-slate-100 border-2 border-slate-700 rounded-xl text-xs font-black text-slate-950">
                                        <?php echo htmlspecialchars(trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''))); ?>
                                    </div>
                                    <input type="hidden" name="first_name" value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>">
                                    <input type="hidden" name="last_name" value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Gender (Read-Only)</label>
                                    <div class="w-full px-3 py-2 bg-slate-100 border-2 border-slate-700 rounded-xl text-xs font-black text-slate-950">
                                        <?php echo htmlspecialchars($employee['gender'] ?? 'Female'); ?>
                                    </div>
                                    <input type="hidden" name="gender" value="<?php echo htmlspecialchars($employee['gender'] ?? 'Female'); ?>">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Contact Number</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Home Address</label>
                                    <textarea name="address" rows="3" class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition resize-none"><?php echo htmlspecialchars($employee['street_address'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="bg-white border-2 border-slate-800 rounded-2xl p-4 space-y-4 shadow-xs">
                                <h3 class="text-[11px] font-black text-slate-950 tracking-wider uppercase border-b-2 border-slate-800 pb-2">Employment Details</h3>
                                
                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Job Position / Title</label>
                                    <div class="text-xs font-black text-slate-950 uppercase pt-1">
                                        <?php echo htmlspecialchars($employee['position'] ?? 'developer'); ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Assigned Department</label>
                                    <div class="text-xs font-black text-slate-950 uppercase pt-1">
                                        <?php echo htmlspecialchars($employee['department'] ?? 'INFORMATION TECHNOLOGY'); ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Employment Type</label>
                                    <div class="text-xs font-black text-slate-950 pt-1">
                                        <?php echo htmlspecialchars($employee['employment_type'] ?? 'Regular / Full-time'); ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Date Hired</label>
                                    <div class="text-xs font-black text-slate-950 pt-1">
                                        <?php echo htmlspecialchars($employee['date_hired'] ?? 'Aug 28, 2026'); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white border-2 border-slate-800 rounded-2xl p-4 space-y-4 shadow-xs">
                                <h3 class="text-[11px] font-black text-slate-950 tracking-wider uppercase border-b-2 border-slate-800 pb-2">Emergency Details</h3>
                                
                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Contact Person</label>
                                    <input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($employee['emergency_name'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Relationship</label>
                                    <input type="text" name="relationship" value="<?php echo htmlspecialchars($employee['emergency_relation'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Emergency Phone</label>
                                    <input type="text" name="emergency_contact_phone" value="<?php echo htmlspecialchars($employee['emergency_phone'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition">
                                </div>
                            </div>

                            <div class="bg-white border-2 border-slate-800 rounded-2xl p-4 space-y-4 shadow-xs">
                                <h3 class="text-[11px] font-black text-slate-950 tracking-wider uppercase border-b-2 border-slate-800 pb-2">Salary & Credentials</h3>
                                
                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Daily Salary Rate</label>
                                    <div class="text-sm font-black text-emerald-700 font-mono pt-0.5">
                                        ₱<?php echo number_format($employee['daily_salary_rate'] ?? 600.00, 2); ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">Portal Login Email</label>
                                    <input type="email" name="username" value="<?php echo htmlspecialchars($employee['username'] ?? ($employee['email'] ?? '')); ?>" required class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">New Password (Optional)</label>
                                    <input type="password" name="new_password" placeholder="••••••••" class="w-full px-3 py-2 bg-white border-2 border-slate-800 focus:border-blue-600 rounded-xl text-xs font-black text-slate-950 outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-950 uppercase mb-1">F01H Biometric ID</label>
                                    <div class="text-xs font-black text-slate-950 font-mono pt-1">
                                        <?php echo htmlspecialchars($employee['biometric_id_f01h'] ?? ($employee['f01h'] ?? '#886')); ?>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <div class="flex justify-end pt-4 border-t-2 border-slate-800">
                            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white text-xs font-black rounded-xl transition shadow-md border-2 border-blue-950 flex items-center space-x-2">
                                <i class="fa-solid fa-floppy-disk"></i><span>Save Changes & Notify Admin</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <div id="dateDetailsModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4 hidden">
        <div class="bg-white border-4 border-slate-900 shadow-2xl rounded-3xl max-w-md w-full p-4 sm:p-6 relative font-bold flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center border-b-2 border-slate-300 pb-3 mb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center text-lg border-2 border-emerald-600">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-950">Date Updates & Holidays</h3>
                        <p id="modal_date_title" class="text-xs text-emerald-700 font-black">Date: Loading...</p>
                    </div>
                </div>
                <button type="button" onclick="closeDateDetailsModal()" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 border-2 border-slate-400 text-slate-900"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div id="modal_date_content" class="overflow-y-auto space-y-3 pr-1 text-xs"></div>
            <div class="pt-4 mt-4 border-t-2 border-slate-300">
                <button type="button" onclick="closeDateDetailsModal()" class="w-full py-2.5 rounded-xl bg-slate-950 hover:bg-slate-850 text-white text-xs border-2 border-slate-800 font-black">Close</button>
            </div>
        </div>
    </div>

    <div id="payslipSummaryModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4 hidden">
        <div class="bg-white border-4 border-slate-900 shadow-2xl rounded-3xl max-w-2xl w-full p-4 sm:p-6 relative font-bold flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-center border-b-2 border-slate-300 pb-3 mb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center text-lg border-2 border-emerald-600">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div>
                        <h3 class="text-base lg:text-lg font-black text-slate-950">Payslip Summary Transparency</h3>
                        <p id="modal_period" class="text-xs text-emerald-700 font-black">Period: Loading...</p>
                    </div>
                </div>
                <button type="button" onclick="closePayslipModal()" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 border-2 border-slate-400 text-slate-900"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <div class="overflow-y-auto space-y-4 pr-1 text-xs">
                <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                    <div>
                        <span class="text-[10px] text-slate-600 uppercase block">Days Worked</span>
                        <span id="modal_days_worked" class="text-sm font-black text-slate-950">0</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-600 uppercase block">Absent Days</span>
                        <span id="modal_absent_days" class="text-sm font-black text-red-700">0</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-600 uppercase block">Tardy Count</span>
                        <span id="modal_tardy_count" class="text-sm font-black text-amber-700">0</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-600 uppercase block">Status</span>
                        <span id="modal_status" class="inline-block px-2 py-0.5 mt-0.5 rounded text-[10px] bg-emerald-100 text-emerald-950 border border-emerald-600">Paid</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="bg-white border-2 border-slate-300 rounded-2xl p-4 space-y-2.5">
                        <h4 class="text-xs font-black text-slate-950 uppercase border-b-2 border-slate-200 pb-1.5"><i class="fa-solid fa-plus-circle text-emerald-700 mr-1.5"></i> Earnings Breakdown</h4>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">Basic Pay:</span>
                            <span id="modal_basic_pay" class="font-black text-slate-950">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">Allowance:</span>
                            <span id="modal_allowance" class="font-black text-slate-950">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">Overtime Pay:</span>
                            <span id="modal_overtime_pay" class="font-black text-slate-950">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-slate-600">Holiday Pay:</span>
                            <span id="modal_holiday_pay" class="font-black text-slate-950">₱0.00</span>
                        </div>
                    </div>

                    <div class="bg-white border-2 border-slate-300 rounded-2xl p-4 space-y-2.5">
                        <h4 class="text-xs font-black text-slate-950 uppercase border-b-2 border-slate-200 pb-1.5"><i class="fa-solid fa-minus-circle text-red-700 mr-1.5"></i> Deductions Breakdown</h4>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">Tardy / Late:</span>
                            <span id="modal_tardy_late" class="font-black text-red-700">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">SSS Deduction:</span>
                            <span id="modal_sss_deduction" class="font-black text-red-700">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">PhilHealth:</span>
                            <span id="modal_philhealth_deduction" class="font-black text-red-700">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-600">Pag-IBIG:</span>
                            <span id="modal_pagibig_deduction" class="font-black text-red-700">₱0.00</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-slate-600">Cash Advance:</span>
                            <span id="modal_cash_advance" class="font-black text-red-700">₱0.00</span>
                        </div>
                    </div>
                </div>

                <div class="bg-emerald-50 border-2 border-emerald-600 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div>
                        <span class="text-[10px] text-emerald-800 uppercase block font-black">Total Deductions: <span id="modal_total_deductions" class="text-red-700">₱0.00</span></span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] text-emerald-800 uppercase block font-black">Net Payout Amount</span>
                        <span id="modal_net_pay" class="text-xl font-black text-emerald-950">₱0.00</span>
                    </div>
                </div>
            </div>

            <div class="pt-4 mt-4 border-t-2 border-slate-300">
                <button type="button" onclick="closePayslipModal()" class="w-full py-2.5 rounded-xl bg-slate-950 hover:bg-slate-850 text-white text-xs border-2 border-slate-800 font-black">Close Summary</button>
            </div>
        </div>
    </div>

    <!-- Camera Modal -->
    <div id="cameraModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4 hidden">
        <div class="bg-white border-4 border-slate-900 shadow-2xl rounded-3xl max-w-lg w-full p-4 sm:p-6 relative font-bold flex flex-col">
            <div class="flex justify-between items-center border-b-2 border-slate-300 pb-3 mb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center text-lg border-2 border-emerald-600">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-950">Live Camera Attendance Capture</h3>
                        <p class="text-xs text-emerald-700 font-black">Position yourself at the work site</p>
                    </div>
                </div>
                <button type="button" onclick="closeCameraModal()" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 border-2 border-slate-400 text-slate-900"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <div id="camera-view-container" class="relative rounded-2xl overflow-hidden bg-slate-950 border-2 border-slate-800 aspect-video flex items-center justify-center">
                <video id="camera-preview" autoplay playsinline class="w-full h-full object-cover"></video>
            </div>

            <div id="preview-view-container" class="relative rounded-2xl overflow-hidden bg-slate-950 border-2 border-emerald-600 aspect-video flex items-center justify-center hidden">
                <img id="photo-preview-img" src="" alt="Captured Preview" class="w-full h-full object-cover">
            </div>

            <canvas id="camera-canvas" class="hidden"></canvas>

            <div class="mt-4 flex items-center justify-center">
                <div id="capture-action-btn">
                    <button type="button" onclick="capturePhoto()" class="px-6 py-3 bg-emerald-700 hover:bg-emerald-800 text-white rounded-2xl text-xs font-black border-2 border-emerald-950 shadow-lg flex items-center space-x-2">
                        <i class="fa-solid fa-camera-rotate text-sm"></i><span>Capture Photo Now</span>
                    </button>
                </div>
                <div id="retake-check-action-btns" class="flex space-x-3 w-full hidden">
                    <button type="button" onclick="retakePhoto()" class="flex-1 py-3 bg-slate-200 hover:bg-slate-300 text-slate-950 rounded-xl text-xs font-black border-2 border-slate-500 transition">
                        <i class="fa-solid fa-rotate-right mr-1"></i> Retake Photo
                    </button>
                    <button type="button" onclick="confirmCapturedPhoto()" class="flex-1 py-3 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl text-xs font-black border-2 border-emerald-950 transition">
                        <i class="fa-solid fa-check mr-1"></i> Use This Photo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Photo Modal -->
    <div id="viewPhotoModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop p-4 hidden">
        <div class="bg-white border-4 border-slate-900 shadow-2xl rounded-3xl max-w-md w-full p-4 sm:p-6 relative font-bold flex flex-col">
            <div class="flex justify-between items-center border-b-2 border-slate-300 pb-3 mb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center text-lg border-2 border-emerald-600">
                        <i class="fa-solid fa-image"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-950">Site Attendance Proof</h3>
                        <p id="modalPhotoDateTime" class="text-xs text-emerald-700 font-black">Date & Time</p>
                    </div>
                </div>
                <button type="button" onclick="closePhotoModal()" class="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 border-2 border-slate-400 text-slate-900"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="rounded-2xl overflow-hidden border-2 border-slate-800 bg-slate-950 aspect-video mb-4">
                <img id="modalPhotoImg" src="" alt="Full Size Photo" class="w-full h-full object-cover">
            </div>
            <div class="bg-slate-50 border-2 border-slate-300 rounded-2xl p-3 mb-4">
                <span class="text-[10px] text-slate-600 uppercase block mb-0.5 font-black">Attendance Notes / Details:</span>
                <p id="modalPhotoCaption" class="text-xs font-black text-slate-950"></p>
            </div>
            <button type="button" onclick="closePhotoModal()" class="w-full py-2.5 rounded-xl bg-slate-950 hover:bg-slate-850 text-white text-xs border-2 border-slate-800 font-black">Close</button>
        </div>
    </div>

</body>
</html>