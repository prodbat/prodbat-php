<?php

declare(strict_types=1);

namespace Prodbat\Tests;

use PHPUnit\Framework\TestCase;
use Prodbat\Prodbat;

final class ProdbatTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: ?array, headers: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        Prodbat::reset();
        Prodbat::init('pbs_test', 'https://pb.example/');
        $this->sent = [];
        Prodbat::$transport = function (string $method, string $url, ?string $body, array $headers): void {
            $this->sent[] = ['method' => $method, 'url' => $url, 'body' => $body === null ? null : json_decode($body, true),
                             'headers' => $headers];
        };
    }

    /** @return list<array> */
    private function sentTo(string $path): array
    {
        Prodbat::flush();
        return array_values(array_filter($this->sent, static fn ($r) => str_starts_with($r['url'], "https://pb.example{$path}")));
    }

    private function failInCheckout(): void
    {
        throw new \DomainException('price_id missing');
    }

    public function testAnExceptionIsReportedWithTheLineItCameFrom(): void
    {
        try {
            $this->failInCheckout();
        } catch (\DomainException $e) {
            Prodbat::captureException($e, 'POST /api/checkout/{id}');
        }
        $report = $this->sentTo('/api/errors')[0];
        $this->assertContains('Authorization: Bearer pbs_test', $report['headers']);
        $this->assertSame(['DomainException', 'price_id missing', 'POST /api/checkout/{id}', 500],
            [$report['body']['kind'], $report['body']['message'], $report['body']['route'], $report['body']['status']]);
        $this->assertMatchesRegularExpression('/tests\/ProdbatTest\.php:\d+ in failInCheckout/', $report['body']['where']);
        $this->assertStringContainsString('failInCheckout', $report['body']['trace']);
        $this->assertTrue(Prodbat::alreadyReported());
    }

    public function testLogLinesPingsStripeEventsAndQueuesAreSent(): void
    {
        Prodbat::log('refund issued', ['order' => 1042]);
        Prodbat::log('card declined', [], 'WARNING');
        Prodbat::ping('nightly email', '24h');
        Prodbat::stripeEvent(new class () {
            public function toArray(): array
            {
                return ['type' => 'invoice.paid', 'data' => ['object' => []]];
            }
        });
        Prodbat::reportQueues('laravel', [['name' => 'default', 'waiting' => 3, 'oldest_seconds' => 12, 'done' => 0, 'failed' => 0]]);
        Prodbat::flush();

        $logs = array_values(array_filter($this->sent, static fn ($r) => str_ends_with($r['url'], '/api/logs')))[0]['body']['lines'];
        $this->assertSame([['refund issued', 'info', ['order' => 1042]], ['card declined', 'warning', null]],
            array_map(static fn ($l) => [$l['message'], $l['level'], $l['fields']], $logs));
        $urls = array_column($this->sent, 'url');
        $this->assertContains('https://pb.example/api/ping/nightly%20email?every=24h', $urls);
        $stripe = array_values(array_filter($this->sent, static fn ($r) => str_ends_with($r['url'], '/api/stripe')))[0];
        $this->assertSame('invoice.paid', $stripe['body']['type']);
        $queues = array_values(array_filter($this->sent, static fn ($r) => str_ends_with($r['url'], '/api/queues')))[0];
        $this->assertSame('laravel', $queues['body']['system']);
    }

    public function testOnlyFiveHundredsAreReportedAsStatuses(): void
    {
        Prodbat::captureStatus(404, 'GET /missing');
        Prodbat::captureStatus(503, 'GET /status');
        $this->assertSame(['HTTP 503'], array_map(static fn ($r) => $r['body']['kind'], $this->sentTo('/api/errors')));
    }

    public function testNothingIsSentWithoutSettingsAndNothingThrows(): void
    {
        Prodbat::init(null, null);
        putenv('PRODBAT_KEY');
        Prodbat::captureException(new \RuntimeException('x'));
        Prodbat::flush();
        $this->assertSame([], $this->sent);
    }

    public function testTheRouteComesFromTheRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/pay?plan=pro';
        $this->assertSame('POST /api/pay', Prodbat::currentRoute());
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $this->assertSame('', Prodbat::currentRoute());
    }
}
