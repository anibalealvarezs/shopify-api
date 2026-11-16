<?php
namespace Tests\Unit;

use Anibalealvarezs\ShopifyApi\Services\BulkOperationsService;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class BulkOperationsServiceTest extends TestCase
{
    public function testStartStatusAndStream(): void
    {
        $responses = [
            new Response(200, [], json_encode(['data' => ['bulkOperationRunQuery' => ['bulkOperation' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'CREATED'], 'userErrors' => []]]])),
            new Response(200, [], json_encode(['data' => ['node' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'COMPLETED', 'url' => 'https://example.test/result']]])),
        ];
        $client = new ShopifyApi('token', 'shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $service = new BulkOperationsService($client);
        $operation = $service->start('{ products { edges { node { id } } } }');
        self::assertSame('COMPLETED', $service->status($operation['id'])['status']);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "{\"id\":1}\n{\"id\":2}\n");
        rewind($stream);
        $records = [];
        $service->processStream($stream, static function ($row) use (&$records) { $records[] = $row; });
        fclose($stream);
        self::assertSame([['id' => 1], ['id' => 2]], $records);
    }

    public function testRejectsMalformedJsonl(): void
    {
        $client = new ShopifyApi('token', 'shop');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "invalid\n");
        rewind($stream);
        try {
            $this->expectException(\JsonException::class);
            (new BulkOperationsService($client))->processStream($stream, static function () {});
        } finally {
            fclose($stream);
        }
    }
    public function testPollsUntilCompleted(): void
    {
        $responses = [
            new Response(200, [], json_encode(['data' => ['node' => ['status' => 'RUNNING']]])),
            new Response(200, [], json_encode(['data' => ['node' => ['status' => 'COMPLETED']]])),
        ];
        $client = new ShopifyApi('token', 'shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        self::assertSame('COMPLETED', (new BulkOperationsService($client))->wait('id', 2, 0)['status']);
    }

    public function testPollingRejectsFailedOperation(): void
    {
        $response = new Response(200, [], json_encode(['data' => ['node' => ['status' => 'FAILED', 'errorCode' => 'INTERNAL_SERVER_ERROR']]]));
        $client = new ShopifyApi('token', 'shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FAILED');
        (new BulkOperationsService($client))->wait('id', 1, 0);
    }

    public function testPollingCanBeCancelledBeforeRequest(): void
    {
        $client = new ShopifyApi('token', 'shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancelled');
        (new BulkOperationsService($client))->wait('id', 1, 0, static fn () => false);
    }
    public function testDownloadStreamsWithoutAdminCredentials(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], "{\"id\":1}\n{\"id\":2}\n")]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $service = new BulkOperationsService(new ShopifyApi('secret-token', 'shop'), new Client(['handler' => $stack]));
        $rows = [];
        $service->download(['status' => 'COMPLETED', 'url' => 'https://example.test/export'], static function ($row) use (&$rows) { $rows[] = $row; });
        self::assertCount(2, $rows);
        self::assertFalse($history[0]['request']->hasHeader('X-Shopify-Access-Token'));
    }

    public function testEmptyBulkOperationNeedsNoDownload(): void
    {
        $service = new BulkOperationsService(new ShopifyApi('token', 'shop'));
        $service->download(['status' => 'COMPLETED', 'objectCount' => '0', 'url' => null], static function () { self::fail('Unexpected record'); });
        self::assertTrue(true);
    }
    public function testStartRejectsUserErrors(): void
    {
        $response = new Response(200, [], json_encode(['data' => ['bulkOperationRunQuery' => ['bulkOperation' => null, 'userErrors' => [['message' => 'Invalid query']]]]]));
        $client = new ShopifyApi('token', 'shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid query');
        (new BulkOperationsService($client))->start('invalid');
    }

    public function testDownloadRejectsHttpErrorsWithoutExposingSignedUrl(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(403)]));
        $service = new BulkOperationsService(new ShopifyApi('token', 'shop'), new Client(['handler' => $stack]));
        try {
            $service->download(['status' => 'COMPLETED', 'url' => 'https://example.test/?signature=secret'], static function () {});
            self::fail('Expected download error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('403', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }
    public function testStreamCancellationIsNotReportedAsSuccess(): void
    {
        $service = new BulkOperationsService(new ShopifyApi('token', 'shop'));
        $stream = fopen('php://temp', 'r+');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cancelled');
            $service->processStream($stream, static function () {}, static fn () => false);
        } finally {
            fclose($stream);
        }
    }
}
