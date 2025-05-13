<?php
// register.php

error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production

// Include your logger setup
require_once __DIR__ . '/logger_setup.php';

// Define the log file path (use the same as login.php or a different one if preferred)
$logFilePath = __DIR__ . '/../logs/auth_operations.log';
$logger = getLogger('AUTH_REGISTER', $logFilePath);

// --- RabbitMQ Client Library ---
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

$logger->info('Registration script started.');

// --- RabbitMQ Connection Settings ---
$rabbitmq_host = '192.168.191.13';
$rabbitmq_port = 5672;
$rabbitmq_user = 'guest';
$rabbitmq_pass = 'guest';
$rabbitmq_vhost = '/';
$exchange_name = 'user_exchange';
$routing_key = 'registration'; // For registration messages
$listener_queue_name = 'registration';

$logger->debug('RabbitMQ configuration defined for registration.');

$error_display_message = null;
$success_message_display = null;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $logger->info('POST request received for registration.');

    $username_raw = $_POST['username'] ?? '';
    $email_raw = $_POST['email'] ?? '';
    $password_raw = $_POST['password'] ?? ''; // Keep password raw

    $username = htmlspecialchars($username_raw, ENT_QUOTES, 'UTF-8');
    $email = filter_var($email_raw, FILTER_SANITIZE_EMAIL);
    $password = $password_raw; // Listener will hash

    $logger->info('Registration input received.', ['username_raw' => $username_raw, 'email_raw' => $email_raw]);
    $logger->debug('Registration input sanitized/prepared.', ['username' => $username, 'email' => $email]);

    if (empty($username) || empty($email) || empty($password)) {
        $error_display_message = "All fields (username, email, password) are required.";
        $logger->warning('Registration validation failed: Missing fields.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_display_message = "Invalid email format.";
        $logger->warning('Registration validation failed: Invalid email format.', ['email_provided' => $email_raw]);
    } else {
        $logger->info('Registration input validated successfully.');

        $correlation_id = uniqid('reg_', true);
        define('CORRELATION_ID', $correlation_id); // For logger

        $registration_data = [
            'username' => $username,
            'email' => $email,
            'password' => $password // Sending plain password
        ];
        $message_body = json_encode($registration_data);
        $logger->info('Registration message body prepared.', ['correlation_id' => $correlation_id]);
        $logger->debug('Registration message body content:', ['body' => $registration_data]); // Careful with password in prod logs

        $connection = null;
        $channel = null;
        $reply_queue_name = '';
        $response_from_listener = null;

        try {
            $logger->info('Attempting RabbitMQ connection for registration.', ['correlation_id' => $correlation_id]);
            $connection = new AMQPStreamConnection(
                $rabbitmq_host, $rabbitmq_port, $rabbitmq_user, $rabbitmq_pass, $rabbitmq_vhost
            );
            $channel = $connection->channel();
            $logger->info('RabbitMQ connection and channel opened for registration.', ['correlation_id' => $correlation_id]);

            list($reply_queue_name, ,) = $channel->queue_declare("", false, false, true, false);
            $logger->debug('Reply queue declared for registration.', ['queue_name' => $reply_queue_name, 'correlation_id' => $correlation_id]);

            // Optional: Declare exchange & queue
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
            $logger->info('Registration request message published.', ['correlation_id' => $correlation_id]);

            $logger->info('Waiting for registration response...', ['correlation_id' => $correlation_id]);
            $callback = function ($reply_msg) use (&$response_from_listener, $correlation_id, $channel, $logger) {
                $logger->debug('Reply received for registration.', ['correlation_id_received' => $reply_msg->get('correlation_id')]);
                if ($reply_msg->get('correlation_id') === $correlation_id) {
                    $response_from_listener = json_decode($reply_msg->body, true);
                    $logger->info('Registration response decoded.', ['response' => $response_from_listener, 'correlation_id' => $correlation_id]);
                    $channel->basic_ack($reply_msg->getDeliveryTag());
                } else {
                    $logger->warning('Registration reply with mismatched correlation ID.', ['expected' => $correlation_id, 'received' => $reply_msg->get('correlation_id')]);
                    $channel->basic_nack($reply_msg->getDeliveryTag(), false, false);
                }
            };

            $channel->basic_consume($reply_queue_name, '', false, false, true, false, $callback);

            try {
                $channel->wait(null, false, 10); // 10-second timeout
            } catch (AMQPTimeoutException $e) {
                $logger->error('Timeout waiting for registration response.', ['correlation_id' => $correlation_id, 'exception' => $e->getMessage()]);
                $response_from_listener = ['success' => false, 'message' => 'Registration request timed out.'];
            }
            $logger->debug('Exited wait loop for registration response.', ['correlation_id' => $correlation_id]);

            if ($response_from_listener && isset($response_from_listener['success'])) {
                if ($response_from_listener['success']) {
                    $success_message_display = $response_from_listener['message'] ?? "Registration successful! Please log in.";
                    $logger->info('Registration successful via listener.', ['message' => $success_message_display, 'correlation_id' => $correlation_id]);
                    // Optionally redirect to login page with a success message
                    // header("Location: login.php?status=registration_success");
                    // exit();
                } else {
                    $error_display_message = $response_from_listener['message'] ?? 'Registration failed.';
                    $logger->warning('Registration failed by listener.', ['reason' => $error_display_message, 'correlation_id' => $correlation_id]);
                }
            } else {
                 $error_display_message = $response_from_listener['message'] ?? 'Invalid response from registration service or request timed out.';
                 $logger->error('Invalid or missing response from listener after registration attempt.', ['response_received' => $response_from_listener, 'correlation_id' => $correlation_id]);
            }

        } catch (\Exception $e) {
            $error_display_message = "A technical issue occurred during registration.";
            $logger->critical('Exception during RabbitMQ operation or registration processing.', [
                'exception_type' => get_class($e),
                'message' => $e->getMessage(),
                'correlation_id' => $correlation_id ?? 'NOT_SET'
            ]);
        } finally {
            $logger->debug('Entering finally block for registration RabbitMQ cleanup.', ['correlation_id' => $correlation_id ?? 'NOT_SET']);
            if (isset($channel) && $channel->is_open()) {
                try { $channel->close(); } catch (\Exception $ce) { $logger->error('Error closing RabbitMQ channel (registration).', ['exception' => $ce->getMessage()]); }
            }
            if (isset($connection) && $connection->isConnected()) {
                try { $connection->close(); } catch (\Exception $ce) { $logger->error('Error closing RabbitMQ connection (registration).', ['exception' => $ce->getMessage()]); }
            }
            $logger->debug('Finally block finished for registration.');
        }
    } // End of validation success
} else {
    $logger->debug('Non-POST request received for registration page.');
}

$logger->info('Registration script finished.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <link rel="stylesheet" href="Frontend/CSS/styles.css"> <style>
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; text-align: center; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="login_form"> <form method="POST" action="register.php">
            <h2 class="login">Create Account</h2> <?php if (!empty($error_display_message)): ?>
                <p class="message error"><?php echo htmlspecialchars($error_display_message); ?></p>
            <?php endif; ?>
            <?php if (!empty($success_message_display)): ?>
                <p class="message success"><?php echo htmlspecialchars($success_message_display); ?> <a href="login.php">Login here</a>.</p>
            <?php endif; ?>

            <div>
                <div class="input_box">
                    <label for="reg_username">Username</label> <input type="text" id="reg_username" name="username" placeholder="Choose a username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                <div class="input_box">
                    <label for="reg_email">Email</label> <input type="email" id="reg_email" name="email" placeholder="Enter your email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <div class="input_box">
                    <label for="reg_password">Password</label> <input type="password" id="reg_password" name="password" placeholder="Create a password" required>
                </div>
                <button type="submit">Register</button>
                <p class="sign_up">Already have an account? <a href="login.php">Login here</a></p>
            </div>
        </form>
    </div>
</body>
</html>
