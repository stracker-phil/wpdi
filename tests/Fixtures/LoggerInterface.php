<?php

namespace WPDI\Tests\Fixtures;

/**
 * Sample interface for testing interface binding
 */
interface LoggerInterface {
    public function log(string $message): void;
    public function get_logs(): array;
}
