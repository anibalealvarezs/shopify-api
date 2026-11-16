<?php

namespace Tests\Unit;

use Anibalealvarezs\ShopifyApi\ShopifyApi;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShopifyApiTest extends TestCase
{
    protected string $apiKey = 'test_api_key';
    protected string $shopName = 'test-shop';

    /**
     * @param MockHandler $mock
     * @return GuzzleClient
     */
    protected function createMockedGuzzleClient(MockHandler $mock): GuzzleClient
    {
        $handlerStack = HandlerStack::create($mock);
        return new GuzzleClient(['handler' => $handlerStack]);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testGetOrdersAllPaginated(): void
    {
        $response1Body = json_encode(['orders' => [['id' => 1], ['id' => 2]]]);
        $response2Body = json_encode(['orders' => [['id' => 3]]]);

        $mock = new MockHandler([
            new Response(
                200,
                ['Link' => '<https://test-shop.myshopify.com/admin/api/2023-01/orders.json?page_info=next_token>; rel="next"'],
                $response1Body
            ),
            new Response(200, [], $response2Body),
        ]);

        $guzzle = $this->createMockedGuzzleClient($mock);
        $client = new ShopifyApi(apiKey: $this->apiKey, shopName: $this->shopName, guzzleClient: $guzzle);

        $result = $client->getOrdersAll();
        
        $this->assertCount(3, $result['orders']);
        $this->assertEquals(1, $result['orders'][0]['id']);
        $this->assertEquals(3, $result['orders'][2]['id']);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testGetOrdersAllAndProcess(): void
    {
        $response1Body = json_encode(['orders' => [['id' => 1]]]);
        $response2Body = json_encode(['orders' => [['id' => 2]]]);

        $mock = new MockHandler([
            new Response(
                200,
                ['Link' => '<https://test-shop.myshopify.com/admin/api/2023-01/orders.json?page_info=token2>; rel="next"'],
                $response1Body
            ),
            new Response(200, [], $response2Body),
        ]);

        $guzzle = $this->createMockedGuzzleClient($mock);
        $client = new ShopifyApi(apiKey: $this->apiKey, shopName: $this->shopName, guzzleClient: $guzzle);

        $processedCount = 0;
        $client->getOrdersAllAndProcess(function ($orders) use (&$processedCount) {
            $processedCount += count($orders);
        });

        $this->assertEquals(2, $processedCount);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testGetProductsAllAndProcess(): void
    {
        $response1Body = json_encode(['products' => [['id' => 'p1']]]);
        $response2Body = json_encode(['products' => [['id' => 'p2']]]);

        $mock = new MockHandler([
            new Response(
                200,
                ['Link' => '<https://test-shop.myshopify.com/admin/api/products.json?page_info=tok2>; rel="next"'],
                $response1Body
            ),
            new Response(200, [], $response2Body),
        ]);

        $guzzle = $this->createMockedGuzzleClient($mock);
        $client = new ShopifyApi(apiKey: $this->apiKey, shopName: $this->shopName, guzzleClient: $guzzle);

        $processedCount = 0;
        $client->getProductsAllAndProcess(function ($products) use (&$processedCount) {
            $processedCount += count($products);
        });

        $this->assertEquals(2, $processedCount);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testGetOrdersAllEmpty(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['orders' => []])),
        ]);

        $guzzle = $this->createMockedGuzzleClient($mock);
        $client = new ShopifyApi(apiKey: $this->apiKey, shopName: $this->shopName, guzzleClient: $guzzle);

        $result = $client->getOrdersAll();
        
        $this->assertCount(0, $result['orders']);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testGetOrdersAllAndProcessErrorOnSecondPage(): void
    {
        $mock = new MockHandler([
            new Response(
                200,
                ['Link' => '<https://test-shop.myshopify.com/admin/api/2023-01/orders.json?page_info=tok2>; rel="next"'],
                json_encode(['orders' => [['id' => 1]]])
            ),
            new Response(400, [], 'Invalid request'),
        ]);

        $guzzle = $this->createMockedGuzzleClient($mock);
        $client = new ShopifyApi(apiKey: $this->apiKey, shopName: $this->shopName, guzzleClient: $guzzle);

        $processedCount = 0;
        
        $this->expectException(\Anibalealvarezs\ApiSkeleton\Classes\Exceptions\ApiRequestException::class);

        $client->getOrdersAllAndProcess(function ($orders) use (&$processedCount) {
            $processedCount += count($orders);
        });

        // We expect the first page to be processed before the exception
        $this->assertEquals(1, $processedCount);
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function testGetOrdersAllMalformedLink(): void
    {
        $mock = new MockHandler([
            new Response(
                200,
                ['Link' => 'malformed content'],
                json_encode(['orders' => [['id' => 1]]])
            ),
        ]);

        $guzzle = $this->createMockedGuzzleClient($mock);
        $client = new ShopifyApi(apiKey: $this->apiKey, shopName: $this->shopName, guzzleClient: $guzzle);

        $result = $client->getOrdersAll();
        
        // Loop should stop and only the first page results should be returned
        $this->assertCount(1, $result['orders']);
    }
    public function testLegacyPaginationDetectsRepeatedCursorWithUppercaseHeader(): void
    {
        $link = '<https://test-shop.myshopify.com/admin/api/orders.json?page_info=repeat>; rel="next"';
        $mock = new MockHandler([
            new Response(200, ['Link' => $link], json_encode(['orders' => [['id' => 1]]])),
            new Response(200, ['Link' => $link], json_encode(['orders' => [['id' => 2]]])),
        ]);
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: $this->createMockedGuzzleClient($mock));
        $this->expectException(\UnexpectedValueException::class);
        $client->getAllOrders();
    }

    public function testStreamingPaginationDetectsRepeatedCursor(): void
    {
        $link = '<https://test-shop.myshopify.com/admin/api/orders.json?page_info=repeat>; rel="next"';
        $mock = new MockHandler([
            new Response(200, ['Link' => $link], json_encode(['orders' => []])),
            new Response(200, ['Link' => $link], json_encode(['orders' => []])),
        ]);
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: $this->createMockedGuzzleClient($mock));
        $this->expectException(\UnexpectedValueException::class);
        $client->getOrdersAllAndProcess(static function () {});
    }
    public function testInventoryHandlesEmptyAndChunksIds(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['inventory_items' => [['id' => 1]]])),
            new Response(200, [], json_encode(['inventory_items' => [['id' => 251]]])),
        ]);
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: $this->createMockedGuzzleClient($mock));
        self::assertSame(['inventory_items' => []], $client->getAllInventoryItems([]));
        self::assertCount(2, $client->getAllInventoryItems(range(1, 251))['inventory_items']);
    }

    public function testStreamingRejectsMissingCollection(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['unexpected' => []]))]);
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: $this->createMockedGuzzleClient($mock));
        $this->expectException(\UnexpectedValueException::class);
        $client->getOrdersAll();
    }
    public function testSinglePageRejectsInvalidJson(): void
    {
        $mock = new MockHandler([new Response(200, [], '{invalid')]);
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: $this->createMockedGuzzleClient($mock));
        $this->expectException(\JsonException::class);
        $client->getOrders();
    }
    public function testLegacyEndpointFamiliesAndParameters(): void
    {
        $history = [];
        $replies = [];
        foreach (['count', 'count', 'count', 'price_rules', 'discount_codes', 'custom_collections', 'smart_collections', 'products', 'collects', 'inventory_items'] as $key) {
            $replies[] = new Response(200, [], json_encode([$key => $key === 'count' ? 0 : []]));
        }
        $stack = HandlerStack::create(new MockHandler($replies));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: new GuzzleClient(['handler' => $stack]));
        $client->getOrdersCount(); $client->getCustomersCount(); $client->getProductsCount();
        $client->getPriceRules(timesUsed: 0); $client->getDiscountCodes(priceRuleId: 123);
        $client->getCustomCollections(); $client->getSmartCollections();
        $client->getProductsForCollection(collectionId: 42); $client->getCollects(); $client->getInventoryItems([1, 2]);
        $expected = ['orders/count.json', 'customers/count.json', 'products/count.json', 'price_rules.json', 'price_rules/123/discount_codes.json', 'custom_collections.json', 'smart_collections.json', 'collections/42/products.json', 'collects.json', 'inventory_items.json'];
        foreach ($expected as $index => $path) self::assertStringEndsWith('/'.$path, $history[$index]['request']->getUri()->getPath());
        parse_str($history[3]['request']->getUri()->getQuery(), $params);
        self::assertSame('0', $params['times_used']);
        parse_str($history[9]['request']->getUri()->getQuery(), $params);
        self::assertSame('1,2', $params['ids']);
    }
    public function testLegacyAllRejectsMissingCollectionInsteadOfReturningEmpty(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['unexpected' => []]))]);
        $client = new ShopifyApi('key', 'test-shop', guzzleClient: $this->createMockedGuzzleClient($mock));
        $this->expectException(\UnexpectedValueException::class);
        $client->getAllProducts();
    }
}

