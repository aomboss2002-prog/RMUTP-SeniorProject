<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/settings.php';
$original = ['academic_year' => '2026', 'approval_mode' => 'advisor-first', 'notification_refresh' => 15000, 'system_name' => 'Original'];
$updated = validated_settings_update($original, ['academic_year' => ' 2569 ']);
if ($updated !== array_replace($original, ['academic_year' => '2569'])) throw new RuntimeException('Unexpected settings change');
foreach ([[], ['academic_year' => '2026'], ['academic_year' => '3000'], ['academic_year' => '2569.5'], ['academic_year' => ['2569']], ['academic_year' => true], ['academic_year' => '2569', 'approval_mode' => 'parallel']] as $invalid) {
    try { validated_settings_update($original, $invalid); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Invalid payload accepted');
}
foreach ([2400, 2999] as $year) {
    if (validated_settings_update([], ['academic_year' => $year])['academic_year'] !== (string) $year) throw new RuntimeException('Boundary failed');
}
echo "SETTINGS_OK: Buddhist year validation, allowlist, legacy values preserved (no database writes)\n";
$full = validated_settings_update($original, ['academic_year' => '2569', 'system_name' => 'ระบบทดสอบ', 'notifications_enabled' => false, 'notification_refresh' => 30000, 'ai_title_enabled' => false, 'ai_risk_enabled' => true]);
if ($full['notifications_enabled'] !== false || $full['ai_title_enabled'] !== false || $full['system_name'] !== 'ระบบทดสอบ') throw new RuntimeException('System settings not saved');
foreach ([['system_name' => ''], ['system_name' => []], ['notifications_enabled' => 'false'], ['notification_refresh' => 9000], ['notification_refresh' => 300001], ['notification_refresh' => 15500]] as $invalid) {
    try { validated_settings_update($original, ['academic_year' => '2569'] + $invalid); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Invalid system setting accepted');
}
echo "SYSTEM_SETTINGS_OK: name, boolean controls and polling bounds\n";
$withoutName = validated_settings_update($original, ['academic_year' => '2569', 'notifications_enabled' => true, 'notification_refresh' => 20000, 'ai_title_enabled' => true, 'ai_risk_enabled' => false]);
if ($withoutName['system_name'] !== $original['system_name'] || $withoutName['notification_refresh'] !== 20000 || $withoutName['ai_risk_enabled'] !== false) throw new RuntimeException('Saving visible settings must preserve the existing name');
$settingsView = file_get_contents(dirname(__DIR__) . '/views/pages/settings.php');
$settingsJs = file_get_contents(dirname(__DIR__) . '/assets/js/student.js');
if (str_contains($settingsView, 'id="systemName"') || str_contains($settingsJs, "$('#systemName')")) throw new RuntimeException('Removed name field must not be used by the form');
echo "SETTINGS_WITHOUT_NAME_OK: hidden field removed, remaining settings saved and existing name preserved\n";
