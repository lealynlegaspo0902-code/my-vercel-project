<?php
// Clear any prior output buffers to prevent JSON corruption from hosting injections
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

session_start();

// Authentication Check: Ensure Admin Is Logged In
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized session. Please log in again.']);
        exit;
    }
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
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => "Database connection failed: " . $e->getMessage()]);
        exit;
    }
    die("Database connection failed: " . $e->getMessage());
}

$success_msg = '';
$error_msg = '';

// Helper function to safely add columns to database tables if they are missing
function ensureColumnExists($pdo, $table, $column, $definition) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (\Exception $e) {}
}

// Ensure all required columns exist including employee saved government deductions
ensureColumnExists($pdo, 'employees', 'email', 'VARCHAR(255) DEFAULT NULL');
ensureColumnExists($pdo, 'employees', 'sss', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'employees', 'philhealth', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'employees', 'pagibig', 'DECIMAL(10,2) DEFAULT 0.00');

ensureColumnExists($pdo, 'payrolls', 'tardy_minutes', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'payrolls', 'tardy_count', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'payrolls', 'tardy_deduction', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'undertime', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'undertime_deduction', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'workhours', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'days_worked', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'payrolls', 'absent_days', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'payrolls', 'overtime_pay', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'cash_advance', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'ot_details', 'TEXT');
ensureColumnExists($pdo, 'payrolls', 'ca_details', 'TEXT');
ensureColumnExists($pdo, 'payrolls', 'paid_leave_days', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'payrolls', 'paid_leave_pay', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'payrolls', 'status', "VARCHAR(50) DEFAULT 'Generated'");

ensureColumnExists($pdo, 'attendance', 'tardy_minutes', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'attendance', 'tardy', 'INT DEFAULT 0');
ensureColumnExists($pdo, 'attendance', 'undertime', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'attendance', 'workhours', 'DECIMAL(10,2) DEFAULT 0.00');
ensureColumnExists($pdo, 'attendance', 'check_out', 'DATETIME NULL');

// Ensure employee_government_deductions table has employee_id to link gov deductions per employee ID
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_government_deductions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            basic_salary DECIMAL(10,2) DEFAULT 0.00,
            sss DECIMAL(10,2) DEFAULT 0.00,
            philhealth DECIMAL(10,2) DEFAULT 0.00,
            pag_ibig DECIMAL(10,2) DEFAULT 0.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (\Exception $e) {}

// Function to fetch system settings dynamically from `system_settings` table
function fetchSystemSettings($pdo) {
    $settings = [
        'standard_time_in' => '08:00:00',
        'standard_time_out' => '17:00:00',
        'grace_period_minutes' => 0,
        'overtime_minimum_minutes' => 0,
        'tardy_rate_per_minute' => 1.00,
        'early_out_rate_per_minute' => 1.00,
        'overtime_multiplier' => 1.00,
        'holiday_overtime_multiplier' => 1.00,
        'tardy_deduction_active' => 1,
        'early_out_deduction_active' => 1
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt->fetch()) {
            $key = $row['setting_key'];
            $val = $row['setting_value'];
            if (array_key_exists($key, $settings)) {
                $settings[$key] = $val;
            }
        }
    } catch (\Exception $e) {}

    return $settings;
}

// Core Calculation Logic mapping actual database structure & referencing dynamic rules from system_settings
function computePayrollData($pdo, $emp_id, $period_start, $period_end) {
    $rules = fetchSystemSettings($pdo);

    $stmtEmp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmtEmp->execute([$emp_id]);
    $employee = $stmtEmp->fetch();

    if (!$employee) {
        return null;
    }

    $daily_rate = floatval($employee['daily_salary'] ?? 0);
    $hourly_rate = $daily_rate > 0 ? ($daily_rate / 8) : 0;

    try {
        $stmtAtt = $pdo->prepare("
            SELECT *, 
                   COALESCE(tardy_minutes, 0) as calculated_tardy_minutes,
                   COALESCE(tardy, 0) as calculated_tardy_flag,
                   COALESCE(workhours, 0.00) as calculated_work_hours,
                   COALESCE(undertime, 0) as calculated_undertime,
                   COALESCE(overtime_hours, 0.00) as calculated_overtime_hours,
                   COALESCE(absent, 0) as calculated_absent,
                   COALESCE(absent_with_pay, 0) as calculated_absent_with_pay
            FROM attendance 
            WHERE employee_id = ? AND (
                (check_in IS NOT NULL AND DATE(check_in) BETWEEN ? AND ?) OR 
                (check_out IS NOT NULL AND DATE(check_out) BETWEEN ? AND ?) OR
                (timestamp IS NOT NULL AND timestamp != '0000-00-00 00:00:00' AND DATE(timestamp) BETWEEN ? AND ?)
            )
        ");
        $stmtAtt->execute([
            $emp_id, 
            $period_start, $period_end, 
            $period_start, $period_end, 
            $period_start, $period_end
        ]);
    } catch (\Exception $e) {
        try {
            $stmtAtt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ?");
            $stmtAtt->execute([$emp_id]);
        } catch (\Exception $ex) {
            $stmtAtt = null;
        }
    }
    
    $attendance_logs = $stmtAtt ? $stmtAtt->fetchAll() : [];

    $days_worked = 0;
    $absent_days = 0;
    $tardy_count = 0;
    $total_tardy_minutes = 0;
    $workhours = 0;
    $total_undertime_minutes = 0; 
    $calculated_basic_pay = 0;
    $attendance_overtime_pay = 0;

    $grace_period = intval($rules['grace_period_minutes']);
    $ot_min_threshold = intval($rules['overtime_minimum_minutes']);
    $ot_multiplier = floatval($rules['overtime_multiplier']);
    $holiday_ot_multiplier = floatval($rules['holiday_overtime_multiplier']);

    foreach ($attendance_logs as $log) {
        $is_absent = intval($log['calculated_absent'] ?? 0);
        if ($is_absent > 0) {
            $absent_days += $is_absent;
            continue;
        }

        $tardy_mins = intval($log['calculated_tardy_minutes'] ?? 0);
        $tardy_flag = intval($log['calculated_tardy_flag'] ?? 0);
        
        $current_log_tardy_minutes = 0;
        if ($tardy_mins > 0) {
            $current_log_tardy_minutes = $tardy_mins;
        } elseif ($tardy_flag > 0) {
            $current_log_tardy_minutes = ($tardy_flag > 1) ? $tardy_flag : 0; 
        }

        // Apply grace period logic dynamically
        if ($current_log_tardy_minutes <= $grace_period) {
            $current_log_tardy_minutes = 0;
        }

        if ($current_log_tardy_minutes > 0 || $tardy_flag > 0) {
            if ($current_log_tardy_minutes > 0) {
                $tardy_count++;
                $total_tardy_minutes += $current_log_tardy_minutes;
            }
        }

        $current_wh = floatval($log['calculated_work_hours'] ?? 0.00);
        if ($current_wh <= 0) {
            continue; 
        }

        $workhours += $current_wh;
        $days_worked++;

        $day_undertime = floatval($log['calculated_undertime'] ?? 0);
        $total_undertime_minutes += round($day_undertime);

        if ($current_wh >= 8) {
            $calculated_basic_pay += $daily_rate;
        } else {
            $calculated_basic_pay += ($current_wh * $hourly_rate);
        }

        $att_ot_hrs = floatval($log['calculated_overtime_hours'] ?? 0);
        if ($att_ot_hrs > 0) {
            $att_ot_mins = $att_ot_hrs * 60;
            if ($att_ot_mins >= $ot_min_threshold) {
                $is_holiday = intval($log['is_holiday'] ?? 0);
                $multiplier = $is_holiday ? $holiday_ot_multiplier : $ot_multiplier;
                $attendance_overtime_pay += ($att_ot_hrs * $hourly_rate * $multiplier);
            }
        }
    }

    try {
        $stmtReq = $pdo->prepare("SELECT * FROM requests WHERE employee_id = ?");
        $stmtReq->execute([$emp_id]);
    } catch (\Exception $e) {
        $stmtReq = null;
    }

    $requests = $stmtReq ? $stmtReq->fetchAll() : [];
    $overtime_pay = $attendance_overtime_pay;
    $ot_details = [];
    $paid_leave_days = 0;
    $paid_leave_pay = 0;
    $unpaid_leave_days = 0;

    foreach ($requests as $req) {
        $req_status = strtolower($req['status'] ?? '');
        $req_type = strtolower($req['type'] ?? '');
        $req_date = $req['start_date'] ?? $req['created_at'] ?? date('Y-m-d');
        
        $is_approved = (strpos($req_status, 'approved') !== false || $req_status === '1');

        if ($is_approved) {
            if ($req_type === 'leave') {
                $end_date_req = $req['end_date'] ?? $req_date;
                if (($req_date <= $period_end) && ($end_date_req >= $period_start)) {
                    $details_text = strtolower($req['details'] ?? '');
                    $is_no_pay = (strpos(strtolower($req_status), 'no pay') !== false || strpos($details_text, 'absent / no pay') !== false);
                    
                    $d1 = new DateTime(max($req_date, $period_start));
                    $d2 = new DateTime(min($end_date_req, $period_end));
                    $d2->modify('+1 day');
                    $interval_days = max(1, $d1->diff($d2)->days);

                    if ($is_no_pay) {
                        $unpaid_leave_days += $interval_days;
                    } else {
                        $paid_leave_days += $interval_days;
                        $paid_leave_pay += ($interval_days * $daily_rate);
                    }
                }
            }

            if ($req_type === 'overtime') {
                if ($req_date >= $period_start && $req_date <= $period_end) {
                    $ot_hours = floatval($req['hours'] ?? 0);
                    if ($ot_hours <= 0) { $ot_hours = 1; }
                    $ot_mins = $ot_hours * 60;
                    if ($ot_mins >= $ot_min_threshold) {
                        $calculated_ot = $ot_hours * $hourly_rate * $ot_multiplier;
                        $overtime_pay += $calculated_ot;
                        
                        $ot_details[] = [
                            'start_date' => $req_date,
                            'hours' => $ot_hours,
                            'amount' => $calculated_ot
                        ];
                    }
                }
            }
        }
    }

    $cash_advance_total = 0;
    $ca_details = [];
    try {
        $stmtDed = $pdo->prepare("SELECT * FROM payroll_deductions WHERE employee_id = ? AND deduction_date BETWEEN ? AND ?");
        $stmtDed->execute([$emp_id, $period_start, $period_end]);
        $deductions = $stmtDed->fetchAll();
        foreach ($deductions as $ded) {
            $amt = floatval($ded['amount'] ?? 0);
            $cash_advance_total += $amt;
            $ca_details[] = [
                'start_date' => $ded['deduction_date'],
                'amount' => $amt,
                'original_amount' => $amt
            ];
        }
    } catch (\Exception $e) {}

    $basic_pay = round($calculated_basic_pay, 2);
    if ($basic_pay <= 0 && $days_worked > 0) {
        $basic_pay = round($days_worked * $daily_rate, 2);
    }
    
    $absent_days = max(0, $absent_days) + $unpaid_leave_days;
    $total_basic_and_leave = $basic_pay + $paid_leave_pay;
    $allowance = floatval($employee['allowance'] ?? 0);
    
    // FETCH GOVERNMENT DEDUCTIONS DIRECTLY FROM `employee_government_deductions` TABLE BY EMPLOYEE ID
    try {
        $stmtGov = $pdo->prepare("SELECT sss, philhealth, pag_ibig FROM employee_government_deductions WHERE employee_id = ?");
        $stmtGov->execute([$emp_id]);
        $govRow = $stmtGov->fetch();
        if ($govRow) {
            $sss_deduction        = floatval($govRow['sss'] ?? 0.00);
            $philhealth_deduction = floatval($govRow['philhealth'] ?? 0.00);
            $pagibig_deduction    = floatval($govRow['pag_ibig'] ?? 0.00);
        } else {
            $sss_deduction        = floatval($employee['sss'] ?? 0.00);
            $philhealth_deduction = floatval($employee['philhealth'] ?? 0.00);
            $pagibig_deduction    = floatval($employee['pagibig'] ?? 0.00);
        }
    } catch (\Exception $e) {
        $sss_deduction        = floatval($employee['sss'] ?? 0.00);
        $philhealth_deduction = floatval($employee['philhealth'] ?? 0.00);
        $pagibig_deduction    = floatval($employee['pagibig'] ?? 0.00);
    }

    // Apply dynamic rates and toggles from system settings
    $tardy_rate = floatval($rules['tardy_rate_per_minute']);
    $early_out_rate = floatval($rules['early_out_rate_per_minute']);
    $tardy_active = intval($rules['tardy_deduction_active']);
    $early_out_active = intval($rules['early_out_deduction_active']);

    $tardy_deduction = $tardy_active ? round($total_tardy_minutes * $tardy_rate, 2) : 0.00; 
    $undertime_deduction = $early_out_active ? round($total_undertime_minutes * $early_out_rate, 2) : 0.00;
    $absent_deduction = 0; 
    
    $total_undertime_hours = $total_undertime_minutes / 60.0;
    $cash_advance_total = round($cash_advance_total, 2);
    
    $total_deductions = round($sss_deduction + $philhealth_deduction + $pagibig_deduction + $tardy_deduction + $undertime_deduction + $cash_advance_total, 2);
    
    $net_pay = round(($total_basic_and_leave + $overtime_pay + $allowance) - $total_deductions, 2);
    if ($net_pay < 0) { $net_pay = 0; }

    $stmtCheck = $pdo->prepare("SELECT id, status FROM payrolls WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?");
    $stmtCheck->execute([$emp_id, $period_start, $period_end]);
    $existing_payroll = $stmtCheck->fetch();

    return [
        'id' => $existing_payroll['id'] ?? 0,
        'employee_id' => $emp_id,
        'pay_period_start' => $period_start,
        'pay_period_end' => $period_end,
        'days_worked' => $days_worked,
        'absent_days' => $absent_days,
        'tardy_count' => $tardy_count,
        'tardy_minutes' => $total_tardy_minutes,
        'tardy_deduction' => $tardy_deduction,
        'undertime' => $total_undertime_hours,
        'undertime_minutes' => $total_undertime_minutes,
        'undertime_deduction' => $undertime_deduction,
        'workhours' => $workhours,
        'basic_pay' => $basic_pay,
        'overtime_pay' => $overtime_pay,
        'allowance' => $allowance,
        'sss_deduction' => $sss_deduction,
        'philhealth_deduction' => $philhealth_deduction,
        'pagibig_deduction' => $pagibig_deduction,
        'absent_deduction' => $absent_deduction,
        'cash_advance' => $cash_advance_total,
        'total_deductions' => $total_deductions,
        'net_pay' => $net_pay,
        'ot_details' => json_encode($ot_details),
        'ca_details' => json_encode($ca_details),
        'paid_leave_days' => $paid_leave_days,
        'paid_leave_pay' => $paid_leave_pay,
        'status' => $existing_payroll['status'] ?? 'Generated'
    ];
}

// Handle Single or Batch AJAX Request for marking as paid
if (isset($_GET['action']) && $_GET['action'] === 'mark_payroll_paid') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $employee_ids = $_GET['employee_ids'] ?? [];
        if (is_string($employee_ids)) {
            $employee_ids = explode(',', $employee_ids);
        }
        $period_start = $_GET['start_date'] ?? date('Y-m-01');
        $period_end = $_GET['end_date'] ?? date('Y-m-d');

        if(empty($employee_ids) || !$period_start || !$period_end) {
            echo json_encode(['error' => 'Invalid payroll parameters or no employees selected']);
            exit;
        }

        foreach($employee_ids as $emp_id) {
            $emp_id = intval($emp_id);
            if(!$emp_id) continue;

            $payroll = computePayrollData($pdo, $emp_id, $period_start, $period_end);
            if(!$payroll) continue;

            $stmtCheck = $pdo->prepare("SELECT id FROM payrolls WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?");
            $stmtCheck->execute([$emp_id, $period_start, $period_end]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE payrolls SET 
                        days_worked = ?, absent_days = ?, tardy_count = ?, tardy_minutes = ?, tardy_deduction = ?, undertime = ?, undertime_deduction = ?, workhours = ?, 
                        basic_pay = ?, overtime_pay = ?, allowance = ?, sss_deduction = ?, philhealth_deduction = ?, 
                        pagibig_deduction = ?, absent_deduction = ?, cash_advance = ?, 
                        total_deductions = ?, net_pay = ?, ot_details = ?, ca_details = ?, paid_leave_days = ?, paid_leave_pay = ?, status = 'Paid'
                    WHERE id = ?
                ");
                $stmtUpdate->execute([
                    $payroll['days_worked'], $payroll['absent_days'], $payroll['tardy_count'], $payroll['tardy_minutes'], $payroll['tardy_deduction'], $payroll['undertime'], $payroll['undertime_deduction'], $payroll['workhours'],
                    $payroll['basic_pay'], $payroll['overtime_pay'], $payroll['allowance'], $payroll['sss_deduction'], $payroll['philhealth_deduction'],
                    $payroll['pagibig_deduction'], $payroll['absent_deduction'], $payroll['cash_advance'],
                    $payroll['total_deductions'], $payroll['net_pay'], $payroll['ot_details'], $payroll['ca_details'], $payroll['paid_leave_days'], $payroll['paid_leave_pay'], $existing['id']
                ]);
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO payrolls (
                        employee_id, pay_period_start, pay_period_end, days_worked, absent_days, tardy_count, 
                        tardy_minutes, tardy_deduction, undertime, undertime_deduction, workhours, basic_pay, overtime_pay, allowance, sss_deduction, 
                        philhealth_deduction, pagibig_deduction, absent_deduction, cash_advance, 
                        total_deductions, net_pay, ot_details, ca_details, paid_leave_days, paid_leave_pay, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid')
                ");
                $stmtInsert->execute([
                    $emp_id, $period_start, $period_end, $payroll['days_worked'], $payroll['absent_days'], $payroll['tardy_count'],
                    $payroll['tardy_minutes'], $payroll['tardy_deduction'], $payroll['undertime'], $payroll['undertime_deduction'], $payroll['workhours'], $payroll['basic_pay'], $payroll['overtime_pay'], $payroll['allowance'], $payroll['sss_deduction'],
                    $payroll['philhealth_deduction'], $payroll['pagibig_deduction'], $payroll['absent_deduction'], $payroll['cash_advance'],
                    $payroll['total_deductions'], $payroll['net_pay'], $payroll['ot_details'], $payroll['ca_details'], $payroll['paid_leave_days'], $payroll['paid_leave_pay']
                ]);
            }
        }

        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle Single or Batch AJAX Request for emailing payroll notifications
if (isset($_GET['action']) && $_GET['action'] === 'notify_employee_email') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $payroll_ids = $_GET['payroll_ids'] ?? [];
        if (is_string($payroll_ids)) {
            $payroll_ids = explode(',', $payroll_ids);
        }

        if(empty($payroll_ids)) {
            echo json_encode(['error' => 'Invalid payroll IDs or no records specified']);
            exit;
        }

        require 'phpmailer/Exception.php';
        require 'phpmailer/PHPMailer.php';
        require 'phpmailer/SMTP.php';

        $sent_count = 0;
        foreach($payroll_ids as $payroll_id) {
            $payroll_id = intval($payroll_id);
            if(!$payroll_id) continue;

            $stmt = $pdo->prepare("
                SELECT p.*, e.first_name, e.last_name, e.email 
                FROM payrolls p 
                JOIN employees e ON p.employee_id = e.id 
                WHERE p.id = ?
            ");
            $stmt->execute([$payroll_id]);
            $row = $stmt->fetch();

            if(!$row || empty($row['email'])) {
                continue;
            }

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'lealynlegaspo0902@gmail.com'; 
            $mail->Password   = 'fxsliwfrjievhyjm';       
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('lealynlegaspo0902@gmail.com', 'GigPay Payroll System');
            $mail->addAddress($row['email'], $row['first_name'] . ' ' . $row['last_name']);

            $mail->isHTML(true);
            $mail->Subject = 'Your Payroll is Ready to View (' . $row['pay_period_start'] . ' to ' . $row['pay_period_end'] . ')';
            
            $net_formatted = number_format($row['net_pay'], 2);
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; padding: 20px; color: #111;'>
                    <h2 style='color: #1d4ed8;'>GigPay Corporation</h2>
                    <p>Hello <b>{$row['first_name']} {$row['last_name']}</b>,</p>
                    <p>Your payslip for the pay period from <b>{$row['pay_period_start']}</b> to <b>{$row['pay_period_end']}</b> has been computed and is ready for review.</p>
                    
                    <div style='background: #f1f5f9; padding: 15px; border-radius: 8px; margin: 20px 0; border: 2px solid #94a3b8;'>
                        <p style='margin: 0; font-size: 14px;'>Net Payout Amount:</p>
                        <p style='margin: 5px 0 0 0; font-size: 22px; font-weight: bold; color: #047857;'>₱{$net_formatted}</p>
                    </div>
                    
                    <p>You can log in to your employee portal or check with administration to view your full breakdown details.</p>
                    <p>Best regards,<br><b>GigPay System Administrator</b></p>
                </div>
            ";

            $mail->send();
            $sent_count++;
        }

        echo json_encode(['success' => true, 'sent_count' => $sent_count]);
    } catch (\Exception $e) {
        echo json_encode(['error' => 'Email sending failed: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Request for fetching payroll details (Preview Only)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_employee_payroll_details') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $emp_id = $_GET['employee_id'] ?? 0;
        $period_start = $_GET['start_date'] ?? '2026-08-01';
        $period_end = $_GET['end_date'] ?? date('Y-m-d');

        $stmtEmp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmtEmp->execute([$emp_id]);
        $employee = $stmtEmp->fetch();

        if (!$employee) {
            echo json_encode(['error' => 'Employee not found']);
            exit;
        }

        $computed_payroll = computePayrollData($pdo, $emp_id, $period_start, $period_end);
        $payrolls = $computed_payroll ? [$computed_payroll] : [];

        echo json_encode([
            'employee' => $employee,
            'payrolls' => $payrolls
        ]);
    } catch (\Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Fetch All Employees
$employees = [];
try {
    $employees = $pdo->query("
        SELECT e.*, 
        (SELECT COUNT(*) FROM payrolls p WHERE p.employee_id = e.id) as total_cycles,
        (SELECT SUM(p.net_pay) FROM payrolls p WHERE p.employee_id = e.id) as total_earned
        FROM employees e 
        ORDER BY e.last_name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    try {
        $employees = $pdo->query("SELECT * FROM employees ORDER BY last_name ASC")->fetchAll();
    } catch (PDOException $ex) {
        $employees = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigPay - Automated Payroll Computation</title>
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
            body * {
                visibility: hidden !important;
            }
            #printablePayslip, #printablePayslip * {
                visibility: visible !important;
            }
            #printablePayslip {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 72mm !important; 
                max-width: 72mm !important;
                margin: 0 auto !important;
                padding: 4mm !important;
                background: white !important;
                box-shadow: none !important;
                border: none !important;
                font-family: 'Courier New', Courier, monospace !important;
                font-size: 11px !important;
                color: #000 !important;
            }
            @page {
                size: 80mm auto;
                margin: 0mm;
            }
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
<body class="h-full font-sans antialiased flex text-slate-950 font-semibold overflow-x-hidden">

    <!-- Mobile Drawer Overlay -->
    <div id="mobileSidebarOverlay" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-950/75 z-40 hidden md:hidden backdrop-blur-xs"></div>

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
                <a href="hikvision.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl hover:bg-slate-800/80 hover:text-white transition">
                    <i class="fa-solid fa-video w-5 text-slate-300"></i><span>Hikvision Biometrics</span>
                </a>
                <a href="payroll.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl bg-blue-700 text-white shadow-lg border border-blue-600 transition">
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

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <header class="h-20 bg-white border-b-2 border-slate-300 flex items-center justify-between px-4 sm:px-8 shrink-0 shadow-sm">
            <div class="flex items-center space-x-3">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-slate-800 hover:text-blue-700 p-2 rounded-xl bg-slate-100 border border-slate-300">
                    <i class="fa-solid fa-bars text-lg"></i>
                </button>
                <h1 class="text-base sm:text-xl font-black text-slate-950 tracking-tight">Automated Payroll Computation</h1>
            </div>
            <div class="flex items-center space-x-3 pl-2">
                <div class="w-10 h-10 rounded-full bg-blue-900 text-white flex items-center justify-center font-black text-sm border border-blue-950 shadow-xs">AD</div>
                <div class="hidden sm:block text-left">
                    <p class="text-xs font-black text-slate-950">System Admin</p>
                    <p class="text-[11px] font-bold text-slate-700">Administrator</p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-8 space-y-6">
            
            <?php if($error_msg): ?>
                <div class="p-4 bg-red-100 text-red-950 border-2 border-red-400 rounded-xl text-sm font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-red-700"></i> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="p-4 bg-emerald-100 text-emerald-950 border-2 border-emerald-400 rounded-xl text-sm font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-check mr-3 text-emerald-700"></i> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <!-- Global Pay Period and Batch Action Controls -->
            <div class="bg-white p-4 sm:p-5 rounded-2xl border-2 border-slate-300 shadow-sm flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-800 uppercase tracking-wider mb-1">Batch Period Start</label>
                        <input type="date" id="globalBatchStart" value="2026-08-01" class="px-3.5 py-2 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950 bg-slate-50 focus:outline-blue-700 transition">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-800 uppercase tracking-wider mb-1">Batch Period End</label>
                        <input type="date" id="globalBatchEnd" value="<?php echo date('Y-m-d'); ?>" class="px-3.5 py-2 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950 bg-slate-50 focus:outline-blue-700 transition">
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" onclick="batchMarkAsPaid()" class="px-4 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-black rounded-xl transition shadow flex items-center space-x-2 border border-emerald-900">
                        <i class="fa-solid fa-circle-check"></i> <span>Mark Selected as Paid</span>
                    </button>
                    <button type="button" onclick="batchSendEmail()" class="px-4 py-2.5 bg-indigo-700 hover:bg-indigo-800 text-white text-xs font-black rounded-xl transition shadow flex items-center space-x-2 border border-indigo-900">
                        <i class="fa-solid fa-envelope"></i> <span>Send Email to Selected</span>
                    </button>
                </div>
            </div>

            <!-- Employee Table List -->
            <div class="bg-white border-2 border-slate-400 rounded-2xl overflow-hidden shadow-md mt-4">
                <div class="p-5 sm:p-6 border-b-2 border-slate-300 flex items-center justify-between bg-slate-200">
                    <h3 class="font-black text-slate-950 flex items-center space-x-2 text-sm sm:text-base">
                        <i class="fa-solid fa-users text-blue-700"></i>
                        <span>Select Employee to View Computed Payroll</span>
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm min-w-[700px]">
                        <thead>
                            <tr class="bg-slate-300 border-b-2 border-slate-400 text-slate-950 uppercase text-[11px] font-black tracking-wider">
                                <th class="py-4 px-4 border-r-2 border-slate-400 w-12 text-center">
                                    <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" class="w-4 h-4 rounded text-blue-700 focus:ring-blue-500 cursor-pointer">
                                </th>
                                <th class="py-4 px-6 border-r-2 border-slate-400">Employee ID / Name</th>
                                <th class="py-4 px-6 border-r-2 border-slate-400">Department</th>
                                <th class="py-4 px-6 border-r-2 border-slate-400">Position</th>
                                <th class="py-4 px-6 border-r-2 border-slate-400">Daily Salary Rate</th>
                                <th class="py-4 px-6 text-right">Action / View Payroll</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-slate-300 text-slate-950">
                            <?php if(empty($employees)): ?>
                                <tr>
                                    <td colspan="6" class="py-12 text-center text-slate-800 text-sm font-black">
                                        No employees found in the database.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($employees as $emp): ?>
                                    <tr class="hover:bg-blue-100 transition font-bold">
                                        <td class="py-4 px-4 border-r-2 border-slate-300 text-center" onclick="event.stopPropagation();">
                                            <input type="checkbox" name="emp_checkbox" value="<?php echo $emp['id']; ?>" class="emp-checkbox w-4 h-4 rounded text-blue-700 focus:ring-blue-500 cursor-pointer">
                                        </td>
                                        <td class="py-4 px-6 font-black text-slate-950 flex items-center space-x-3 border-r-2 border-slate-300 cursor-pointer" onclick="openEmployeePayrollModal(<?php echo $emp['id']; ?>)">
                                            <div class="w-9 h-9 rounded-full bg-blue-200 text-blue-900 flex items-center justify-center font-black text-xs shadow-sm border border-blue-400 shrink-0">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <div>
                                                <div class="text-[10px] text-blue-800 font-black tracking-wide">ID: #<?php echo htmlspecialchars($emp['id']); ?> | gsc</div>
                                                <div class="text-slate-950 font-black"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></div>
                                                <div class="text-[11px] text-slate-700 font-black">Email: <?php echo htmlspecialchars($emp['email'] ?? 'Not set'); ?></div>
                                            </div>
                                        </td>
                                        <td class="py-4 px-6 text-xs text-slate-950 font-black border-r-2 border-slate-300 cursor-pointer" onclick="openEmployeePayrollModal(<?php echo $emp['id']; ?>)">
                                            <?php echo htmlspecialchars($emp['department'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="py-4 px-6 text-xs text-slate-950 font-black border-r-2 border-slate-300 cursor-pointer" onclick="openEmployeePayrollModal(<?php echo $emp['id']; ?>)">
                                            <?php echo htmlspecialchars($emp['position'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="py-4 px-6 font-black text-emerald-900 border-r-2 border-slate-300 cursor-pointer" onclick="openEmployeePayrollModal(<?php echo $emp['id']; ?>)">
                                            ₱<?php echo number_format($emp['daily_salary'] ?? 0, 2); ?>
                                        </td>
                                        <td class="py-4 px-6 text-right">
                                            <button type="button" onclick="openEmployeePayrollModal(<?php echo $emp['id']; ?>);" class="inline-flex items-center px-3.5 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-black rounded-xl transition shadow border border-blue-900">
                                                <i class="fa-solid fa-calculator mr-1.5"></i> <span>View Payroll</span>
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

    <!-- Enhanced Employee Filtered Payroll Modal -->
    <div id="employeeModal" class="fixed inset-0 bg-slate-950/75 backdrop-blur-md z-50 hidden flex items-center justify-center p-2 sm:p-6 transition-all">
        <div class="bg-gradient-to-b from-white to-slate-50 rounded-3xl max-w-6xl w-full shadow-2xl border-2 border-slate-400 overflow-hidden flex flex-col max-h-[96vh] sm:max-h-[94vh] transform transition-transform">
            
            <div class="px-5 sm:px-8 py-4 sm:py-5 border-b-2 border-slate-300 flex justify-between items-center bg-white/90 backdrop-blur sticky top-0 z-10 shrink-0 shadow-xs">
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-2xl bg-gradient-to-tr from-blue-700 to-indigo-600 text-white flex items-center justify-center font-black text-sm sm:text-base shadow-lg shadow-blue-500/30 shrink-0">
                        <i class="fa-solid fa-calculator"></i>
                    </div>
                    <div>
                        <div id="modalEmpIdBadge" class="text-[10px] text-blue-700 font-black uppercase tracking-wider">ID: #-- | gsc</div>
                        <h3 id="modalEmpTitleName" class="text-sm sm:text-lg font-black tracking-tight text-slate-950">Employee Payroll Computation</h3>
                        <p id="modalEmpSubtitle" class="text-[11px] sm:text-xs text-slate-700 font-black mt-0.5">Prorated basic calculation with paid leave, undertime & CA repayment schedules</p>
                    </div>
                </div>
                <button onclick="closeEmployeePayrollModal()" class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-slate-200 text-slate-800 hover:bg-slate-300 hover:text-slate-950 flex items-center justify-center transition font-black border border-slate-400 shrink-0"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <div class="p-4 sm:p-8 overflow-y-auto flex-1 space-y-6">

                <!-- Filter Controls Moved Up to Drive Summary Cards & Calculations -->
                <div class="flex flex-wrap items-center justify-between gap-4 bg-white p-4 rounded-2xl border-2 border-slate-300 shadow-2xs">
                    <div class="flex flex-wrap items-center gap-4 w-full sm:w-auto">
                        <div class="flex-1 sm:flex-initial">
                            <label class="block text-[10px] font-black text-slate-800 uppercase tracking-wider mb-1">Filter Start Date</label>
                            <input type="date" id="computeStartDate" value="2026-08-01" onchange="reloadEmployeePayrollData()" class="w-full sm:w-auto px-3.5 py-2 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950 bg-slate-50 focus:outline-blue-700 transition">
                        </div>
                        <div class="flex-1 sm:flex-initial">
                            <label class="block text-[10px] font-black text-slate-800 uppercase tracking-wider mb-1">Filter End Date</label>
                            <input type="date" id="computeEndDate" value="<?php echo date('Y-m-d'); ?>" onchange="reloadEmployeePayrollData()" class="w-full sm:w-auto px-3.5 py-2 border-2 border-slate-400 rounded-xl text-xs font-black text-slate-950 bg-slate-50 focus:outline-blue-700 transition">
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4">
                    <div class="bg-white p-4 sm:p-5 rounded-2xl border-2 border-slate-300 shadow-xs hover:shadow-md transition flex flex-col justify-between group">
                        <span class="text-[10px] sm:text-[11px] font-black uppercase text-slate-700 tracking-wider">Work Days</span>
                        <div class="flex items-center justify-between mt-3">
                            <span id="cardWorkDays" class="text-xl sm:text-2xl font-black text-slate-950 group-hover:text-blue-700 transition">0</span>
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center shadow-xs border border-blue-200"><i class="fa-solid fa-calendar-check text-xs sm:text-sm"></i></div>
                        </div>
                    </div>
                    <div class="bg-white p-4 sm:p-5 rounded-2xl border-2 border-slate-300 shadow-xs hover:shadow-md transition flex flex-col justify-between group">
                        <span class="text-[10px] sm:text-[11px] font-black uppercase text-slate-700 tracking-wider">Absences</span>
                        <div class="flex items-center justify-between mt-3">
                            <span id="cardAbsences" class="text-xl sm:text-2xl font-black text-red-700">0</span>
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-red-100 text-red-700 flex items-center justify-center shadow-xs border border-red-200"><i class="fa-solid fa-user-xmark text-xs sm:text-sm"></i></div>
                        </div>
                    </div>
                    <div class="bg-white p-4 sm:p-5 rounded-2xl border-2 border-slate-300 shadow-xs hover:shadow-md transition flex flex-col justify-between group">
                        <span class="text-[10px] sm:text-[11px] font-black uppercase text-slate-700 tracking-wider">Paid Leave</span>
                        <div class="flex items-center justify-between mt-3">
                            <span id="cardPaidLeave" class="text-xl sm:text-2xl font-black text-emerald-700">0</span>
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shadow-xs border border-emerald-200"><i class="fa-solid fa-umbrella-beach text-xs sm:text-sm"></i></div>
                        </div>
                    </div>
                    <div class="bg-white p-4 sm:p-5 rounded-2xl border-2 border-slate-300 shadow-xs hover:shadow-md transition flex flex-col justify-between group">
                        <span class="text-[10px] sm:text-[11px] font-black uppercase text-slate-700 tracking-wider">Tardy Minutes</span>
                        <div class="flex items-center justify-between mt-3">
                            <span id="cardTardyMinutes" class="text-xl sm:text-2xl font-black text-amber-700">0m</span>
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center shadow-xs border border-amber-200"><i class="fa-solid fa-business-time text-xs sm:text-sm"></i></div>
                        </div>
                    </div>
                    <div class="bg-white p-4 sm:p-5 rounded-2xl border-2 border-slate-300 shadow-xs hover:shadow-md transition flex flex-col justify-between col-span-2 sm:col-span-1 group">
                        <span class="text-[10px] sm:text-[11px] font-black uppercase text-slate-700 tracking-wider">Early Out Minutes</span>
                        <div class="flex items-center justify-between mt-3">
                            <span id="cardEarlyOutMinutes" class="text-xl sm:text-2xl font-black text-orange-700">0m</span>
                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-orange-100 text-orange-700 flex items-center justify-center shadow-xs border border-orange-200"><i class="fa-solid fa-person-running text-xs sm:text-sm"></i></div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Payroll Cycles Only -->
                <div id="tabContent-payrolls" class="space-y-4">
                    <div class="bg-white rounded-2xl border-2 border-slate-300 overflow-hidden shadow-2xs">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs border-collapse min-w-[750px]">
                                <thead>
                                    <tr class="bg-slate-200 text-slate-950 font-black uppercase text-[10px] border-b-2 border-slate-300 tracking-wider">
                                        <th class="py-4 px-6 border-r border-slate-300">Pay Period</th>
                                        <th class="py-4 px-6 border-r border-slate-300">Worked / Unworked</th>
                                        <th class="py-4 px-6 border-r border-slate-300">Paid Leave</th>
                                        <th class="py-4 px-6 border-r border-slate-300">Basic Pay</th>
                                        <th class="py-4 px-6 border-r border-slate-300">Overtime</th>
                                        <th class="py-4 px-6 border-r border-slate-300">Total Deductions</th>
                                        <th class="py-4 px-6 border-r border-slate-300">Net Payout</th>
                                        <th class="py-4 px-6 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="modalPayrollTableBody" class="divide-y-2 divide-slate-200">
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

            <div class="px-5 sm:px-8 py-4 bg-slate-200 border-t-2 border-slate-300 flex justify-end shrink-0">
                <button type="button" onclick="closeEmployeePayrollModal()" class="w-full sm:w-auto px-6 py-2.5 bg-slate-300 hover:bg-slate-400 text-slate-950 text-xs font-black rounded-xl transition shadow-2xs border border-slate-400">Close Modal</button>
            </div>
        </div>
    </div>

    <!-- Payslip Preview Modal -->
    <div id="payslipModal" class="fixed inset-0 bg-slate-950/75 backdrop-blur-md z-60 hidden flex items-center justify-center p-2 sm:p-4">
        <div class="bg-white rounded-3xl max-w-xl w-full shadow-2xl border-2 border-slate-400 overflow-hidden max-h-[94vh] flex flex-col">
            <div class="px-5 sm:px-6 py-4 border-b-2 border-slate-300 flex justify-between items-center bg-slate-200 no-print shrink-0">
                <h3 class="text-xs sm:text-sm font-black text-slate-950 flex items-center">
                    <div class="w-7 h-7 rounded-lg bg-blue-200 text-blue-800 flex items-center justify-center mr-2 shadow-2xs border border-blue-300"><i class="fa-solid fa-receipt text-xs"></i></div> Thermal Payslip Preview
                </h3>
                <button onclick="closePayslipModal()" class="w-8 h-8 rounded-lg bg-slate-300 text-slate-800 hover:bg-slate-400 flex items-center justify-center font-black transition border border-slate-400"><i class="fa-solid fa-xmark text-sm"></i></button>
            </div>
            
            <div class="p-4 sm:p-6 bg-slate-100 overflow-y-auto flex-1 flex justify-center">
                <!-- Thermal Layout Container -->
                <div id="printablePayslip" class="w-full max-w-[320px] bg-white p-4 font-mono text-[11px] text-slate-950 font-bold leading-tight space-y-3 shadow-sm border border-slate-300 rounded-xl">
                    
                    <div class="text-center border-b border-dashed border-slate-900 pb-2 space-y-0.5">
                        <h2 class="text-sm font-black tracking-widest uppercase">GIGPAY CORPORATION</h2>
                        <p class="text-[9px] text-slate-600">Official Statement of Earnings</p>
                        <p id="modalPayPeriod" class="text-[9px] font-bold text-slate-800 mt-1"></p>
                        <div><span id="modalStatus" class="inline-block px-2 py-0.5 bg-slate-200 text-slate-900 rounded text-[9px] uppercase font-bold mt-1">Generated</span></div>
                    </div>

                    <div class="border-b border-dashed border-slate-900 pb-2 space-y-1 text-[10px]">
                        <div class="flex justify-between"><span>EMP ID:</span> <span id="modalReceiptEmpId" class="font-black text-blue-800"></span></div>
                        <div class="flex justify-between"><span>NAME:</span> <span id="modalEmpName" class="font-black"></span></div>
                        <div class="flex justify-between"><span>DEPT:</span> <span id="modalEmpDept" class="font-black"></span></div>
                    </div>

                    <!-- Earnings Section -->
                    <div class="space-y-1 border-b border-dashed border-slate-900 pb-2 text-[10px]">
                        <div class="font-black text-slate-900 uppercase tracking-wider text-[10px]">--- EARNINGS ---</div>
                        <div class="flex justify-between"><span>Basic Pay:</span> <span id="modalBasicPay"></span></div>
                        <div class="flex justify-between"><span>Paid Leave (<span id="modalPlDays">0</span>d):</span> <span id="modalPaidLeavePay"></span></div>
                        <div class="flex justify-between"><span>Overtime Pay:</span> <span id="modalOvertime"></span></div>
                        <div class="flex justify-between"><span>Allowance:</span> <span id="modalAllowance"></span></div>
                    </div>

                    <!-- Deductions Section -->
                    <div class="space-y-1 border-b border-dashed border-slate-900 pb-2 text-[10px]">
                        <div class="font-black text-slate-900 uppercase tracking-wider text-[10px]">--- DEDUCTIONS ---</div>
                        <div class="flex justify-between"><span>SSS Share:</span> <span id="modalSss"></span></div>
                        <div class="flex justify-between"><span>PhilHealth:</span> <span id="modalPhilhealth"></span></div>
                        <div class="flex justify-between"><span>Pag-IBIG:</span> <span id="modalPagibig"></span></div>
                        <div class="flex justify-between"><span>Tardy Ded.:</span> <span id="modalTardyDeduction"></span></div>
                        <div class="flex justify-between"><span>Undertime Ded.:</span> <span id="modalUndertimeDeduction"></span></div>
                        <div class="flex justify-between"><span>Cash Advance:</span> <span id="modalCashAdvance"></span></div>
                        <div class="flex justify-between font-black pt-1 border-t border-dotted border-slate-600"><span>TOTAL DED:</span> <span id="modalTotalDeductions"></span></div>
                    </div>

                    <!-- Net Pay Receipt Block -->
                    <div class="bg-slate-100 p-2.5 rounded border border-slate-400 text-center space-y-1">
                        <span class="text-[9px] font-bold uppercase tracking-wider text-slate-700 block">NET PAYOUT AMOUNT</span>
                        <span id="modalNetPay" class="text-sm font-black text-emerald-800 block"></span>
                    </div>

                    <div class="pt-2 text-center text-[9px] text-slate-600 space-y-2">
                        <p class="italic">Thank you! System generated payslip.</p>
                        <div class="pt-3">
                            <span class="inline-block border-b border-slate-800 w-32 mb-1"></span>
                            <span class="block text-[8px] uppercase">Employee Signature</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-5 sm:px-6 py-3 bg-slate-200 border-t-2 border-slate-300 flex flex-col sm:flex-row gap-2 modal-actions shrink-0 justify-between items-center">
                <button type="button" onclick="closePayslipModal()" class="w-full sm:w-auto px-4 py-2 bg-slate-300 hover:bg-slate-400 text-slate-900 text-xs font-black rounded-xl transition border border-slate-400">Close</button>
                <div class="flex flex-wrap gap-2 w-full sm:w-auto justify-end">
                    <button type="button" onclick="window.print();" class="flex-1 sm:flex-initial px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-black rounded-xl transition shadow-xs flex items-center justify-center border border-blue-900"><i class="fa-solid fa-print mr-1.5"></i> Print Thermal</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 bg-slate-950/75 backdrop-blur-md z-70 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border-2 border-slate-400 transform transition-all text-center space-y-4">
            <div class="w-14 h-14 bg-red-100 text-red-700 rounded-2xl flex items-center justify-center mx-auto text-lg font-black border-2 border-red-300 shadow-xs">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </div>
            <div>
                <h3 class="text-base font-black text-slate-950">Ready to Leave?</h3>
                <p class="text-xs font-black text-slate-700 mt-1">Are you sure you want to log out of your admin session?</p>
            </div>
            <div class="flex space-x-2 pt-2">
                <button onclick="closeLogoutModal()" class="flex-1 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-900 text-xs font-black rounded-xl transition border border-slate-400">Cancel</button>
                <a href="index.php?logout=true" class="flex-1 py-2.5 bg-red-700 hover:bg-red-800 text-white text-xs font-black rounded-xl transition shadow-xs flex items-center justify-center border border-red-900">Logout</a>
            </div>
        </div>
    </div>

    <script>
        let currentFilteredEmployee = {};
        let cachedPayslipData = null;

        function toggleMobileSidebar() {
            let sidebar = document.querySelector('aside');
            let overlay = document.getElementById('mobileSidebarOverlay');
            sidebar.classList.toggle('hidden');
            sidebar.classList.toggle('fixed');
            sidebar.classList.toggle('inset-y-0');
            sidebar.classList.toggle('left-0');
            sidebar.classList.toggle('z-50');
            overlay.classList.toggle('hidden');
        }

        function toggleSelectAll(masterCheckbox) {
            let checkboxes = document.querySelectorAll('.emp-checkbox');
            checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
        }

        function getSelectedEmployeeIds() {
            let checkboxes = document.querySelectorAll('.emp-checkbox:checked');
            let ids = [];
            checkboxes.forEach(cb => ids.push(cb.value));
            return ids;
        }

        function batchMarkAsPaid() {
            let ids = getSelectedEmployeeIds();
            if(ids.length === 0) {
                alert("Please select at least one employee from the list.");
                return;
            }

            let startDate = document.getElementById('globalBatchStart').value;
            let endDate = document.getElementById('globalBatchEnd').value;

            if(!confirm(`Are you sure you want to mark payroll as Paid for ${ids.length} selected employee(s) for the period ${startDate} to ${endDate}?`)) return;

            fetch(`payroll.php?action=mark_payroll_paid&employee_ids=${ids.join(',')}&start_date=${startDate}&end_date=${endDate}`)
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        alert("Selected payroll records successfully marked as paid!");
                        location.reload();
                    } else {
                        alert("Error: " + (data.error || "Unable to update status"));
                    }
                })
                .catch(err => alert("An error occurred during batch processing."));
        }

        function batchSendEmail() {
            let ids = getSelectedEmployeeIds();
            if(ids.length === 0) {
                alert("Please select at least one employee from the list.");
                return;
            }

            let startDate = document.getElementById('globalBatchStart').value;
            let endDate = document.getElementById('globalBatchEnd').value;

            if(!confirm(`Send email notifications to ${ids.length} selected employee(s)? Note: Ensure their payroll records are generated or marked as paid first.`)) return;

            Promise.all(ids.map(empId => 
                fetch(`payroll.php?action=fetch_employee_payroll_details&employee_id=${empId}&start_date=${startDate}&end_date=${endDate}`).then(r => r.json())
            )).then(results => {
                let payrollIds = [];
                results.forEach(res => {
                    if(res.payrolls && res.payrolls.length > 0 && res.payrolls[0].id > 0) {
                        payrollIds.push(res.payrolls[0].id);
                    }
                });

                if(payrollIds.length === 0) {
                    alert("No valid generated/paid payroll records found for the selected employees within this period. Please mark them as paid first or generate records.");
                    return;
                }

                fetch(`payroll.php?action=notify_employee_email&payroll_ids=${payrollIds.join(',')}`)
                    .then(res => res.json())
                    .then(data => {
                        if(data.success) {
                            alert(`Successfully sent email notifications to ${data.sent_count} employee(s)!`);
                        } else {
                            alert("Error: " + (data.error || "Failed to send emails"));
                        }
                    });
            }).catch(err => {
                alert("An error occurred while preparing batch emails.");
            });
        }

        function openEmployeePayrollModal(empId) {
            reloadEmployeePayrollData(empId);
        }

        function reloadEmployeePayrollData(specificEmpId = null) {
            let empId = specificEmpId || (currentFilteredEmployee && currentFilteredEmployee.id);
            if(!empId) return;

            let startDate = document.getElementById('computeStartDate').value;
            let endDate = document.getElementById('computeEndDate').value;

            fetch(`payroll.php?action=fetch_employee_payroll_details&employee_id=${empId}&start_date=${startDate}&end_date=${endDate}`)
                .then(async res => {
                    let text = await res.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Invalid JSON response from server.');
                    }
                })
                .then(data => {
                    if(data.error) {
                        alert(data.error);
                        return;
                    }
                    currentFilteredEmployee = data.employee;
                    let exactEmpId = '#' + data.employee.id + ' | gsc';
                    document.getElementById('modalEmpIdBadge').innerText = 'ID: ' + exactEmpId;
                    document.getElementById('modalEmpTitleName').innerText = data.employee.first_name + ' ' + data.employee.last_name;
                    document.getElementById('modalEmpSubtitle').innerText = (data.employee.department || 'Department N/A') + ' • ' + (data.employee.position || 'Position N/A');

                    renderPayrollTable(data.payrolls, data.employee);
                    updateSummaryCards(data.payrolls);

                    document.getElementById('employeeModal').classList.remove('hidden');
                })
                .catch(err => {
                    console.error('Error loading details:', err);
                    alert('An error occurred while fetching details.');
                });
        }

        function updateSummaryCards(payrolls) {
            let totalWorkDays = 0;
            let totalAbsences = 0;
            let totalPaidLeave = 0;
            let totalTardyMins = 0;
            let totalEarlyOutMins = 0;

            if (payrolls && payrolls.length > 0) {
                payrolls.forEach(p => {
                    totalWorkDays += parseInt(p.days_worked || 0);
                    totalAbsences += parseInt(p.absent_days || 0);
                    totalPaidLeave += parseInt(p.paid_leave_days || 0);
                    totalTardyMins += parseInt(p.tardy_minutes || 0);
                    totalEarlyOutMins += parseInt(p.undertime_minutes || 0);
                });
            }

            document.getElementById('cardWorkDays').innerText = totalWorkDays;
            document.getElementById('cardAbsences').innerText = totalAbsences;
            document.getElementById('cardPaidLeave').innerText = totalPaidLeave;
            document.getElementById('cardTardyMinutes').innerText = totalTardyMins + 'm';
            document.getElementById('cardEarlyOutMinutes').innerText = totalEarlyOutMins + 'm';
        }

        function renderPayrollTable(payrolls, employee) {
            let payrollTable = document.getElementById('modalPayrollTableBody');
            payrollTable.innerHTML = '';
            if(payrolls && payrolls.length > 0) {
                payrolls.forEach(row => {
                    let tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-100 transition font-bold';
                    row.first_name = employee.first_name;
                    row.last_name = employee.last_name;
                    row.department = employee.department;

                    let absentDisplay = parseInt(row.absent_days || 0) > 0 ? `${row.absent_days}d` : `<span class="text-slate-500 font-bold">-</span>`;
                    let statusBadge = row.status === 'Paid' 
                        ? `<span class="px-2.5 py-1 bg-emerald-100 text-emerald-950 rounded-lg font-black text-[10px] border-2 border-emerald-400 ml-2">Paid</span>` 
                        : `<span class="px-2.5 py-1 bg-amber-100 text-amber-950 rounded-lg font-black text-[10px] border-2 border-amber-400 ml-2">Preview</span>`;

                    let plDays = parseInt(row.paid_leave_days || 0);
                    let plDisplay = plDays > 0 ? `${plDays}d (+₱${parseFloat(row.paid_leave_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2})})` : `<span class="text-slate-600 font-black">0d</span>`;

                    tr.innerHTML = `
                        <td class="py-4 px-6 font-black text-slate-950 border-r border-slate-300">
                            ${row.pay_period_start} to ${row.pay_period_end} ${statusBadge}
                        </td>
                        <td class="py-4 px-6 text-slate-900 font-black border-r border-slate-300">Wrk: <span class="font-black text-emerald-800">${row.days_worked}d</span> | Abs: <span class="font-black text-slate-700">${absentDisplay}</span></td>
                        <td class="py-4 px-6 font-black text-emerald-800 border-r border-slate-300">${plDisplay}</td>
                        <td class="py-4 px-6 font-black text-slate-950 border-r border-slate-300">₱${parseFloat(row.basic_pay).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td class="py-4 px-6 font-black text-blue-800 border-r border-slate-300">+₱${parseFloat(row.overtime_pay).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td class="py-4 px-6 font-black text-red-700 border-r border-slate-300">-₱${parseFloat(row.total_deductions).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td class="py-4 px-6 font-black text-emerald-800 text-sm border-r border-slate-300">₱${parseFloat(row.net_pay).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td class="py-4 px-6 text-right">
                            <button onclick='openPayslipModal(${JSON.stringify(row)})' class="px-3.5 py-2 bg-blue-700 hover:bg-blue-800 text-white font-black rounded-xl transition shadow-xs text-xs inline-flex items-center space-x-1.5 border border-blue-900">
                                <i class="fa-solid fa-eye text-xs"></i> <span>View / Actions</span>
                            </button>
                        </td>
                    `;
                    payrollTable.appendChild(tr);
                });
            } else {
                payrollTable.innerHTML = `<tr><td colspan="8" class="py-8 text-center text-slate-700 font-black italic">No computed payroll cycles found for this period.</td></tr>`;
            }
        }

        function closeEmployeePayrollModal() {
            document.getElementById('employeeModal').classList.add('hidden');
        }

        function openPayslipModal(data) {
            cachedPayslipData = data;
            let empIdNum = data.employee_id || currentFilteredEmployee.id;
            document.getElementById('modalReceiptEmpId').innerText = '#' + empIdNum;
            document.getElementById('modalEmpName').innerText = (data.first_name || currentFilteredEmployee.first_name) + ' ' + (data.last_name || currentFilteredEmployee.last_name);
            document.getElementById('modalEmpDept').innerText = data.department || currentFilteredEmployee.department || 'N/A';
            document.getElementById('modalPayPeriod').innerText = 'Period: ' + data.pay_period_start + ' to ' + data.pay_period_end;
            
            let statusElem = document.getElementById('modalStatus');
            statusElem.innerText = data.status || 'Generated';
            statusElem.className = (data.status === 'Paid') 
                ? 'inline-block px-2 py-0.5 bg-emerald-100 text-emerald-950 rounded text-[9px] uppercase font-bold mt-1' 
                : 'inline-block px-2 py-0.5 bg-amber-100 text-amber-950 rounded text-[9px] uppercase font-bold mt-1';

            document.getElementById('modalBasicPay').innerText = '₱' + parseFloat(data.basic_pay).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalPlDays').innerText = data.paid_leave_days || 0;
            document.getElementById('modalPaidLeavePay').innerText = '+₱' + parseFloat(data.paid_leave_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalOvertime').innerText = '+₱' + parseFloat(data.overtime_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalAllowance').innerText = '+₱' + parseFloat(data.allowance || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            
            document.getElementById('modalSss').innerText = '-₱' + parseFloat(data.sss_deduction || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalPhilhealth').innerText = '-₱' + parseFloat(data.philhealth_deduction || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalPagibig').innerText = '-₱' + parseFloat(data.pagibig_deduction || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalTardyDeduction').innerText = '-₱' + parseFloat(data.tardy_deduction || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalUndertimeDeduction').innerText = '-₱' + parseFloat(data.undertime_deduction || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalCashAdvance').innerText = '-₱' + parseFloat(data.cash_advance || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('modalTotalDeductions').innerText = '-₱' + parseFloat(data.total_deductions || 0).toLocaleString('en-US', {minimumFractionDigits: 2});

            document.getElementById('modalNetPay').innerText = '₱' + parseFloat(data.net_pay).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('payslipModal').classList.remove('hidden');
        }

        function closePayslipModal() {
            document.getElementById('payslipModal').classList.add('hidden');
        }

        function openLogoutModal()  {
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }
    </script>
</body>
</html>