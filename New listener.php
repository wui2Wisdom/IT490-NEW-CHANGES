#!/usr/bin/php
<?php
/**
 * Authentication Service Listener
 *
 * This script handles user registration and login requests received through RabbitMQ.
 * It processes messages, interacts with the database, and returns responses.
 */

// Load dependencies
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// --- Configuration ---

// Database configuration
$dbConfig = [
    'host' => '127.0.0.1', // Connect to LOCALHOST since DB is on same machine as script
    'name' => 'IT490',     // Database name
    'user' => 'IT490',     // DB User
    'pass' => 'password'  // DB Password
];

// RabbitMQ configuration
$mqConfig = [
    'host' => '192.168.191.13', // YOUR VM's ZeroTier IP where RabbitMQ runs
    'port' => 5672,             // Standard RabbitMQ AMQP port
    'user' => 'guest',          // Ensure 'guest' user can login remotely OR use a different user
    'pass' => 'guest'           // Ensure 'guest' user can login remotely OR use a different password
];

// --- Configuration End ---


// Connect to database
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

// Connect to RabbitMQ
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

// Declare message queues
$mqChannel->queue_declare('registration', false, true, false, false);
$mqChannel->queue_declare('login', false, true, false, false);

echo "Waiting for messages. To exit press CTRL+C\n";

/**
 * Process incoming messages from RabbitMQ
 */
$messageHandler = function ($message) use ($dbConnection, $mqChannel) {
    $requestData = json_decode($message->body, true);
    $requestType = $message->get('routing_key'); // Use routing key to determine type
    $response = [];

    // Determine identifier for logging based on request type
    $logIdentifier = 'unknown';
    if ($requestType === 'login' && isset($requestData['identifier'])) {
        $logIdentifier = $requestData['identifier'];
    } elseif ($requestType === 'registration' && isset($requestData['username'])) {
        $logIdentifier = $requestData['username'];
    }

    echo "Received {$requestType} request for identifier: {$logIdentifier}\n";

    // Basic validation based on request type
    if (!is_array($requestData)) {
        $response = ['success' => false, 'message' => 'Invalid request format: not an array'];
    } elseif ($requestType === 'registration') {
        // Registration requires username, email, password
        if (!isset($requestData['username']) || !isset($requestData['email']) || !isset($requestData['password'])) {
             $response = ['success' => false, 'message' => 'Invalid registration format: missing fields'];
        } else {
            $response = handleRegistration($dbConnection, $requestData);
        }
    } elseif ($requestType === 'login') {
        // Login requires identifier, password
         if (!isset($requestData['identifier']) || !isset($requestData['password'])) {
             $response = ['success' => false, 'message' => 'Invalid login format: missing fields'];
        } else {
            $response = handleLogin($dbConnection, $requestData);
        }
    } else {
        $response = ['success' => false, 'message' => 'Unknown request type'];
    }

    // Send response back to client if reply_to and correlation_id are set (RPC pattern)
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
        echo "No reply_to or correlation_id found, skipping RPC response.\n";
    }


    // Acknowledge the message was processed
    $message->ack();
    echo "Message acknowledged.\n";
};

/**
 * Handle user registration requests
 * --- UPDATED ---
 */
function handleRegistration($db, $userData) {
    // Basic validation
    if (empty($userData['username']) || empty($userData['email']) || empty($userData['password'])) {
        return ['success' => false, 'message' => 'Username, email, and password cannot be empty'];
    }

    // Validate email format
    if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    try {
        // Check if username already exists
        $checkUserStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $checkUserStmt->execute(['username' => $userData['username']]);
        if ($checkUserStmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Username already exists'];
        }

        // Check if email already exists
        $checkEmailStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
        $checkEmailStmt->execute(['email' => $userData['email']]);
        if ($checkEmailStmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Email address already registered'];
        }

        // Create new user - Include email
        $insertStmt = $db->prepare(
            "INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)"
        );

        // Hash the password received from the queue
        $hashedPassword = password_hash($userData['password'], PASSWORD_BCRYPT);

        $insertStmt->execute([
            'username' => $userData['username'],
            'email' => $userData['email'], // Add email here
            'password_hash' => $hashedPassword
        ]);

        return [
            'success' => true,
            'message' => "User registered successfully: {$userData['username']}"
        ];
    } catch (PDOException $e) {
        // Log detailed error on server, return generic message to client
        error_log("Registration failed: {$e->getMessage()}");
        return ['success' => false, 'message' => "Registration process failed."];
    }
}

/**
 * Handle user login requests
 * --- UPDATED ---
 */
function handleLogin($db, $userData) {
     // Basic validation - Expect 'identifier' and 'password' keys
    if (empty($userData['identifier']) || empty($userData['password'])) {
        echo "Listener (Login): Identifier or password empty.\n";
        return ['success' => false, 'message' => 'Username/Email and password cannot be empty'];
    }

    $identifier = $userData['identifier']; // The input from the "Username or Email" field
    $password = $userData['password']; // The plain password from the form

    echo "Listener (Login): Received identifier '{$identifier}' and password (masked).\n";

    try {
        // --- MODIFIED QUERY ---
        // Prepare a query to find the user by EITHER username OR email
        echo "Listener (Login): Preparing DB query for identifier: {$identifier}.\n";
        // *** Assumes you have added an 'email' column to your 'users' table! ***
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :identifier OR email = :identifier");
        $stmt->execute(['identifier' => $identifier]); // Use the input for both placeholders
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        // --- END OF MODIFIED QUERY ---

        if ($user) {
            // Log which field matched if possible (optional)
            $matched_field = ($user['username'] === $identifier) ? 'username' : 'email';
            echo "Listener (Login): User found in DB via {$matched_field}. User ID: {$user['id']}, Username: {$user['username']}, Email: {$user['email']}.\n";

            // Verify credentials using the plain password from the message and the stored hash
            echo "Listener (Login): Verifying password using password_verify().\n";
            if (password_verify($password, $user['password_hash'])) {
                echo "Listener (Login): Password verification successful.\n";
                // Generate session
                $sessionKey = bin2hex(random_bytes(16));
                $expiryTime = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

                // Insert session into the 'sessions' table
                echo "Listener (Login): Preparing to insert session for user ID: {$user['id']}.\n";
                $sessionStmt = $db->prepare(
                    "INSERT INTO sessions (user_id, session_key, expires_at)
                     VALUES (:user_id, :session_key, :expires_at)"
                );
                $sessionStmt->execute([
                    'user_id' => $user['id'],
                    'session_key' => $sessionKey,
                    'expires_at' => $expiryTime
                ]);
                echo "Listener (Login): Session inserted successfully.\n";

                // Return success, session key, and the canonical username
                return [
                    'success' => true,
                    'session_key' => $sessionKey,
                    'username' => $user['username'] // Send back the actual username
                ];
            } else {
                echo "Listener (Login): Password verification failed for identifier {$identifier}.\n";
                return ['success' => false, 'message' => 'Invalid credentials']; // Generic error
            }
        } else {
            echo "Listener (Login): Identifier '{$identifier}' not found in DB as username or email.\n";
            return ['success' => false, 'message' => 'Invalid credentials']; // Generic error
        }
    } catch (PDOException $e) {
        echo "Listener (Login): Caught DB exception: " . $e->getMessage() . "\n";
        error_log("Login failed: {$e->getMessage()}");
        return ['success' => false, 'message' => "Login process failed."];
    }
}

// Start consuming messages
// Set auto-ack (4th param) to false - we will manually acknowledge after processing
$mqChannel->basic_consume('registration', '', false, false, false, false, $messageHandler);
$mqChannel->basic_consume('login', '', false, false, false, false, $messageHandler);


// Keep the script running until interrupted
try {
    while ($mqChannel->is_consuming()) {
        $mqChannel->wait();
    }
} catch (Exception $e) {
    echo "Error during wait: " . $e->getMessage() . "\n";
} finally {
    // Clean up connections
    if ($mqChannel->is_open()) {
        $mqChannel->close();
    }
    if ($mqConnection->isConnected()) {
        $mqConnection->close();
    }
    echo "Connections closed\n";
}
?>
