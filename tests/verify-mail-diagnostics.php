<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/mail-diagnostics.php';
function mail_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$config = ['SMTP_HOST' => 'smtp.gmail.com', 'SMTP_PORT' => '465', 'SMTP_ENCRYPTION' => 'ssl',
    'SMTP_USERNAME' => 'sender@example.test', 'SMTP_PASSWORD' => 'SECRET-DO-NOT-LEAK', 'MAIL_FROM' => 'System <sender@example.test>'];
mail_expect(mail_configuration_issue($config, 'smtp') === null, 'Valid SMTP');
mail_expect(mail_configuration_issue([], 'smtp')['code'] === 'MAIL_CONFIG_MISSING', 'Missing keys');
mail_expect(mail_configuration_issue([], 'unknown')['code'] === 'MAIL_TRANSPORT_INVALID', 'Invalid transport');
mail_expect(mail_configuration_issue([], 'log') === null, 'Log mode');
mail_expect(mail_configuration_issue(array_replace($config, ['MAIL_FROM' => 'broken']), 'smtp')['code'] === 'MAIL_FROM_INVALID', 'Invalid sender');
mail_expect(mail_configuration_issue(array_replace($config, ['MAIL_FROM' => 'other@example.test']), 'smtp')['code'] === 'SMTP_SENDER_MISMATCH', 'Gmail mismatch');
mail_expect(mail_configuration_issue(array_replace($config, ['SMTP_PORT' => 587]), 'smtp')['code'] === 'SMTP_TLS_CONFIG', 'Port mismatch');
mail_expect(mail_configuration_issue(array_replace($config, ['SMTP_PORT' => 587, 'SMTP_ENCRYPTION' => 'tls']), 'smtp') === null, 'STARTTLS config');
$cases = [
    ['SMTP command failed (code 535).', 0, 'SMTP_AUTH_FAILED'],
    ['SMTP command failed (code 534).', 0, 'SMTP_AUTH_FAILED'],
    ['SMTP command failed (code 550).', 0, 'SMTP_ADDRESS_REJECTED'],
    ['SMTP command failed (code 421).', 0, 'SMTP_TEMPORARY_FAILURE'],
    ['Unable to connect to the SMTP server: SECRET-DO-NOT-LEAK', 0, 'SMTP_CONNECTION_FAILED'],
    ['Unable to enable SMTP TLS encryption.', 0, 'SMTP_TLS_FAILED'],
    ['Email provider rejected the test message.', 403, 'MAIL_PROVIDER_DENIED'],
    ['Email provider rejected the test message.', 429, 'MAIL_RATE_LIMIT'],
    ['arbitrary error SECRET-DO-NOT-LEAK', 0, 'MAIL_DELIVERY_FAILED'],
];
foreach ($cases as [$message, $code, $expected]) {
    $issue = mail_delivery_issue(new RuntimeException($message, $code));
    mail_expect($issue['code'] === $expected, 'Classification: ' . $expected);
    mail_expect(!str_contains(json_encode($issue), 'SECRET-DO-NOT-LEAK'), 'Secret exposure');
}
echo "MAIL_DIAGNOSTICS_OK: config checks, SMTP/provider errors, secret exclusion; no email sent\n";
