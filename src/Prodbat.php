<?php

declare(strict_types=1);

namespace Prodbat;

use Throwable;

/**
 * Prodbat for PHP: server errors with their cause, log lines, scheduled-job
 * check-ins, queue health and Stripe events. No dependencies.
 *
 *   Laravel:      nothing more; the package registers itself.
 *   Other PHP:    \Prodbat\Prodbat::register();   as early as possible, after the autoloader
 *
 *   Prodbat::log('refund issued', ['order' => 1042]);
 *   Prodbat::ping('nightly-email', '24h');   // at the end of a scheduled job, after it succeeded
 *   Prodbat::stripeEvent($event);            // in the Stripe webhook, after the signature check
 *
 * Set PRODBAT_KEY and PRODBAT_URL in the environment. Nothing here ever throws or
 * slows a visitor down: reports are collected during the request and sent after
 * the response has gone (fastcgi_finish_request on PHP-FPM), and dropped if
 * Prodbat cannot be reached.
 */
final class Prodbat
{
    public const VERSION = '0.0.1';
    private const MAX_QUEUE = 200;
    private const MAX_LOGS = 2000;
    private const TIMEOUT_SECONDS = 3;

    private static ?string $key = null;
    private static ?string $url = null;
    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private static array $queue = [];
    /** @var list<array<string, mixed>> */
    private static array $logs = [];
    private static bool $shutdownRegistered = false;
    private static bool $handling = false;
    private static bool $reported = false;
    /** @var callable|null Replaces the network for tests: fn(string $method, string $url, ?string $body, array $headers). */
    public static $transport = null;

    /** Optional. Without it, PRODBAT_KEY and PRODBAT_URL are read from the environment. */
    public static function init(?string $key = null, ?string $url = null): void
    {
        self::$key = $key;
        self::$url = $url;
    }

    /**
     * Plain PHP: report uncaught exceptions, fatal errors and 5xx answers.
     * Keeps any exception handler that was already set.
     */
    public static function register(): void
    {
        $previous = set_exception_handler(null);
        set_exception_handler(static function (Throwable $e) use ($previous): void {
            self::captureException($e, self::currentRoute());
            if ($previous) {
                $previous($e);
            } else {
                http_response_code(500);
                error_log((string) $e);
            }
        });
        self::onShutdown();
    }

    // ---- errors -------------------------------------------------------------

    public static function captureException(Throwable $e, string $route = ''): void
    {
        try {
            self::$reported = true;
            $trace = get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
            self::enqueue('POST', '/api/errors', [
                'kind' => substr((new \ReflectionClass($e))->getShortName(), 0, 200),
                'message' => substr($e->getMessage(), 0, 1000),
                'route' => substr($route, 0, 300),
                'where' => substr(self::where($e), 0, 300),
                'trace' => strlen($trace) > 20000 ? substr($trace, -20000) : $trace,
                'status' => 500,
            ]);
        } catch (Throwable) {
            // reporting must never become the problem
        }
    }

    /** A 5xx answer that did not come from an exception. */
    public static function captureStatus(int $status, string $route = ''): void
    {
        if ($status >= 500) {
            self::enqueue('POST', '/api/errors', ['kind' => "HTTP {$status}", 'status' => $status, 'route' => substr($route, 0, 300)]);
        }
    }

    /** "app/Http/Controllers/CheckoutController.php:88 in store" */
    public static function where(Throwable $e): string
    {
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '']], $e->getTrace());
        foreach ($frames as $i => $frame) {
            $file = $frame['file'] ?? '';
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }
            // The function a line is in is named on the frame after it.
            $function = $frames[$i + 1]['function'] ?? '';
            $parts = explode('/', str_replace('\\', '/', $file));
            return implode('/', array_slice($parts, -2)) . ':' . ($frame['line'] ?? 0) . ($function ? " in {$function}" : '');
        }
        return '';
    }

    // ---- logs ---------------------------------------------------------------

    /** @param array<string, mixed> $fields */
    public static function log(string $message, array $fields = [], string $level = 'info'): void
    {
        if (count(self::$logs) < self::MAX_LOGS) {
            self::$logs[] = ['message' => substr($message, 0, 4000), 'level' => strtolower($level),
                             't' => microtime(true), 'fields' => $fields ?: null];
        }
        self::onShutdown();
    }

    // ---- jobs, queues and payments ------------------------------------------

    /** A scheduled job checks in, at the end, after the work has succeeded. $every: "15m", "24h", "7d". */
    public static function ping(string $name, ?string $every = null): void
    {
        self::enqueue('POST', '/api/ping/' . rawurlencode($name) . ($every ? '?every=' . rawurlencode($every) : ''));
    }

    /** Forward a Stripe webhook event (an array, a \Stripe\Event, or its JSON), after its signature has been checked. */
    public static function stripeEvent(mixed $event): void
    {
        try {
            if (is_object($event) && method_exists($event, 'toArray')) {
                $event = $event->toArray();
            } elseif (is_string($event)) {
                $event = json_decode($event, true, 512, JSON_THROW_ON_ERROR);
            }
            if (is_array($event)) {
                self::enqueue('POST', '/api/stripe', $event);
            }
        } catch (Throwable) {
        }
    }

    /** @param list<array<string, mixed>> $snapshots [['name' =>, 'waiting' =>, 'oldest_seconds' =>, 'done' =>, 'failed' =>]] */
    public static function reportQueues(string $system, array $snapshots): void
    {
        self::enqueue('POST', '/api/queues', ['system' => $system, 'queues' => $snapshots]);
    }

    // ---- sending ------------------------------------------------------------

    /** Send everything collected so far. Runs by itself at the end of the request. */
    public static function flush(): void
    {
        if ($logs = self::$logs) {
            self::$logs = [];
            foreach (array_chunk($logs, 500) as $batch) {
                self::$queue[] = ['POST', '/api/logs', ['lines' => $batch]];
            }
        }
        $queue = self::$queue;
        self::$queue = [];
        if (!$queue) {
            return;
        }
        [$key, $url] = self::settings();
        if (!$key || !$url) {
            return;
        }
        if (self::runtimeDue()) {
            array_unshift($queue, ['PUT', '/api/runtime', ['language' => 'php', 'version' => PHP_VERSION]]);
        }
        foreach ($queue as [$method, $path, $payload]) {
            self::deliver($method, $url . $path, $payload === null ? null : json_encode($payload), $key);
        }
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$logs = [];
        self::$reported = false;
    }

    public static function alreadyReported(): bool
    {
        return self::$reported;
    }

    /** "POST /api/checkout" from the current request, or "" outside one. */
    public static function currentRoute(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
        return $method && $path ? "{$method} {$path}" : '';
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function settings(): array
    {
        $env = static fn (string $name): ?string => ($v = getenv($name)) !== false && $v !== '' ? $v : ($_ENV[$name] ?? $_SERVER[$name] ?? null);
        $url = rtrim((string) (self::$url ?? $env('PRODBAT_URL') ?? ''), '/');
        return [self::$key ?? $env('PRODBAT_KEY'), $url !== '' ? $url : null];
    }

    private static function enqueue(string $method, string $path, mixed $payload = null): void
    {
        if (count(self::$queue) < self::MAX_QUEUE) {
            self::$queue[] = [$method, $path, $payload];
        }
        self::onShutdown();
    }

    private static function onShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            try {
                $error = error_get_last();
                $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
                if ($error && ($error['type'] & $fatal) && !self::$reported) {
                    self::enqueue('POST', '/api/errors', [
                        'kind' => 'FatalError', 'message' => substr($error['message'], 0, 1000), 'status' => 500,
                        'route' => self::currentRoute(),
                        'where' => basename(dirname($error['file'])) . '/' . basename($error['file']) . ':' . $error['line'],
                    ]);
                } elseif (PHP_SAPI !== 'cli' && http_response_code() >= 500 && !self::$reported) {
                    self::captureStatus((int) http_response_code(), self::currentRoute());
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // the visitor has their answer; now send
                }
                self::flush();
            } catch (Throwable) {
            }
        });
    }

    private static function deliver(string $method, string $url, ?string $body, string $key): void
    {
        $headers = ["Authorization: Bearer {$key}", 'Content-Type: application/json', 'User-Agent: prodbat-php/' . self::VERSION];
        try {
            if (self::$transport) {
                (self::$transport)($method, $url, $body, $headers);
                return;
            }
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                    CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]));
                curl_exec($ch);
                curl_close($ch);
                return;
            }
            @file_get_contents($url, false, stream_context_create(['http' => [
                'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
                'timeout' => self::TIMEOUT_SECONDS, 'ignore_errors' => true]]));
        } catch (Throwable) {
        }
    }

    /** PHP starts a new process for every request, so the version is sent at most once a day per server. */
    private static function runtimeDue(): bool
    {
        $marker = sys_get_temp_dir() . '/prodbat-runtime-' . PHP_VERSION . '-' . date('Y-m-d');
        if (@file_exists($marker)) {
            return false;
        }
        @touch($marker);
        return true;
    }
}
