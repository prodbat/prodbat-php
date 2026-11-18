# prodbat/prodbat (PHP)

Monitoring for apps built with AI. This package sends a PHP app's server errors, log lines, scheduled-job check-ins, queue health and Stripe events to [Prodbat](https://github.com/prodbat/prodbat). It has no dependencies and never throws. Reports are collected during a request and sent after the response has gone (on PHP-FPM, after `fastcgi_finish_request()`), so visitors never wait for them; they are dropped if Prodbat can't be reached.

## Install

```sh
composer require prodbat/prodbat
```

Set `PRODBAT_KEY` and `PRODBAT_URL` in the environment (`npx prodbat init` writes them to `.env`).

- **Laravel**: nothing more; package discovery registers it. Exceptions are reported with their route (`POST /api/checkout/{id}`), along with other 5xx answers and failed queued jobs. With the scheduler running, the size of each queue and how long its oldest job has waited are reported every minute. Set `PRODBAT_QUEUES=default,emails` (or `redis:emails`) to choose the queues, or leave it empty to turn this off.
- **Laravel logs**: add a channel in `config/logging.php`, then add `prodbat` to your stack:
  ```php
  'prodbat' => ['driver' => 'monolog', 'handler' => \Prodbat\Monolog\ProdbatHandler::class, 'level' => 'info'],
  ```
- **Any other PHP app**: call `\Prodbat\Prodbat::register();` as early as possible, after the autoloader. It reports uncaught exceptions, fatal errors and 5xx answers, and keeps any exception handler you already had.

## Also

```php
use Prodbat\Prodbat;

Prodbat::log('refund issued', ['order' => 1042]);
Prodbat::ping('nightly-email', '24h');   // at the end of a scheduled job, after it succeeded
Prodbat::stripeEvent($event);            // in the Stripe webhook, after the signature check
Prodbat::captureException($e, 'POST /x'); // report one yourself
```

## Tests

```sh
composer install && composer test
```

MIT licence.
