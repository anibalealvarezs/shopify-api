<?php

declare(strict_types=1);

namespace Tests\Integration;

use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Anibalealvarezs\ShopifyApi\Services\BulkOperationsService;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class ShopifyLiveTest extends TestCase
{
    private ShopifyApi $api;

    protected function setUp(): void
    {
        if (getenv('SHOPIFY_LIVE_TESTS') !== '1') {
            self::markTestSkipped('Set SHOPIFY_LIVE_TESTS=1 to enable live Shopify tests.');
        }
        $path = getenv('SHOPIFY_TEST_CREDENTIALS') ?: 'D:/laragon/www/_shared/shopify-test-credentials.json';
        if (!is_file($path)) {
            self::markTestSkipped('Shopify test credentials file is absent.');
        }
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $shop = $config['shop'] ?? '';
        if (!is_string($shop) || !preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i', $shop)) {
            self::fail('Invalid Shopify store domain in credentials file.');
        }
        $providedToken = $config['access_token'] ?? null;
        if (is_string($providedToken) && $providedToken !== '') {
            $name = substr($shop, 0, -strlen('.myshopify.com'));
            $this->api = new ShopifyApi($providedToken, $name, $config['api_version'] ?? '2026-07');
            return;
        }
        $id = $config['client_id'] ?? '';
        $secret = $config['client_secret'] ?? '';
        if (!$id || !$secret || str_starts_with($id, 'YOUR_') || str_starts_with($secret, 'YOUR_')) {
            self::fail('Shopify client credentials are missing.');
        }
        try {
            $http = new Client(['timeout' => 20, 'connect_timeout' => 10]);
            $response = $http->post('https://'.$shop.'/admin/oauth/access_token', [
                'form_params' => ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret],
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            self::fail('Shopify authentication request failed; check network connectivity.');
        }
        if ($status !== 200 || !is_array($body) || empty($body['access_token'])) {
            $code = $body['error'] ?? '';
            $known = ['invalid_client', 'invalid_request', 'invalid_grant', 'unauthorized_client', 'unsupported_grant_type', 'access_denied'];
            $detail = is_string($code) && in_array($code, $known, true) ? ' '.$code.'.' : '';
            self::fail('Shopify authentication failed (HTTP '.$status.').'.$detail.' Check app installation and credentials.');
        }
        $name = substr($shop, 0, -strlen('.myshopify.com'));
        $this->api = new ShopifyApi($body['access_token'], $name, $config['api_version'] ?? '2026-07');
    }

    public function testShopAndGrantedScopes(): void
    {
        $result = $this->api->graphql('query { shop { name myshopifyDomain } currentAppInstallation { accessScopes { handle } } }');
        self::assertNotEmpty($result['data']['shop']['myshopifyDomain'] ?? null);
        $scopes = array_column($result['data']['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        foreach (['read_orders', 'read_customers', 'read_products', 'read_reports'] as $scope) {
            self::assertContains($scope, $scopes, 'Missing required scope: '.$scope);
        }
    }

    public function testShopifyqlSales(): void
    {
        $table = $this->api->shopifyqlQuery('FROM sales SHOW orders TIMESERIES day SINCE -7d');
        self::assertIsArray($table['columns'] ?? null);
        self::assertIsArray($table['rows'] ?? null);
    }
    public function testProductsPagination(): void
    {
        $pages = 0;
        $this->api->graphqlPaginate(
            'query Products($cursor: String) { products(first: 5, after: $cursor) { nodes { id } pageInfo { hasNextPage endCursor } } }',
            'products',
            static function (array $page) use (&$pages): void { self::assertIsArray($page['nodes']); $pages++; },
        );
        self::assertGreaterThan(0, $pages);
    }

    public function testBulkProductsExport(): void
    {
        $bulk = new BulkOperationsService($this->api);
        $operation = $bulk->start('{ products { edges { node { id title } } } }');
        $result = $bulk->wait($operation['id'], 30, 1000);
        self::assertSame('COMPLETED', $result['status']);
        $count = 0;
        $bulk->download($result, static function (array $row) use (&$count): void {
            self::assertNotEmpty($row['id'] ?? null);
            $count++;
        });
        self::assertSame((int) $result['objectCount'], $count);
    }
    public function testRestReadEndpoints(): void
    {
        foreach ([$this->api->getOrders(limit: 1), $this->api->getCustomers(limit: 1), $this->api->getProducts(limit: 1)] as $index => $result) {
            $key = ['orders', 'customers', 'products'][$index];
            self::assertIsArray($result[$key] ?? null, 'Invalid REST '.$key.' response');
        }
    }
    public function testOrdersAndCustomersConnections(): void
    {
        foreach (['orders', 'customers'] as $connection) {
            $pages = 0;
            $query = 'query Entities($cursor: String) { '.$connection.'(first: 5, after: $cursor) { nodes { id } pageInfo { hasNextPage endCursor } } }';
            $this->api->graphqlPaginate($query, $connection, static function (array $page) use (&$pages): void {
                self::assertIsArray($page['nodes']);
                $pages++;
            });
            self::assertGreaterThan(0, $pages);
        }
    }
    public function testRestCountsAndCollections(): void
    {
        foreach ([$this->api->getOrdersCount(), $this->api->getCustomersCount(), $this->api->getProductsCount()] as $result) {
            self::assertIsInt($result['count'] ?? null);
        }
        self::assertIsArray($this->api->getCustomCollections(limit: 1)['custom_collections'] ?? null);
        self::assertIsArray($this->api->getSmartCollections(limit: 1)['smart_collections'] ?? null);
        self::assertIsArray($this->api->getCollects(limit: 1)['collects'] ?? null);
    }
    public function testRestPriceRulesWhenGranted(): void
    {
        $result = $this->api->graphql('query { currentAppInstallation { accessScopes { handle } } }');
        $scopes = array_column($result['data']['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        if (!in_array('read_price_rules', $scopes, true)) {
            self::markTestSkipped('Legacy read_price_rules scope is not granted.');
        }
        self::assertIsArray($this->api->getPriceRules(limit: 1)['price_rules'] ?? null);
    }
    public function testRestInventoryWithExistingVariant(): void
    {
        $products = $this->api->getProducts(limit: 10)['products'];
        $id = null;
        foreach ($products as $product) {
            foreach ($product['variants'] ?? [] as $variant) {
                if (!empty($variant['inventory_item_id'])) { $id = $variant['inventory_item_id']; break 2; }
            }
        }
        if ($id === null) self::markTestSkipped('No product variant with an inventory item exists.');
        self::assertIsArray($this->api->getInventoryItems([(int) $id])['inventory_items'] ?? null);
    }

    public function testRestNestedCollectionProducts(): void
    {
        $collections = $this->api->getCustomCollections(limit: 1)['custom_collections'];
        if (!$collections) $collections = $this->api->getSmartCollections(limit: 1)['smart_collections'];
        if (!$collections) self::markTestSkipped('No collection exists for the nested products test.');
        self::assertIsArray($this->api->getProductsForCollection(collectionId: (int) $collections[0]['id'])['products'] ?? null);
    }

    public function testRestDiscountCodesForExistingPriceRule(): void
    {
        $scopes = array_column($this->api->graphql('query { currentAppInstallation { accessScopes { handle } } }')['data']['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        if (!in_array('read_price_rules', $scopes, true)) self::markTestSkipped('Legacy price rule scope is absent.');
        $rules = $this->api->getPriceRules(limit: 1)['price_rules'];
        if (!$rules) self::markTestSkipped('No price rule exists for the discount-code test.');
        self::assertIsArray($this->api->getDiscountCodes((int) $rules[0]['id'])['discount_codes'] ?? null);
    }
    public function testShopAndCustomerIdentityFields(): void
    {
        $shop = $this->api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }');
        self::assertNotEmpty($shop['data']['shop']['id'] ?? null);
        self::assertNotEmpty($shop['data']['shop']['primaryDomain']['host'] ?? null);
        $result = $this->api->graphql('query ShopifyCustomers($cursor: String) { customers(first: 100, after: $cursor) { nodes { id legacyResourceId createdAt email firstName lastName } pageInfo { hasNextPage endCursor } } }');
        self::assertIsArray($result['data']['customers']['nodes'] ?? null);
    }
    public function testProductAndVariantSyncQueries(): void
    {
        $result = $this->api->graphql('query ShopifyProducts($cursor: String) { products(first: 100, after: $cursor) { nodes { id legacyResourceId title handle vendor createdAt updatedAt } pageInfo { hasNextPage endCursor } } }');
        $products = $result['data']['products']['nodes'] ?? null;
        self::assertIsArray($products);
        if ($products === []) self::markTestSkipped('No product exists for variant query verification.');
        $variants = $this->api->graphql('query ShopifyProductVariants($id: ID!, $cursor: String) { product(id: $id) { variants(first: 100, after: $cursor) { nodes { id legacyResourceId sku createdAt updatedAt } pageInfo { hasNextPage endCursor } } } }', ['id' => $products[0]['id']]);
        self::assertIsArray($variants['data']['product']['variants']['nodes'] ?? null);
    }
    public function testOrderAndLineItemSyncQueries(): void
    {
        $result = $this->api->graphql('query ShopifyOrders($cursor: String) { orders(first: 10, after: $cursor) { nodes { id legacyResourceId name createdAt updatedAt processedAt displayFinancialStatus displayFulfillmentStatus customer { id legacyResourceId email } currentTotalPriceSet { shopMoney { amount currencyCode } } customerJourneySummary { firstVisit { landingPage referrerUrl occurredAt utmParameters { source medium campaign content term } } lastVisit { landingPage referrerUrl occurredAt utmParameters { source medium campaign content term } } } } pageInfo { hasNextPage endCursor } } }');
        $orders = $result['data']['orders']['nodes'] ?? null;
        self::assertIsArray($orders);
        $id = $orders[0]['id'] ?? 'gid://shopify/Order/0';
        $lines = $this->api->graphql('query ShopifyOrderLines($id: ID!, $cursor: String) { order(id: $id) { lineItems(first: 100, after: $cursor) { nodes { id name quantity sku product { id legacyResourceId } variant { id legacyResourceId } } pageInfo { hasNextPage endCursor } } } }', ['id' => $id]);
        if ($orders === []) { self::assertArrayHasKey('order', $lines['data']); return; }
        self::assertIsArray($lines['data']['order']['lineItems']['nodes'] ?? null);
    }
    public function testPopulatedTestOrdersHaveLineItems(): void
    {
        $result = $this->api->graphql('query { orders(first: 20) { nodes { id lineItems(first: 10) { nodes { id } } } } }');
        $orders = $result['data']['orders']['nodes'] ?? [];
        self::assertNotEmpty($orders, 'No accessible orders were returned by the configured test store.');
        self::assertGreaterThan(0, array_sum(array_map(static fn ($order) => count($order['lineItems']['nodes'] ?? []), $orders)));
    }
    public function testLiveDriverOrderSync(): void
    {
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        if (!is_file($source.'/src/Drivers/ShopifyDriver.php') || !is_file($source.'/vendor/autoload.php')) self::markTestSkipped('Driver source or dependencies unavailable.');
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        require_once $source.'/src/Conversions/ShopifyEventConvert.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        $driver = new class($this->api) extends \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver {
            public function __construct(private ShopifyApi $client) { parent::__construct(); }
            public function getApi(array $config = []): ShopifyApi { return $this->client; }
        };
        $counts = ['account' => 0, 'store' => 0, 'order' => 0, 'event' => 0, 'lines' => 0];
        $driver->setDataProcessor(static function ($records, $type) use (&$counts): void {
            if (!isset($counts[$type])) throw new \RuntimeException('Unexpected entity type.');
            $counts[$type] += $records->count();
            if ($type === 'order') foreach ($records as $record) {
                if (!$record->platformId || !$record->storePlatformId) throw new \RuntimeException('Order identity is missing.');
                $counts['lines'] += count($record->lineItems);
            }
        });
        $driver->syncShopAndOrders();
        self::assertSame(1, $counts['account']);
        self::assertSame(1, $counts['store']);
        self::assertGreaterThan(0, $counts['order']);
        self::assertGreaterThan(0, $counts['lines']);
    }
    public function testDailySalesQueryFields(): void
    {
        $table = $this->api->shopifyqlQuery('FROM sales SHOW orders, gross_sales, net_sales, total_sales TIMESERIES day SINCE -7d UNTIL today ORDER BY day ASC LIMIT 1000');
        $columns = array_column($table['columns'], 'name');
        foreach (['day', 'orders', 'gross_sales', 'net_sales', 'total_sales'] as $field) self::assertContains($field, $columns);
    }
    public function testFinancialFixtureAvailability(): void
    {
        $result = $this->api->graphql('query { orders(first: 100) { nodes { id test refunds { id } discountCodes } pageInfo { hasNextPage } } discountNodes(first: 100) { nodes { id } pageInfo { hasNextPage } } currentAppInstallation { accessScopes { handle } } }');
        $orders = $result['data']['orders']['nodes'];
        $scopes = array_column($result['data']['currentAppInstallation']['accessScopes'], 'handle');
        $table = $this->api->shopifyqlQuery('FROM sales SHOW orders, gross_sales, net_sales, total_sales TIMESERIES day SINCE -90d UNTIL today ORDER BY day ASC LIMIT 1000');
        fwrite(STDOUT, json_encode(['sampled_orders' => count($orders), 'sampled_refunds' => array_sum(array_map(static fn ($o) => count($o['refunds']), $orders)), 'sampled_discounted_orders' => count(array_filter($orders, static fn ($o) => $o['discountCodes'] !== [])), 'sampled_discount_definitions' => count($result['data']['discountNodes']['nodes']), 'sales_rows' => count($table['rows']), 'positive_sales_rows' => count(array_filter($table['rows'], static fn ($r) => (float)($r['total_sales'] ?? 0) > 0)), 'can_write_orders' => in_array('write_orders', $scopes, true), 'can_write_discounts' => in_array('write_discounts', $scopes, true)]).PHP_EOL);
        self::assertIsArray($orders);
    }
    public function testLiveDailySalesParity(): void
    {
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        require_once $source.'/src/Conversions/ShopifyMetricConvert.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        $shop = $this->api->graphql('query { shop { id currencyCode ianaTimezone } }')['data']['shop'];
        $end = new \DateTime('today', new \DateTimeZone($shop['ianaTimezone']));
        $start = (clone $end)->modify('-90 days');
        $query = 'FROM sales SHOW orders, gross_sales, net_sales, total_sales TIMESERIES day SINCE '.$start->format('Y-m-d').' UNTIL '.$end->format('Y-m-d').' ORDER BY day ASC LIMIT 1000';
        $table = $this->api->shopifyqlQuery($query);
        self::assertNotEmpty(array_filter($table['rows'], static fn ($r) => (float)($r['total_sales'] ?? 0) > 0), 'Financial parity requires nonzero ShopifyQL sales.');
        $expected = [];
        foreach ($table['rows'] as $row) foreach (['orders', 'gross_sales', 'net_sales', 'total_sales'] as $name) if ($row[$name] !== null) $expected[$row['day'].'/'.$name] = (float)$row[$name];
        $driver = new class($this->api) extends \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver {
            public function __construct(private ShopifyApi $client) { parent::__construct(); }
            public function getApi(array $config = []): ShopifyApi { return $this->client; }
        };
        $actual = [];
        $driver->setDataProcessor(static function ($records, $type = null) use (&$actual, $shop): void {
            if ($type !== null) return;
            foreach ($records as $record) {
                self::assertSame($shop['id'], $record->channeledAccountPlatformId);
                self::assertSame($shop['currencyCode'], $record->sourceCurrency);
                $actual[$record->metricDate.'/'.$record->name] = (float)$record->value;
            }
        });
        $driver->syncDailySales($start, $end);
        ksort($expected); ksort($actual);
        self::assertEquals($expected, $actual);
        $fixture = getenv('SHOPIFY_TEST_SALES_FIXTURE');
        if ($fixture !== false && $fixture !== '') {
            $allowed = realpath('D:/laragon/www/_shared/.stage2-postgres');
            if ($allowed === false || realpath(dirname($fixture)) !== $allowed) self::fail('Financial fixture must use the isolated test runtime directory.');
            self::assertNotFalse(file_put_contents($fixture, json_encode(['shop_id' => $shop['id'], 'currency' => $shop['currencyCode'], 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'table' => $table], JSON_THROW_ON_ERROR), LOCK_EX));
        }
    }
    public function testLiveDriverDiscountSync(): void
    {
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        if (!is_file($source.'/src/Drivers/ShopifyDriver.php') || !is_file($source.'/vendor/autoload.php')) self::markTestSkipped('Driver source or dependencies unavailable.');
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        require_once $source.'/src/Conversions/ShopifyEventConvert.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        $driver = new class($this->api) extends \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver {
            public function __construct(private ShopifyApi $client) { parent::__construct(); }
            public function getApi(array $config = []): ShopifyApi { return $this->client; }
        };
        $types = [];
        $driver->setDataProcessor(static function ($records, $type) use (&$types): void { $types[] = $type; });
        $driver->syncShopAndDiscounts();
        self::assertSame(['account', 'store'], array_slice($types, 0, 2));
        $result = $this->api->graphql('query ShopifyDiscountCodes($id: ID!, $cursor: String) { discountNode(id: $id) { discount { ... on DiscountCodeBasic { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } ... on DiscountCodeBxgy { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } ... on DiscountCodeFreeShipping { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } ... on DiscountCodeApp { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } } } }', ['id' => 'gid://shopify/DiscountCodeNode/0']);
        self::assertArrayHasKey('discountNode', $result['data']);
    }
    public function testPopulatedDiscountSnapshotsMatchShopify(): void
    {
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        $driver = new class($this->api) extends \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver {
            public function __construct(private ShopifyApi $client) { parent::__construct(); }
            public function getApi(array $config = []): ShopifyApi { return $this->client; }
        };
        $records = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$records): void { if ($type === 'price_rule') foreach ($items as $item) $records[] = $item; });
        $driver->syncShopAndDiscounts();
        self::assertNotEmpty($records, 'Discount acceptance requires populated discount fixtures.');
        $codes = 0;
        $fields = '';
        foreach (['DiscountCodeBasic', 'DiscountCodeBxgy', 'DiscountCodeFreeShipping', 'DiscountCodeApp'] as $type) $fields .= ' ... on '.$type.' { title codes(first: 100) { nodes { id code } pageInfo { hasNextPage } } }';
        foreach (['DiscountAutomaticBasic', 'DiscountAutomaticBxgy', 'DiscountAutomaticFreeShipping', 'DiscountAutomaticApp'] as $type) $fields .= ' ... on '.$type.' { title }';
        foreach ($records as $record) {
            $raw = $this->api->graphql('query($id: ID!) { discountNode(id: $id) { id discount { __typename '.$fields.' } } }', ['id' => $record->platformId])['data']['discountNode'];
            self::assertSame($raw['id'], $record->platformId);
            self::assertSame($raw['discount']['title'], $record->data['title']);
            self::assertSame($raw['discount']['__typename'], $record->data['__typename']);
            if (isset($raw['discount']['codes'])) self::assertFalse($raw['discount']['codes']['pageInfo']['hasNextPage'], 'Fixture exceeds independent snapshot comparison limit.');
            $expected = array_column($raw['discount']['codes']['nodes'] ?? [], 'code', 'id');
            $actual = array_column($record->data['codes'], 'code', 'id');
            self::assertEquals($expected, $actual);
            foreach ($record->data['codes'] as $code) self::assertSame($code['id'], $code['identity']);
            $codes += count($actual);
        }
        self::assertGreaterThan(0, $codes, 'Discount acceptance requires a redeem-code fixture.');
    }
    public function testPopulatedRefundEventsMatchShopify(): void
    {
        $raw = $this->api->graphql('query { shop { id } orders(first: 100, sortKey: CREATED_AT, reverse: true) { nodes { id refunds { id createdAt totalRefundedSet { shopMoney { amount currencyCode } } } } } }')['data'];
        $expected = [];
        foreach ($raw['orders']['nodes'] as $order) foreach ($order['refunds'] as $refund) $expected[$refund['id']] = ['order_id' => $order['id']] + $refund;
        self::assertNotEmpty($expected, 'Refund acceptance requires a refund in a test order.');
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        require_once $source.'/src/Conversions/ShopifyEventConvert.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        $driver = new class($this->api) extends \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver {
            public function __construct(private ShopifyApi $client) { parent::__construct(); }
            public function getApi(array $config = []): ShopifyApi { return $this->client; }
        };
        $actual = []; $orders = [];
        $driver->setDataProcessor(static function ($records, $type) use (&$actual, &$orders): void {
            foreach ($records as $record) {
                if ($type === 'event' && $record->name === 'order_refunded') $actual[$record->platformId] = $record;
                if ($type === 'order' && !empty($record->data['refunds'])) {
                    $data = $record->data;
                    if (isset($data['customer'])) $data['customer'] = ['id' => $data['customer']['id'] ?? null, 'email' => null];
                    unset($data['customerJourneySummary']);
                    $orders[] = $data;
                }
            }
        });
        $driver->syncShopAndOrders();
        foreach ($expected as $id => $refund) {
            self::assertArrayHasKey($id, $actual);
            self::assertSame($raw['shop']['id'], $actual[$id]->channeledAccountId);
            self::assertSame($refund['order_id'], $actual[$id]->data['order_id']);
            self::assertSame($refund['createdAt'], $actual[$id]->data['occurred_at']);
            self::assertSame($refund['totalRefundedSet'], $actual[$id]->data['totalRefundedSet']);
            self::assertFalse($actual[$id]->data['financial_status_verified']);
        }
        $path = getenv('SHOPIFY_TEST_REFUND_FIXTURE');
        if ($path) {
            $allowed = realpath('D:/laragon/www/_shared/.stage2-postgres');
            if (!$allowed || realpath(dirname($path)) !== $allowed) self::fail('Use the isolated fixture directory.');
            self::assertNotFalse(file_put_contents($path, json_encode(['shop_id' => $raw['shop']['id'], 'orders' => $orders], JSON_THROW_ON_ERROR), LOCK_EX));
        }
    }
    public function testLiveDriverEntitySync(): void
    {
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        if (!is_file($source.'/vendor/autoload.php')) self::markTestSkipped('Driver source unavailable.');
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        require_once $source.'/src/Conversions/ShopifyEventConvert.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        $driver = new class($this->api) extends \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver {
            public function __construct(private ShopifyApi $client) { parent::__construct(); }
            public function getApi(array $config = []): ShopifyApi { return $this->client; }
        };
        $counts = [];
        $driver->setDataProcessor(static function ($records, $type) use (&$counts): void { $counts[$type] = ($counts[$type] ?? 0) + $records->count(); });
        $driver->syncEntities(['transport' => getenv('SHOPIFY_TEST_TRANSPORT') ?: 'graphql']);
        self::assertGreaterThan(0, $counts['account'] ?? 0);
        self::assertGreaterThan(0, $counts['product'] ?? 0);
        self::assertGreaterThan(0, $counts['order'] ?? 0);
        self::assertGreaterThan(0, $counts['event'] ?? 0);
    }
}
