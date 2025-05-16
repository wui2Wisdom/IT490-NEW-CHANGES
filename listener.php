#!/usr/bin/php
<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dbConfig = [
    'host' => '127.0.0.1',
    'name' => 'IT490',
    'user' => 'IT490',
    'pass' => 'password'
];

$mqConfig = [
    'host' => '192.168.191.13',
    'port' => 5672,
    'user' => 'guest',
    'pass' => 'guest'
];

try {
    $dbConnection = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']}",
        $dbConfig['user'],
        $dbConfig['pass']
    );
    $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection established successfully\n";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

try {
    $mqConnection = new AMQPStreamConnection(
        $mqConfig['host'],
        $mqConfig['port'],
        $mqConfig['user'],
        $mqConfig['pass']
    );
    $mqChannel = $mqConnection->channel();
    echo "RabbitMQ connection established successfully\n";
} catch (Exception $e) {
    die("RabbitMQ connection failed: " . $e->getMessage() . "\n");
}

$mqChannel->queue_declare('registration', false, true, false, false);
$mqChannel->queue_declare('login', false, true, false, false);

echo "Waiting for messages. To exit press CTRL+C\n";

$messageHandler = function ($message) use ($dbConnection, $mqChannel) {
    $requestData = json_decode($message->body, true);
    $username = isset($requestData['username']) ? $requestData['username'] : 'unknown';
    $requestType = $message->get('routing_key');
    $response = [];

    echo "Received {$requestType} request for user: {$username}\n";

    if (!is_array($requestData) || !isset($requestData['username']) || !isset($requestData['password'])) {
         $response = ['success' => false, 'message' => 'Invalid request format'];
    } elseif ($requestType === 'registration') {
         $response = handleRegistration($dbConnection, $requestData);
    } elseif ($requestType === 'login') {
         $response = handleLogin($dbConnection, $requestData);
    } else {
         $response = ['success' => false, 'message' => 'Unknown request type'];
    }

    $replyTo = $message->has('reply_to') ? $message->get('reply_to') : null;
    $correlationId = $message->has('correlation_id') ? $message->get('correlation_id') : null;

    if ($replyTo && $correlationId) {
        $responseMessage = new AMQPMessage(
            json_encode($response),
            ['correlation_id' => $correlationId]
        );

        $mqChannel->basic_publish($responseMessage, '', $replyTo);
        echo "Response sent: " . json_encode($response) . "\n";
    } else {
        echo "No reply_to or correlation_id found, skipping response.\n";
    }

    $message->ack();
    echo "Message acknowledged.\n";
};

function handleRegistration($db, $userData) {
    if (empty($userData['username']) || empty($userData['password'])) {
        return ['success' => false, 'message' => 'Username and password cannot be empty'];
    }
    try {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $checkStmt->execute(['username' => $userData['username']]);

        if ($checkStmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Username already exists'];
        }

        $insertStmt = $db->prepare(
            "INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)"
        );

        $insertStmt->execute([
            'username' => $userData['username'],
            'password_hash' => password_hash($userData['password'], PASSWORD_BCRYPT)
        ]);

        return [
            'success' => true,
            'message' => "User registered successfully: {$userData['username']}"
        ];
    } catch (PDOException $e) {
        error_log("Registration failed: {$e->getMessage()}");
        return ['success' => false, 'message' => "Registration process failed."];
    }
}

function handleLogin($db, $userData) {
    if (empty($userData['username']) || empty($userData['password'])) {
        return ['success' => false, 'message' => 'Username and password cannot be empty'];
    }

    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $userData['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (password_verify($userData['password'], $user['password_hash'])) {
                $sessionKey = bin2hex(random_bytes(16));
                $expiryTime = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

                $sessionStmt = $db->prepare(
                    "INSERT INTO sessions (user_id, session_key, expires_at)
                     VALUES (:user_id, :session_key, :expires_at)"
                );

                $sessionStmt->execute([
                    'user_id' => $user['id'],
                    'session_key' => $sessionKey,
                    'expires_at' => $expiryTime
                ]);

                return [
                    'success' => true,
                    'session_key' => $sessionKey,
                    'username' => $user['username']
                ];
            } else {
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
        } else {
            return ['success' => false, 'message' => 'Invalid username or password'];
        }
    } catch (PDOException $e) {
        error_log("Login failed: {$e->getMessage()}");
        return ['success' => false, 'message' => "Login process failed."];
    }
}

$mqChannel->basic_consume('registration', '', false, false, false, false, $messageHandler);
$mqChannel->basic_consume('login', '', false, false, false, false, $messageHandler);

try {
    while ($mqChannel->is_consuming()) {
        $mqChannel->wait();
    }
} catch (Exception $e) {
    echo "Error during wait: " . $e->getMessage() . "\n";
} finally {
    $mqChannel->close();
    $mqConnection->close();
    echo "Connections closed\n";
}
?>

while ($channel->is_consuming()) {
    $channel->wait();
}
