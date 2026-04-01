<?php
// =============================================================
// includes/rate_limiter.php - حماية Rate Limiting للـ API
// =============================================================
// يحد من عدد الطلبات لكل IP لمنع هجمات DoS
// يستخدم ملفات مؤقتة (متوافق مع الاستضافة المشتركة)
// =============================================================

/**
 * التحقق من Rate Limit لعنوان IP
 * @param int $maxRequests الحد الأقصى للطلبات
 * @param int $windowSeconds نافذة الوقت بالثواني
 * @param string $prefix بادئة لتمييز نقاط النهاية
 * @return bool true إذا تجاوز الحد
 */
function isRateLimited(int $maxRequests = 60, int $windowSeconds = 60, string $prefix = 'api'): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = preg_replace('/[^a-zA-Z0-9._:-]/', '_', $ip);
    
    $rateLimitDir = sys_get_temp_dir() . '/attendance_rate_limit';
    if (!is_dir($rateLimitDir)) {
        @mkdir($rateLimitDir, 0700, true);
    }
    
    $file = $rateLimitDir . '/' . $prefix . '_' . $ip . '.json';
    $now  = time();
    
    // قراءة البيانات الحالية (مع File Locking لمنع Race Conditions)
    $data = ['requests' => [], 'blocked_until' => 0];
    $fp = @fopen($file, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $content = stream_get_contents($fp);
        if ($content) {
            $data = json_decode($content, true) ?: $data;
        }
    }
    
    // التحقق من القفل
    if ($data['blocked_until'] > $now) {
        return true;
    }
    
    // تنظيف الطلبات القديمة
    $data['requests'] = array_filter($data['requests'], function($ts) use ($now, $windowSeconds) {
        return ($now - $ts) < $windowSeconds;
    });
    $data['requests'] = array_values($data['requests']);
    
    // التحقق من الحد
    $isLimited = false;
    if (count($data['requests']) >= $maxRequests) {
        // قفل لمضاعفة النافذة
        $data['blocked_until'] = $now + $windowSeconds;
        $isLimited = true;
    } else {
        // تسجيل الطلب
        $data['requests'][] = $now;
        $data['blocked_until'] = 0;
    }

    if ($fp) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
    } else {
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    return $isLimited;
}

/**
 * إرجاع رد Rate Limit وإنهاء التنفيذ
 */
function rateLimitResponse(): void {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 60');
    echo json_encode([
        'success' => false,
        'message' => 'تم تجاوز الحد المسموح من الطلبات. حاول مرة أخرى بعد دقيقة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * تنظيف الملفات المنتهية الصلاحية (يُستدعى دورياً)
 */
function cleanupRateLimitFiles(int $maxAgeSeconds = 3600): void {
    $rateLimitDir = sys_get_temp_dir() . '/attendance_rate_limit';
    if (!is_dir($rateLimitDir)) return;
    
    $now = time();
    foreach (glob($rateLimitDir . '/*.json') as $file) {
        if (($now - filemtime($file)) > $maxAgeSeconds) {
            @unlink($file);
        }
    }
}
