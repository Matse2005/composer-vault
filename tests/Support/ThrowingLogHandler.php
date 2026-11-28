<?php

namespace Tests\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use RuntimeException;

/**
 * A log handler whose destination is down.
 */
class ThrowingLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('The destination is not reachable.');
    }
}
