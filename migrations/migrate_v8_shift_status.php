<?php
/**
 * ================================================================
 * Migration v8 - Shift-Aware Status & Legacy Remediation
 * ================================================================
 * 1. Add `status` column to attendances (manual / auto_checkout / system_fixed / overtime_approved)
 * 2. Add `shift_id` column → links attendance to branch_shifts.id
 * 3. Add `closed_by_checkin_id` → pairs each checkout with its check-in
 * 4. Add index for fast open-record queries
 * 5. Add auto_checkout_grace_minutes setting (default 15)
 * 6. Backfill shift_id for existing records
 * 7. Legacy remediation: close ALL open check-ins from the past
 *
 * SAFE TO RE-RUN: Every step checks before acting
 * ================================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v8: Shift-Aware Status & Legacy Remediation ===\n\n";
$results = [];

// ── 1. Add `status` column to attendances ──
try {
    $col = db()->query("SHOW COLUMNS FROM attendances LIKE 'status'")->fetch();
    if (!$col) {
        db()->exec("
            ALTER TABLE attendances
            ADD COLUMN status ENUM('manual','auto_checkout','system_fixed','overtime_approved')
            DEFAULT 'manual' AFTER notes
        ");
        // Mark existing auto records
        db()->exec("UPDATE attendances SET status = 'auto_checkout' WHERE user_agent IN ('AUTO-CHECKOUT-CRON','AUTO-CHECKOUT') AND status = 'manual'");
        $results[] = "✅ Added `status` column + backfilled auto_checkout records";
    } else {
        $results[] = "⏭️ status column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ status column: " . $e->getMessage();
}

// ── 2. Add `shift_id` column to attendances ──
try {
    $col = db()->query("SHOW COLUMNS FROM attendances LIKE 'shift_id'")->fetch();
    if (!$col) {
        db()->exec("
            ALTER TABLE attendances
            ADD COLUMN shift_id INT DEFAULT NULL AFTER status,
            ADD INDEX idx_shift_id (shift_id)
        ");
        $results[] = "✅ Added `shift_id` column with index";
    } else {
        $results[] = "⏭️ shift_id column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ shift_id column: " . $e->getMessage();
}

// ── 3. Add `closed_by_checkin_id` to pair check-out → check-in ──
try {
    $col = db()->query("SHOW COLUMNS FROM attendances LIKE 'closed_by_checkin_id'")->fetch();
    if (!$col) {
        db()->exec("
            ALTER TABLE attendances
            ADD COLUMN closed_by_checkin_id INT DEFAULT NULL AFTER shift_id,
            ADD INDEX idx_closed_by (closed_by_checkin_id)
        ");
        $results[] = "✅ Added `closed_by_checkin_id` column";
    } else {
        $results[] = "⏭️ closed_by_checkin_id column already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ closed_by_checkin_id: " . $e->getMessage();
}

// ── 4. Composite index for open-record detection ──
try {
    $idx = db()->query("SHOW INDEX FROM attendances WHERE Key_name = 'idx_open_records'")->fetch();
    if (!$idx) {
        db()->exec("ALTER TABLE attendances ADD INDEX idx_open_records (type, attendance_date, employee_id, timestamp)");
        $results[] = "✅ Added composite index idx_open_records";
    } else {
        $results[] = "⏭️ idx_open_records already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ idx_open_records: " . $e->getMessage();
}

// ── 5. auto_checkout_grace_minutes setting ──
try {
    $exists = db()->query("SELECT 1 FROM settings WHERE setting_key = 'auto_checkout_grace_minutes'")->fetch();
    if (!$exists) {
        db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute(['auto_checkout_grace_minutes', '15']);
        $results[] = "✅ Added auto_checkout_grace_minutes = 15";
    } else {
        $results[] = "⏭️ auto_checkout_grace_minutes already exists";
    }
} catch (Exception $e) {
    $results[] = "❌ auto_checkout_grace_minutes: " . $e->getMessage();
}

// ── 6. Backfill shift_id for existing records ──
try {
    $needFill = db()->query("SELECT COUNT(*) FROM attendances WHERE shift_id IS NULL")->fetchColumn();
    if ((int)$needFill > 0) {
        echo "Backfilling shift_id for {$needFill} records...\n";
        $batchSize = 500;
        $filled = 0;

        // Cache all branch shifts
        $allShifts = [];
        $shiftRows = db()->query("SELECT id, branch_id, shift_number, shift_start, shift_end FROM branch_shifts WHERE is_active = 1 ORDER BY branch_id, shift_number")->fetchAll();
        foreach ($shiftRows as $sr) {
            $allShifts[$sr['branch_id']][] = $sr;
        }

        while (true) {
            $batch = db()->query("
                SELECT a.id, a.timestamp, e.branch_id
                FROM attendances a
                JOIN employees e ON a.employee_id = e.id
                WHERE a.shift_id IS NULL
                LIMIT {$batchSize}
            ")->fetchAll();

            if (empty($batch)) break;

            $updateStmt = db()->prepare("UPDATE attendances SET shift_id = ? WHERE id = ?");
            foreach ($batch as $row) {
                $branchId = (int)($row['branch_id'] ?? 0);
                $shifts = $allShifts[$branchId] ?? [];
                if (empty($shifts)) {
                    // No branch shifts — leave null, will use system defaults
                    $updateStmt->execute([null, $row['id']]);
                    $filled++;
                    continue;
                }
                $time = date('H:i', strtotime($row['timestamp']));
                $shiftNum = assignTimeToShift($time, $shifts);
                $matchedId = null;
                foreach ($shifts as $s) {
                    if ((int)$s['shift_number'] === $shiftNum) {
                        $matchedId = (int)$s['id'];
                        break;
                    }
                }
                $updateStmt->execute([$matchedId, $row['id']]);
                $filled++;
            }
            echo "  ... {$filled} / {$needFill}\n";
        }
        $results[] = "✅ Backfilled shift_id for {$filled} records";
    } else {
        $results[] = "⏭️ All records already have shift_id";
    }
} catch (Exception $e) {
    $results[] = "❌ Backfill shift_id: " . $e->getMessage();
}

// ── 7. Backfill closed_by_checkin_id for existing checkouts ──
try {
    $needPair = db()->query("SELECT COUNT(*) FROM attendances WHERE type = 'out' AND closed_by_checkin_id IS NULL")->fetchColumn();
    if ((int)$needPair > 0) {
        echo "Pairing {$needPair} checkout records with check-ins...\n";
        // For each checkout, find the most recent check-in before it for the same employee+date
        $paired = db()->exec("
            UPDATE attendances co
            INNER JOIN (
                SELECT co2.id AS co_id,
                       (SELECT ci.id FROM attendances ci
                        WHERE ci.employee_id = co2.employee_id
                          AND ci.type = 'in'
                          AND ci.attendance_date = co2.attendance_date
                          AND ci.timestamp < co2.timestamp
                        ORDER BY ci.timestamp DESC LIMIT 1
                       ) AS paired_ci_id
                FROM attendances co2
                WHERE co2.type = 'out' AND co2.closed_by_checkin_id IS NULL
            ) pairs ON co.id = pairs.co_id
            SET co.closed_by_checkin_id = pairs.paired_ci_id
            WHERE pairs.paired_ci_id IS NOT NULL
        ");
        $results[] = "✅ Paired {$paired} checkout→checkin records";
    } else {
        $results[] = "⏭️ All checkouts already paired";
    }
} catch (Exception $e) {
    $results[] = "❌ Pair checkouts: " . $e->getMessage();
}

// ================================================================
// 8. LEGACY REMEDIATION — Close all open check-ins from the past
// ================================================================
echo "\n--- Legacy Remediation: Closing open check-ins ---\n";
try {
    // Find all check-ins with no check-out after them, EXCLUDING today
    $openStmt = db()->prepare("
        SELECT
            ci.id AS checkin_id,
            ci.employee_id,
            ci.timestamp AS checkin_time,
            ci.attendance_date,
            ci.latitude,
            ci.longitude,
            ci.shift_id,
            e.branch_id,
            e.name
        FROM attendances ci
        INNER JOIN employees e ON ci.employee_id = e.id
        WHERE ci.type = 'in'
          AND ci.attendance_date < CURDATE()
          AND NOT EXISTS (
              SELECT 1 FROM attendances co
              WHERE co.employee_id = ci.employee_id
                AND co.type = 'out'
                AND co.attendance_date = ci.attendance_date
                AND co.timestamp > ci.timestamp
          )
        ORDER BY ci.attendance_date, ci.employee_id
    ");
    $openStmt->execute();
    $openRecords = $openStmt->fetchAll();

    $fixedCount = 0;

    if (!empty($openRecords)) {
        // Cache branch shifts
        $allShifts = [];
        $shiftRows = db()->query("SELECT id, branch_id, shift_number, shift_start, shift_end FROM branch_shifts WHERE is_active = 1 ORDER BY branch_id, shift_number")->fetchAll();
        foreach ($shiftRows as $sr) {
            $allShifts[$sr['branch_id']][] = $sr;
        }

        $insertStmt = db()->prepare("
            INSERT INTO attendances (
                employee_id, type, timestamp, attendance_date,
                latitude, longitude, location_accuracy,
                ip_address, user_agent, notes, status,
                shift_id, closed_by_checkin_id
            ) VALUES (
                :emp_id, 'out', :ts, :att_date,
                :lat, :lon, 0,
                'SYSTEM', 'MIGRATION-V8', :notes, 'system_fixed',
                :shift_id, :ci_id
            )
        ");

        foreach ($openRecords as $rec) {
            $branchId = (int)($rec['branch_id'] ?? 0);
            $shifts = $allShifts[$branchId] ?? [];

            // Determine shift end time for this check-in
            $checkinTime = date('H:i', strtotime($rec['checkin_time']));
            $shiftEnd = '16:00'; // fallback
            $shiftId = $rec['shift_id'];

            if (!empty($shifts)) {
                $shiftNum = assignTimeToShift($checkinTime, $shifts);
                foreach ($shifts as $s) {
                    if ((int)$s['shift_number'] === $shiftNum) {
                        $shiftEnd = $s['shift_end'];
                        if (!$shiftId) $shiftId = (int)$s['id'];
                        break;
                    }
                }
            } else {
                $shiftEnd = getSystemSetting('work_end_time', '16:00');
            }

            // Build checkout timestamp = attendance_date + shift_end
            $checkoutDT = new DateTime($rec['attendance_date'] . ' ' . $shiftEnd);
            $checkinDT = new DateTime($rec['checkin_time']);

            // Midnight crossing: if checkout <= checkin, add a day
            if ($checkoutDT <= $checkinDT) {
                $checkoutDT->modify('+1 day');
            }

            $insertStmt->execute([
                'emp_id'   => $rec['employee_id'],
                'ts'       => $checkoutDT->format('Y-m-d H:i:s'),
                'att_date' => $rec['attendance_date'],
                'lat'      => $rec['latitude'],
                'lon'      => $rec['longitude'],
                'notes'    => "إغلاق تلقائي للسجل المفتوح - ترحيل v8 | وردية " . ($shiftNum ?? 1),
                'shift_id' => $shiftId,
                'ci_id'    => $rec['checkin_id']
            ]);
            $fixedCount++;
            echo "  ✅ Fixed: {$rec['name']} — {$rec['attendance_date']}\n";
        }
    }

    $results[] = "✅ Legacy remediation: closed {$fixedCount} open records (status=system_fixed)";
} catch (Exception $e) {
    $results[] = "❌ Legacy remediation: " . $e->getMessage();
}

// ================================================================
// Print Summary
// ================================================================
echo "\n=== Migration v8 Results ===\n";
foreach ($results as $r) {
    echo $r . "\n";
}
echo "\n✅ Migration v8 complete.\n";
