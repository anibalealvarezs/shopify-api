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
            public function testWrappedHttpStatusAndGraphqlAccessDeniedAreClassified(): void
        {
            $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.test');
            $cause = new \GuzzleHttp\Exception\RequestException('denied', $request, new Response(403));
            self::assertSame('fatal_auth', ShopifyErrorClassifier::classify(new \RuntimeException('wrapped', 0, $cause))['category']);
            self::assertSame('fatal_auth', ShopifyErrorClassifier::classify(['errors' => [['extensions' => ['code' => 'ACCESS_DENIED']]]])['category']);
            self::assertSame('not_found', ShopifyErrorClassifier::classify(['status' => 404])['category']);
        }
        public function testClassifiesGraphqlAndServerErrors(): void
        {
            self::assertSame('retryable', ShopifyErrorClassifier::classify(['status' => 503])['category']);
            self::assertSame('retryable', ShopifyErrorClassifier::classify(['errors' => [['extensions' => ['code' => 'THROTTLED']]]])['category']);
            self::assertSame('invalid_request', ShopifyErrorClassifier::classify(['errors' => [['extensions' => ['code' => 'RESPONSE_TOO_LARGE']]]])['category']);
        }
        /** @dataProvider classificationCases */
        public function testErrorClassification(array $input, string $category, bool $retry): void
        {
            $result = ShopifyErrorClassifier::classify($input);
            self::assertSame($category, $result['category']);
            self::assertSame($retry, $result['should_retry']);
        }

        public static function classificationCases(): array
        {
            return [
                [['status' => 401], 'fatal_auth', false],
                [['status' => 403], 'fatal_auth', false],
                [['status' => 404], 'not_found', false],
                [['status' => 429], 'retryable', true],
                [['status' => 430], 'retryable', true],
                [['status' => 503], 'retryable', true],
                [['errors' => [['extensions' => ['code' => 'THROTTLED']]]], 'retryable', true],
                [['errors' => [['extensions' => ['code' => 'ACCESS_DENIED']]]], 'fatal_auth', false],
                [['errors' => [['extensions' => ['code' => 'INTERNAL_SERVER_ERROR']]]], 'retryable', true],
                [['errors' => [['extensions' => ['code' => 'RESPONSE_TOO_LARGE']]]], 'invalid_request', false],
                [['errors' => [['extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED']]]], 'invalid_request', false],
            ];
        }
}

