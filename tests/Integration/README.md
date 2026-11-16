# Shopify live tests

Live tests are opt-in: set `SHOPIFY_LIVE_TESTS=1`. The credential file is selected by `SHOPIFY_TEST_CREDENTIALS`, defaulting to the ignored shared `shopify-test-credentials.json` file. Never commit or print its contents.

Required fields: `shop` (the full myshopify.com domain) and `api_version`.

Authentication supports two alternatives:

- `access_token`: use a pre-authorized Admin API token. When nonempty, this takes precedence and the test does not exchange client credentials. This permits testing with an OAuth token supported by bulk operations.
- `client_id` and `client_secret`: the test obtains a token with the client-credentials grant. A successful ordinary query does not establish that this token is supported for bulk operations.

For independent-store routing tests, add `additional_shops` as an array of full myshopify.com domains to the same private file. Install the same app in each store; the test obtains separate tokens using the shared app credentials and registers each reported shop GID as its own account in the driver. Tokens remain in memory. Run `ShopifyMultiStoreLiveTest.php` to verify identity, account routing, source ownership and removing one account without disrupting another. This verifies driver dispatch, not production Facade provisioning or database persistence of the live records.

Run complete entity dispatch with `php vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php --filter testLiveDriverEntitySync tests/Integration/ShopifyLiveTest.php`.

Set `SHOPIFY_TEST_TRANSPORT=bulk` to test bulk root streaming with the same entity normalization. Shopify authorization failures are reported as failures, not silently skipped or changed into paginated success. The test callbacks validate dispatch; they do not write to the worker database.
