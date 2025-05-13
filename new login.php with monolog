<?php
// login.php

// Start Session - MUST be at the very top
session_start();

// Enable error reporting for development, but consider turning off display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production, errors should go to logs

// Include your logger setup
require_once __DIR__ . '/logger_setup.php';

// Define the log file path

$logFilePath = __DIR__ . '/../logs/auth_operations.log'; 

// Get a logger instance for the authentication channel
$logger = getLogger('AUTH_LOGIN', $logFilePath);

// --- RabbitMQ Client Library ---
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

$logger->info('Login script started.'); // Replaces: echo "Script started.<br>";

// --- RabbitMQ Connection Settings ---
$rabbitmq_host = '192.168.191.13';
$rabbitmq_port = 5672;
$rabbitmq_user = 'guest';
$rabbitmq_pass = 'guest';
$rabbitmq_vhost = '/';
$exchange_name = 'user_exchange';
$listener_queue_name = 'login';
$routing_key = 'login';

$logger->debug('RabbitMQ configuration defined.', [
    'host' => $rabbitmq_host,
    'port' => $rabbitmq_port,
    'user' => $rabbitmq_user,
    'vhost' => $rabbitmq_vhost,
    'exchange' => $exchange_name,
    'queue' => $listener_queue_name,
    'routing_key' => $routing_key
]);

$error_display_message = null; // For user-facing errors

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $logger->info('POST request received for login.');

    $identifier_raw = $_POST['username'] ?? '';
    $password_raw = $_POST['password'] ?? '';

    $identifier = htmlspecialchars($identifier_raw, ENT_QUOTES, 'UTF-8');
    $password = $password_raw; // Password sent raw for listener to verify

    $logger->info('Input received.', ['identifier_received' => $identifier_raw]); // Log raw for audit if needed, be careful with PII
    $logger->debug('Input sanitized/prepared.', ['identifier' => $identifier]);


    if (empty($identifier) || empty($password)) {
        $error_display_message = "Username/Email and password are required.";
        $logger->warning('Input validation failed: Missing fields.', ['identifier_empty' => empty($identifier), 'password_empty' => empty($password)]);
    } else {
        $logger->info('Input validated successfully.');

        // Generate a correlation ID for this request
        // You can define this as a global or constant if preferred for the custom processor
        $correlation_id = uniqid('login_', true);
        define('CORRELATION_ID', $correlation_id); // For the logger processor
        // Or $GLOBALS['correlation_id'] = $correlation_id;


        $login_data = [
            'identifier' => $identifier,
            'password' => $password
        ];
        $message_body = json_encode($login_data);
        $logger->info('Login message body prepared for RabbitMQ.', ['correlation_id' => $correlation_id]);
        $logger->debug('Message body content:', ['body' => $login_data]); // Don't log plain password in prod usually

        $connection = null;
        $channel = null;
        $reply_queue_name = '';
        $response_from_listener = null;

        try {
            $logger->info('Attempting to connect to RabbitMQ.', ['correlation_id' => $correlation_id]);
            $connection = new AMQPStreamConnection(
                $rabbitmq_host, $rabbitmq_port, $rabbitmq_user, $rabbitmq_pass, $rabbitmq_vhost
            );
            $logger->info('RabbitMQ connection established.', ['correlation_id' => $correlation_id]);

            $channel = $connection->channel();
            $logger->info('RabbitMQ channel opened.', ['correlation_id' => $correlation_id]);

            list($reply_queue_name, ,) = $channel->queue_declare("", false, false, true, false);
            $logger->debug('Reply queue declared.', ['queue_name' => $reply_queue_name, 'correlation_id' => $correlation_id]);

            // Optional: Declare exchange & queue if not sure listener did
            // $channel->exchange_declare($exchange_name, 'direct', false, true, false);
            // $channel->queue_declare($listener_queue_name, false, true, false, false);
            // $channel->queue_bind($listener_queue_name, $exchange_name, $routing_key);

            $msg = new AMQPMessage(
                $message_body,
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'reply_to' => $reply_queue_name,
                    'correlation_id' => $correlation_id
                ]
            );

            $channel->basic_publish($msg, $exchange_name, $routing_key);
            $logger->info('Login request message published to RabbitMQ.', [
                'exchange' => $exchange_name,
                'routing_key' => $routing_key,
                'correlation_id' => $correlation_id
            ]);

            $logger->info('Waiting for login response from listener...', ['correlation_id' => $correlation_id]);

            $callback = function ($reply_msg) use (&$response_from_listener, $correlation_id, $channel, $logger) {
                $logger->debug('Reply message received from listener.', ['correlation_id_received' => $reply_msg->get('correlation_id'), 'expected_correlation_id' => $correlation_id]);
                if ($reply_msg->get('correlation_id') === $correlation_id) {
                    $response_from_listener = json_decode($reply_msg->body, true);
                    $logger->info('Login response received and decoded.', ['response' => $response_from_listener, 'correlation_id' => $correlation_id]);
                    $channel->basic_ack($reply_msg->getDeliveryTag());
                    $logger->debug('Reply message acknowledged.', ['correlation_id' => $correlation_id]);
                } else {
                    $logger->warning('Received message with mismatched correlation ID.', [
                        'expected' => $correlation_id,
                        'received' => $reply_msg->get('correlation_id')
                    ]);
                    $channel->basic_nack($reply_msg->getDeliveryTag(), false, false); // Reject, don't requeue
                }
            };

            $channel->basic_consume($reply_queue_name, '', false, false, true, false, $callback);

            try {
                $channel->wait(null, false, 10); // 10-second timeout
            } catch (AMQPTimeoutException $e) {
                $logger->error('Timeout waiting for login response from listener.', ['correlation_id' => $correlation_id, 'exception' => $e->getMessage()]);
                $response_from_listener = ['success' => false, 'message' => 'Login request timed out. Service may be busy.'];
            }
            $logger->debug('Exited wait loop for listener response.', ['correlation_id' => $correlation_id]);


            if ($response_from_listener && isset($response_from_listener['success'])) {
                if ($response_from_listener['success']) {
                    $logger->info('Login successful via listener.', [
                        'username' => $response_from_listener['username'] ?? 'N/A',
                        'session_key' => isset($response_from_listener['session_key']) ? '******' : 'N/A', // Mask session key
                        'correlation_id' => $correlation_id
                    ]);
                    $_SESSION['username'] = $response_from_listener['username'];
                    $_SESSION['session_key'] = $response_from_listener['session_key'];
                    // Redirect
                    header("Location: index.html?status=login_success"); // Or dashboard.php
                    exit();
                } else {
                    $error_display_message = $response_from_listener['message'] ?? 'Invalid credentials or login failed.';
                    $logger->warning('Login failed by listener.', ['reason' => $error_display_message, 'correlation_id' => $correlation_id]);
                }
            } else {
                $error_display_message = $response_from_listener['message'] ?? 'Invalid response from authentication service or request timed out.';
                $logger->error('Invalid or missing response from listener after login attempt.', ['response_received' => $response_from_listener, 'correlation_id' => $correlation_id]);
            }

        } catch (\Exception $e) {
            $error_display_message = "A technical issue occurred. Please try again later.";
            $logger->critical('Exception during RabbitMQ operation or login processing.', [
                'exception_type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'correlation_id' => $correlation_id ?? 'NOT_SET'
                // 'trace' => $e->getTraceAsString() // Can be very verbose
            ]);
        } finally {
            $logger->debug('Entering finally block for RabbitMQ cleanup.', ['correlation_id' => $correlation_id ?? 'NOT_SET']);
            if (isset($channel) && $channel->is_open()) {
                try { $channel->close(); $logger->debug('RabbitMQ channel closed.'); } catch (\Exception $ce) { $logger->error('Error closing RabbitMQ channel.', ['exception' => $ce->getMessage()]); }
            }
            if (isset($connection) && $connection->isConnected()) {
                try { $connection->close(); $logger->debug('RabbitMQ connection closed.'); } catch (\Exception $ce) { $logger->error('Error closing RabbitMQ connection.', ['exception' => $ce->getMessage()]); }
            }
            $logger->debug('Finally block finished.');
        }
    } // End of input validation success
} else {
    // Not a POST request
    $logger->debug('Non-POST request received for login page.');
}

$logger->info('Login script finished.');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login</title>
    <link rel="stylesheet" href="Frontend/CSS/styles.css"> <style>
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; text-align: center; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="login_form">
        <form method="post" action="login.php">
            <h2 class="login">Login</h2>

            <?php if (!empty($error_display_message)): ?>
                <p class="message error"><?php echo htmlspecialchars($error_display_message); ?></p>
            <?php endif; ?>
            <?php if (isset($_GET['status']) && $_GET['status'] === 'registration_success'): ?>
                <p class="message success">Registration successful! Please log in.</p>
            <?php endif; ?>

            <div>
                <div class="input_box">
                    <label for="username_field">Username or Email</label> <input type="text" id="username_field" name="username" placeholder="Enter username or email" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" />
                </div>
                <div class="input_box">
                    <div>
                        <label for="password">Password</label>
                    </div>
                    <input type="password" id="password" name="password" placeholder="Enter password" required />
                </div>
                <button type="submit">Log In</button>
                <p class="sign_up">Don't have an account? <a href="register.php">Sign up</a></p>
            </div>
        </form>
    </div>
</body>
</html>
