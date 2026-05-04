<?php

    namespace Tests\Unit;

    use Anibalealvarezs\ShopifyApi\ShopifyApi;
    use Anibalealvarezs\ShopifyApi\Support\ShopifyErrorClassifier;
    use GuzzleHttp\Client as GuzzleClient;
    use GuzzleHttp\Exception\GuzzleException;
    use GuzzleHttp\Handler\MockHandler;
    use GuzzleHttp\HandlerStack;
    use GuzzleHttp\Psr7\Response;
    use PHPUnit\Framework\TestCase;

    class ShopifyErrorHandlingTest extends TestCase
    {
        protected string $apiKey = 'test_api_key';
        protected string $shopName = 'test-shop';

        protected function createMockedGuzzleClient(MockHandler $mock): GuzzleClient
        {
            $handlerStack = HandlerStack::create($mock);

            return new GuzzleClient(['handler' => $handlerStack]);
        }

        /**
         * @throws GuzzleException
         */
        public function testShopifySemanticRetryableFalsy200EventuallySucceeds(): void
        {
            $mock = new MockHandler([
                new Response(200, [], json_encode(['errors' => 'Throttled'])),
                new Response(200, [], json_encode(['orders' => []]))
            ]);

            $client = new ShopifyApi(
                apiKey: $this->apiKey,
                shopName: $this->shopName,
                guzzleClient: $this->createMockedGuzzleClient($mock)
            );

            $response = $client->performRequest(method: 'GET', endpoint: 'orders.json');
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertTrue(is_callable($client->getRateLimitDetector()));
        }

            public function testShopifyErrorClassifierRecognizesThrottlingSignals(): void
        {
            $classification = ShopifyErrorClassifier::classify([
                    'errors' => 'Exceeded 2 calls per second for api client. Reduce request rates to resume uninterrupted service.',
                    'status' => 429,
            ]);

            $this->assertSame('retryable', $classification['category']);
            $this->assertTrue($classification['should_retry']);
        }
    }

