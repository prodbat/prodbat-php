<?php

declare(strict_types=1);

namespace Prodbat\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Prodbat\Prodbat;

/**
 * Send what the app already logs. Laravel, in config/logging.php:
 *   'prodbat' => ['driver' => 'monolog', 'handler' => \Prodbat\Monolog\ProdbatHandler::class, 'level' => 'info'],
 * then add 'prodbat' to the stack channel. Monolog 3 (Laravel 10+).
 */
final class ProdbatHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Info, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(\Monolog\LogRecord $record): void
    {
        $fields = array_filter(['channel' => $record->channel] + $record->context, static fn ($v) => is_scalar($v) || $v === null);
        Prodbat::log($record->message, $fields, match (true) {
            $record->level->value >= Level::Error->value => 'error',
            $record->level->value >= Level::Warning->value => 'warning',
            $record->level->value >= Level::Info->value => 'info',
            default => 'debug',
        });
    }
}
