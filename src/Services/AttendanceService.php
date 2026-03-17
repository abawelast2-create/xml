<?php
// =============================================================
// src/Services/AttendanceService.php - خدمة الحضور والانصراف
// =============================================================

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Branch;

class AttendanceService
{
    private Attendance $attendance;
    private Employee $employee;
    private Branch $branch;

    public function __construct()
    {
        $this->attendance = new Attendance();
        $this->employee = new Employee();
        $this->branch = new Branch();
    }

    /**
     * تسجيل حضور أو انصراف
     */
    public function record(int $employeeId, string $type, float $lat, float $lon, float $accuracy = 0): array
    {
        $validTypes = ['in', 'out', 'overtime-start', 'overtime-end'];
        if (!in_array($type, $validTypes, true)) {
            return ['success' => false, 'message' => 'نوع تسجيل غير صالح'];
        }

        // التحقق من التكرار
        if ($this->attendance->hasRecentRecord($employeeId, $type, 5)) {
            return ['success' => false, 'message' => 'تم التسجيل مسبقاً خلال آخر 5 دقائق'];
        }

        // حساب التأخير
        $lateMinutes = 0;
        if ($type === 'in') {
            $lateMinutes = $this->calculateLateMinutes($employeeId);
        }

        $ip = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $id = $this->attendance->create([
            'employee_id' => $employeeId,
            'type' => $type,
            'timestamp' => date('Y-m-d H:i:s'),
            'attendance_date' => date('Y-m-d'),
            'late_minutes' => $lateMinutes,
            'latitude' => $lat,
            'longitude' => $lon,
            'location_accuracy' => $accuracy,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        $messages = [
            'in' => 'تم تسجيل الدخول بنجاح',
            'out' => 'تم تسجيل الانصراف بنجاح',
            'overtime-start' => 'تم بدء الوقت الإضافي',
            'overtime-end' => 'تم إنهاء الوقت الإضافي',
        ];

        return [
            'success' => true,
            'message' => $messages[$type] ?? 'تم التسجيل',
            'late_minutes' => $lateMinutes,
            'record_id' => $id,
        ];
    }

    /**
     * حساب دقائق التأخير
     */
    private function calculateLateMinutes(int $employeeId): int
    {
        $emp = $this->employee->find($employeeId);
        if (!$emp) return 0;

        $schedule = getBranchSchedule($emp['branch_id'] ?? null);
        $now = time();

        // تحديد مرجع حساب التأخير: إذا توجد استراحة والوقت الحالي بعد نهاية الاستراحة
        $referenceTimeStr = $schedule['work_start_time'];
        if (!empty($schedule['break_start']) && !empty($schedule['break_end'])) {
            $breakStart = strtotime(date('Y-m-d') . ' ' . $schedule['break_start']);
            if ($now >= $breakStart) {
                $referenceTimeStr = $schedule['break_end'];
            }
        }

        $workStart = strtotime(date('Y-m-d') . ' ' . $referenceTimeStr);

        // عبور منتصف الليل
        if ($workStart > $now + 43200) {
            $workStart = strtotime(date('Y-m-d', strtotime('-1 day')) . ' ' . $referenceTimeStr);
        }

        if ($now > $workStart) {
            return max(0, (int)round(($now - $workStart) / 60));
        }

        return 0;
    }

    /**
     * التحقق من الموقع الجغرافي
     */
    public function validateGeofence(float $empLat, float $empLon, ?int $branchId = null): array
    {
        return isWithinGeofence($empLat, $empLon, $branchId);
    }

    /**
     * التحقق من نافذة الوقت
     */
    public function isWithinTimeWindow(string $type, ?int $branchId = null): array
    {
        $schedule = getBranchSchedule($branchId);

        if ($type === 'in') {
            $start = $schedule['check_in_start_time'];
            $end = $schedule['check_in_end_time'];
        } elseif ($type === 'out') {
            $start = $schedule['check_out_start_time'];
            $end = $schedule['check_out_end_time'];
        } else {
            return ['allowed' => true, 'message' => 'مسموح'];
        }

        $nowMin = (int)date('H') * 60 + (int)date('i');
        $startMin = $this->timeToMinutes($start);
        $endMin = $this->timeToMinutes($end);

        // عبور منتصف الليل
        if ($endMin < $startMin) {
            $allowed = ($nowMin >= $startMin || $nowMin <= $endMin);
        } else {
            $allowed = ($nowMin >= $startMin && $nowMin <= $endMin);
        }

        if ($allowed) {
            return ['allowed' => true, 'message' => 'ضمن الوقت المسموح'];
        }

        return [
            'allowed' => false,
            'message' => "خارج وقت التسجيل ({$start} - {$end})",
        ];
    }

    /**
     * إحصائيات لوحة التحكم
     */
    public function getDashboardStats(): array
    {
        $stats = $this->attendance->getTodayStats();
        $recent = $this->attendance->getRecent(15);
        $absent = $this->employee->getAbsentToday(10);
        $branchCount = $this->branch->count(['is_active' => 1]);

        return [
            'stats' => $stats,
            'recent' => $recent,
            'absent' => $absent,
            'branch_count' => $branchCount,
        ];
    }

    private function timeToMinutes(string $time): int
    {
        $p = explode(':', $time);
        return (int)$p[0] * 60 + (int)($p[1] ?? 0);
    }

    private function getClientIP(): string
    {
        return function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
