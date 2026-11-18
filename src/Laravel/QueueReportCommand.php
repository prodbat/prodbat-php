<?php

declare(strict_types=1);

namespace Prodbat\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Prodbat\Prodbat;
use Throwable;

/** Run every minute by the scheduler: each configured queue's size and its oldest job's wait. */
final class QueueReportCommand extends Command
{
    protected $signature = 'prodbat:queues';
    protected $description = 'Report queue sizes and waits to Prodbat';

    public function handle(): int
    {
        $snapshots = [];
        foreach ((array) config('prodbat.queues') as $entry) {
            [$connection, $queue] = str_contains((string) $entry, ':') ? explode(':', (string) $entry, 2) : [null, (string) $entry];
            try {
                $driver = Queue::connection($connection);
                $oldest = null;
                if (method_exists($driver, 'creationTimeOfOldestPendingJob')) {
                    $created = $driver->creationTimeOfOldestPendingJob($queue);
                    $oldest = $created ? max(0, time() - (int) $created) : 0;
                }
                $snapshots[] = ['name' => $queue, 'waiting' => $driver->size($queue), 'oldest_seconds' => $oldest,
                                'done' => 0, 'failed' => 0];
            } catch (Throwable) {
                // an unreachable queue is reported next minute
            }
        }
        if ($snapshots) {
            Prodbat::reportQueues('laravel', $snapshots);
            Prodbat::flush();
        }
        return self::SUCCESS;
    }
}
