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

// Handle Logout Request
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_destroy();
    header("Location: index.php");
    exit();
}

// 1. HANDLE LOGIN FORM SUBMISSION FROM login.php / index.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emp_id']) && isset($_POST['password']) && !isset($_POST['action'])) {
    $emp_identifier = trim($_POST['emp_id']);
    $password = trim($_POST['password']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? OR email = ? LIMIT 1");
        $stmt->execute([$emp_identifier, $emp_identifier]);
        $employeeData = $stmt->fetch();

        if ($employeeData && (password_verify($password, $employeeData['password'] ?? '') || $password === ($employeeData['password'] ?? ''))) {
            $_SESSION['employee_id'] = $employeeData['id'];
            header("Location: employee_dashboard.php");
            exit();
        } else {
            header("Location: index.php?error=invalid_employee_credentials");
            exit();
        }
    } catch (Exception $e) {
        header("Location: index.php?error=db_error");
        exit();
    }
}

// 2. AUTHENTICATION GUARD
if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
    exit();
}

$employee_id = $_SESSION['employee_id'];
$success_msg = '';
$error_msg = '';
$active_tab = $_GET['tab'] ?? 'overview';

// Handle Action Submissions from Inside Dashboard Tabs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        // 1. Unified Dynamic Request (Leave, OT, Cash Advance)
        if ($action === 'submit_request') {
            $req_type = trim($_POST['req_type']); 
            $reason = trim($_POST['reason']);
            
            if ($req_type === 'Leave') {
                $leave_category = trim($_POST['leave_category'] ?? 'Sick Leave');
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];

                $stmt = $pdo->prepare("INSERT INTO requests (employee_id, type, details, start_date, end_date, status, created_at) VALUES (?, 'Leave', ?, ?, ?, 'Pending', NOW())");
                $stmt->execute([$employee_id, "$leave_category: $reason", $start_date, $end_date]);
                
            } elseif ($req_type === 'Overtime') {
                $ot_date = $_POST['ot_date'];
                $hours = (float)$_POST['hours'];

                $stmt = $pdo->prepare("INSERT INTO requests (employee_id, type, details, start_date, hours, status, created_at) VALUES (?, 'Overtime', ?, ?, ?, 'Pending', NOW())");
                $stmt->execute([$employee_id, $reason, $ot_date, $hours]);

            } elseif ($req_type === 'Cash Advance') {
                $amount = (float)$_POST['amount'];

                $stmt = $pdo->prepare("INSERT INTO requests (employee_id, type, details, amount, status, created_at) VALUES (?, 'Cash Advance', ?, ?, 'Pending', NOW())");
                $stmt->execute([$employee_id, $reason, $amount]);

            } elseif ($req_type === 'Attendance Correction') {
                $correction_date = $_POST['correction_date'] ?? '';
                $scan_type = trim($_POST['scan_type'] ?? '');
                $correct_time = trim($_POST['correct_time'] ?? '');

                $details = "Scan Type: $scan_type | Correct Time: $correct_time | Reason: $reason";

                $stmt = $pdo->prepare("INSERT INTO requests (employee_id, type, details, start_date, status, created_at) VALUES (?, 'Attendance Correction', ?, ?, 'Pending', NOW())");
                $stmt->execute([$employee_id, $details, $correction_date]);
            }

            $success_msg = "$req_type request submitted successfully for admin approval!";
            $active_tab = 'requests';
        }

        // Edit / Update Pending Request
        elseif ($action === 'update_request') {
            $req_id = (int)$_POST['request_id'];
            $reason = trim($_POST['reason']);
            
            $chk = $pdo->prepare("SELECT * FROM requests WHERE id = ? AND employee_id = ? AND status = 'Pending'");
            $chk->execute([$req_id, $employee_id]);
            $targetReq = $chk->fetch();

            if ($targetReq) {
                $upd = $pdo->prepare("UPDATE requests SET details = ? WHERE id = ?");
                $upd->execute([$reason, $req_id]);
                $success_msg = "Request ticket #$req_id updated successfully!";
            } else {
                $error_msg = "Unable to edit request. It may already be approved/rejected or unauthorized.";
            }
            $active_tab = 'requests';
        }

        // 2. Direct Employee Profile & Account Update + Admin Notification
        elseif ($action === 'update_profile_and_account') {
            $phone = trim($_POST['phone']);
            $address = trim($_POST['address']);
            $emergency_name = trim($_POST['emergency_name']);
            $emergency_relation = trim($_POST['emergency_relation']);
            $emergency_phone = trim($_POST['emergency_phone']);
            $email = trim($_POST['email']);
            $password = trim($_POST['password']);

            // Update employee record directly (Fixed duplicate/missing input mappings to match database columns)
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE employees SET phone = ?, street_address = ?, emergency_name = ?, emergency_relation = ?, emergency_phone = ?, email = ?, password = ? WHERE id = ?");
                $stmt->execute([$phone, $address, $emergency_name, $emergency_relation, $emergency_phone, $email, $hashed, $employee_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE employees SET phone = ?, street_address = ?, emergency_name = ?, emergency_relation = ?, emergency_phone = ?, email = ? WHERE id = ?");
                $stmt->execute([$phone, $address, $emergency_name, $emergency_relation, $emergency_phone, $email, $employee_id]);
            }

            // Automatically notify admin via requests table
            $notification_details = "Employee updated their personal and contact information (Phone, Address, or Emergency Details).";
            $notifStmt = $pdo->prepare("INSERT INTO requests (employee_id, type, details, status, created_at) VALUES (?, 'Info Update', ?, 'Pending', NOW())");
            $notifStmt->execute([$employee_id, $notification_details]);

            $success_msg = "Your personal information and account settings were updated successfully, and admin has been notified!";
            $active_tab = 'settings';
        }

    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Fetch Current Employee Data Safely
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    session_destroy();
    header("Location: index.php?error=employee_not_found");
    exit();
}

$f01h_biometric_id = $employee['f01h_biometric_id'] ?? $employee['fingerprint_id'] ?? $employee['id'];

// Fetch Paid Leave Balance
$paid_leave_balance = $employee['paid_leave_balance'] ?? $employee['leave_balance'] ?? 15;

// Fetch Attendance Logs Correctly with Enhanced Transparency and 8:15 Rule Logic Implementation
$attendance_logs = [];
$attendance_by_date = [];
try {
    $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? OR employee_id = ? ORDER BY timestamp DESC");
    $attStmt->execute([$employee_id, $f01h_biometric_id]);
    $attendance_logs = $attStmt->fetchAll();

    foreach ($attendance_logs as &$log) {
        $checkInTimeStr = $log['check_in'] ?? $log['time_in'] ?? $log['timestamp'] ?? null;
        if (!empty($checkInTimeStr) && $checkInTimeStr !== '0000-00-00 00:00:00') {
            $checkInTimestamp = strtotime($checkInTimeStr);
            if ($checkInTimestamp) {
                $logDate = date('Y-m-d', $checkInTimestamp);
                
                $shiftStartTimestamp = strtotime("$logDate 08:00:00");
                $tardyGraceLimitTimestamp = strtotime("$logDate 08:15:00");

                if ($checkInTimestamp > $tardyGraceLimitTimestamp) {
                    $calculatedLateMins = round(($checkInTimestamp - $shiftStartTimestamp) / 60);
                    if (empty($log['tardy_minutes']) || $log['tardy_minutes'] == 0) {
                        $log['tardy_minutes'] = $calculatedLateMins;
                    }
                } else {
                    if (empty($log['tardy_minutes'])) {
                        $log['tardy_minutes'] = 0;
                    }
                }
                
                $attendance_by_date[$logDate] = $log;
            }
        }
    }
    unset($log);
} catch (Exception $e) {}

// Fetch Company Holidays / Admin Calendar Updates from company_holidays table
$company_holidays_map = [];
try {
    $holidayStmt = $pdo->query("SELECT * FROM company_holidays");
    $allHolidays = $holidayStmt->fetchAll();
    
    foreach ($allHolidays as $hol) {
        $title = $hol['title'] ?? 'Company Event / Holiday';
        $type = $hol['type'] ?? 'Holiday';
        $description = $hol['description'] ?? '';
        
        $start = $hol['start_date'] ?? '';
        $end = (!empty($hol['end_date']) && $hol['end_date'] !== '0000-00-00') ? $hol['end_date'] : $start;
        
        if (!empty($start) && $start !== '0000-00-00') {
            $curr = strtotime($start);
            $last = strtotime($end);
            
            while ($curr <= $last) {
                $dKey = date('Y-m-d', $curr);
                $company_holidays_map[$dKey] = [
                    'title' => $title,
                    'type' => $type,
                    'description' => $description
                ];
                $curr = strtotime("+1 day", $curr);
            }
        }
    }
} catch (Exception $e) {}

// Fetch Request Dates to map on calendars (Leave ranges or OT/CA dates)
$request_dates_map = [];
try {
    $reqDateStmt = $pdo->prepare("SELECT type, start_date, end_date, status FROM requests WHERE employee_id = ?");
    $reqDateStmt->execute([$employee_id]);
    $allRequests = $reqDateStmt->fetchAll();
    
    foreach ($allRequests as $req) {
        $status = $req['status'] ?? 'Pending';
        $type = $req['type'] ?? 'Request';
        
        if (!empty($req['start_date']) && $req['start_date'] !== '0000-00-00') {
            $start = $req['start_date'];
            $end = (!empty($req['end_date']) && $req['end_date'] !== '0000-00-00') ? $req['end_date'] : $start;
            
            $currentPeriod = strtotime($start);
            $endPeriod = strtotime($end);
            
            while ($currentPeriod <= $endPeriod) {
                $dKey = date('Y-m-d', $currentPeriod);
                $request_dates_map[$dKey] = [
                    'type' => $type,
                    'status' => $status
                ];
                $currentPeriod = strtotime("+1 day", $currentPeriod);
            }
        }
    }
} catch (Exception $e) {}

$total_workdays = count($attendance_logs);
$total_workhours = 0.0;
foreach ($attendance_logs as $log) {
    $cIn = $log['check_in'] ?? $log['time_in'] ?? $log['timestamp'] ?? null;
    $cOut = $log['check_out'] ?? $log['time_out'] ?? null;
    if (!empty($cIn) && !empty($cOut) && $cIn !== '0000-00-00 00:00:00' && $cOut !== '0000-00-00 00:00:00') {
        $inTime = strtotime($cIn);
        $outTime = strtotime($cOut);
        if ($outTime > $inTime) {
            $total_workhours += ($outTime - $inTime) / 3600;
        }
    }
}

$total_absents = 0;
try {
    if (isset($employee['absent_days'])) {
        $total_absents = (float)$employee['absent_days'];
    } elseif (isset($employee['absents'])) {
        $total_absents = (float)$employee['absents'];
    } else {
        $absStmt = $pdo->prepare("SELECT absent_days FROM payrolls WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
        $absStmt->execute([$employee_id]);
        $absRes = $absStmt->fetch();
        if ($absRes && isset($absRes['absent_days'])) {
            $total_absents = (float)$absRes['absent_days'];
        }
    }
} catch (Exception $e) {
    $total_absents = 0;
}

$requests = [];
try {
    $reqStmt = $pdo->prepare("SELECT * FROM requests WHERE employee_id = ? ORDER BY created_at DESC");
    $reqStmt->execute([$employee_id]);
    $requests = $reqStmt->fetchAll();
} catch (Exception $e) {}

$payslips = [];
try {
    $payStmt = $pdo->prepare("SELECT * FROM payrolls WHERE employee_id = ? ORDER BY created_at DESC");
    $payStmt->execute([$employee_id]);
    $payslips = $payStmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 font-bold text-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1280">
    <title>GigPay - Employee Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal-backdrop {
            background-color: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
    </style>
    <script>
        const attendanceDataMap = <?php echo json_encode($attendance_by_date); ?>;
        const requestDatesMap = <?php echo json_encode($request_dates_map); ?>;
        const companyHolidaysMap = <?php echo json_encode($company_holidays_map); ?>;

        function switchTab(tabId) {
            document.querySelectorAll('.dashboard-section').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('bg-violet-700', 'text-white', 'shadow-sm', 'border-slate-800');
                el.classList.add('text-slate-100', 'hover:bg-slate-800', 'hover:text-white', 'border-2', 'border-slate-700');
            });

            document.getElementById('section_' + tabId).classList.remove('hidden');
            const activeBtn = document.getElementById('btn_' + tabId);
            if(activeBtn) {
                activeBtn.classList.add('bg-violet-700', 'text-white', 'shadow-sm', 'border-violet-800');
                activeBtn.classList.remove('text-slate-100', 'hover:bg-slate-800', 'hover:text-white');
            }
            
            if(tabId === 'attendance') {
                renderFullAttendanceCalendar();
            }
        }

        function refreshTabData(tabName) {
            window.location.href = 'employee_dashboard.php?tab=' + tabName;
        }

        function handleRequestTypeChange(val) {
            document.getElementById('leaveFields').classList.add('hidden');
            document.getElementById('otFields').classList.add('hidden');
            document.getElementById('caFields').classList.add('hidden');
            document.getElementById('acFields').classList.add('hidden');

            if (val === 'Leave') {
                document.getElementById('leaveFields').classList.remove('hidden');
            } else if (val === 'Overtime') {
                document.getElementById('otFields').classList.remove('hidden');
            } else if (val === 'Cash Advance') {
                document.getElementById('caFields').classList.remove('hidden');
            } else if (val === 'Attendance Correction') {
                document.getElementById('acFields').classList.remove('hidden');
            }
        }

        function openEditRequestModal(id, details, status) {
            if (status !== 'Pending') {
                alert('Only pending requests can be edited.');
                return;
            }
            document.getElementById('edit_request_id').value = id;
            document.getElementById('edit_reason').value = details;
            document.getElementById('editRequestModal').classList.remove('hidden');
        }

        function closeEditRequestModal() {
            document.getElementById('editRequestModal').classList.add('hidden');
        }

        function confirmLogout(e) {
            e.preventDefault();
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }

        function proceedLogout() {
            window.location.href = 'employee_dashboard.php?logout=true';
        }

        function openPayrollModal(pay) {
            let bodyEl = document.getElementById('payrollModalBody');
            let dateStr = pay.pay_period_start && pay.pay_period_end ? (pay.pay_period_start + ' to ' + pay.pay_period_end) : (pay.created_at || 'N/A');
            let tardyMinsValue = pay.tardy_minutes || pay.tardy_mins || 0;

            bodyEl.innerHTML = `
                <div class="grid grid-cols-2 gap-3 bg-white p-3 rounded-xl border-2 border-slate-600 mb-3 text-slate-900 font-bold">
                    <div><span class="text-slate-900 font-black block uppercase text-[10px]">Pay Period / Date</span> <strong class="text-black text-sm">${dateStr}</strong></div>
                    <div><span class="text-slate-900 font-black block uppercase text-[10px]">Status</span> <strong class="text-emerald-800 text-sm">${pay.status || 'Paid'}</strong></div>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center bg-violet-100/75 p-3 rounded-xl border-2 border-violet-500 mb-3 text-slate-900 font-bold">
                    <div>
                        <span class="text-[10px] font-black uppercase text-slate-900 block">Days Worked</span>
                        <strong class="text-emerald-900 text-sm">${parseFloat(pay.days_worked || 0)} days</strong>
                    </div>
                    <div>
                        <span class="text-[10px] font-black uppercase text-slate-900 block">Absents</span>
                        <strong class="text-red-800 text-sm">${parseFloat(pay.absent_days || 0)} days</strong>
                    </div>
                    <div>
                        <span class="text-[10px] font-black uppercase text-slate-900 block">Tardy Minutes</span>
                        <strong class="text-amber-900 text-sm">${tardyMinsValue} mins</strong>
                    </div>
                </div>

                <div class="space-y-2 bg-white p-3 rounded-xl border-2 border-slate-600 text-slate-900 font-bold">
                    <span class="font-black text-black block border-b-2 border-slate-500 pb-1">Earnings & Deductions Breakdown</span>
                    <div class="flex justify-between text-slate-900"><span>Basic Pay:</span> <strong class="text-black">₱${parseFloat(pay.basic_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></div>
                    <div class="flex justify-between text-slate-900"><span>Allowance:</span> <strong class="text-emerald-900">+₱${parseFloat(pay.allowance || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></div>
                    <div class="flex justify-between text-slate-900"><span>Overtime Pay:</span> <strong class="text-violet-900">+₱${parseFloat(pay.overtime_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></div>
                    <div class="flex justify-between text-slate-900"><span>Cash Advance Deduction:</span> <strong class="text-red-800">-₱${parseFloat(pay.cash_advance || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></div>
                    <div class="flex justify-between text-slate-900"><span>Total Deductions:</span> <strong class="text-red-800">-₱${parseFloat(pay.total_deductions || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></div>
                    <div class="flex justify-between border-t-2 border-slate-500 pt-1 text-sm font-black"><span class="text-black">Net Payout:</span> <span class="text-emerald-900">₱${parseFloat(pay.net_pay || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</span></div>
                </div>
            `;
            document.getElementById('payrollModal').classList.remove('hidden');
        }

        function closePayrollModal() {
            document.getElementById('payrollModal').classList.add('hidden');
        }

        function openDateDetailModal(dateKey, record, reqInfo, holidayInfo) {
            let bodyEl = document.getElementById('attendanceModalBody');
            let contentHtml = '';

            if (holidayInfo) {
                contentHtml += `
                    <div class="bg-amber-50 p-3 rounded-xl border-2 border-amber-500 space-y-1 mb-3 text-slate-900 font-bold">
                        <span class="text-[10px] font-black uppercase text-amber-950 block border-b border-amber-300 pb-1">Admin Company Calendar Update</span>
                        <div class="flex justify-between"><span>Title:</span> <strong class="text-amber-950">${holidayInfo.title}</strong></div>
                        <div class="flex justify-between"><span>Type:</span> <strong class="text-amber-950">${holidayInfo.type}</strong></div>
                        <div class="text-[11px] text-slate-900 mt-1"><strong>Description:</strong> ${holidayInfo.description || 'No additional details provided.'}</div>
                    </div>
                `;
            }

            if (record) {
                let checkIn = record.check_in || record.time_in || record.timestamp || 'N/A';
                let checkOut = record.check_out || record.time_out || 'Not Checked Out Yet';
                let tardyMins = record.tardy_minutes || 0;
                let statusBadge = tardyMins > 0 ? `<span class="px-2 py-0.5 bg-amber-300 text-amber-950 rounded font-black text-[10px] border border-amber-500">Late (${tardyMins} mins)</span>` : `<span class="px-2 py-0.5 bg-emerald-300 text-emerald-950 rounded font-black text-[10px] border border-emerald-500">Present / On Time</span>`;
                
                contentHtml += `
                    <div class="grid grid-cols-2 gap-3 bg-white p-3 rounded-xl border-2 border-slate-600 mb-3 text-slate-900 font-bold">
                        <div><span class="text-slate-900 font-black block uppercase text-[10px]">Date</span> <strong class="text-black text-sm">${dateKey}</strong></div>
                        <div><span class="text-slate-900 font-black block uppercase text-[10px]">Status</span> <div class="mt-0.5">${statusBadge}</div></div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-center bg-violet-100/75 p-3 rounded-xl border-2 border-violet-500 mb-3 text-slate-900 font-bold">
                        <div><span class="text-[10px] font-black uppercase text-slate-900 block">Check-In Time</span><strong class="text-black text-xs">${checkIn}</strong></div>
                        <div><span class="text-[10px] font-black uppercase text-slate-900 block">Check-Out Time</span><strong class="text-black text-xs">${checkOut}</strong></div>
                    </div>
                `;
            } else if (!holidayInfo) {
                contentHtml += `
                    <div class="bg-white p-4 rounded-xl border-2 border-slate-600 text-center space-y-1 mb-3">
                        <div class="text-slate-900 text-base font-black"><i class="fa-solid fa-calendar mr-1"></i> Date: ${dateKey}</div>
                        <p class="text-xs text-slate-800 font-bold">No attendance records logged for this day.</p>
                    </div>
                `;
            }

            if (reqInfo) {
                let badgeColor = reqInfo.status === 'Approved' ? 'bg-emerald-300 text-emerald-950 border-emerald-600' : 'bg-amber-300 text-amber-950 border-amber-600';
                contentHtml += `
                    <div class="bg-violet-50 p-3 rounded-xl border-2 border-violet-400 space-y-1 text-slate-900 font-bold">
                        <span class="text-[10px] font-black uppercase text-violet-950 block border-b border-violet-300 pb-1">Request on this Date</span>
                        <div class="flex justify-between"><span>Type:</span> <strong class="text-violet-950">${reqInfo.type}</strong></div>
                        <div class="flex justify-between"><span>Status:</span> <span class="px-2 py-0.5 rounded text-[10px] border ${badgeColor}">${reqInfo.status}</span></div>
                    </div>
                `;
            }

            bodyEl.innerHTML = contentHtml;
            document.getElementById('attendanceModal').classList.remove('hidden');
        }

        function closeAttendanceModal() {
            document.getElementById('attendanceModal').classList.add('hidden');
        }

        function updateRealtimeClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            const strTime = `${String(hours).padStart(2, '0')}:${minutes}:${seconds} ${ampm}`;
            
            const clockEl = document.getElementById('realtime-clock');
            if (clockEl) clockEl.textContent = strTime;

            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const dateStr = now.toLocaleDateString('en-US', options);
            const dateEl = document.getElementById('realtime-date');
            if (dateEl) dateEl.textContent = dateStr;
        }

        let currentCalendarDate = new Date();
        let currentFullCalendarDate = new Date();

        function renderCalendar() {
            const year = currentCalendarDate.getFullYear();
            const month = currentCalendarDate.getMonth();
            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            
            const monthYearLabel = document.getElementById('calendar-month-year');
            if (monthYearLabel) monthYearLabel.textContent = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const totalDays = new Date(year, month + 1, 0).getDate();
            const prevTotalDays = new Date(year, month, 0).getDate();

            const calendarGrid = document.getElementById('calendar-days-grid');
            if (!calendarGrid) return;
            
            calendarGrid.innerHTML = '';
            const today = new Date();

            for (let i = firstDayIndex; i > 0; i--) {
                const dayDiv = document.createElement('div');
                dayDiv.textContent = prevTotalDays - i + 1;
                dayDiv.className = "h-10 flex flex-col items-center justify-center text-xs text-slate-500 font-bold";
                calendarGrid.appendChild(dayDiv);
            }

            for (let i = 1; i <= totalDays; i++) {
                const dayDiv = document.createElement('div');
                const mFormatted = String(month + 1).padStart(2, '0');
                const dFormatted = String(i).padStart(2, '0');
                const dateKey = `${year}-${mFormatted}-${dFormatted}`;
                const holidayInfo = companyHolidaysMap[dateKey];
                const record = attendanceDataMap[dateKey];
                const reqInfo = requestDatesMap[dateKey];

                dayDiv.className = "h-10 flex flex-col items-center justify-center text-xs font-black rounded-xl border-2 transition relative group cursor-pointer";

                if (holidayInfo) {
                    dayDiv.className += " bg-amber-300 text-amber-950 border-amber-600 hover:bg-amber-400";
                } else if (reqInfo) {
                    if (reqInfo.status === 'Approved') {
                        dayDiv.className += " bg-emerald-300 text-emerald-950 border-emerald-600 hover:bg-emerald-400";
                    } else {
                        dayDiv.className += " bg-violet-300 text-violet-950 border-violet-600 hover:bg-violet-400";
                    }
                } else if (record) {
                    dayDiv.className += " bg-emerald-200 text-emerald-950 border-emerald-600 hover:bg-emerald-300";
                } else if (new Date(dateKey) < today && new Date(dateKey).getDay() !== 0 && new Date(dateKey).getDay() !== 6) {
                    dayDiv.className += " bg-red-200 text-red-950 border-red-600 hover:bg-red-300";
                } else {
                    dayDiv.className += " bg-white text-slate-900 border-slate-500 hover:bg-slate-200";
                }

                let dayNumberSpan = document.createElement('span');
                dayNumberSpan.textContent = i;
                dayDiv.appendChild(dayNumberSpan);

                dayDiv.onclick = () => openDateDetailModal(dateKey, record, reqInfo, holidayInfo);
                calendarGrid.appendChild(dayDiv);
            }
        }

        function renderFullAttendanceCalendar() {
            const year = currentFullCalendarDate.getFullYear();
            const month = currentFullCalendarDate.getMonth();
            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            
            const monthYearLabel = document.getElementById('full_calendar_month_year');
            if (monthYearLabel) monthYearLabel.textContent = `${monthNames[month]} ${year}`;

            const firstDayIndex = new Date(year, month, 1).getDay();
            const totalDays = new Date(year, month + 1, 0).getDate();
            const prevTotalDays = new Date(year, month, 0).getDate();

            const calendarGrid = document.getElementById('full_calendar_grid');
            if (!calendarGrid) return;
            
            calendarGrid.innerHTML = '';
            const today = new Date();

            for (let i = firstDayIndex; i > 0; i--) {
                const dayDiv = document.createElement('div');
                dayDiv.className = "min-h-[105px] bg-slate-100 rounded-xl p-2 border-2 border-slate-400 opacity-50";
                dayDiv.innerHTML = `<span class="text-xs font-black text-slate-500">${prevTotalDays - i + 1}</span>`;
                calendarGrid.appendChild(dayDiv);
            }

            for (let i = 1; i <= totalDays; i++) {
                const dayDiv = document.createElement('div');
                const mFormatted = String(month + 1).padStart(2, '0');
                const dFormatted = String(i).padStart(2, '0');
                const dateKey = `${year}-${mFormatted}-${dFormatted}`;
                const holidayInfo = companyHolidaysMap[dateKey];
                const record = attendanceDataMap[dateKey];
                const reqInfo = requestDatesMap[dateKey];

                let detailsHtml = '';
                let bgClass = "bg-white border-slate-500 hover:bg-slate-100";

                if (holidayInfo) {
                    bgClass = "bg-amber-200 border-amber-600 text-amber-950";
                    detailsHtml += `<div class="mt-1 px-1.5 py-0.5 bg-amber-300 text-amber-950 text-[9px] rounded font-black truncate border border-amber-500" title="${holidayInfo.title}">${holidayInfo.title}</div>`;
                }
                if (reqInfo) {
                    if (reqInfo.status === 'Approved') {
                        bgClass = "bg-emerald-200 border-emerald-600 text-emerald-950";
                        detailsHtml += `<div class="mt-1 px-1.5 py-0.5 bg-emerald-300 text-emerald-950 text-[9px] rounded font-black truncate border border-emerald-500">${reqInfo.type} (Approved)</div>`;
                    } else {
                        bgClass = "bg-violet-200 border-violet-600 text-violet-950";
                        detailsHtml += `<div class="mt-1 px-1.5 py-0.5 bg-violet-300 text-violet-950 text-[9px] rounded font-black truncate border border-violet-500">${reqInfo.type} (${reqInfo.status})</div>`;
                    }
                }
                if (record) {
                    bgClass = "bg-emerald-100 border-emerald-600 text-emerald-950";
                    let tardyMins = record.tardy_minutes || 0;
                    if (tardyMins > 0) {
                        detailsHtml += `<div class="mt-1 px-1.5 py-0.5 bg-amber-300 text-amber-950 text-[9px] rounded font-black truncate border border-amber-500">Late (${tardyMins}m)</div>`;
                    } else {
                        detailsHtml += `<div class="mt-1 px-1.5 py-0.5 bg-emerald-300 text-emerald-950 text-[9px] rounded font-black truncate border border-emerald-500">Present</div>`;
                    }
                } else if (!holidayInfo && !reqInfo && new Date(dateKey) < today && new Date(dateKey).getDay() !== 0 && new Date(dateKey).getDay() !== 6) {
                    bgClass = "bg-red-100 border-red-600 text-red-950";
                    detailsHtml += `<div class="mt-1 px-1.5 py-0.5 bg-red-300 text-red-950 text-[9px] rounded font-black truncate border border-red-500">Absent</div>`;
                }

                dayDiv.className = `min-h-[105px] rounded-xl p-2.5 border-2 transition cursor-pointer flex flex-col justify-between ${bgClass}`;
                dayDiv.innerHTML = `
                    <div class="flex justify-between items-center">
                        <span class="text-xs font-black">${i}</span>
                        ${i === today.getDate() && month === today.getMonth() && year === today.getFullYear() ? '<span class="w-2.5 h-2.5 bg-violet-700 rounded-full border border-violet-900"></span>' : ''}
                    </div>
                    <div class="space-y-1 overflow-hidden">${detailsHtml}</div>
                `;

                dayDiv.onclick = () => openDateDetailModal(dateKey, record, reqInfo, holidayInfo);
                calendarGrid.appendChild(dayDiv);
            }
        }

        function changeCalendarMonth(direction) {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + direction);
            renderCalendar();
        }

        function changeFullCalendarMonth(direction) {
            currentFullCalendarDate.setMonth(currentFullCalendarDate.getMonth() + direction);
            renderFullAttendanceCalendar();
        }

        window.addEventListener('DOMContentLoaded', () => {
            updateRealtimeClock();
            setInterval(updateRealtimeClock, 1000);
            renderCalendar();
            renderFullAttendanceCalendar();
        });
    </script>
</head>
<body class="h-full font-sans antialiased flex flex-row text-slate-900 font-bold bg-slate-200 min-w-[1280px]">

    <!-- Desktop Sidebar Navigation -->
    <aside class="w-64 text-white flex flex-col justify-between shrink-0 select-none border-r-4 border-slate-900 relative bg-cover bg-center" style="background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.92)), url('w.jpg');">
        
        <div class="relative z-10">
            <div class="h-20 flex items-center px-6 space-x-3 border-b-4 border-slate-900">
                <img src="pay.png" alt="GigPay Logo" class="w-10 h-10 object-contain rounded-xl shadow-lg border-2 border-violet-600 bg-violet-700/30 p-1">
                <span class="text-xl font-black tracking-tight text-white">Gig<span class="text-violet-400">Pay</span> <span class="text-xs bg-violet-700/60 text-violet-200 border-2 border-violet-500 px-2 py-0.5 rounded-full ml-1 font-black">Portal</span></span>
            </div>

            <nav class="p-4 space-y-2 text-sm font-black">
                <button onclick="switchTab('overview')" id="btn_overview" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='overview'?'bg-violet-700 text-white shadow-sm border-2 border-violet-900':'text-slate-100 hover:bg-slate-800 hover:text-white border-2 border-slate-700'; ?> transition text-left">
                    <i class="fa-solid fa-chart-pie w-5"></i>
                    <span>Overview</span>
                </button>
                <button onclick="switchTab('attendance')" id="btn_attendance" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='attendance'?'bg-violet-700 text-white shadow-sm border-2 border-violet-900':'text-slate-100 hover:bg-slate-800 hover:text-white border-2 border-slate-700'; ?> transition text-left">
                    <i class="fa-solid fa-calendar-days w-5"></i>
                    <span>My Attendance</span>
                </button>
                <button onclick="switchTab('requests')" id="btn_requests" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='requests'?'bg-violet-700 text-white shadow-sm border-2 border-violet-900':'text-slate-100 hover:bg-slate-800 hover:text-white border-2 border-slate-700'; ?> transition text-left">
                    <i class="fa-solid fa-file-invoice w-5"></i>
                    <span>Leave, OT & Cash Advance</span>
                </button>
                <button onclick="switchTab('payslips')" id="btn_payslips" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='payslips'?'bg-violet-700 text-white shadow-sm border-2 border-violet-900':'text-slate-100 hover:bg-slate-800 hover:text-white border-2 border-slate-700'; ?> transition text-left">
                    <i class="fa-solid fa-wallet w-5"></i>
                    <span>Payslips</span>
                </button>
                <button onclick="switchTab('settings')" id="btn_settings" class="tab-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo $active_tab=='settings'?'bg-violet-700 text-white shadow-sm border-2 border-violet-900':'text-slate-100 hover:bg-slate-800 hover:text-white border-2 border-slate-700'; ?> transition text-left">
                    <i class="fa-solid fa-gear w-5"></i>
                    <span>Account Settings</span>
                </button>
            </nav>
        </div>

        <div class="p-4 border-t-4 border-slate-900 relative z-10">
            <a href="employee_dashboard.php?logout=true" onclick="confirmLogout(event)" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-red-300 hover:bg-red-950/60 hover:text-red-200 border-2 border-red-900 transition text-sm font-black">
                <i class="fa-solid fa-arrow-right-from-bracket w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <header class="h-20 bg-white border-b-4 border-slate-500 flex items-center justify-between px-8 shrink-0 shadow-sm">
            <div class="flex items-center space-x-3">
                <h1 class="text-xl font-black text-black truncate">Employee Workspace</h1>
            </div>
            
            <div class="flex items-center space-x-3 pl-2">
                <div class="w-10 h-10 rounded-full bg-violet-200 text-violet-950 flex items-center justify-center font-black text-sm border-2 border-violet-600 shrink-0">
                    <?php echo strtoupper(substr($employee['first_name'] ?? 'E', 0, 1) . substr($employee['last_name'] ?? 'P', 0, 1)); ?>
                </div>
                <div class="text-left">
                    <p class="text-xs font-black text-black"><?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?></p>
                    <p class="text-[11px] font-extrabold text-slate-800"><?php echo htmlspecialchars($employee['department'] ?? 'General'); ?></p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-8 space-y-6">
            
            <?php if($error_msg): ?>
                <div class="p-4 bg-red-100 text-red-950 border-2 border-red-600 rounded-xl text-sm font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-triangle-exclamation mr-3 text-lg"></i> <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            <?php if($success_msg): ?>
                <div class="p-4 bg-emerald-100 text-emerald-950 border-2 border-emerald-600 rounded-xl text-sm font-black flex items-center shadow-sm">
                    <i class="fa-solid fa-circle-check mr-3 text-lg"></i> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>

            <!-- OVERVIEW TAB -->
            <div id="section_overview" class="dashboard-section space-y-6 <?php echo $active_tab=='overview'?'':'hidden'; ?>">
                <div class="flex justify-between items-center bg-white p-4 rounded-2xl border-2 border-slate-500 shadow-sm">
                    <button onclick="refreshTabData('overview')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-900 text-xs font-black rounded-xl border-2 border-slate-500 transition flex items-center space-x-2">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Refresh Overview</span>
                    </button>
                </div>

                <div class="bg-gradient-to-r from-violet-700 to-purple-900 rounded-2xl p-6 text-white shadow-lg border-2 border-violet-950 flex justify-between items-center">
                    <div>
                        <h2 class="text-2xl font-black text-white tracking-wide">HELLO!!!, <?php echo htmlspecialchars($employee['first_name'] ?? 'Employee'); ?>! 👋</h2>
                    </div>
                    <div class="flex items-center space-x-3 bg-black/45 backdrop-blur-md px-5 py-3 rounded-xl border-2 border-violet-300/40">
                        <div class="text-white text-lg"><i class="fa-regular fa-clock"></i></div>
                        <div>
                            <div id="realtime-clock" class="text-xs font-black text-white font-mono">00:00:00 AM</div>
                            <div id="realtime-date" class="text-[10px] font-black text-violet-200">Loading date...</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 grid grid-cols-2 gap-4 content-start">
                        <div class="bg-white p-5 rounded-2xl border-2 border-slate-500 shadow-sm flex items-center space-x-4">
                            <div class="p-3 bg-violet-100 text-violet-900 rounded-xl border-2 border-violet-400"><i class="fa-solid fa-calendar-check text-xl"></i></div>
                            <div>
                                <p class="text-[11px] font-black text-slate-900 uppercase">Total Workdays</p>
                                <p class="text-base font-black text-black"><?php echo $total_workdays; ?> days</p>
                            </div>
                        </div>
                        <div class="bg-white p-5 rounded-2xl border-2 border-slate-500 shadow-sm flex items-center space-x-4">
                            <div class="p-3 bg-red-100 text-red-900 rounded-xl border-2 border-red-400"><i class="fa-solid fa-calendar-xmark text-xl"></i></div>
                            <div>
                                <p class="text-[11px] font-black text-slate-900 uppercase">Total Absents </p>
                                <p class="text-base font-black text-red-800"><?php echo $total_absents; ?> days</p>
                            </div>
                        </div>
                        <div class="bg-white p-5 rounded-2xl border-2 border-slate-500 shadow-sm flex items-center space-x-4">
                            <div class="p-3 bg-purple-100 text-purple-950 rounded-xl border-2 border-purple-400"><i class="fa-solid fa-clock text-xl"></i></div>
                            <div>
                                <p class="text-[11px] font-black text-slate-900 uppercase">Total Work Hours</p>
                                <p class="text-base font-black text-purple-950"><?php echo number_format($total_workhours, 1); ?> hrs</p>
                            </div>
                        </div>
                        <div class="bg-white p-5 rounded-2xl border-2 border-slate-500 shadow-sm flex items-center space-x-4">
                            <div class="p-3 bg-emerald-100 text-emerald-950 rounded-xl border-2 border-emerald-400"><i class="fa-solid fa-umbrella-beach text-xl"></i></div>
                            <div>
                                <p class="text-[11px] font-black text-slate-900 uppercase">Paid Leave Balance</p>
                                <p class="text-base font-black text-emerald-950"><?php echo $paid_leave_balance; ?> days</p>
                            </div>
                        </div>
                        <div class="bg-white p-5 rounded-2xl border-2 border-slate-500 shadow-sm flex items-center space-x-4">
                            <div class="p-3 bg-violet-100 text-violet-950 rounded-xl border-2 border-violet-400"><i class="fa-solid fa-fingerprint text-xl"></i></div>
                            <div>
                                <p class="text-[11px] font-black text-slate-900 uppercase">Biometric ID</p>
                                <p class="text-base font-black font-mono text-violet-950">#<?php echo htmlspecialchars($f01h_biometric_id); ?></p>
                            </div>
                        </div>
                        <div class="bg-white p-5 rounded-2xl border-2 border-slate-500 shadow-sm flex items-center space-x-4">
                            <div class="p-3 bg-violet-100 text-violet-900 rounded-xl border-2 border-violet-400"><i class="fa-solid fa-peso-sign text-xl"></i></div>
                            <div>
                                <p class="text-[11px] font-black text-slate-900 uppercase">Daily Salary Rate</p>
                                <p class="text-base font-black text-black">₱<?php echo number_format($employee['daily_rate'] ?? $employee['daily_salary'] ?? 0, 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border-2 border-slate-500 rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <h3 id="calendar-month-year" class="text-sm font-black text-black">Month Year</h3>
                                <div class="flex space-x-1">
                                    <button onclick="changeCalendarMonth(-1)" class="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-100 border-2 border-slate-400 hover:bg-slate-200 text-slate-900 transition">
                                        <i class="fa-solid fa-chevron-left text-xs"></i>
                                    </button>
                                    <button onclick="changeCalendarMonth(1)" class="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-100 border-2 border-slate-400 hover:bg-slate-200 text-slate-900 transition">
                                        <i class="fa-solid fa-chevron-right text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="grid grid-cols-7 gap-1 text-center mb-2">
                                <span class="text-[10px] font-black text-slate-900 uppercase">Su</span>
                                <span class="text-[10px] font-black text-slate-900 uppercase">Mo</span>
                                <span class="text-[10px] font-black text-slate-900 uppercase">Tu</span>
                                <span class="text-[10px] font-black text-slate-900 uppercase">We</span>
                                <span class="text-[10px] font-black text-slate-900 uppercase">Th</span>
                                <span class="text-[10px] font-black text-slate-900 uppercase">Fr</span>
                                <span class="text-[10px] font-black text-slate-900 uppercase">Sa</span>
                            </div>

                            <div id="calendar-days-grid" class="grid grid-cols-7 gap-1 text-center"></div>
                        </div>
                        <div class="mt-4 pt-3 border-t-2 border-slate-400 flex justify-between items-center text-[10px] font-black text-slate-900 uppercase tracking-wider">
                            <span>Live Calendar Tracker</span>
                            <span class="flex items-center"><span class="w-2 h-2 bg-amber-600 rounded-full inline-block mr-1"></span> Admin Event</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ATTENDANCE TAB -->
            <div id="section_attendance" class="dashboard-section space-y-6 <?php echo $active_tab=='attendance'?'':'hidden'; ?>">
                <div class="flex justify-between items-center bg-white p-4 rounded-2xl border-2 border-slate-500 shadow-sm">
                    <button onclick="refreshTabData('attendance')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-900 text-xs font-black rounded-xl border-2 border-slate-500 transition flex items-center space-x-2">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Refresh Attendance</span>
                    </button>
                </div>

                <div class="bg-white border-2 border-slate-500 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-6">
                        <h3 id="full_calendar_month_year" class="text-lg font-black text-black">Month Year</h3>
                        <div class="flex space-x-2">
                            <button onclick="changeFullCalendarMonth(-1)" class="px-3 py-1.5 flex items-center justify-center rounded-xl bg-slate-100 border-2 border-slate-500 hover:bg-slate-200 text-slate-900 transition text-xs font-black">
                                <i class="fa-solid fa-chevron-left mr-1"></i> Prev
                            </button>
                            <button onclick="changeFullCalendarMonth(1)" class="px-3 py-1.5 flex items-center justify-center rounded-xl bg-slate-100 border-2 border-slate-500 hover:bg-slate-200 text-slate-900 transition text-xs font-black">
                                Next <i class="fa-solid fa-chevron-right ml-1"></i>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-7 gap-2 text-center mb-3">
                        <span class="text-xs font-black text-slate-900 uppercase">Sunday</span>
                        <span class="text-xs font-black text-slate-900 uppercase">Monday</span>
                        <span class="text-xs font-black text-slate-900 uppercase">Tuesday</span>
                        <span class="text-xs font-black text-slate-900 uppercase">Wednesday</span>
                        <span class="text-xs font-black text-slate-900 uppercase">Thursday</span>
                        <span class="text-xs font-black text-slate-900 uppercase">Friday</span>
                        <span class="text-xs font-black text-slate-900 uppercase">Saturday</span>
                    </div>

                    <div id="full_calendar_grid" class="grid grid-cols-7 gap-2"></div>
                </div>
            </div>

            <!-- REQUESTS TAB -->
            <div id="section_requests" class="dashboard-section space-y-6 <?php echo $active_tab=='requests'?'':'hidden'; ?>">
                <div class="flex justify-between items-center bg-white p-4 rounded-2xl border-2 border-slate-500 shadow-sm">
                    <button onclick="refreshTabData('requests')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-900 text-xs font-black rounded-xl border-2 border-slate-500 transition flex items-center space-x-2">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Refresh Requests</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="space-y-6">
                        <div class="bg-white border-2 border-slate-500 rounded-2xl p-5 shadow-sm">
                            <h4 class="text-xs font-black uppercase text-black mb-3 tracking-wider"><i class="fa-solid fa-file-circle-plus mr-1.5 text-violet-700"></i> Submit Request Ticket</h4>
                            <form method="POST" action="employee_dashboard.php?tab=requests" class="space-y-3">
                                <input type="hidden" name="action" value="submit_request">
                                
                                <div>
                                    <label class="block text-[10px] font-black text-slate-900 mb-1">SELECT REQUEST CATEGORY</label>
                                    <select name="req_type" id="reqTypeSelect" onchange="handleRequestTypeChange(this.value)" required class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                        <option value="Leave">Leave Request</option>
                                        <option value="Overtime">Overtime (OT) Request</option>
                                        <option value="Cash Advance">Cash Advance Request</option>
                                        <option value="Attendance Correction">Attendance Correction / Missed Scan</option>
                                    </select>
                                </div>

                                <div id="leaveFields" class="space-y-3 pt-1">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">LEAVE TYPE</label>
                                        <select name="leave_category" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                            <option value="Sick Leave">Sick Leave</option>
                                            <option value="Vacation Leave">Vacation Leave</option>
                                            <option value="Emergency Leave">Emergency Leave</option>
                                        </select>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] font-black text-slate-900 mb-1">START DATE</label>
                                            <input type="date" name="start_date" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-black text-slate-900 mb-1">END DATE</label>
                                            <input type="date" name="end_date" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                        </div>
                                    </div>
                                </div>

                                <div id="otFields" class="space-y-3 pt-1 hidden">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">OT DATE</label>
                                        <input type="date" name="ot_date" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">ESTIMATED HOURS</label>
                                        <input type="number" step="0.5" name="hours" placeholder="2.0" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                    </div>
                                </div>

                                <div id="caFields" class="space-y-3 pt-1 hidden">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">AMOUNT (₱)</label>
                                        <input type="number" step="0.01" name="amount" placeholder="1000.00" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                    </div>
                                </div>

                                <div id="acFields" class="space-y-3 pt-1 hidden">
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">DATE OF MISSED SCAN</label>
                                        <input type="date" name="correction_date" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">SCAN TYPE</label>
                                        <select name="scan_type" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                            <option value="Check In">Check In</option>
                                            <option value="Check Out">Check Out</option>
                                        
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-slate-900 mb-1">CORRECT TIME</label>
                                        <input type="time" name="correct_time" class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-[10px] font-black text-slate-900 mb-1">REASON / NOTES</label>
                                    <textarea name="reason" rows="2" placeholder="State reason or purpose (e.g. biometric device offline, forgot to tap card)..." required class="w-full px-3 py-2 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black"></textarea>
                                </div>

                                <button type="submit" class="w-full py-2.5 bg-violet-700 hover:bg-violet-800 text-white text-xs font-black rounded-xl transition shadow-sm border-2 border-violet-950">Submit Request Ticket</button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-2 space-y-4">
                        <div class="bg-white border-2 border-slate-500 rounded-2xl p-6 shadow-sm">
                            <h3 class="text-base font-black text-black mb-1">My Requests History & Status</h3>
                            <p class="text-xs font-bold text-slate-800 mb-4">Track the approval status of your submitted leave, overtime, cash advance, and attendance correction tickets.</p>

                            <div class="border-2 border-slate-500 rounded-xl overflow-x-auto shadow-sm">
                                <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                                    <thead>
                                        <tr class="bg-slate-200 border-b-2 border-slate-500 text-black uppercase text-[10px] font-black tracking-wider">
                                            <th class="py-3 px-4">Type</th>
                                            <th class="py-3 px-4">Details / Notes</th>
                                            <th class="py-3 px-4">Date / Value</th>
                                            <th class="py-3 px-4">Status</th>
                                            <th class="py-3 px-4 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y-2 divide-slate-400 text-black font-bold">
                                        <?php if(empty($requests)): ?>
                                            <tr>
                                                <td colspan="5" class="py-12 text-center text-slate-600 font-black">No requests submitted yet.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($requests as $req): ?>
                                                <tr class="hover:bg-slate-100">
                                                    <td class="py-3.5 px-4 font-black text-black">
                                                        <?php
                                                            $req_type_label = $req['type'] ?? '';
                                                            if ($req_type_label === 'Leave') {
                                                                $type_badge_class = 'bg-violet-100 text-violet-950 border-violet-500';
                                                            } elseif ($req_type_label === 'Overtime') {
                                                                $type_badge_class = 'bg-purple-100 text-purple-950 border-purple-500';
                                                            } elseif ($req_type_label === 'Attendance Correction') {
                                                                $type_badge_class = 'bg-sky-100 text-sky-950 border-sky-500';
                                                            } else {
                                                                $type_badge_class = 'bg-emerald-100 text-emerald-950 border-emerald-500';
                                                            }
                                                        ?>
                                                        <span class="px-2.5 py-1 rounded-lg text-[10px] font-black border-2 <?php echo $type_badge_class; ?>">
                                                            <?php echo htmlspecialchars($req_type_label); ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3.5 px-4 font-black text-slate-900"><?php echo htmlspecialchars($req['details'] ?? ''); ?></td>
                                                    <td class="py-3.5 px-4 font-mono font-black text-slate-900">
                                                        <?php echo !empty($req['amount']) ? '₱'.number_format($req['amount'], 2) : (!empty($req['hours']) ? $req['hours'].' hrs' : ($req['start_date'] ?? '')); ?>
                                                    </td>
                                                    <td class="py-3.5 px-4">
                                                        <span class="px-2.5 py-1 rounded-full font-black text-[10px] border-2 
                                                            <?php echo ($req['status']??'')=='Approved'?'bg-emerald-200 text-emerald-950 border-emerald-600':(($req['status']??'')=='Rejected'?'bg-red-200 text-red-950 border-red-600':'bg-amber-200 text-amber-950 border-amber-600'); ?>">
                                                            <?php echo htmlspecialchars($req['status'] ?? 'Pending'); ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3.5 px-4 text-right">
                                                        <?php if(($req['status'] ?? 'Pending') === 'Pending'): ?>
                                                            <button type="button" onclick="openEditRequestModal(<?php echo $req['id']; ?>, '<?php echo htmlspecialchars(addslashes($req['details'])); ?>', '<?php echo $req['status']; ?>')" class="px-3 py-1.5 bg-violet-100 text-violet-950 hover:bg-violet-200 rounded-lg text-[10px] font-black border-2 border-violet-500 transition">
                                                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-slate-500 italic text-[10px]">Locked</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PAYSLIPS TAB -->
            <div id="section_payslips" class="dashboard-section space-y-6 <?php echo $active_tab=='payslips'?'':'hidden'; ?>">
                <div class="flex justify-between items-center bg-white p-4 rounded-2xl border-2 border-slate-500 shadow-sm">
                    <button onclick="refreshTabData('payslips')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-900 text-xs font-black rounded-xl border-2 border-slate-500 transition flex items-center space-x-2">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Refresh Payslips</span>
                    </button>
                </div>

                <div class="bg-white border-2 border-slate-500 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-base font-black text-black"><i class="fa-solid fa-wallet text-emerald-800 mr-2"></i>My Payslip Records</h3>
                        <span class="text-xs bg-emerald-100 text-emerald-950 font-black px-3 py-1 rounded-full border-2 border-emerald-500">Payroll History</span>
                    </div>
                    <p class="text-xs font-bold text-slate-800 mb-4">Review your individual employee payslip records fetched correctly from the databases. Click any row to inspect complete breakdown details.</p>

                    <div class="border-2 border-slate-500 rounded-xl overflow-x-auto shadow-sm">
                        <table class="w-full text-left border-collapse text-xs whitespace-nowrap">
                            <thead>
                                <tr class="bg-slate-200 border-b-2 border-slate-500 text-black uppercase text-[10px] font-black tracking-wider">
                                    <th class="py-3 px-4">Pay Period / Date</th>
                                    <th class="py-3 px-4">Days Worked</th>
                                    <th class="py-3 px-4">Absents</th>
                                    <th class="py-3 px-4">Work Hours</th>
                                    <th class="py-3 px-4">Basic / Gross Pay</th>
                                    <th class="py-3 px-4">Deductions / CA</th>
                                    <th class="py-3 px-4 font-black text-emerald-900">Net Payout</th>
                                    <th class="py-3 px-4 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y-2 divide-slate-400 text-black font-bold">
                                <?php if(empty($payslips)): ?>
                                    <tr>
                                        <td colspan="8" class="py-12 text-center text-slate-600 font-black">No payslip records generated yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($payslips as $pay): ?>
                                        <tr onclick='openPayrollModal(<?php echo htmlspecialchars(json_encode($pay, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>)' class="hover:bg-violet-100/75 cursor-pointer transition">
                                            <td class="py-3.5 px-4 font-black text-black">
                                                <?php echo !empty($pay['pay_period_start']) ? date('M d', strtotime($pay['pay_period_start'])) . ' - ' . date('M d, Y', strtotime($pay['pay_period_end'])) : date('M d, Y', strtotime($pay['created_at'])); ?>
                                            </td>
                                            <td class="py-3.5 px-4 font-black text-emerald-900"><?php echo number_format($pay['days_worked'] ?? 0, 1); ?> days</td>
                                            <td class="py-3.5 px-4 font-black text-red-800"><?php echo number_format($pay['absent_days'] ?? 0, 1); ?> days</td>
                                            <td class="py-3.5 px-4 font-black text-violet-900"><?php echo number_format($pay['workhours'] ?? 0, 1); ?> hrs</td>
                                            <td class="py-3.5 px-4 font-black text-slate-900">₱<?php echo number_format($pay['basic_pay'] ?? 0, 2); ?></td>
                                            <td class="py-3.5 px-4 font-black text-red-800">-₱<?php echo number_format($pay['cash_advance'] ?? 0, 2); ?></td>
                                            <td class="py-3.5 px-4 font-black text-emerald-900">₱<?php echo number_format($pay['net_pay'] ?? 0, 2); ?></td>
                                            <td class="py-3.5 px-4 text-right"><span class="px-2.5 py-1 bg-emerald-200 text-emerald-950 font-black rounded-full text-[10px] border-2 border-emerald-500"><?php echo htmlspecialchars($pay['status'] ?? 'Paid'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SETTINGS TAB -->
            <div id="section_settings" class="dashboard-section space-y-6 <?php echo $active_tab=='settings'?'':'hidden'; ?>">
                <div class="flex justify-between items-center bg-white p-4 rounded-2xl border-2 border-slate-500 shadow-sm">
                    <button onclick="refreshTabData('settings')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-900 text-xs font-black rounded-xl border-2 border-slate-500 transition flex items-center space-x-2">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Refresh Settings</span>
                    </button>
                </div>
                
                <div class="bg-white border-2 border-slate-500 rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-5 bg-slate-100 border-b-2 border-slate-500 flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="p-2.5 bg-violet-700 text-white rounded-xl shadow-sm border-2 border-violet-950">
                                <i class="fa-solid fa-address-card text-sm"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-black text-black">Edit Personal & Account Information</h3>
                                <p class="text-[11px] font-extrabold text-slate-800">Make changes directly to your personal details below. Saving will update your profile and notify the admin automatically.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Direct Editable Profile & Account Form -->
                    <form method="POST" action="employee_dashboard.php?tab=settings" class="p-6 bg-white space-y-6">
                        <input type="hidden" name="action" value="update_profile_and_account">

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-xs">
                            
                            <!-- Personal Information -->
                            <div class="space-y-3 bg-slate-50 p-4 rounded-xl border-2 border-slate-500">
                                <h4 class="font-black text-black border-b-2 border-slate-400 pb-2 uppercase tracking-wider text-[10px] text-violet-900">Personal Information</h4>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Full Name (Read-Only)</span> <strong class="text-black text-sm"><?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['middle_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?></strong></div>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Gender (Read-Only)</span> <strong class="text-black"><?php echo htmlspecialchars($employee['gender'] ?? 'N/A'); ?></strong></div>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">Contact Number</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? $employee['contact_no'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                </div>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">Home Address</label>
                                    <textarea name="address" rows="2" class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600"><?php echo htmlspecialchars($employee['address'] ?? $employee['street_address'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <!-- Employment Details -->
                            <div class="space-y-3 bg-slate-50 p-4 rounded-xl border-2 border-slate-500">
                                <h4 class="font-black text-black border-b-2 border-slate-400 pb-2 uppercase tracking-wider text-[10px] text-emerald-900">Employment Details</h4>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Job Position / Title</span> <strong class="text-black text-sm"><?php echo htmlspecialchars($employee['position'] ?? $employee['job_title'] ?? 'N/A'); ?></strong></div>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Assigned Department</span> <strong class="text-black"><?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></strong></div>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Employment Type</span> <strong class="text-black"><?php echo htmlspecialchars($employee['employment_type'] ?? 'Regular / Full-time'); ?></strong></div>
                                <div>
                                    <span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Date Hired</span> 
                                    <strong class="text-black">
                                        <?php 
                                        $hired = $employee['created_at'] ?? null;
                                        echo (!empty($hired) && $hired !== '0000-00-00 00:00:00') ? date('M d, Y', strtotime($hired)) : 'N/A'; 
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <!-- Emergency Details -->
                            <div class="space-y-3 bg-slate-50 p-4 rounded-xl border-2 border-slate-500">
                                <h4 class="font-black text-black border-b-2 border-slate-400 pb-2 uppercase tracking-wider text-[10px] text-amber-900">Emergency Details</h4>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">Contact Person</label>
                                    <input type="text" name="emergency_name" value="<?php echo htmlspecialchars($employee['emergency_name'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                </div>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">Relationship</label>
                                    <input type="text" name="emergency_relation" value="<?php echo htmlspecialchars($employee['emergency_relation'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                </div>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">Emergency Phone</label>
                                    <input type="text" name="emergency_phone" value="<?php echo htmlspecialchars($employee['emergency_phone'] ?? ''); ?>" class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                </div>
                            </div>

                            <!-- Salary & Credentials -->
                            <div class="space-y-3 bg-slate-50 p-4 rounded-xl border-2 border-slate-500">
                                <h4 class="font-black text-black border-b-2 border-slate-400 pb-2 uppercase tracking-wider text-[10px] text-purple-950">Salary & Credentials</h4>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px] mb-1">Daily Salary Rate</span> <strong class="text-emerald-900 text-sm">₱<?php echo number_format($employee['daily_rate'] ?? $employee['daily_salary'] ?? 0, 2); ?></strong></div>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">Portal Login Email</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" required class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                </div>
                                <div>
                                    <label class="text-slate-900 font-black block uppercase text-[10px] mb-1">New Password (Optional)</label>
                                    <input type="password" name="password" placeholder="••••••••" class="w-full px-3 py-2 bg-white border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600">
                                </div>
                                <div><span class="text-slate-900 font-black block uppercase text-[10px]">F01H Biometric ID</span> <strong class="text-purple-950 font-mono">#<?php echo htmlspecialchars($f01h_biometric_id); ?></strong></div>
                            </div>

                        </div>

                        <div class="pt-4 border-t-2 border-slate-400 flex justify-end">
                            <button type="submit" class="px-6 py-3 bg-violet-700 hover:bg-violet-800 text-white text-xs font-black rounded-xl transition shadow-md border-2 border-violet-950 flex items-center space-x-2">
                                <i class="fa-solid fa-floppy-disk"></i>
                                <span>Save Changes & Notify Admin</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </main>
    </div>

    <!-- Modals -->
    <div id="editRequestModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop hidden transition-opacity duration-200">
        <div class="bg-white border-4 border-slate-700 shadow-2xl rounded-3xl max-w-md w-full p-6 relative m-4 text-black font-bold flex flex-col">
            <div class="flex justify-between items-start border-b-2 border-slate-500 pb-4 mb-4">
                <div>
                    <span class="px-2.5 py-1 rounded-lg text-[10px] font-black bg-violet-100 text-violet-950 uppercase tracking-wider border-2 border-violet-500">Ticket Revision</span>
                    <h3 class="text-lg font-black text-black mt-2">Edit Pending Request</h3>
                </div>
                <button onclick="closeEditRequestModal()" class="text-slate-900 hover:text-black p-2 rounded-xl bg-slate-100 border-2 border-slate-500 hover:bg-slate-200 transition">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <form method="POST" action="employee_dashboard.php?tab=requests" class="space-y-4">
                <input type="hidden" name="action" value="update_request">
                <input type="hidden" name="request_id" id="edit_request_id">
                <div>
                    <label class="block text-xs font-black text-slate-900 mb-1">UPDATE REASON / DETAILS</label>
                    <textarea name="reason" id="edit_reason" rows="3" required class="w-full px-3 py-2.5 bg-slate-50 border-2 border-slate-500 rounded-xl text-xs font-black text-black focus:outline-none focus:ring-2 focus:ring-violet-600"></textarea>
                </div>
                <div class="flex space-x-3 pt-2">
                    <button type="button" onclick="closeEditRequestModal()" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-500 hover:bg-slate-300 text-slate-900 font-black text-xs transition">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl bg-violet-700 hover:bg-violet-800 text-white font-black text-xs transition shadow-md border-2 border-violet-950">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Attendance Modal -->
    <div id="attendanceModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop hidden transition-opacity duration-200">
        <div class="bg-white border-4 border-slate-700 shadow-2xl rounded-3xl max-w-md w-full p-6 relative m-4 text-black font-bold flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-start border-b-2 border-slate-500 pb-4 mb-4 shrink-0">
                <div>
                    <span class="px-2.5 py-1 rounded-lg text-[10px] font-black bg-violet-100 text-violet-950 uppercase tracking-wider border-2 border-violet-500">Attendance Log Audit</span>
                    <h3 class="text-lg font-black text-black mt-2">Day-by-Day Full Record</h3>
                </div>
                <button onclick="closeAttendanceModal()" class="text-slate-900 hover:text-black p-2 rounded-xl bg-slate-100 border-2 border-slate-500 hover:bg-slate-200 transition">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="overflow-y-auto space-y-4 flex-1 pr-1 text-xs text-black font-bold bg-slate-100 p-4 rounded-xl border-2 border-slate-500" id="attendanceModalBody"></div>
            <div class="mt-6 pt-4 border-t-2 border-slate-500 flex items-center justify-end shrink-0">
                <button type="button" onclick="closeAttendanceModal()" class="px-5 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-500 hover:bg-slate-300 text-black font-black text-xs transition">Close Record View</button>
            </div>
        </div>
    </div>

    <!-- Payroll Modal -->
    <div id="payrollModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop hidden transition-opacity duration-200">
        <div class="bg-white border-4 border-slate-700 shadow-2xl rounded-3xl max-w-md w-full p-6 relative m-4 text-black font-bold flex flex-col max-h-[90vh]">
            <div class="flex justify-between items-start border-b-2 border-slate-500 pb-4 mb-4 shrink-0">
                <div>
                    <span class="px-2.5 py-1 rounded-lg text-[10px] font-black bg-violet-100 text-violet-950 uppercase tracking-wider border-2 border-violet-500">Payroll Transparency Audit</span>
                    <h3 class="text-lg font-black text-black mt-2">Payslip Breakdown</h3>
                </div>
                <button onclick="closePayrollModal()" class="text-slate-900 hover:text-black p-2 rounded-xl bg-slate-100 border-2 border-slate-500 hover:bg-slate-200 transition">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="overflow-y-auto space-y-4 flex-1 pr-1 text-xs text-black font-bold bg-slate-100 p-4 rounded-xl border-2 border-slate-500" id="payrollModalBody"></div>
            <div class="mt-6 pt-4 border-t-2 border-slate-500 flex items-center justify-end shrink-0">
                <button type="button" onclick="closePayrollModal()" class="px-5 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-500 hover:bg-slate-300 text-black font-black text-xs transition">Close Transparency View</button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div id="logoutModal" class="fixed inset-0 z-50 flex items-center justify-center modal-backdrop hidden transition-opacity duration-200">
        <div class="bg-white border-4 border-slate-700 shadow-2xl rounded-3xl max-w-sm w-full p-6 relative m-4 text-black font-bold text-center">
            <div class="w-14 h-14 bg-red-100 text-red-800 rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4 border-2 border-red-500">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </div>
            <h3 class="text-lg font-black text-black">Ready to Leave?</h3>
            <p class="text-xs font-bold text-slate-800 mt-1 mb-6">Are you sure you want to log out of your GigPay employee session?</p>
            <div class="flex space-x-3">
                <button type="button" onclick="closeLogoutModal()" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-200 border-2 border-slate-500 hover:bg-slate-300 text-slate-900 font-black text-xs transition">Cancel</button>
                <button type="button" onclick="proceedLogout()" class="flex-1 px-4 py-2.5 rounded-xl bg-red-700 hover:bg-red-800 text-white font-black text-xs transition shadow-md shadow-red-950/30 border-2 border-red-950">Yes, Logout</button>
            </div>
        </div>
    </div>

</body>
</html>