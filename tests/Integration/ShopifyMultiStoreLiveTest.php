<?php
declare(strict_types=1);
namespace Tests\Integration;

use Anibalealvarezs\ShopifyApi\ShopifyApi;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class ShopifyMultiStoreLiveTest extends TestCase
{
    public function testIndependentStoresRouteAndNormalizeWithoutCrossingAccounts(): void
    {
        if (getenv('SHOPIFY_LIVE_TESTS') !== '1') self::markTestSkipped('Live Shopify tests are opt-in.');
        $path = getenv('SHOPIFY_TEST_CREDENTIALS') ?: 'D:/laragon/www/_shared/shopify-test-credentials.json';
        $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $shops = array_values(array_unique(array_merge([$config['shop'] ?? ''], $config['additional_shops'] ?? [])));
        if (count($shops) < 2) self::markTestSkipped('Configure a second test store.');
        $source = getenv('SHOPIFY_TEST_DRIVER_SOURCE') ?: dirname(__DIR__, 3).'/shopify-hub-driver';
        require_once $source.'/vendor/autoload.php';
        require_once $source.'/src/Auth/ShopifyAuthProvider.php';
        require_once $source.'/src/Drivers/ShopifyDriver.php';
        require_once $source.'/src/Conversions/ShopifyConvert.php';
        $auth = new \Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider([]);
        $http = new Client(['timeout' => 20, 'connect_timeout' => 10]);
        $identities = [];
        foreach ($shops as $index => $domain) {
            if (!is_string($domain) || !preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $domain)) self::fail('Invalid configured test-store domain.');
            try {
                $response = $http->post('https://'.$domain.'/admin/oauth/access_token', ['form_params' => ['grant_type' => 'client_credentials', 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret']], 'http_errors' => false, 'allow_redirects' => false]);
            } catch (\Throwable $e) { self::fail('Unable to authenticate test store '.($index + 1).'; check connectivity.'); }
            $body = json_decode((string)$response->getBody(), true);
            if ($response->getStatusCode() !== 200 || empty($body['access_token'])) self::fail('Test store '.($index + 1).' authentication failed (HTTP '.$response->getStatusCode().'). Confirm the existing app is installed in that store.');
            $api = new ShopifyApi($body['access_token'], substr($domain, 0, -strlen('.myshopify.com')), $config['api_version']);
            $shop = $api->graphql('query { shop { id myshopifyDomain } }')['data']['shop'];
            self::assertSame($domain, $shop['myshopifyDomain']);
            $identities[] = $shop['id'];
            $auth->storeAccountCredentials($shop['id'], ['shop_id' => $shop['id'], 'shop_name' => $domain, 'access_token' => $body['access_token'], 'version' => $config['api_version']]);
        }
        self::assertCount(count($shops), array_unique($identities));
        $driver = new \Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver($auth);
        foreach ($identities as $id) {
            $reported = $driver->getApi(['account_id' => $id])->graphql('query { shop { id } }')['data']['shop']['id'];
            self::assertSame($id, $reported);
            $driver->setDataProcessor(static function ($records, $type) use ($id): void {
                foreach ($records as $record) {
                    if ($type === 'account' || $type === 'store') self::assertSame($id, $record->platformId);
                    if ($type === 'customer') self::assertSame($id, $record->storePlatformId);
                }
            });
            $driver->syncShopAndCustomers(['account_id' => $id]);
        }
        // Remove one account and prove routing to the remaining store is intact.
        $auth->removeAccountCredentials($identities[0]);
        self::assertNull($auth->getCredentialsForAccount($identities[0]));
        self::assertSame($identities[1], $driver->getApi(['account_id' => $identities[1]])->graphql('query { shop { id } }')['data']['shop']['id']);
    }
}
