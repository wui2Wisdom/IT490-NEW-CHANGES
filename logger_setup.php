<?php
// logger_setup.php

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\IntrospectionProcessor;

/**
 * Creates and returns a configured Monolog logger instance.
 *
 * @param string $channelName The name of the logging channel (e.g., 'auth', 'registration').
 * @param string $logFilePath The absolute path to the log file.
 * @return Logger
 */
function getLogger(string $channelName, string $logFilePath): Logger {
    // Create a logger instance
    $logger = new Logger($channelName);

    // Create a JsonFormatter - logs will be in JSON format
    $formatter = new JsonFormatter();

    // Create a stream handler to write to a file
    // Log messages of DEBUG level and above will be recorded
    $streamHandler = new StreamHandler($logFilePath, Logger::DEBUG);
    $streamHandler->setFormatter($formatter);

    // Push the handler to the logger
    $logger->pushHandler($streamHandler);

    // Add some useful processors
    $logger->pushProcessor(new ProcessIdProcessor()); // Adds the process ID
    $logger->pushProcessor(new UidProcessor());       // Adds a unique ID to each log record
    $logger->pushProcessor(new WebProcessor($_SERVER)); // Adds web request data (URL, IP, HTTP method)
    
    // IntrospectionProcessor adds file, line, class, method from where logger was called
    // Note: This can have a slight performance impact, use Logger::INFO for level if too verbose
    $logger->pushProcessor(new IntrospectionProcessor(Logger::DEBUG, ['Monolog\\']));


    // Add a custom processor to include correlation_id if available
    // This assumes you might set a global or pass around a correlation_id
    $logger->pushProcessor(function ($record) {
        if (isset($GLOBALS['correlation_id'])) {
            $record['extra']['correlation_id'] = $GLOBALS['correlation_id'];
        } elseif (defined('CORRELATION_ID')) {
            $record['extra']['correlation_id'] = CORRELATION_ID;
        }
        return $record;
    });


    return $logger;
}

?>
