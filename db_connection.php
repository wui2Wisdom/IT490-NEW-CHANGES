#!/usr/bin/php
<?php

$mydb = new mysqli('127.0.0.1', 'IT490', 'password', 'IT490');

if ($mydb->errno != 0) {
    echo "Failed to connect to database: " . $mydb->error . PHP_EOL;
    exit(0);
}

echo "Successfully connected to database!" . PHP_EOL;


$query = "SELECT * FROM users";
$response = $mydb->query($query);

if ($mydb->errno != 0) {
    echo "Failed to execute query:" . PHP_EOL;
    echo __FILE__ . " Line " . __LINE__ . ": " . $mydb->error . PHP_EOL;
    exit(0);
}


while ($row = $response->fetch_assoc()) {
    echo "User: " . $row['username'] . PHP_EOL;
}

$mydb->close();
