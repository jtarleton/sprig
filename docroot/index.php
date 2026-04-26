<?php
$db = new SQLite3('/var/www/data/app.sqlite');
$db->exec('CREATE TABLE IF NOT EXISTS visits (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL)');
$db->exec("INSERT INTO visits (created_at) VALUES (datetime('now'))");

$result = $db->query('SELECT COUNT(*) AS count FROM visits');
$row = $result->fetchArray(SQLITE3_ASSOC);

echo "Hello from PHP + SQLite! Visits: " . $row['count'];


