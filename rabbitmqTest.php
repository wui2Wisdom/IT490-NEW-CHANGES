<?php

// Enable error reporting and display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting RabbitMQ connection test...<br>";

// Load the RabbitMQ client library
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

// --- RabbitMQ Connection Settings ---
// IMPORTANT: The exact credentials and host/port
// that your login.php and register.php scripts are using.
$rabbitmq_host = '192.168.191.13';
$rabbitmq_port = 5672;
$rabbitmq_user = 'wisdom'; // <-- Your RabbitMQ username
$rabbitmq_pass = 'wisdom'; // <-- Your RabbitMQ password
$rabbitmq_vhost = '/';        // <-- Your RabbitMQ virtual host

echo "RabbitMQ config: Host={$rabbitmq_host}, Port={$rabbitmq_port}, User={$rabbitmq_user}, VHost={$rabbitmq_vhost}<br>";

$connection = null;

try {
    echo "Attempting to connect to RabbitMQ...<br>";
    // Attempt to establish the connection
    $connection = new AMQPStreamConnection(
        $rabbitmq_host,
        $rabbitmq_port,
        $rabbitmq_user,
        $rabbitmq_pass,
        $rabbitmq_vhost
    );

    echo "RabbitMQ connection established successfully!<br>";

    // If the connection is successful, we can try opening a channel as well
    $channel = $connection->channel();
    echo "RabbitMQ channel opened successfully!<br>";
    $channel->close();
    echo "RabbitMQ channel closed.<br>";


} catch (\Exception $e) {
    // If an exception occurs during connection
    echo "Caught an exception during connection attempt:<br>";
    echo "Error Type: " . get_class($e) . "<br>";
    echo "Error Message: " . $e->getMessage() . "<br>";
    echo "Error Code: " . $e->getCode() . "<br>";
    // You might also want to print the stack trace for more details
    // echo "Stack Trace:<pre>" . $e->getTraceAsString() . "</pre>";

} finally {
    // Always try to close the connection
    if ($connection) {
        try {
            $connection->close();
            echo "RabbitMQ connection closed.<br>";
        } catch (\Exception $e) {
            echo "Error closing RabbitMQ connection: " . $e->getMessage() . "<br>";
        }
    }
}

echo "RabbitMQ connection test finished.<br>";

?>
