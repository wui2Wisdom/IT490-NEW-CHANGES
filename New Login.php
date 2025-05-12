<?php

session_start();


error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Script started.<br>"; // Debug point 1


require_once __DIR__ . '/vendor/autoload.php';

echo "Autoloader included.<br>"; // Debug point 2


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable; 
use PhpAmqpLib\Exception\AMQPTimeoutException; 

// --- RabbitMQ Connection Settings ---

$rabbitmq_host = '192.168.191.13';
// This is the standard port for RabbitMQ's AMQP protocol.
$rabbitmq_port = 5672;
// The username your PHP script will use to connect to RabbitMQ.
$rabbitmq_user = 'guest'; // Using guest as our working bind test
// The password for the RabbitMQ user.
$rabbitmq_pass = 'guest'; // Using guest password as per your working bind test
// The virtual host within RabbitMQ you are connecting to (usually '/').
$rabbitmq_vhost = '/';

// Define exchange, queue, and routing key variables before the try block
$exchange_name = 'user_exchange';
$listener_queue_name = 'login'; // Specific queue for login messages
$routing_key = 'login';         // Routing key for login messages


echo "RabbitMQ config defined.<br>"; // Debug point 3

// Check if the form data was sent using the POST method.
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    echo "POST request detected.<br>"; // Debug point 4

    // --- Get and Clean User Input ---
    // Grab the data sent from the HTML form's 'username' field (which holds username OR email)
    $identifier_raw = $_POST['username'] ?? '';
    $password_raw = $_POST['password'] ?? '';

    // --- Replace deprecated FILTER_SANITIZE_STRING ---
    // Use htmlspecialchars for basic XSS prevention on the identifier
    $identifier = htmlspecialchars($identifier_raw, ENT_QUOTES, 'UTF-8');
    // Password should be sent raw for verification by the listener
    $password = $password_raw;

    echo "Input sanitized (using htmlspecialchars).<br>"; // Debug point 5

    // --- Basic Input Validation ---
    if (empty($identifier) || empty($password)) {
        // If something's missing, tell the user and stop.
        // In a real app, redirect back with an error message.
        echo "Error: Both username/email and password are required for login.";
        // header("Location: auth.html?error=missing_fields&form=login");
        exit(); // Stop the script here.
    }

    echo "Input validated.<br>"; // Debug point 6

    // --- Prepare Data for RabbitMQ ---
    
    $login_data = [
        // 'action' => 'login', // Listener doesn't strictly need this if using routing_key, but can keep if desired
        'identifier' => $identifier, // Send the username OR email under this key
        'password' => $password      // Send the plain password for listener to verify
    ];

    // Convert the PHP array into a JSON string.
    $message_body = json_encode($login_data);

    echo "Message body prepared: " . htmlspecialchars($message_body) . "<br>"; // Debug point 7


    // --- Implement Request/Reply Pattern ---
    $connection = null;
    $channel = null;
    $reply_queue_name = '';
    $correlation_id = uniqid(); // Unique ID for this request

    echo "Before try block for RabbitMQ connection.<br>"; // Debug point 8

    try {
        echo "Inside try block, before establishing connection.<br>"; // Debug point 9
        $connection = new AMQPStreamConnection(
            $rabbitmq_host,
            $rabbitmq_port,
            $rabbitmq_user,
            $rabbitmq_pass,
            $rabbitmq_vhost
        );
        echo "RabbitMQ connection established.<br>"; // Debug point 10

        echo "Before opening channel.<br>"; // Debug point 10.1
        $channel = $connection->channel();
        echo "RabbitMQ channel opened.<br>"; // Debug point 11

        echo "Before declaring reply queue.<br>"; // Debug point 11.1
        // Declare a temporary, exclusive queue for the reply. Auto-delete is often implied by exclusive.
        list($reply_queue_name, ,) = $channel->queue_declare(
            "",    // Queue name: empty string for a generated name
            false, // Passive: false
            false, // Durable: false
            true,  // Exclusive: true
            false  // Auto-delete: false (can be true if preferred, exclusive usually handles cleanup)
        );
        echo "Reply queue declared: " . $reply_queue_name . "<br>"; // Debug point 12

        // --- Exchange/Queue/Binding declarations (Optional but good practice) ---
        
        echo "Before declaring exchange: {$exchange_name}.<br>"; // Debug point 12.1
        $channel->exchange_declare($exchange_name, 'direct', false, true, false); // Assuming durable exchange
        echo "Exchange declared: {$exchange_name}.<br>"; // Debug point 12.2

        echo "Before declaring listener queue: {$listener_queue_name}.<br>"; // Debug point 12.3
        $channel->queue_declare($listener_queue_name, false, true, false, false); // Assuming durable queue
        echo "Listener queue declared: {$listener_queue_name}.<br>"; // Debug point 12.4

        echo "Before binding listener queue: {$listener_queue_name} to exchange {$exchange_name} with routing key {$routing_key}.<br>"; // Debug point 12.5
        $channel->queue_bind($listener_queue_name, $exchange_name, $routing_key);
        echo "Listener queue bound.<br>"; // Debug point 13
        // --- End Optional Declarations ---

        echo "Before creating message object.<br>"; // Debug point 13.1
        $msg = new AMQPMessage(
            $message_body,
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'reply_to' => $reply_queue_name, // Tell listener where to reply
                'correlation_id' => $correlation_id // Match request to reply
            ]
        );
        echo "Message object created.<br>"; // Debug point 14

        echo "Before publishing message.<br>"; // Debug point 14.1
        // Publish to the exchange with the login routing key
        $channel->basic_publish($msg, $exchange_name, $routing_key);
        echo "Message published. Correlation ID: " . $correlation_id . "<br>"; // Debug point 15

        echo "Login request sent. Waiting for response..."; // Debug point 16

        // --- Wait for the Reply ---
        $response = null;
        $callback = function ($reply_msg) use (&$response, $correlation_id, $channel) {
            echo "Reply received in callback.<br>"; // Debug point C1
            if ($reply_msg->get('correlation_id') === $correlation_id) {
                echo "Correlation ID matches.<br>"; // Debug point C2
                $response = json_decode($reply_msg->body, true);
                echo "Response decoded: " . print_r($response, true) . "<br>"; // Debug point C3
                // Acknowledge the reply message *manually*
                $channel->basic_ack($reply_msg->getDeliveryTag());
                echo "Reply message acknowledged.<br>"; // Debug point C4
            } else {
                echo "Correlation ID mismatch. Expected: " . $correlation_id . ", Received: " . $reply_msg->get('correlation_id') . "<br>"; // Debug point C5
                // Optionally reject the mismatched message
                $channel->basic_reject($reply_msg->getDeliveryTag(), false); // Reject, don't requeue
            }
        };

        echo "Before starting consumer on reply queue.<br>"; // Debug point 16.1
        // Start consuming from the reply queue, *requiring manual ACK*
        $channel->basic_consume(
            $reply_queue_name,
            '',             // Consumer tag
            false,          // No-local
            false,          // No-ack (FALSE - manual ack required)
            true,           // Exclusive
            false,          // No-wait
            $callback
        );
        echo "Started consuming from reply queue.<br>"; // Debug point 17

        // Wait for a reply with a timeout (e.g., 10 seconds)
        echo "Entering wait loop.<br>"; // Debug point 18
        try {
            // Wait for max 10 seconds. Adjust timeout as needed.
            $channel->wait(null, false, 10);
        } catch (AMQPTimeoutException $e) {
            echo "Timeout waiting for reply.<br>"; // Debug point T1
            $response = ['success' => false, 'message' => 'Login request timed out. The authentication service might be busy or unavailable.'];
        }
        echo "Exited wait loop.<br>"; // Debug point 19

        // --- Process the Response ---
        echo "Processing response.<br>"; // Debug point 20
        if ($response && isset($response['success'])) {
            if ($response['success']) {
                echo "Login successful!<br>"; // Debug point 21
                // --- Store Session Data ---
                // Store the username and session key provided by the listener
                $_SESSION['username'] = $response['username'];
                $_SESSION['session_key'] = $response['session_key'];
                echo "Session data stored: Username=" . $_SESSION['username'] . ", Key=" . $_SESSION['session_key'] . "<br>"; // Debug point S1

                // Redirect to the main website index.html (or dashboard.php if preferred)
                header("Location: index.html?status=login_success");
                exit();
            } else {
                // Login failed according to the listener
                $error_message = isset($response['message']) ? $response['message'] : 'Login failed.';
                echo "Login failed: " . htmlspecialchars($error_message) . "<br>"; // Debug point 22
                // Set error message to display on the form
                $error = htmlspecialchars($error_message);
            }
        } else {
            // If the response format is unexpected or timed out without a proper message
            $error_message = $response['message'] ?? 'Received invalid response from the server or request timed out.';
            echo "Error: " . htmlspecialchars($error_message) . "<br>"; // Debug point 23
            error_log("Invalid response or timeout for login: " . json_encode($response));
             // Set error message to display on the form
            $error = htmlspecialchars($error_message);
        }

    } catch (\Exception $e) {
        // --- Handle Connection or Communication Errors ---
        echo "Caught an exception: " . get_class($e) . " - " . $e->getMessage() . "<br>"; // Debug point E1
        $error = "Sorry, there was a technical issue during login. Please try again later.";
        echo $error . "<br>"; // Debug point E2
        error_log("RabbitMQ/PHP Error (login.php): " . $e->getMessage() . "\n" . $e->getTraceAsString());
    } finally {
        // --- Clean Up Connections ---
        echo "Entering finally block.<br>"; // Debug point F1
        if (isset($channel) && $channel->is_open()) { // Check if channel is open before closing
            echo "Closing channel.<br>"; // Debug point F2
            try { $channel->close(); } catch (\Exception $ce) { error_log("Error closing RabbitMQ channel: " . $ce->getMessage()); }
        }
        if (isset($connection) && $connection->isConnected()) { // Check if connection is open before closing
             echo "Closing connection.<br>"; // Debug point F3
            try { $connection->close(); } catch (\Exception $ce) { error_log("Error closing RabbitMQ connection: " . $ce->getMessage()); }
        }
        echo "Finally block finished.<br>"; // Debug point F4
    }

} else {
    // --- Handle Non-POST Requests ---
   
}

// --- HTML Form ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login</title>
    <link rel="stylesheet" href="Frontend/CSS/styles.css"> <style>
        /* Basic styles for error messages */
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="login_form"> <form method="post" action="login.php"> <h2 class="login">Login</h2>

            <?php if (!empty($error)): ?>
                <p class="message error"><?php echo $error; // Already htmlspecialchar'd above ?></p>
            <?php endif; ?>

            <div>
                <div class="input_box">
                    <label for="username">Username or Email</label>
                    <input type="text" id="username" name="username" placeholder="Enter username or email" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" />
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

     <?php echo "Script finished.<br>"; // Debug point 25 ?>
</body>
</html>
