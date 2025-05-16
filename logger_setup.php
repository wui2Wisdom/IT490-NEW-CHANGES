<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\IntrospectionProcessor;

function getLogger(string $channelName, string $logFilePath): Logger {
    $logger = new Logger($channelName);

    $formatter = new JsonFormatter();
    $streamHandler = new StreamHandler($logFilePath, Logger::DEBUG);
    $streamHandler->setFormatter($formatter);
    $logger->pushHandler($streamHandler);

    $logger->pushProcessor(new ProcessIdProcessor());
    $logger->pushProcessor(new UidProcessor());
    $logger->pushProcessor(new WebProcessor($_SERVER));
    $logger->pushProcessor(new IntrospectionProcessor(Logger::DEBUG, ['Monolog\\']));

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
