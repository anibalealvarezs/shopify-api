<?php

namespace Tests\Unit;

use Anibalealvarezs\ShopifyApi\ShopifyApi;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShopifyGraphqlTest extends TestCase
{
    public function testShopifyqlSendsVariablesAndReturnsTable(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
            'data' => ['shopifyqlQuery' => ['tableData' => ['columns' => [], 'rows' => [['orders' => 2]]], 'parseErrors' => []]],
        ]))]));
        $stack->push(Middleware::history($history));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        $table = $client->shopifyqlQuery('FROM sales SHOW orders');
        self::assertSame(2, $table['rows'][0]['orders']);
        $request = $history[0]['request'];
        self::assertStringEndsWith('/admin/api/2025-10/graphql.json', (string) $request->getUri());
        self::assertSame('FROM sales SHOW orders', json_decode((string) $request->getBody(), true)['variables']['query']);
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testShopifyqlRejectsParseErrors(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
            'data' => ['shopifyqlQuery' => ['tableData' => null, 'parseErrors' => ['Invalid query']]],
        ]))]));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ShopifyQL parse errors');
        $client->shopifyqlQuery('invalid');
    }
    public function testPaginationRejectsRepeatedCursor(): void
    {
        $page = ['data' => ['products' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'same']]]];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode($page)), new Response(200, [], json_encode($page))]));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('repeated');
        $client->graphqlPaginate('query($cursor: String) { products(first: 1, after: $cursor) { pageInfo { hasNextPage endCursor } } }', 'products', static function () {});
    }

    public function testPaginationHonoursCancellation(): void
    {
        $stack = HandlerStack::create(new MockHandler([]));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        $client->graphqlPaginate('query { shop { name } }', 'products', static function () { self::fail('Unexpected page'); }, [], static fn () => false);
        self::assertTrue(true);
    }
    public function testThrottledGraphqlRetriesOriginalRequest(): void
    {
        $history = [];
        $responses = [
            new Response(200, [], json_encode(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])),
            new Response(200, [], json_encode(['data' => ['shop' => ['name' => 'test']]])),
        ];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        $result = $client->graphql('query { shop { name } }');
        self::assertSame('test', $result['data']['shop']['name']);
        self::assertCount(2, $history);
        self::assertSame((string) $history[0]['request']->getBody(), (string) $history[1]['request']->getBody());
        self::assertSame('application/json', $history[1]['request']->getHeaderLine('Content-Type'));
    }
    public function testGraphqlAccessDeniedRestoresErrorDetector(): void
    {
        $responses = [new Response(200, [], json_encode(['data' => null, 'errors' => [['message' => 'Access denied', 'extensions' => ['code' => 'ACCESS_DENIED']]]]))];
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        try {
            $client->graphql('query { shop { name } }');
            self::fail('Expected access error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Access denied', $e->getMessage());
            self::assertSame('errors', $client->getResponseErrorDetector());
        }
    }

    public function testPaginationRejectsMissingCursor(): void
    {
        $response = new Response(200, [], json_encode(['data' => ['products' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]]));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));
        $this->expectException(\UnexpectedValueException::class);
        $client->graphqlPaginate('query { products { pageInfo { hasNextPage endCursor } } }', 'products', static function () {});
    }
    public function testHttpRateLimitRetriesGraphqlBody(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(429, [], json_encode(['errors' => 'rate limit'])),
            new Response(200, [], json_encode(['data' => ['shop' => ['name' => 'test']]])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        self::assertSame('test', $client->graphql('query { shop { name } }')['data']['shop']['name']);
        self::assertSame((string) $history[0]['request']->getBody(), (string) $history[1]['request']->getBody());
        self::assertSame('application/json', $history[1]['request']->getHeaderLine('Content-Type'));
    }
    public function testSemanticRetryExhaustionIsBounded(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['errors' => [['message' => 'Throttled']]]))]));
        $stack->push(Middleware::history($history));
        $client = new ShopifyApi('token', 'test-shop', '2025-10', guzzleClient: new Client(['handler' => $stack]));
        $client->setMaxRetries(0);
        try {
            $client->graphql('query { shop { name } }');
            self::fail('Expected throttling failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Throttled', $e->getMessage());
            self::assertCount(1, $history);
            self::assertSame('errors', $client->getResponseErrorDetector());
        }
    }
}
