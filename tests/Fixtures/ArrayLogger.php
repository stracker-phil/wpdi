<?php

namespace WPDI\Tests\Fixtures;

/**
 * Concrete implementation of LoggerInterface
 */
class ArrayLogger implements LoggerInterface {
    private array $logs = array();

    public function log(string $message): void {
        $this->logs[] = $message;
    }

    public function get_logs(): array {
        return $this->logs;
    }
}
