<?php
declare(strict_types=1);

/** Only expose settings that currently have a supported consumer. */
function validated_settings_update(array $current, array $input): array
{
    if (array_diff(array_keys($input), ['academic_year', 'system_name', 'notifications_enabled', 'notification_refresh', 'ai_title_enabled', 'ai_risk_enabled'])) {
        throw new InvalidArgumentException('พบค่าที่ระบบไม่รองรับ กรุณาโหลดหน้าใหม่');
    }
    $raw = $input['academic_year'] ?? null;
    if (!is_string($raw) && !is_int($raw)) {
        throw new InvalidArgumentException('กรุณากรอกปีการศึกษาเป็น พ.ศ. 4 หลัก');
    }
    $year = trim((string) $raw);
    if (!preg_match('/^[0-9]{4}$/D', $year) || (int) $year < 2400 || (int) $year > 2999) {
        throw new InvalidArgumentException('กรุณากรอกปีการศึกษาเป็น พ.ศ. ระหว่าง 2400–2999');
    }
    // Keep legacy keys for compatibility, but do not allow this form to change them.
    $current['academic_year'] = $year;
    if (array_key_exists('system_name', $input)) {
        if (!is_string($input['system_name']) || trim($input['system_name']) === '' || mb_strlen(trim($input['system_name'])) > 120) {
            throw new InvalidArgumentException('กรุณากรอกชื่อระบบ 1–120 ตัวอักษร');
        }
        $current['system_name'] = trim($input['system_name']);
    }
    foreach (['notifications_enabled', 'ai_title_enabled', 'ai_risk_enabled'] as $key) {
        if (!array_key_exists($key, $input)) continue;
        if (!is_bool($input[$key])) throw new InvalidArgumentException('ค่าเปิด–ปิดไม่ถูกต้อง');
        $current[$key] = $input[$key];
    }
    if (array_key_exists('notification_refresh', $input)) {
        $interval = $input['notification_refresh'];
        if ((!is_int($interval) && !is_string($interval)) || !preg_match('/^[0-9]+$/D', (string) $interval)
            || (int) $interval < 10000 || (int) $interval > 300000 || (int) $interval % 1000 !== 0) {
            throw new InvalidArgumentException('รอบรีเฟรชต้องเป็นจำนวนเต็ม 10–300 วินาที');
        }
        $current['notification_refresh'] = (int) $interval;
    }
    return $current;
}

function system_settings(): array
{
    static $settings, $loadedAt = 0.0;
    if ($settings !== null && microtime(true) - $loadedAt < 5) return $settings;
    $json = database_connection()->query("SELECT JSON_EXTRACT(state_json, '$.settings') FROM app_state WHERE state_key='runtime' LIMIT 1")->fetchColumn();
    $settings = json_decode(is_string($json) ? $json : '{}', true);
    $loadedAt = microtime(true);
    return $settings = is_array($settings) ? $settings : [];
}

function system_setting_enabled(string $key): bool
{
    $settings = system_settings();
    return !array_key_exists($key, $settings) || filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
}
