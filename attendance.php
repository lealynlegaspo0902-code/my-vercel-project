<?php
session_start();
date_default_timezone_set('Asia/Manila'); 

// Authentication Guard: Check if the admin is logged in
if (!isset($_SESSION['admin_id'])) {
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

// Fetch System Settings Dynamically from Database
$systemSettings = [];
try {
    $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmtSettings->fetch()) {
        $systemSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

// Define dynamic setting variables with fallback defaults
$defaultCheckIn = $systemSettings['standard_time_in'] ?? $systemSettings['check_in_time'] ?? '08:30:00';
$defaultCheckOut = $systemSettings['standard_time_out'] ?? $systemSettings['check_out_time'] ?? '17:30:00';
$gracePeriodMins = intval($systemSettings['grace_period_minutes'] ?? 15);
$standardWorkHours = floatval($systemSettings['standard_work_hours'] ?? 8.00);

// Ensure field_worker_updates table exists automatically, using 'image' instead of 'site_picture'
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS field_worker_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        update_date DATE NOT NULL,
        check_in_time TIME DEFAULT '$defaultCheckIn',
        check_out_time TIME DEFAULT '$defaultCheckOut',
        image VARCHAR(255) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        is_paid TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(employee_id),
        INDEX(update_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// Corrected safe check for attendance, employees, and field_worker_updates table columns (MySQL compatible)
try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'site_picture'");
    if ($columnCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN site_picture VARCHAR(255) DEFAULT NULL;");
    }
    
    $mimeCheck = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'picture_mime'");
    if ($mimeCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN picture_mime VARCHAR(50) DEFAULT 'image/jpeg';");
    }

    $fwImageCheck = $pdo->query("SHOW COLUMNS FROM field_worker_updates LIKE 'image'");
    if ($fwImageCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE field_worker_updates ADD COLUMN image VARCHAR(255) DEFAULT NULL;");
    }

    // Ensure mapping columns exist in employees table for ID owners
    $f01hColCheck = $pdo->query("SHOW COLUMNS FROM employees LIKE 'f01h_id'");
    if ($f01hColCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN f01h_id VARCHAR(50) DEFAULT NULL;");
    }

    $fpColCheck = $pdo->query("SHOW COLUMNS FROM employees LIKE 'fingerprint_id'");
    if ($fpColCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN fingerprint_id VARCHAR(50) DEFAULT NULL;");
    }
} catch (Exception $e) {}

// Handle Field Worker Status / Approval / Paid Actions & Field Worker Time Edits via POST
$actionMessage = '';
$actionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $actionType = $_POST['action_type'];
    
    if ($actionType === 'approve_field_update') {
        $updateId = intval($_POST['update_id'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM field_worker_updates WHERE id = ? LIMIT 1");
            $stmt->execute([$updateId]);
            $fwRecord = $stmt->fetch();
            
            if ($fwRecord) {
                $resolvedEmpId = $fwRecord['employee_id'];
                $logDateOnly = $fwRecord['update_date'];
                $checkInTimestamp = $logDateOnly . ' ' . $fwRecord['check_in_time'];
                $checkOutTimestamp = $logDateOnly . ' ' . $fwRecord['check_out_time'];
                $sitePicture = $fwRecord['image'] ?? ($fwRecord['site_picture'] ?? '');
                
                $updStmt = $pdo->prepare("UPDATE field_worker_updates SET status = 'Approved' WHERE id = ?");
                $updStmt->execute([$updateId]);
                
                $chkExisting = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND DATE(COALESCE(check_in, check_out, NOW())) = ? LIMIT 1");
                $chkExisting->execute([$resolvedEmpId, $logDateOnly]);
                $existingAtt = $chkExisting->fetch();
                
                $workHours = $standardWorkHours;
                $workDays = 1;
                
                if ($existingAtt) {
                    $updAtt = $pdo->prepare("UPDATE attendance SET check_in = ?, check_out = ?, workhours = ?, workdays = ?, site_picture = COALESCE(?, site_picture), absent = 0 WHERE id = ?");
                    $updAtt->execute([$checkInTimestamp, $checkOutTimestamp, $workHours, $workDays, $sitePicture, $existingAtt['id']]);
                } else {
                    $insAtt = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, check_out, workdays, workhours, overtime_hours, absent, tardy, undertime, site_picture) VALUES (?, ?, ?, ?, ?, 0.00, 0, 0, 0, ?)");
                    $insAtt->execute([$resolvedEmpId, $checkInTimestamp, $checkOutTimestamp, $workDays, $workHours, $sitePicture]);
                }
                
                $actionMessage = "Field update #$updateId successfully approved and attendance records autofilled with image!";
            }
        } catch (Exception $ex) {
            $actionError = "Failed to approve field update: " . $ex->getMessage();
        }
    }
    
    if ($actionType === 'mark_as_paid') {
        $updateId = intval($_POST['update_id'] ?? 0);
        try {
            $paidStmt = $pdo->prepare("UPDATE field_worker_updates SET is_paid = 1, status = 'Paid & Completed' WHERE id = ?");
            $paidStmt->execute([$updateId]);
            $actionMessage = "Field update #$updateId marked as PAID successfully. Attendance & payroll cycles synchronized!";
        } catch (Exception $ex) {
            $actionError = "Failed to mark as paid: " . $ex->getMessage();
        }
    }

    if ($actionType === 'edit_field_update_time') {
        $updateId = intval($_POST['update_id'] ?? 0);
        $newCheckInTime = trim($_POST['check_in_time'] ?? '');
        $newCheckOutTime = trim($_POST['check_out_time'] ?? '');
        $newUpdateDate = trim($_POST['update_date'] ?? '');
        
        try {
            $updTimeStmt = $pdo->prepare("UPDATE field_worker_updates SET update_date = COALESCE(NULLIF(?, ''), update_date), check_in_time = NULLIF(?, ''), check_out_time = NULLIF(?, '') WHERE id = ?");
            $updTimeStmt->execute([$newUpdateDate, $newCheckInTime, $newCheckOutTime, $updateId]);
            $actionMessage = "Field worker update #$updateId date and times updated successfully!";
        } catch (Exception $ex) {
            $actionError = "Failed to update field worker update details: " . $ex->getMessage();
        }
    }
}

// Handle Biometric File Upload & Parsing Logic
$uploadMessage = '';
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['biometric_file'])) {
    if ($_FILES['biometric_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['biometric_file']['tmp_name'];
        $fileName = $_FILES['biometric_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $parsedRows = [];

        if (in_array($fileExtension, ['csv', 'txt', 'dat'])) {
            $fileHandle = fopen($fileTmpPath, 'r');
            while (($line = fgets($fileHandle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                if (stripos($line, 'ID') !== false && stripos($line, 'Name') !== false) {
                    continue;
                }

                if (preg_match('/^(\d+)\s+.*?\s+(\d{2}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', $line, $matches)) {
                    $f01hId = $matches[1];
                    $dateStr = $matches[2];
                    $timeStr = $matches[3];

                    if (strpos($dateStr, '/') !== false) {
                        $dp = explode('/', $dateStr);
                        if (count($dp) === 3) {
                            $dateStr = $dp[2] . '-' . $dp[0] . '-' . $dp[1];
                        }
                    }
                    $parsedRows[] = [$f01hId, $dateStr . ' ' . $timeStr];
                }
            }
            fclose($fileHandle);
        }

        if (!empty($parsedRows)) {
            $importedCount = 0;
            foreach ($parsedRows as $row) {
                $f01hIdentifier = trim($row[0]); 
                $logTimestamp = trim($row[1]);

                if (!empty($f01hIdentifier) && !empty($logTimestamp)) {
                    try {
                        $chkEmp = $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE fingerprint_id = ? OR f01h_id = ? OR id = ? LIMIT 1");
                        $chkEmp->execute([$f01hIdentifier, $f01hIdentifier, $f01hIdentifier]);
                        $empRecord = $chkEmp->fetch();
                        
                        if ($empRecord) {
                            $resolvedEmpId = $empRecord['id'];
                            $logDateOnly = date('Y-m-d', strtotime($logTimestamp));
                            $logHour = intval(date('H', strtotime($logTimestamp)));

                            $isCheckIn = ($logHour < 12);

                            $chkExisting = $pdo->prepare("SELECT id, check_in, check_out FROM attendance WHERE employee_id = ? AND DATE(COALESCE(check_in, check_out, NOW())) = ? LIMIT 1");
                            $chkExisting->execute([$resolvedEmpId, $logDateOnly]);
                            $existingRecord = $chkExisting->fetch();

                            if ($existingRecord) {
                                if ($isCheckIn) {
                                    $stmtUpdateIn = $pdo->prepare("UPDATE attendance SET check_in = ? WHERE id = ?");
                                    $stmtUpdateIn->execute([$logTimestamp, $existingRecord['id']]);
                                    $importedCount++;
                                } else {
                                    $stmtUpdate = $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?");
                                    $stmtUpdate->execute([$logTimestamp, $existingRecord['id']]);
                                    $importedCount++;
                                }
                            } else {
                                if ($isCheckIn) {
                                    $stmtInsert = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, check_out, workdays, workhours, absent, tardy, undertime) VALUES (?, ?, NULL, 0, 0.00, 0, 0, 0)");
                                    $stmtInsert->execute([$resolvedEmpId, $logTimestamp]);
                                } else {
                                    $stmtInsert = $pdo->prepare("INSERT INTO attendance (employee_id, check_in, check_out, workdays, workhours, absent, tardy, undertime) VALUES (?, NULL, ?, 0, 0.00, 0, 0, 0)");
                                    $stmtInsert->execute([$resolvedEmpId, $logTimestamp]);
                                }
                                $importedCount++;
                            }
                        }
                    } catch (Exception $ex) {}
                }
            }
            $uploadMessage = "Successfully synchronized $importedCount attendance records strictly mapped to ID owners!";
        } else {
            $uploadError = "Invalid file structure format or unmatched ID owners.";
        }
    }
}

// Dynamic Tardy & Undertime Calculation Engine
function calculateAttendanceMetrics($checkInStr, $checkOutStr, $logDate, $defaultIn, $defaultOut, $graceMins, $workHoursSetting) {
    $metrics = [
        'tardy' => 0,
        'undertime' => 0,
        'workhours' => 0.00,
        'workdays' => 0
    ];

    $standardInSecs = strtotime($logDate . ' ' . $defaultIn) + ($graceMins * 60);
    $standardOutSecs = strtotime($logDate . ' ' . $defaultOut);

    if (!empty($checkInStr) && $checkInStr !== '0000-00-00 00:00:00') {
        $actualInSecs = strtotime($checkInStr);
        if ($actualInSecs > $standardInSecs) {
            $metrics['tardy'] = round(($actualInSecs - $standardInSecs) / 60);
        }
    }

    if (!empty($checkOutStr) && $checkOutStr !== '0000-00-00 00:00:00' && !empty($checkInStr) && $checkInStr !== '0000-00-00 00:00:00') {
        $actualOutSecs = strtotime($checkOutStr);
        if ($actualOutSecs < $standardOutSecs) {
            $metrics['undertime'] = round(($standardOutSecs - $actualOutSecs) / 60);
        }
        $metrics['workhours'] = floatval($workHoursSetting);
        $metrics['workdays'] = 1;
    }

    return $metrics;
}

function formatTardyDisplay($tardyTime) {
    if (empty($tardyTime) || intval($tardyTime) <= 0) {
        return '<span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-950 font-black border border-emerald-400">On Time</span>';
    }
    return '<span class="px-2 py-0.5 rounded bg-amber-100 text-amber-950 font-black border border-amber-400">' . intval($tardyTime) . ' mins late</span>';
}

function formatUndertimeDisplay($undertimeTime) {
    if (empty($undertimeTime) || intval($undertimeTime) <= 0) {
        return '<span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-950 font-black border border-emerald-400">Complete</span>';
    }
    return '<span class="px-2 py-0.5 rounded bg-rose-100 text-rose-950 font-black border border-rose-400">' . intval($undertimeTime) . ' mins early</span>';
}

// Handle AJAX Request for Attendance Logs focused on ID Owner Records
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $emp_id = $_GET['employee_id'] ?? 0;
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';

    try {
        $query = "SELECT id, check_in, check_out, overtime_hours, absent, site_picture FROM attendance WHERE employee_id = ?";
        $params = [$emp_id];

        if (!empty($start) && !empty($end)) {
            $query .= " AND (DATE(COALESCE(check_in, check_out, NOW())) BETWEEN ? AND ?)";
            array_push($params, $start, $end);
        }

        $query .= " ORDER BY id DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        $formatted_logs = [];
        foreach ($logs as $l) {
            $checkInVal = !empty($l['check_in']) && $l['check_in'] !== '0000-00-00 00:00:00' ? $l['check_in'] : '';
            $checkOutVal = !empty($l['check_out']) && $l['check_out'] !== '0000-00-00 00:00:00' ? $l['check_out'] : '';
            
            $logDateOnly = '';
            if ($checkInVal) {
                $logDateOnly = date('Y-m-d', strtotime($checkInVal));
            } elseif ($checkOutVal) {
                $logDateOnly = date('Y-m-d', strtotime($checkOutVal));
            } else {
                $logDateOnly = date('Y-m-d');
            }

            $calculated = calculateAttendanceMetrics(
                $checkInVal, 
                $checkOutVal, 
                $logDateOnly, 
                $defaultCheckIn, 
                $defaultCheckOut, 
                $gracePeriodMins, 
                $standardWorkHours
            );

            $picHtml = '---';
            if (!empty($l['site_picture'])) {
                $rawImg = trim($l['site_picture']);
                $imgPath = (strpos($rawImg, 'uploads/field_photos/') === false && strpos($rawImg, 'field_photos/') === false && strpos($rawImg, 'http') !== 0) ? 'uploads/field_photos/' . $rawImg : $rawImg;
                $picHtml = '<a href="' . htmlspecialchars($imgPath) . '" target="_blank"><img src="' . htmlspecialchars($imgPath) . '" class="w-10 h-10 object-cover rounded-xl border border-slate-300 shadow-sm hover:scale-105 transition"></a>';
            }
            
            $formatted_logs[] = [
                'id' => $l['id'],
                'raw_check_in' => $checkInVal,
                'raw_check_out' => $checkOutVal,
                'check_in' => $checkInVal ? date('F d, Y - h:i A', strtotime($checkInVal)) : '---',
                'check_out' => $checkOutVal ? date('F d, Y - h:i A', strtotime($checkOutVal)) : '<span class="text-amber-950 font-black">Active / Open</span>',
                'workdays' => $calculated['workdays'],
                'workhours' => $calculated['workhours'],
                'overtime_hours' => floatval($l['overtime_hours']),
                'absent' => intval($l['absent']),
                'absent_html' => intval($l['absent']) === 1 ? '<span class="px-2 py-0.5 rounded bg-purple-100 text-purple-950 font-black border border-purple-400">Absent</span>' : '<span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-950 font-black border border-emerald-400">Present</span>',
                'tardy_html' => formatTardyDisplay($calculated['tardy']),
                'undertime_html' => formatUndertimeDisplay($calculated['undertime']),
                'site_picture_html' => $picHtml
            ];
        }
        echo json_encode($formatted_logs);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

$employees = [];
try {
    $employees = $pdo->query("SELECT id, first_name, last_name, department, fingerprint_id, f01h_id FROM employees ORDER BY last_name ASC")->fetchAll();
} catch (Exception $e) {}

$fieldUpdates = [];
try {
    $fwQuery = "SELECT fu.*, e.first_name, e.last_name, e.department FROM field_worker_updates fu JOIN employees e ON fu.employee_id = e.id ORDER BY fu.id DESC";
    $fieldUpdates = $pdo->query($fwQuery)->fetchAll();
} catch (Exception $e) {}

// Group field updates by full name for the grouped row view
$groupedFieldUpdates = [];
foreach ($fieldUpdates as $fu) {
    $fullName = trim($fu['first_name'] . ' ' . $fu['last_name']);
    if (!isset($groupedFieldUpdates[$fullName])) {
        $groupedFieldUpdates[$fullName] = [
            'first_name' => $fu['first_name'],
            'last_name' => $fu['last_name'],
            'department' => $fu['department'],
            'updates' => []
        ];
    }
    $rawImgCol = $fu['image'] ?? ($fu['site_picture'] ?? '');
    $picPath = !empty($rawImgCol) ? (strpos($rawImgCol, 'uploads/field_photos/') === false && strpos($rawImgCol, 'field_photos/') === false ? 'uploads/field_photos/' . $rawImgCol : $rawImgCol) : '';
    $fu['image_url'] = $picPath;
    
    $groupedFieldUpdates[$fullName]['updates'][] = $fu;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-200">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Attendance & ID Owner Records</title>
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
        @media print {
            body * { visibility: hidden; }
            #printableAttendance, #printableAttendance * { visibility: visible; }
            #printableAttendance { position: absolute; left: 0; top: 0; width: 100%; margin: 0; padding: 10px; background: white !important; }
            .no-print { display: none !important; }
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
<body class="h-full font-sans antialiased flex text-slate-950 overflow-x-hidden" onload="initRealtimeClock()">

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
                <a href="attendance.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
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

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-8 shrink-0 shadow-sm">
            <div class="flex items-center space-x-3">
                <h1 class="text-xl font-black text-slate-950 tracking-tight">Attendance & ID Owner Management</h1>
            </div>
            
            <div class="flex items-center space-x-3">
                <div class="flex items-center space-x-2 bg-slate-100 px-4 py-2 rounded-xl border-2 border-slate-300 text-xs font-black text-slate-950 shadow-sm">
                    <i class="fa-regular fa-clock text-blue-700 font-bold"></i>
                    <span id="realtimeClock">Loading Date & Time...</span>
                </div>

                <form action="attendance.php" method="POST" enctype="multipart/form-data" class="inline-flex items-center m-0">
                    <input type="file" name="biometric_file" id="biometricFileField" class="hidden" accept=".dat,.txt,.csv" onchange="this.form.submit()">
                    <button type="button" onclick="document.getElementById('biometricFileField').click();" class="bg-emerald-700 hover:bg-emerald-800 text-white font-black text-xs px-4 py-2.5 rounded-xl transition shadow-md border border-emerald-500 flex items-center space-x-2">
                        <i class="fa-solid fa-file-arrow-up font-bold"></i>
                        <span>Import Biometric Log</span>
                    </button>
                </form>

                <button onclick="openFieldUpdatesModal()" class="bg-indigo-700 hover:bg-indigo-800 text-white font-black text-xs px-4 py-2.5 rounded-xl transition shadow-md border border-indigo-500 flex items-center space-x-2">
                    <i class="fa-solid fa-camera-retro font-bold"></i>
                    <span>Field Updates (<?php echo count($fieldUpdates); ?>)</span>
                </button>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-8 space-y-6 bg-slate-100">
            <?php if (!empty($uploadMessage) || !empty($actionMessage)): ?>
                <div class="bg-emerald-100 border-2 border-emerald-400 text-emerald-950 px-4 py-3 rounded-xl text-xs font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-check text-emerald-700 mr-3 text-sm font-bold"></i> <?php echo $uploadMessage ?: $actionMessage; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($uploadError) || !empty($actionError)): ?>
                <div class="bg-red-100 border-2 border-red-400 text-red-950 px-4 py-3 rounded-xl text-xs font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-exclamation text-red-700 mr-3 text-sm font-bold"></i> <?php echo $uploadError ?: $actionError; ?>
                </div>
            <?php endif; ?>

            <div class="bg-white border-2 border-slate-300 rounded-2xl overflow-hidden shadow-md">
                <div class="p-4 border-b-2 border-slate-200 bg-slate-50 flex items-center justify-between flex-wrap gap-3">
                    <span class="text-xs font-black uppercase tracking-wider text-slate-900">Select ID Owner to View Attendance Record Logs (Click any row)</span>
                    <div class="flex items-center space-x-2">
                        <span class="text-xs bg-blue-100 text-blue-950 font-black px-3 py-1 rounded-full border-2 border-blue-400 shadow-sm">Total ID Owners: <?php echo count($employees); ?></span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm min-w-[600px]">
                        <thead>
                            <tr class="bg-slate-200 border-b-2 border-slate-300 text-slate-950 uppercase text-[11px] font-black tracking-wider">
                                <th class="py-4 px-6">ID Owner Name</th>
                                <th class="py-4 px-6">Department</th>
                                <th class="py-4 px-6">ID / Fingerprint Mapping</th>
                                <th class="py-4 px-6 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-200 text-slate-900 font-semibold">
                            <?php if(empty($employees)): ?>
                                <tr><td colspan="4" class="py-12 text-center text-slate-600 font-bold text-xs">No ID owners found.</td></tr>
                            <?php else: ?>
                                <?php foreach($employees as $emp): ?>
                                    <tr onclick="openAttendanceModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($emp['department'], ENT_QUOTES); ?>')" class="hover:bg-blue-50 transition cursor-pointer">
                                        <td class="py-4 px-6 font-black text-slate-950 flex items-center space-x-3">
                                            <div class="w-8 h-8 rounded-full bg-blue-200 text-blue-950 flex items-center justify-center font-black text-xs border-2 border-blue-400 shadow-sm shrink-0">
                                                <?php echo strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1)); ?>
                                            </div>
                                            <span class="truncate"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></span>
                                        </td>
                                        <td class="py-4 px-6 text-xs text-slate-900 font-bold"><?php echo htmlspecialchars($emp['department']); ?></td>
                                        <td class="py-4 px-6 text-xs font-mono font-black text-blue-800">
                                            <?php 
                                                $displayId = !empty($emp['f01h_id']) ? $emp['f01h_id'] : (!empty($emp['fingerprint_id']) ? $emp['fingerprint_id'] : '');
                                                echo !empty($displayId) ? htmlspecialchars($displayId) : '<span class="text-slate-600 italic">Not Linked</span>'; 
                                            ?>
                                        </td>
                                        <td class="py-4 px-6 text-right space-x-2">
                                            <button onclick="event.stopPropagation(); openAttendanceModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($emp['department'], ENT_QUOTES); ?>')" class="inline-flex items-center px-3 py-1.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-black rounded-xl transition shadow-sm border border-blue-500 whitespace-nowrap">
                                                <i class="fa-solid fa-calendar-check mr-1.5 font-bold"></i> Get Records
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

    <!-- Field Worker Updates Modal (Grouped by Same Name Owner) -->
    <div id="fieldUpdatesModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-5xl w-full shadow-2xl border-2 border-slate-400 overflow-hidden flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b-2 border-slate-200 flex justify-between items-center bg-slate-50 shrink-0">
                <h3 class="text-lg font-black text-slate-950"><i class="fa-solid fa-camera-retro text-indigo-700 mr-2 font-bold"></i> Field Worker On-Site Submissions (Grouped by Owner)</h3>
                <button onclick="closeFieldUpdatesModal()" class="text-slate-800 hover:text-slate-950 font-bold border-2 border-slate-300 p-2 rounded-xl hover:bg-slate-200 transition"><i class="fa-solid fa-xmark text-lg font-bold"></i></button>
            </div>

            <div class="p-6 overflow-y-auto flex-1 space-y-4">
                <div class="border-2 border-slate-300 rounded-2xl overflow-hidden shadow-sm">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-200 border-b-2 border-slate-300 text-slate-950 uppercase text-[10px] font-black">
                                <th class="py-3 px-4">Owner Name</th>
                                <th class="py-3 px-4">Department</th>
                                <th class="py-3 px-4">Total Updates</th>
                                <th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-200 text-slate-900 font-semibold">
                            <?php if(empty($groupedFieldUpdates)): ?>
                                <tr><td colspan="4" class="py-8 text-center text-slate-600 font-bold">No field updates available.</td></tr>
                            <?php else: ?>
                                <?php foreach($groupedFieldUpdates as $fullName => $group): ?>
                                    <tr onclick="toggleOwnerUpdatesRow('<?php echo md5($fullName); ?>')" class="hover:bg-indigo-50 transition cursor-pointer bg-slate-50">
                                        <td class="py-3 px-4 font-black text-slate-950 flex items-center space-x-2">
                                            <i class="fa-solid fa-chevron-right text-slate-500 text-xs transition-transform" id="icon-<?php echo md5($fullName); ?>"></i>
                                            <span><?php echo htmlspecialchars($fullName); ?></span>
                                        </td>
                                        <td class="py-3 px-4 text-slate-900 font-bold"><?php echo htmlspecialchars($group['department'] ?? 'N/A'); ?></td>
                                        <td class="py-3 px-4 font-mono font-black text-indigo-700"><?php echo count($group['updates']); ?> update(s)</td>
                                        <td class="py-3 px-4 text-right">
                                            <span class="text-indigo-700 font-black hover:underline">View List &rarr;</span>
                                        </td>
                                    </tr>
                                    <!-- Nested Expandable Row containing the list of updates for this owner -->
                                    <tr id="list-<?php echo md5($fullName); ?>" class="hidden bg-white">
                                        <td colspan="4" class="p-4">
                                            <div class="bg-slate-50 border-2 border-slate-200 rounded-xl p-3 space-y-2">
                                                <div class="text-[11px] font-black uppercase text-slate-700 mb-2">Updates list for <?php echo htmlspecialchars($fullName); ?> (Click any update to edit date/time):</div>
                                                <div class="space-y-2">
                                                    <?php foreach($group['updates'] as $fu): ?>
                                                        <div onclick="openFieldDetailModal(<?php echo htmlspecialchars(json_encode($fu), ENT_QUOTES); ?>)" class="p-3 bg-white border border-slate-300 rounded-xl hover:bg-indigo-50 transition cursor-pointer flex items-center justify-between">
                                                            <div class="flex items-center space-x-3">
                                                                <?php if(!empty($fu['image_url'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($fu['image_url']); ?>" class="w-8 h-8 object-cover rounded-lg border border-slate-300">
                                                                <?php else: ?>
                                                                    <div class="w-8 h-8 bg-slate-200 rounded-lg flex items-center justify-center text-[10px] text-slate-500 font-bold">No Img</div>
                                                                <?php endif; ?>
                                                                <div>
                                                                    <span class="font-mono font-black text-slate-950 text-xs">Date: <?php echo htmlspecialchars($fu['update_date']); ?></span>
                                                                    <span class="block text-[10px] text-slate-500">In: <?php echo htmlspecialchars($fu['check_in_time']); ?> | Out: <?php echo htmlspecialchars($fu['check_out_time']); ?></span>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <?php if($fu['status'] === 'Approved'): ?>
                                                                    <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-950 font-black text-[10px] border border-emerald-400">Approved</span>
                                                                <?php elseif($fu['status'] === 'Paid & Completed'): ?>
                                                                    <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-950 font-black text-[10px] border border-blue-400">Paid & Completed</span>
                                                                <?php else: ?>
                                                                    <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-950 font-black text-[10px] border border-amber-400">Pending</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="px-6 py-4 bg-slate-50 border-t-2 border-slate-200 flex justify-end shrink-0">
                <button onclick="closeFieldUpdatesModal()" class="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border-2 border-slate-400">Close</button>
            </div>
        </div>
    </div>

    <!-- Specific Field Worker Update Detail Modal (Allows editing Date & Times) -->
    <div id="fieldDetailModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-[60] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full shadow-2xl border-2 border-slate-400 overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b-2 border-slate-200 flex justify-between items-center bg-slate-50">
                <h3 class="text-base font-black text-slate-950"><i class="fa-solid fa-circle-info text-indigo-700 mr-2"></i> Field Update Details & Date Editor</h3>
                <button onclick="closeFieldDetailModal()" class="text-slate-800 hover:text-slate-950 font-bold border-2 border-slate-300 p-2 rounded-xl hover:bg-slate-200"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <div class="p-6 space-y-4 text-xs font-semibold">
                <div class="grid grid-cols-2 gap-4 bg-slate-100 p-4 rounded-2xl border-2 border-slate-300">
                    <div>
                        <span class="text-[10px] text-slate-600 font-black uppercase">ID Owner Name:</span>
                        <p id="detEmpName" class="font-black text-slate-950 text-sm mt-0.5"></p>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-600 font-black uppercase">Department:</span>
                        <p id="detDept" class="font-black text-slate-950 text-sm mt-0.5"></p>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-600 font-black uppercase">Target Date:</span>
                        <p id="detDate" class="font-mono font-black text-slate-950 mt-0.5"></p>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-[10px] text-slate-600 font-black uppercase">Autofill Check-In / Out:</span>
                            <p id="detTimes" class="font-mono font-black text-blue-800 mt-0.5"></p>
                        </div>
                        <button type="button" onclick="toggleEditFieldTimes()" class="px-2.5 py-1 bg-blue-700 hover:bg-blue-800 text-white font-black rounded-lg transition shadow-sm border border-blue-500 text-[11px]">
                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit Date & Time
                        </button>
                    </div>
                </div>

                <!-- Inline Edit Field Worker Date & Times Form -->
                <form id="editFieldTimesForm" action="attendance.php" method="POST" class="bg-blue-50 p-4 rounded-2xl border-2 border-blue-300 space-y-3 hidden">
                    <input type="hidden" name="action_type" value="edit_field_update_time">
                    <input type="hidden" name="update_id" id="editFieldUpdateId">
                    <div class="font-black text-slate-950 text-xs mb-1">Update Field Date, Check-In / Check-Out Times</div>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-[10px] font-black uppercase text-slate-700 mb-1">Update Date</label>
                            <input type="date" name="update_date" id="inputUpdateDate" class="w-full px-3 py-2 bg-white border-2 border-slate-400 rounded-xl font-mono font-black text-slate-950" required>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-700 mb-1">Check-In Time</label>
                                <input type="text" name="check_in_time" id="inputCheckInTime" class="w-full px-3 py-2 bg-white border-2 border-slate-400 rounded-xl font-mono font-black text-slate-950" required>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black uppercase text-slate-700 mb-1">Check-Out Time</label>
                                <input type="text" name="check_out_time" id="inputCheckOutTime" class="w-full px-3 py-2 bg-white border-2 border-slate-400 rounded-xl font-mono font-black text-slate-950" required>
                            </div>
                        </div>
                    </div>
                    <div class="flex space-x-2 pt-1">
                        <button type="button" onclick="toggleEditFieldTimes()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-950 font-black rounded-xl border border-slate-400">Cancel</button>
                        <button type="submit" class="px-4 py-1.5 bg-blue-700 hover:bg-blue-800 text-white font-black rounded-xl border border-blue-500 shadow-sm">Save Details</button>
                    </div>
                </form>

                <div>
                    <span class="text-[10px] text-slate-600 font-black uppercase">On-Site Image Proof:</span>
                    <div class="mt-1">
                        <a id="detPicLink" href="#" target="_blank" class="inline-block w-full">
                            <img id="detPicImg" src="" alt="Site Image" class="w-full max-h-60 object-contain bg-slate-900 rounded-2xl border-2 border-slate-300 shadow-sm">
                        </a>
                    </div>
                </div>

                <div>
                    <span class="text-[10px] text-slate-600 font-black uppercase">Worker Field Notes:</span>
                    <p id="detNotes" class="mt-1 p-3 bg-slate-50 border-2 border-slate-300 rounded-xl text-slate-800 font-medium"></p>
                </div>

                <div class="pt-2 flex items-center justify-between border-t-2 border-slate-200">
                    <div>
                        <span class="text-[10px] text-slate-600 font-black uppercase">Current Status:</span>
                        <div id="detStatusBadge" class="mt-0.5"></div>
                    </div>
                    <div id="detActionButtons" class="space-x-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Individual Attendance Log Modal (Focused on ID Owner Records) -->
    <div id="attendanceModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[55] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-7xl w-full shadow-2xl border-2 border-slate-400 overflow-hidden flex flex-col max-h-[90vh]">
            
            <div class="px-6 py-4 border-b-2 border-slate-200 flex justify-between items-center bg-slate-50 no-print shrink-0">
                <h3 class="text-lg font-black text-slate-950"><i class="fa-solid fa-file-lines text-blue-700 mr-2 font-bold"></i> ID Owner Attendance Records</h3>
                <button onclick="closeAttendanceModal()" class="text-slate-800 hover:text-slate-950 font-bold border-2 border-slate-300 p-2 rounded-xl hover:bg-slate-200 transition"><i class="fa-solid fa-xmark text-lg font-bold"></i></button>
            </div>

            <div id="printableAttendance" class="p-6 space-y-4 bg-white overflow-y-auto flex-1 text-slate-950">
                <div class="text-center border-b-2 border-slate-300 pb-4">
                    <h2 class="text-xl font-black text-slate-950 tracking-tight">Gig<span class="text-blue-700 font-black">Pay</span> Corporation</h2>
                    <p class="text-xs text-slate-900 font-bold">ID Owner Attendance Record Report</p>
                </div>

                <div class="grid grid-cols-2 gap-4 text-xs bg-slate-100 p-4 rounded-2xl border-2 border-slate-300 items-center shadow-sm">
                    <div>
                        <p class="text-slate-900 font-black uppercase text-[10px]">ID Owner Name</p>
                        <p id="modalEmpName" class="font-black text-slate-950 text-sm mt-0.5"></p>
                    </div>
                    <div>
                        <p class="text-slate-900 font-black uppercase text-[10px]">Department</p>
                        <p id="modalEmpDept" class="font-black text-slate-950 text-sm mt-0.5"></p>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 bg-blue-50 p-4 rounded-2xl border-2 border-blue-300 no-print shadow-sm">
                    <div class="flex items-center space-x-3 flex-1 w-full">
                        <div class="flex-1">
                            <label class="block text-[10px] font-black text-slate-950 mb-1">START DATE</label>
                            <input type="date" id="filterStart" onchange="fetchEmployeeLogs()" class="w-full px-3 py-2 bg-white border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950 shadow-sm">
                        </div>
                        <div class="flex-1">
                            <label class="block text-[10px] font-black text-slate-950 mb-1">END DATE</label>
                            <input type="date" id="filterEnd" onchange="fetchEmployeeLogs()" class="w-full px-3 py-2 bg-white border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950 shadow-sm">
                        </div>
                    </div>
                </div>

                <div class="border-2 border-slate-300 rounded-2xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                            <thead>
                                <tr class="bg-slate-200 border-b-2 border-slate-300 text-slate-950 uppercase text-[10px] font-black">
                                    <th class="py-3 px-4">Log ID</th>
                                    <th class="py-3 px-4">Check-In Time</th>
                                    <th class="py-3 px-4">Check-Out Time</th>
                                    <th class="py-3 px-4">Workdays</th>
                                    <th class="py-3 px-4">Work Hours</th>
                                    <th class="py-3 px-4">Overtime Hours</th>
                                    <th class="py-3 px-4">Status / Absent</th>
                                    <th class="py-3 px-4">Tardy Status</th>
                                    <th class="py-3 px-4">Undertime Status</th>
                                    <th class="py-3 px-4">Site Image</th>
                                </tr>
                            </thead>
                            <tbody id="logsTableBody" class="divide-y-2 divide-slate-200 text-slate-900 font-bold"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 bg-slate-50 border-t-2 border-slate-200 flex space-x-3 no-print shrink-0">
                <button onclick="closeAttendanceModal()" class="flex-1 px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border-2 border-slate-400 shadow-sm">Close</button>
                <button onclick="window.print();" class="flex-1 px-4 py-2.5 bg-blue-700 hover:bg-blue-800 text-white text-xs font-black rounded-xl transition shadow-md border border-blue-500"><i class="fa-solid fa-print mr-1.5 font-bold"></i> Print Records</button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border-2 border-slate-400 text-center space-y-4 text-slate-950">
            <div class="w-12 h-12 bg-red-100 border-2 border-red-400 text-red-700 rounded-2xl flex items-center justify-center mx-auto text-lg font-black shadow-inner">
                <i class="fa-solid fa-arrow-right-from-bracket font-bold"></i>
            </div>
            <div>
                <h3 class="text-base font-black text-slate-950">Ready to Leave?</h3>
                <p class="text-xs font-bold text-slate-700 mt-1">Are you sure you want to log out of your admin session?</p>
            </div>
            <div class="flex space-x-2 pt-2">
                <button onclick="closeLogoutModal()" class="flex-1 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-950 text-xs font-black rounded-xl transition border-2 border-slate-400 shadow-sm">Cancel</button>
                <a href="index.php" class="flex-1 py-2.5 bg-red-700 hover:bg-red-800 text-white text-xs font-black rounded-xl transition shadow-md flex items-center justify-center border border-red-500">Logout</a>
            </div>
        </div>
    </div>

    <script>
        let currentEmployeeId = null;
        let cachedRawLogs = [];

        function initRealtimeClock() {
            setInterval(() => {
                const now = new Date();
                const clockEl = document.getElementById('realtimeClock');
                if (clockEl) {
                    clockEl.innerText = now.toLocaleString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
                }
            }, 1000);
        }

        function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); }
        function closeLogoutModal() { document.getElementById('logoutModal').classList.add('hidden'); }

        function openFieldUpdatesModal() { document.getElementById('fieldUpdatesModal').classList.remove('hidden'); }
        function closeFieldUpdatesModal() { document.getElementById('fieldUpdatesModal').classList.add('hidden'); }

        function toggleOwnerUpdatesRow(hashKey) {
            const rowEl = document.getElementById('list-' + hashKey);
            const iconEl = document.getElementById('icon-' + hashKey);
            if (rowEl.classList.contains('hidden')) {
                rowEl.classList.remove('hidden');
                iconEl.classList.add('rotate-90');
            } else {
                rowEl.classList.add('hidden');
                iconEl.classList.remove('rotate-90');
            }
        }

        function openFieldDetailModal(fu) {
            document.getElementById('detEmpName').innerText = fu.first_name + ' ' + fu.last_name;
            document.getElementById('detDept').innerText = fu.department || 'N/A';
            document.getElementById('detDate').innerText = fu.update_date;
            document.getElementById('detTimes').innerText = `In: ${fu.check_in_time} | Out: ${fu.check_out_time}`;
            
            document.getElementById('editFieldUpdateId').value = fu.id;
            document.getElementById('inputUpdateDate').value = fu.update_date;
            document.getElementById('inputCheckInTime').value = fu.check_in_time;
            document.getElementById('inputCheckOutTime').value = fu.check_out_time;
            document.getElementById('editFieldTimesForm').classList.add('hidden');

            const picImg = document.getElementById('detPicImg');
            const picLink = document.getElementById('detPicLink');
            
            if (fu.image_url) {
                picImg.src = fu.image_url;
                picLink.href = fu.image_url;
                picImg.classList.remove('hidden');
            } else {
                picImg.src = '';
                picLink.href = '#';
                picImg.classList.add('hidden');
            }

            document.getElementById('detNotes').innerText = fu.notes || 'No notes provided.';
            
            const badgeEl = document.getElementById('detStatusBadge');
            const actionsEl = document.getElementById('detActionButtons');
            
            if (fu.status === 'Approved') {
                badgeEl.innerHTML = '<span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-950 font-black border border-emerald-400">Approved</span>';
            } else if (fu.status === 'Paid & Completed') {
                badgeEl.innerHTML = '<span class="px-2 py-0.5 rounded bg-blue-100 text-blue-950 font-black border border-blue-400">Paid & Completed</span>';
            } else {
                badgeEl.innerHTML = '<span class="px-2 py-0.5 rounded bg-amber-100 text-amber-950 font-black border border-amber-400">Pending Approval</span>';
            }

            let actionHtml = '';
            if (fu.status === 'Pending') {
                actionHtml = `
                    <form action="attendance.php" method="POST" class="inline">
                        <input type="hidden" name="action_type" value="approve_field_update">
                        <input type="hidden" name="update_id" value="${fu.id}">
                        <button type="submit" class="px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white font-black rounded-xl transition shadow-sm border border-emerald-500">
                            <i class="fa-solid fa-check mr-1.5"></i> Mark as Approved (Autofill Attendance)
                        </button>
                    </form>
                `;
            } else if (fu.status === 'Approved' && !fu.is_paid) {
                actionHtml = `
                    <form action="attendance.php" method="POST" class="inline">
                        <input type="hidden" name="action_type" value="mark_as_paid">
                        <input type="hidden" name="update_id" value="${fu.id}">
                        <button type="submit" class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white font-black rounded-xl transition shadow-sm border border-blue-500">
                            <i class="fa-solid fa-wallet mr-1.5"></i> Mark as Paid (Trigger Attendance/Payroll)
                        </button>
                    </form>
                `;
            } else {
                actionHtml = '<span class="text-xs text-slate-500 font-bold italic">Process Completed</span>';
            }
            actionsEl.innerHTML = actionHtml;

            document.getElementById('fieldDetailModal').classList.remove('hidden');
        }

        function closeFieldDetailModal() { document.getElementById('fieldDetailModal').classList.add('hidden'); }

        function toggleEditFieldTimes() {
            const formEl = document.getElementById('editFieldTimesForm');
            formEl.classList.toggle('hidden');
        }

        function openAttendanceModal(id, name, dept) {
            currentEmployeeId = id;
            document.getElementById('modalEmpName').innerText = name;
            document.getElementById('modalEmpDept').innerText = dept || 'N/A';
            
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const firstDayOfMonth = `${year}-${month}-01`;
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;
            
            document.getElementById('filterStart').value = firstDayOfMonth;
            document.getElementById('filterEnd').value = todayStr;

            fetchEmployeeLogs();
            document.getElementById('attendanceModal').classList.remove('hidden');
        }

        function closeAttendanceModal() { document.getElementById('attendanceModal').classList.add('hidden'); }

        function fetchEmployeeLogs() {
            const startDate = document.getElementById('filterStart').value;
            const endDate = document.getElementById('filterEnd').value;
            const tbody = document.getElementById('logsTableBody');

            tbody.innerHTML = `<tr><td colspan="10" class="py-6 text-center text-slate-900 font-bold">Loading ID owner records...</td></tr>`;

            fetch(`attendance.php?ajax=1&employee_id=${currentEmployeeId}&start=${startDate}&end=${endDate}`)
                .then(response => response.json())
                .then(data => {
                    cachedRawLogs = data;
                    renderLogsTable();
                })
                .catch(() => {
                    tbody.innerHTML = `<tr><td colspan="10" class="py-6 text-center text-red-900 font-black">Failed to load ID owner records.</td></tr>`;
                });
        }

        function renderLogsTable() {
            const tbody = document.getElementById('logsTableBody');

            if (cachedRawLogs.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" class="py-6 text-center text-slate-900 font-bold">No attendance records found for this ID owner.</td></tr>`;
                return;
            }

            let rows = '';
            cachedRawLogs.forEach(log => {
                let otBadge = parseFloat(log.overtime_hours) > 0
                    ? `<span class="px-2 py-0.5 rounded bg-indigo-100 text-indigo-950 font-black border border-indigo-400">+${parseFloat(log.overtime_hours).toFixed(2)} hrs</span>`
                    : '<span class="text-slate-500 font-bold">0.00 hrs</span>';

                rows += `
                    <tr class="hover:bg-blue-50">
                        <td class="py-3 px-4 font-mono font-black text-slate-950">#${log.id}</td>
                        <td class="py-3 px-4 font-black text-slate-950"><i class="fa-solid fa-right-to-bracket text-emerald-700 mr-1.5 font-bold"></i> ${log.check_in}</td>
                        <td class="py-3 px-4 font-black text-slate-950"><i class="fa-solid fa-right-from-bracket text-amber-700 mr-1.5 font-bold"></i> ${log.check_out}</td>
                        <td class="py-3 px-4 font-black"><span class="px-2 py-0.5 rounded bg-blue-100 text-blue-950 font-black border border-blue-400">${parseInt(log.workdays)}</span></td>
                        <td class="py-3 px-4 font-black"><span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-950 font-black border border-emerald-400">${parseFloat(log.workhours).toFixed(2)} hrs</span></td>
                        <td class="py-3 px-4 font-black">${otBadge}</td>
                        <td class="py-3 px-4 font-black">${log.absent_html}</td>
                        <td class="py-3 px-4 font-black">${log.tardy_html}</td>
                        <td class="py-3 px-4 font-black">${log.undertime_html}</td>
                        <td class="py-3 px-4 font-black">${log.site_picture_html}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = rows;
        }
    </script>
</body>
</html>