<?php
require_once __DIR__ . '/config.php';

$db = new PDO("sqlite:" . DB_FILE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT,
    subject TEXT,
    filename TEXT,
    analysis TEXT,
    status TEXT
)";
$db->exec($sql);

echo "Database ready: " . DB_FILE . PHP_EOL;
