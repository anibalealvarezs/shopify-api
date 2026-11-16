# Shopify API SDK

The SDK supports GraphQL Admin requests, ShopifyQL, cursor pagination, bulk query exports, and legacy REST read methods. Use an explicitly supported Admin API version; ShopifyQL requires 2025-10 or later, `read_reports`, and protected customer data access.

```php
$api = new ShopifyApi($accessToken, $shopName, '2026-07');
$table = $api->shopifyqlQuery('FROM sales SHOW orders TIMESERIES day SINCE -7d');
```

`$shopName` is the store subdomain without `.myshopify.com`. The caller supplies a valid Admin access token; client credentials are not themselves an access token. REST is a legacy Shopify API and its available resources and permissions vary by version. The existing methods remain available for compatibility.

For bulk exports, create `BulkOperationsService`, call `start()`, `wait()`, then `download()` with the completed operation and a callback. `download()` streams JSONL without sending the Admin token to the download host. Treat the signed result URL as a secret; only pass operations returned by Shopify, never untrusted caller input. A failed operation can have partial data; it is not treated as a successful export.

Run unit tests with `php vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php tests/Unit`. Live tests are opt-in: set `SHOPIFY_LIVE_TESTS=1` and `SHOPIFY_TEST_CREDENTIALS` to a local JSON file with `shop`, `client_id`, `client_secret`, and `api_version`. The development app must be installed, have the necessary scopes, and have bulk queries enabled. Tests read data and start a product export; they do not create store entities. Do not commit credentials or signed result URLs. Client-credentials authentication in this test harness applies to a store in the app owner’s organization.
