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
                ['Link' => '<...page_info=tok2>; rel="next"'],
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
            new Response(500, [], 'Internal Server Error'),
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
}
