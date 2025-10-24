<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class that depends on an interface
 */
class ClassWithInterface {
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function get_logger(): LoggerInterface {
        return $this->logger;
    }

    public function do_something(): void {
        $this->logger->log('Something was done');
    }
}
