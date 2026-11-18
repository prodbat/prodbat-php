<?php

declare(strict_types=1);

namespace Prodbat\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Prodbat\Prodbat;
use Throwable;

/**
 * Laravel: found by package discovery, so `composer require prodbat/prodbat` is
 * all it takes. Reports exceptions with the route they happened on, other 5xx
 * answers and failed queued jobs; reports queue health every minute through the
 * scheduler; sends after each request and each job, so long-running workers
 * (queue:work, Octane) don't hold reports until they exit.
 */
final class ProdbatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/prodbat.php', 'prodbat');
    }

    public function boot(): void
    {
        Prodbat::init(config('prodbat.key'), config('prodbat.url'));
        $this->publishes([__DIR__ . '/../../config/prodbat.php' => config_path('prodbat.php')], 'prodbat-config');

        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e): void {
                    Prodbat::captureException($e, self::route());
                });
            }
        });

        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            $status = $event->response->getStatusCode();
            if ($status >= 500 && !Prodbat::alreadyReported()) {
                Prodbat::captureStatus($status, self::route());
            }
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Prodbat::log('Queued job ' . $event->job->resolveName() . ' failed: ' . get_class($event->exception),
                ['queue' => $event->job->getQueue()], 'error');
            Prodbat::captureException($event->exception);
            Prodbat::flush();
        });
        Event::listen(JobProcessed::class, static fn () => Prodbat::flush());

        $this->app->terminating(static function (): void {
            Prodbat::flush();
            Prodbat::reset();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([QueueReportCommand::class]);
        }
        if (config('prodbat.queues')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('prodbat:queues')->everyMinute()->runInBackground();
            });
        }
    }

    /** "POST /api/checkout/{id}", the route as declared, or the path outside a route. */
    public static function route(): string
    {
        try {
            $request = app('request');
            $route = $request->route();
            $path = $route ? '/' . ltrim($route->uri(), '/') : '/' . ltrim($request->path(), '/');
            return $request->method() . ' ' . $path;
        } catch (Throwable) {
            return Prodbat::currentRoute();
        }
    }
}
