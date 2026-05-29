<?php
/**
 * Monthly Leave Accrual - Meta School
 * Runs on the 1st of each month via cron.
 * Credits configured leave days to all active employees.
 */

// carry_over: true  = valid until Dec 31 (unused days accumulate)
// carry_over: false = expires end of month (use it or lose it)
$accrualRules = [
    ['leave_type_id' => 2, 'leave_type_name' => 'Monthly', 'days' => 1.0, 'carry_over' => true],
    ['leave_type_id' => 1, 'leave_type_name' => 'Sick',    'days' => 2.0, 'carry_over' => false],
];

// Read DB config from OrangeHRM's own config file
require_once __DIR__ . '/../lib/confs/Conf.php';
$conf = new Conf();

try {
    $pdo = new PDO(
        "mysql:host={$conf->getDbHost()};port={$conf->getDbPort()};dbname={$conf->getDbName()};charset=utf8",
        $conf->getDbUser(),
        $conf->getDbPass()
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "[ERROR] DB connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$now          = new DateTime();
$fromDate     = $now->format('Y-m-01 00:00:00');
$toDateMonth  = $now->format('Y-m-t 23:59:59');       // end of current month (Sick)
$toDateYear   = $now->format('Y-12-31 00:00:00');     // end of year (Monthly carry-over)
$creditDate   = $now->format('Y-m-d H:i:s');
$month        = $now->format('F Y');

// Get all active (non-terminated) employees
$employees = $pdo->query("
    SELECT emp_number, emp_firstname, emp_lastname
    FROM hs_hr_employee
    WHERE termination_id IS NULL
    ORDER BY emp_number
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($employees)) {
    echo "No active employees found. Exiting." . PHP_EOL;
    exit(0);
}

echo "=== Meta School Monthly Leave Accrual — {$month} ===" . PHP_EOL;
echo "Active employees: " . count($employees) . PHP_EOL . PHP_EOL;

$insertStmt = $pdo->prepare("
    INSERT INTO ohrm_leave_entitlement
        (emp_number, no_of_days, days_used, leave_type_id, from_date, to_date,
         credited_date, note, entitlement_type, deleted, created_by_id)
    VALUES
        (:emp, :days, 0, :type, :from, :to, :credited, :note, 1, 0, 1)
");

$checkStmt = $pdo->prepare("
    SELECT id FROM ohrm_leave_entitlement
    WHERE emp_number    = :emp
      AND leave_type_id = :type
      AND from_date     = :from
      AND deleted       = 0
");

foreach ($accrualRules as $rule) {
    echo "--- {$rule['leave_type_name']} Leave ({$rule['days']} day/month) ---" . PHP_EOL;
    $credited = 0;
    $skipped  = 0;

    foreach ($employees as $emp) {
        $checkStmt->execute([
            ':emp'  => $emp['emp_number'],
            ':type' => $rule['leave_type_id'],
            ':from' => $fromDate,
        ]);

        if ($checkStmt->fetch()) {
            echo "  SKIP (already credited): {$emp['emp_firstname']} {$emp['emp_lastname']}" . PHP_EOL;
            $skipped++;
            continue;
        }

        $toDate = $rule['carry_over'] ? $toDateYear : $toDateMonth;

        $insertStmt->execute([
            ':emp'      => $emp['emp_number'],
            ':days'     => $rule['days'],
            ':type'     => $rule['leave_type_id'],
            ':from'     => $fromDate,
            ':to'       => $toDate,
            ':credited' => $creditDate,
            ':note'     => "Monthly accrual - {$month}",
        ]);

        echo "  CREDITED {$rule['days']} day(s): {$emp['emp_firstname']} {$emp['emp_lastname']}" . PHP_EOL;
        $credited++;
    }

    echo "  Result: {$credited} credited, {$skipped} skipped" . PHP_EOL . PHP_EOL;
}

echo "=== Done ===" . PHP_EOL;
