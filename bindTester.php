<?php

// Enable error reporting and display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting RabbitMQ queue bind test...<br>"; // Debug point 1

// Load the RabbitMQ client library
require_once __DIR__ . '/vendor/autoload.php';

echo "Autoloader included.<br>"; // Debug point 2

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

// --- RabbitMQ Connection Settings ---
// IMPORTANT: Use the same credentials as your login.php
$rabbitmq_host = '192.168.191.13';
$rabbitmq_port = 5672;
$rabbitmq_user = 'guest'; // Using guest as per your login.php
$rabbitmq_pass = 'guest'; // Using guest password as per your login.php
$rabbitmq_vhost = '/';        // Using the default virtual host

// --- Exchange, Queue, and Routing Key ---
$exchange_name = 'user_exchange';
$queue_name = 'login'; // The queue you are trying to bind
$routing_key = 'login'; // The routing key for the binding

echo "RabbitMQ config and details defined.<br>"; // Debug point 3

$connection = null;
$channel = null;

try {
    echo "Attempting to connect to RabbitMQ...<br>"; // Debug point 4
    // Attempt to establish the connection
    $connection = new AMQPStreamConnection(
        $rabbitmq_host,
        $rabbitmq_port,
        $rabbitmq_user,
        $rabbitmq_pass,
        $rabbitmq_vhost
    );
    echo "RabbitMQ connection established.<br>"; // Debug point 5

    echo "Before opening channel.<br>"; // Debug point 6
    // Open a channel
    $channel = $connection->channel();
    echo "RabbitMQ channel opened.<br>"; // Debug point 7

    // It's good practice to ensure the exchange and queue exist before binding
    echo "Before declaring exchange '{$exchange_name}'.<br>"; // Debug point 8
    $channel->exchange_declare($exchange_name, 'direct', false, true, false);
    echo "Exchange declared.<br>"; // Debug point 9

    echo "Before declaring queue '{$queue_name}'.<br>"; // Debug point 10
    $channel->queue_declare($queue_name, false, true, false, false);
    echo "Queue declared.<br>"; // Debug point 11

    echo "Before binding queue '{$queue_name}' to exchange '{$exchange_name}' with routing key '{$routing_key}'.<br>"; // Debug point 12
    // Attempt to bind the queue
    $channel->queue_bind($queue_name, $exchange_name, $routing_key);
    echo "Queue bound successfully!<br>"; // Debug point 13


} catch (\Exception $e) {
    // If an exception occurs
    echo "Caught an exception during bind attempt:<br>"; // Debug point E1
    echo "Error Type: " . get_class($e) . "<br>"; // Debug point E2
    echo "Error Message: " . $e->getMessage() . "<br>"; // Debug point E3
    echo "Error Code: " . $e->getCode() . "<br>"; // Debug point E4
    // You might also want to print the stack trace for more details
    // echo "Stack Trace:<pre>" . $e->getTraceAsString() . "</pre>"; // Debug point E5

} finally {
    // Always try to close the channel and connection
    echo "Entering finally block.<br>"; // Debug point F1
    if (isset($channel) && $channel) {
        echo "Closing channel.<br>"; // Debug point F2
        try {
            $channel->close();
        } catch (\Exception $e) {
            error_log("Error closing RabbitMQ channel: " . $e->getMessage());
        }
    }
    if (isset($connection) && $connection) {
         echo "Closing connection.<br>"; // Debug point F3
        try {
            $connection->close();
        } catch (\Exception $e) {
            error_log("Error closing RabbitMQ connection: " . $e->getMessage());
        }
    }
     echo "Finally block finished.<br>"; // Debug point F4
}

echo "RabbitMQ queue bind test finished.<br>"; // Debug point 14

?>
