-- Retired standalone migration. Use scripts/migrate-twenty-tables.php for existing databases.
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use scripts/migrate-twenty-tables.php; legacy tables must not be recreated';
