<?php

    namespace Anibalealvarezs\ShopifyApi;

    use Anibalealvarezs\ApiSkeleton\Clients\ApiKeyClient;
    use Anibalealvarezs\ShopifyApi\Enums\AccountType;
    use Anibalealvarezs\ShopifyApi\Enums\CollectionPublishedStatus;
    use Anibalealvarezs\ShopifyApi\Enums\FinancialStatus;
    use Anibalealvarezs\ShopifyApi\Enums\FulfillmentStatus;
    use Anibalealvarezs\ShopifyApi\Enums\PublishedStatus;
    use Anibalealvarezs\ShopifyApi\Enums\SortOptions;
    use Anibalealvarezs\ShopifyApi\Enums\Status;
    use Anibalealvarezs\ShopifyApi\Support\ShopifyErrorClassifier;
    use Exception;
    use GuzzleHttp\Cookie\CookieJar;
    use GuzzleHttp\Exception\GuzzleException;
    use GuzzleHttp\Psr7\Response;

    class ShopifyApi extends ApiKeyClient
    {
        protected string $analyticsToken;
        protected string $storeName;
        protected AccountType $accountType;

        /**
         * @param string $apiKey
         * @param string $shopName
         * @param string $version
         * @param string $analyticsToken
         * @param string $accountType
         * @param \GuzzleHttp\Client|null $guzzleClient
         * @throws GuzzleException
         */
        public function __construct(
            string              $apiKey,
            string              $shopName,
            string              $version = "2023-01",
            string              $analyticsToken = "",
            string              $accountType = 'standard',
            ?\GuzzleHttp\Client $guzzleClient = null,
        )
        {
            $this->analyticsToken = $analyticsToken;
            $this->storeName = $shopName;
            $this->accountType = AccountType::from($accountType);
            parent::__construct(
                baseUrl: "https://".$shopName.".myshopify.com/admin/api/".$version."/",
                apiKey: $apiKey,
                authSettings: [
                    "location" => "header",
                    "name"     => "X-Shopify-Access-Token",
                ],
                guzzleClient: $guzzleClient,
            );

            $this->setResponseErrorDetector('errors');
            $this->setErrorMessageParser(fn($data) => is_array($data['errors']) ? json_encode($data['errors']) : $data['errors']);
            $this->setRateLimitDetector([ShopifyErrorClassifier::class, 'isRetryable']);
        }

        /**
         * @param string $method
         * @param string $endpoint
         * @param array $query
         * @param string|array $body
         * @param array $form_params
         * @param string $baseUrl
         * @param array $headers
         * @param array $additionalHeaders
         * @param ?CookieJar $cookies
         * @param bool $verify
         * @param bool $allowNewToken
         * @param string $pathToSave
         * @param bool|null $stream
         * @param array|null $errorMessageNesting
         * @param int $sleep
         * @param array $customErrors
         * @param bool $ignoreAuth
         * @param mixed|null $onFailure
         * @return mixed
         * @throws GuzzleException
         */
        public function performRequest(
            string       $method,
            string       $endpoint,
            array        $query = [],
            string|array $body = "",
            array        $form_params = [],
            string       $baseUrl = "",
            array        $headers = [],
            array        $additionalHeaders = [], // Ex: ["Amazon-Advertising-API-Scope" => 'profileId'];
            ?CookieJar   $cookies = null,
            bool         $verify = true,
            bool         $allowNewToken = true,
            string       $pathToSave = "",
            ?bool        $stream = null,
            mixed        $errorMessageNesting = null,
            int          $sleep = 0,
            array        $customErrors = [], // Ex: ['403' => 'body'] or ['500' => 'code'] or ['404' => 'message']
            bool         $ignoreAuth = false,
            mixed        $onFailure = null,
            int          $retries = 0,
            ?int         $maxRetries = null,
        ): mixed
        {

            $sleep = $retries > 0 ? $sleep : match ($this->accountType) {
                AccountType::standard => 500000,
                AccountType::advanced => 250000,
                AccountType::plus => 50000,
            };

            return parent::performRequest(
                method: $method,
                endpoint: $endpoint,
                query: $query,
                body: $body,
                form_params: $form_params,
                baseUrl: $baseUrl,
                headers: $headers,
                additionalHeaders: $additionalHeaders,
                cookies: $cookies,
                verify: $verify,
                allowNewToken: $allowNewToken,
                pathToSave: $pathToSave,
                stream: $stream,
                errorMessageNesting: $errorMessageNesting,
                sleep: $sleep,
                customErrors: $customErrors,
                ignoreAuth: $ignoreAuth,
                onFailure: $onFailure,
                retries: $retries,
                maxRetries: $maxRetries,
            );
        }

        /** Execute an Admin GraphQL operation and retain its cost metadata. */
        public function graphql(string $query, array $variables = []): array
        {
            $requestBody = json_encode(['query' => $query, 'variables' => (object) $variables], JSON_THROW_ON_ERROR);
            $detector = $this->getResponseErrorDetector();
            try {
                // Handle semantic GraphQL errors here so retries retain the original request body.
                $this->setResponseErrorDetector(null);
                for ($attempt = 0; ; $attempt++) {
                    $response = $this->performRequest(
                        method: 'POST', endpoint: 'graphql.json', verify: true,
                        body: $requestBody, headers: ['Content-Type' => 'application/json'],
                    );
                    $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($payload) && !empty($payload['errors'])) {
                        if (ShopifyErrorClassifier::isRetryable($payload) && $attempt < $this->getMaxRetries()) {
                            usleep(min($this->maxBackoffSleepMicroseconds, 1000000 * (2 ** min($attempt, 6))));
                            continue;
                        }
                        throw new \RuntimeException('Shopify GraphQL returned errors: '.json_encode($payload['errors']));
                    }
                    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
                        throw new \UnexpectedValueException('Invalid Shopify GraphQL response.');
                    }
                    $throttle = $payload['extensions']['cost']['throttleStatus'] ?? null;
                    if (is_array($throttle) && is_numeric($throttle['currentlyAvailable'] ?? null) && is_numeric($throttle['restoreRate'] ?? null)) {
                        $available = (float) $throttle['currentlyAvailable'];
                        $restoreRate = (float) $throttle['restoreRate'];
                        if ($available < 150 && $restoreRate > 0) {
                            usleep((int) min(5000000, max(0, ceil((150 - $available) / $restoreRate * 1000000))));
                        }
                    }
                    break;
                }
            } finally {
                $this->setResponseErrorDetector($detector);
            }
            return $payload;
        }

        /** Process a GraphQL connection page by page. The operation must accept $cursor. */
        public function graphqlPaginate(string $query, string $connection, callable $callback, array $variables = [], ?callable $shouldContinue = null): void
        {
            $cursor = $variables['cursor'] ?? null;
            $seen = [];
            if ($cursor !== null) {
                $seen[$cursor] = true;
            }
            do {
                if ($shouldContinue !== null && !$shouldContinue()) {
                    return;
                }
                $variables['cursor'] = $cursor;
                $response = $this->graphql($query, $variables);
                $page = $response['data'];
                foreach (explode('.', $connection) as $segment) {
                    $page = is_array($page) ? ($page[$segment] ?? null) : null;
                }
                if (!is_array($page) || !isset($page['pageInfo']['hasNextPage']) || !is_bool($page['pageInfo']['hasNextPage'])) {
                    throw new \UnexpectedValueException('Invalid Shopify GraphQL connection page.');
                }
                $callback($page);
                if (!$page['pageInfo']['hasNextPage']) {
                    return;
                }
                $cursor = $page['pageInfo']['endCursor'] ?? null;
                if (!is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                    throw new \UnexpectedValueException('Missing or repeated Shopify GraphQL cursor.');
                }
                $seen[$cursor] = true;
            } while (true);
        }

        /** Execute ShopifyQL; parse errors must not be mistaken for empty results. */
        public function shopifyqlQuery(string $query): array
        {
            $response = $this->graphql(
                'query ShopifyQL($query: String!) { shopifyqlQuery(query: $query) { tableData { columns { name dataType displayName } rows } parseErrors } }',
                ['query' => $query],
            );
            $result = $response['data']['shopifyqlQuery'] ?? null;
            if (!is_array($result)) {
                throw new \UnexpectedValueException('Missing ShopifyQL result.');
            }
            if (!empty($result['parseErrors'])) {
                throw new \RuntimeException('ShopifyQL parse errors: '.json_encode($result['parseErrors']));
            }
            if (!isset($result['tableData']) || !is_array($result['tableData'])) {
                throw new \UnexpectedValueException('Missing ShopifyQL table data.');
            }
            return $result['tableData'];
        }

        private function decodeRestCollection(Response $response, string $key): array
        {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload[$key]) || !is_array($payload[$key])) {
                throw new \UnexpectedValueException('Invalid Shopify REST collection response: '.$key);
            }
            return $payload;
        }

        protected function fetchShopifyAllAndProcess(
            callable $callback,
            string   $endpoint,
            array    $query = [],
            string   $dataKey = null,
        ): void
        {
            $nextPageInfo = null;
            $seenCursors = [];

            do {
                if ($nextPageInfo) {
                    // When using page_info, we MUST NOT include other parameters except limit and fields
                    $pageQuery = [
                        'page_info' => $nextPageInfo,
                        'limit'     => $query['limit'] ?? 250,
                    ];
                    if (isset($query['fields'])) {
                        $pageQuery['fields'] = $query['fields'];
                    }
                } else {
                    $pageQuery = $query;
                }

                $response = $this->performRequest(
                    method: 'GET',
                    endpoint: $endpoint,
                    query: $pageQuery,
                );

                $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || ($dataKey !== null && (!isset($data[$dataKey]) || !is_array($data[$dataKey])))) {
                    throw new \UnexpectedValueException('Invalid Shopify REST collection response.');
                }
                $items = $dataKey !== null ? $data[$dataKey] : $data;

                $callback($items);

                $nextPageInfo = $this->guardCursor($this->extractNextPageInfo($response), $seenCursors);
            } while ($nextPageInfo);
        }

        /**
         * @param Response $response
         * @return string|null
         */
        protected function extractNextPageInfo(Response $response): ?string
        {
            return $this->getCursorFromUrl($response->getHeaderLine('Link'));
        }

        private function guardCursor(?string $cursor, array &$seen): ?string
        {
            if ($cursor !== null && $cursor !== '') {
                if (isset($seen[$cursor])) {
                    throw new \UnexpectedValueException('Repeated Shopify pagination cursor.');
                }
                $seen[$cursor] = true;
                return $cursor;
            }
            return null;
        }

        /**
         * @param string|null $pageInfo
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param FinancialStatus|null $financialStatus
         * @param FulfillmentStatus|null $fulfillmentStatus
         * @param array|null $ids
         * @param int|null $limit
         * @param string|null $processedAtMin
         * @param string|null $processedAtMax
         * @param int|null $sinceId
         * @param Status|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param bool $includeHeaders
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getOrders(
            ?string            $pageInfo = null,
            ?string            $createdAtMin = null,
            ?string            $createdAtMax = null,
            ?array             $fields = null,
            ?FinancialStatus   $financialStatus = null,
            ?FulfillmentStatus $fulfillmentStatus = null,
            ?array             $ids = null,
            ?int               $limit = 250, // Max: 250,
            ?string            $processedAtMin = null,
            ?string            $processedAtMax = null,
            ?int               $sinceId = null,
            ?Status            $status = Status::any,
            ?string            $updatedAtMin = null,
            ?string            $updatedAtMax = null,
            bool               $includeHeaders = false,
            SortOptions        $sort = SortOptions::idAsc,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($createdAtMin) {
                    $query["created_at_min"] = $createdAtMin;
                }
                if ($createdAtMax) {
                    $query["created_at_max"] = $createdAtMax;
                }
                if ($financialStatus) {
                    $query["financial_status"] = $financialStatus->value;
                }
                if ($fulfillmentStatus) {
                    $query["fulfillment_status"] = $fulfillmentStatus->value;
                }
                if ($ids) {
                    $query["ids"] = implode(",", $ids);
                }
                if ($processedAtMin) {
                    $query["processed_at_min"] = $processedAtMin;
                }
                if ($processedAtMax) {
                    $query["processed_at_max"] = $processedAtMax;
                }
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
                if ($status) {
                    $query["status"] = $status->value;
                }
                if ($updatedAtMin) {
                    $query["updated_at_min"] = $updatedAtMin;
                }
                if ($updatedAtMax) {
                    $query["updated_at_max"] = $updatedAtMax;
                }
                if ($sort) {
                    $query["order"] = $sort->value;
                }
            }
            if ($fields) {
                $query["fields"] = implode(",", $fields);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "orders.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'orders'),
                ];
            }

            return $this->decodeRestCollection($response, 'orders');
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param FinancialStatus|null $financialStatus
         * @param FulfillmentStatus|null $fulfillmentStatus
         * @param array|null $ids
         * @param int|null $limit
         * @param string|null $processedAtMin
         * @param string|null $processedAtMax
         * @param int|null $sinceId
         * @param Status|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getOrdersAll(
            ?string            $createdAtMin = null,
            ?string            $createdAtMax = null,
            ?array             $fields = null,
            ?FinancialStatus   $financialStatus = null,
            ?FulfillmentStatus $fulfillmentStatus = null,
            ?array             $ids = null,
            ?int               $limit = 250,
            ?string            $processedAtMin = null,
            ?string            $processedAtMax = null,
            ?int               $sinceId = null,
            ?Status            $status = Status::any,
            ?string            $updatedAtMin = null,
            ?string            $updatedAtMax = null,
            SortOptions        $sort = SortOptions::idAsc,
        ): array
        {
            $orders = [];
            $this->getOrdersAllAndProcess(
                callback: function ($data) use (&$orders) {
                    $orders = array_merge($orders, $data);
                },
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                fields: $fields,
                financialStatus: $financialStatus,
                fulfillmentStatus: $fulfillmentStatus,
                ids: $ids,
                limit: $limit,
                processedAtMin: $processedAtMin,
                processedAtMax: $processedAtMax,
                sinceId: $sinceId,
                status: $status,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                sort: $sort,
            );

            return ['orders' => $orders];
        }

        /**
         * @param callable $callback
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param FinancialStatus|null $financialStatus
         * @param FulfillmentStatus|null $fulfillmentStatus
         * @param array|null $ids
         * @param int|null $limit
         * @param string|null $processedAtMin
         * @param string|null $processedAtMax
         * @param int|null $sinceId
         * @param Status|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param SortOptions $sort
         * @return void
         * @throws GuzzleException
         */
        public function getOrdersAllAndProcess(
            callable           $callback,
            ?string            $createdAtMin = null,
            ?string            $createdAtMax = null,
            ?array             $fields = null,
            ?FinancialStatus   $financialStatus = null,
            ?FulfillmentStatus $fulfillmentStatus = null,
            ?array             $ids = null,
            ?int               $limit = 250,
            ?string            $processedAtMin = null,
            ?string            $processedAtMax = null,
            ?int               $sinceId = null,
            ?Status            $status = Status::any,
            ?string            $updatedAtMin = null,
            ?string            $updatedAtMax = null,
            SortOptions        $sort = SortOptions::idAsc,
        ): void
        {
            $query = [];
            if ($createdAtMin) $query["created_at_min"] = $createdAtMin;
            if ($createdAtMax) $query["created_at_max"] = $createdAtMax;
            if ($financialStatus) $query["financial_status"] = $financialStatus->value;
            if ($fulfillmentStatus) $query["fulfillment_status"] = $fulfillmentStatus->value;
            if ($ids) $query["ids"] = implode(",", $ids);
            if ($processedAtMin) $query["processed_at_min"] = $processedAtMin;
            if ($processedAtMax) $query["processed_at_max"] = $processedAtMax;
            if ($sinceId) $query["since_id"] = $sinceId;
            if ($status) $query["status"] = $status->value;
            if ($updatedAtMin) $query["updated_at_min"] = $updatedAtMin;
            if ($updatedAtMax) $query["updated_at_max"] = $updatedAtMax;
            if ($sort) $query["order"] = $sort->value;
            if ($fields) $query["fields"] = implode(",", $fields);
            $query["limit"] = $limit;

            $this->fetchShopifyAllAndProcess(
                callback: $callback,
                endpoint: "orders.json",
                query: $query,
                dataKey: 'orders',
            );
        }

        /**
         * @param string|null $pageInfo
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param bool $includeHeaders
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getCustomers(
            ?string     $pageInfo = null,
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?array      $fields = null,
            ?array      $ids = null,
            ?int        $limit = 250, // Max: 250,
            ?int        $sinceId = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            bool        $includeHeaders = false,
            SortOptions $sort = SortOptions::idAsc,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($createdAtMin) {
                    $query["created_at_min"] = $createdAtMin;
                }
                if ($createdAtMax) {
                    $query["created_at_max"] = $createdAtMax;
                }
                if ($ids) {
                    $query["ids"] = implode(",", $ids);
                }
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
                if ($updatedAtMin) {
                    $query["updated_at_min"] = $updatedAtMin;
                }
                if ($updatedAtMax) {
                    $query["updated_at_max"] = $updatedAtMax;
                }
                if ($sort) {
                    $query["order"] = $sort->value;
                }
            }
            if ($fields) {
                $query["fields"] = implode(",", $fields);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "customers.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'customers'),
                ];
            }

            return $this->decodeRestCollection($response, 'customers');
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getCustomersAll(
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?array      $fields = null,
            ?array      $ids = null,
            ?int        $limit = 250,
            ?int        $sinceId = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            SortOptions $sort = SortOptions::idAsc,
        ): array
        {
            $customers = [];
            $this->getCustomersAllAndProcess(
                callback: function ($data) use (&$customers) {
                    $customers = array_merge($customers, $data);
                },
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                fields: $fields,
                ids: $ids,
                limit: $limit,
                sinceId: $sinceId,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                sort: $sort,
            );

            return ['customers' => $customers];
        }

        /**
         * @param callable $callback
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param SortOptions $sort
         * @return void
         * @throws GuzzleException
         */
        public function getCustomersAllAndProcess(
            callable    $callback,
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?array      $fields = null,
            ?array      $ids = null,
            ?int        $limit = 250,
            ?int        $sinceId = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            SortOptions $sort = SortOptions::idAsc,
        ): void
        {
            $query = [];
            if ($createdAtMin) $query["created_at_min"] = $createdAtMin;
            if ($createdAtMax) $query["created_at_max"] = $createdAtMax;
            if ($ids) $query["ids"] = implode(",", $ids);
            if ($sinceId) $query["since_id"] = $sinceId;
            if ($updatedAtMin) $query["updated_at_min"] = $updatedAtMin;
            if ($updatedAtMax) $query["updated_at_max"] = $updatedAtMax;
            if ($sort) $query["order"] = $sort->value;
            if ($fields) $query["fields"] = implode(",", $fields);
            $query["limit"] = $limit;

            $this->fetchShopifyAllAndProcess(
                callback: $callback,
                endpoint: "customers.json",
                query: $query,
                dataKey: 'customers',
            );
        }

        /**
         * @param string|null $pageInfo
         * @param string|null $collectionId
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param array|null $presentmentCurrencies
         * @param string|null $productType
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param int|null $sinceId
         * @param PublishedStatus|null $status
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $vendor
         * @param bool $includeHeaders
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getProducts(
            ?string          $pageInfo = null,
            ?string          $collectionId = null,
            ?string          $createdAtMin = null,
            ?string          $createdAtMax = null,
            ?array           $fields = null,
            ?array           $handle = null,
            ?array           $ids = null,
            ?int             $limit = 250, // Max: 250,
            ?array           $presentmentCurrencies = null,
            ?string          $productType = null,
            ?string          $publishedAtMin = null,
            ?string          $publishedAtMax = null,
            ?int             $sinceId = null,
            ?PublishedStatus $status = null,
            ?string          $title = null,
            ?string          $updatedAtMin = null,
            ?string          $updatedAtMax = null,
            ?string          $vendor = null,
            bool             $includeHeaders = false,
            SortOptions      $sort = SortOptions::idAsc,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($collectionId) {
                    $query["collection_id"] = $collectionId;
                }
                if ($createdAtMin) {
                    $query["created_at_min"] = $createdAtMin;
                }
                if ($createdAtMax) {
                    $query["created_at_max"] = $createdAtMax;
                }
                if ($handle) {
                    $query["handle"] = implode(",", $handle);
                }
                if ($ids) {
                    $query["ids"] = implode(",", $ids);
                }
                if ($presentmentCurrencies) {
                    $query["presentment_currencies"] = implode(",", $presentmentCurrencies);
                }
                if ($productType) {
                    $query["product_type"] = $productType;
                }
                if ($publishedAtMin) {
                    $query["published_at_min"] = $publishedAtMin;
                }
                if ($publishedAtMax) {
                    $query["published_at_max"] = $publishedAtMax;
                }
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
                if ($status) {
                    $query["status"] = $status->value;
                }
                if ($title) {
                    $query["title"] = $title;
                }
                if ($updatedAtMin) {
                    $query["updated_at_min"] = $updatedAtMin;
                }
                if ($updatedAtMax) {
                    $query["updated_at_max"] = $updatedAtMax;
                }
                if ($vendor) {
                    $query["vendor"] = $vendor;
                }
                if ($sort) {
                    $query["order"] = $sort->value;
                }
            }
            if ($fields) {
                $query["fields"] = implode(",", $fields);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "products.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'products'),
                ];
            }

            return $this->decodeRestCollection($response, 'products');
        }

        /**
         * @param string|null $collectionId
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param array|null $presentmentCurrencies
         * @param string|null $productType
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param int|null $sinceId
         * @param PublishedStatus|null $status
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $vendor
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getProductsAll(
            ?string          $collectionId = null,
            ?string          $createdAtMin = null,
            ?string          $createdAtMax = null,
            ?array           $fields = null,
            ?array           $handle = null,
            ?array           $ids = null,
            ?int             $limit = 250,
            ?array           $presentmentCurrencies = null,
            ?string          $productType = null,
            ?string          $publishedAtMin = null,
            ?string          $publishedAtMax = null,
            ?int             $sinceId = null,
            ?PublishedStatus $status = null,
            ?string          $title = null,
            ?string          $updatedAtMin = null,
            ?string          $updatedAtMax = null,
            ?string          $vendor = null,
            SortOptions      $sort = SortOptions::idAsc,
        ): array
        {
            $products = [];
            $this->getProductsAllAndProcess(
                callback: function ($data) use (&$products) {
                    $products = array_merge($products, $data);
                },
                collectionId: $collectionId,
                createdAtMin: $createdAtMin,
                createdAtMax: $createdAtMax,
                fields: $fields,
                handle: $handle,
                ids: $ids,
                limit: $limit,
                presentmentCurrencies: $presentmentCurrencies,
                productType: $productType,
                publishedAtMin: $publishedAtMin,
                publishedAtMax: $publishedAtMax,
                sinceId: $sinceId,
                status: $status,
                title: $title,
                updatedAtMin: $updatedAtMin,
                updatedAtMax: $updatedAtMax,
                vendor: $vendor,
                sort: $sort,
            );

            return ['products' => $products];
        }

        /**
         * @param callable $callback
         * @param string|null $collectionId
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param array|null $presentmentCurrencies
         * @param string|null $productType
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param int|null $sinceId
         * @param PublishedStatus|null $status
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $vendor
         * @param SortOptions $sort
         * @return void
         * @throws GuzzleException
         */
        public function getProductsAllAndProcess(
            callable         $callback,
            ?string          $collectionId = null,
            ?string          $createdAtMin = null,
            ?string          $createdAtMax = null,
            ?array           $fields = null,
            ?array           $handle = null,
            ?array           $ids = null,
            ?int             $limit = 250,
            ?array           $presentmentCurrencies = null,
            ?string          $productType = null,
            ?string          $publishedAtMin = null,
            ?string          $publishedAtMax = null,
            ?int             $sinceId = null,
            ?PublishedStatus $status = null,
            ?string          $title = null,
            ?string          $updatedAtMin = null,
            ?string          $updatedAtMax = null,
            ?string          $vendor = null,
            SortOptions      $sort = SortOptions::idAsc,
        ): void
        {
            $query = [];
            if ($collectionId) $query["collection_id"] = $collectionId;
            if ($createdAtMin) $query["created_at_min"] = $createdAtMin;
            if ($createdAtMax) $query["created_at_max"] = $createdAtMax;
            if ($handle) $query["handle"] = implode(",", $handle);
            if ($ids) $query["ids"] = implode(",", $ids);
            if ($presentmentCurrencies) $query["presentment_currencies"] = implode(",", $presentmentCurrencies);
            if ($productType) $query["product_type"] = $productType;
            if ($publishedAtMin) $query["published_at_min"] = $publishedAtMin;
            if ($publishedAtMax) $query["published_at_max"] = $publishedAtMax;
            if ($sinceId) $query["since_id"] = $sinceId;
            if ($status) $query["status"] = $status->value;
            if ($title) $query["title"] = $title;
            if ($updatedAtMin) $query["updated_at_min"] = $updatedAtMin;
            if ($updatedAtMax) $query["updated_at_max"] = $updatedAtMax;
            if ($vendor) $query["vendor"] = $vendor;
            if ($sort) $query["order"] = $sort->value;
            if ($fields) $query["fields"] = implode(",", $fields);
            $query["limit"] = $limit;

            $this->fetchShopifyAllAndProcess(
                callback: $callback,
                endpoint: "products.json",
                query: $query,
                dataKey: 'products',
            );
        }

        /**
         * @param int[] $ids
         * @param string|null $pageInfo
         * @param int|null $limit
         * @param bool $includeHeaders
         * @return array
         * @throws GuzzleException
         */
        public function getInventoryItems(
            array   $ids,
            ?string $pageInfo = null,
            ?int    $limit = 250, // Max: 250,
            bool    $includeHeaders = false,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($ids) {
                    $query["ids"] = implode(",", $ids);
                }
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "inventory_items.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'inventory_items'),
                ];
            }

            return $this->decodeRestCollection($response, 'inventory_items');
        }

        /**
         * @param int[] $ids
         * @param int|null $limit
         * @return array
         * @throws GuzzleException
         */
        public function getInventoryItemsAll(
            array $ids,
            ?int  $limit = 250,
        ): array
        {
            $items = [];
            $this->getInventoryItemsAllAndProcess(
                callback: function ($data) use (&$items) {
                    $items = array_merge($items, $data);
                },
                ids: $ids,
                limit: $limit,
            );

            return ['inventory_items' => $items];
        }

        /**
         * @param callable $callback
         * @param int[] $ids
         * @param int|null $limit
         * @return void
         * @throws GuzzleException
         */
        public function getInventoryItemsAllAndProcess(
            callable $callback,
            array    $ids,
            ?int     $limit = 250,
        ): void
        {
            foreach (array_chunk($ids, 250) as $chunk) {
                $this->fetchShopifyAllAndProcess(
                    callback: $callback,
                    endpoint: 'inventory_items.json',
                    query: ['ids' => implode(',', $chunk), 'limit' => $limit],
                    dataKey: 'inventory_items',
                );
            }
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param FinancialStatus|null $financialStatus
         * @param FulfillmentStatus|null $fulfillmentStatus
         * @param Status|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @return array
         * @throws GuzzleException
         */
        public function getOrdersCount(
            ?string            $createdAtMin = null,
            ?string            $createdAtMax = null,
            ?FinancialStatus   $financialStatus = null,
            ?FulfillmentStatus $fulfillmentStatus = null,
            ?Status            $status = Status::any,
            ?string            $updatedAtMin = null,
            ?string            $updatedAtMax = null,
        ): array
        {
            $query = [];

            if ($createdAtMin) {
                $query["created_at_min"] = $createdAtMin;
            }
            if ($createdAtMax) {
                $query["created_at_max"] = $createdAtMax;
            }
            if ($financialStatus) {
                $query["financial_status"] = $financialStatus->value;
            }
            if ($fulfillmentStatus) {
                $query["fulfillment_status"] = $fulfillmentStatus->value;
            }
            if ($status) {
                $query["status"] = $status->value;
            }
            if ($updatedAtMin) {
                $query["updated_at_min"] = $updatedAtMin;
            }
            if ($updatedAtMax) {
                $query["updated_at_max"] = $updatedAtMax;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "orders/count.json",
                query: $query,
            );

            // Return response
            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @return array
         * @throws GuzzleException
         */
        public function getCustomersCount(
            ?string $createdAtMin = null,
            ?string $createdAtMax = null,
            ?string $updatedAtMin = null,
            ?string $updatedAtMax = null,
        ): array
        {
            $query = [];

            if ($createdAtMin) {
                $query["created_at_min"] = $createdAtMin;
            }
            if ($createdAtMax) {
                $query["created_at_max"] = $createdAtMax;
            }
            if ($updatedAtMin) {
                $query["updated_at_min"] = $updatedAtMin;
            }
            if ($updatedAtMax) {
                $query["updated_at_max"] = $updatedAtMax;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "customers/count.json",
                query: $query,
            );

            // Return response
            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        }

        /**
         * @param string|null $collectionId
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param string|null $productType
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param PublishedStatus|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $vendor
         * @return array
         * @throws GuzzleException
         */
        public function getProductsCount(
            ?string          $collectionId = null,
            ?string          $createdAtMin = null,
            ?string          $createdAtMax = null,
            ?string          $productType = null,
            ?string          $publishedAtMin = null,
            ?string          $publishedAtMax = null,
            ?PublishedStatus $status = null,
            ?string          $updatedAtMin = null,
            ?string          $updatedAtMax = null,
            ?string          $vendor = null,
        ): array
        {
            $query = [];

            if ($collectionId) {
                $query["collection_id"] = $collectionId;
            }
            if ($createdAtMin) {
                $query["created_at_min"] = $createdAtMin;
            }
            if ($createdAtMax) {
                $query["created_at_max"] = $createdAtMax;
            }
            if ($productType) {
                $query["product_type"] = $productType;
            }
            if ($publishedAtMin) {
                $query["published_at_min"] = $publishedAtMin;
            }
            if ($publishedAtMax) {
                $query["published_at_max"] = $publishedAtMax;
            }
            if ($status) {
                $query["status"] = $status->value;
            }
            if ($updatedAtMin) {
                $query["updated_at_min"] = $updatedAtMin;
            }
            if ($updatedAtMax) {
                $query["updated_at_max"] = $updatedAtMax;
            }
            if ($vendor) {
                $query["vendor"] = $vendor;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "products/count.json",
                query: $query,
            );

            // Return response
            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param FinancialStatus|null $financialStatus
         * @param FulfillmentStatus|null $fulfillmentStatus
         * @param array|null $ids
         * @param int|null $limit
         * @param string|null $processedAtMin
         * @param string|null $processedAtMax
         * @param int|null $sinceId
         * @param Status|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $pageInfo
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getAllOrders(
            ?string            $createdAtMin = null,
            ?string            $createdAtMax = null,
            ?array             $fields = null,
            ?FinancialStatus   $financialStatus = null,
            ?FulfillmentStatus $fulfillmentStatus = null,
            ?array             $ids = null,
            ?int               $limit = 250, // Max: 250,
            ?string            $processedAtMin = null,
            ?string            $processedAtMax = null,
            ?int               $sinceId = null,
            ?Status            $status = Status::any,
            ?string            $updatedAtMin = null,
            ?string            $updatedAtMax = null,
            ?string            $pageInfo = null,
            SortOptions        $sort = SortOptions::idAsc,
        ): array
        {
            $orders = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getOrders(
                    pageInfo: $pageInfo,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    fields: $fields,
                    financialStatus: $financialStatus,
                    fulfillmentStatus: $fulfillmentStatus,
                    ids: $ids,
                    limit: $limit,
                    processedAtMin: $processedAtMin,
                    processedAtMax: $processedAtMax,
                    sinceId: $sinceId,
                    status: $status,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['orders'])) {
                    $orders = [...$orders, ...$response['body']['orders']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['orders' => $orders];
        }

        /**
         * @param string|null $collectionId
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param array|null $presentmentCurrencies
         * @param string|null $productType
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param int|null $sinceId
         * @param PublishedStatus|null $status
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $vendor
         * @param string|null $pageInfo
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getAllProducts(
            ?string          $collectionId = null,
            ?string          $createdAtMin = null,
            ?string          $createdAtMax = null,
            ?array           $fields = null,
            ?array           $handle = null,
            ?array           $ids = null,
            ?int             $limit = 250, // Max: 250,
            ?array           $presentmentCurrencies = null,
            ?string          $productType = null,
            ?string          $publishedAtMin = null,
            ?string          $publishedAtMax = null,
            ?int             $sinceId = null,
            ?PublishedStatus $status = null,
            ?string          $title = null,
            ?string          $updatedAtMin = null,
            ?string          $updatedAtMax = null,
            ?string          $vendor = null,
            ?string          $pageInfo = null,
            SortOptions      $sort = SortOptions::idAsc,
        ): array
        {
            $products = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getProducts(
                    pageInfo: $pageInfo,
                    collectionId: $collectionId,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    fields: $fields,
                    handle: $handle,
                    ids: $ids,
                    limit: $limit,
                    presentmentCurrencies: $presentmentCurrencies,
                    productType: $productType,
                    publishedAtMin: $publishedAtMin,
                    publishedAtMax: $publishedAtMax,
                    sinceId: $sinceId,
                    status: $status,
                    title: $title,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    vendor: $vendor,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['products'])) {
                    $products = [...$products, ...$response['body']['products']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['products' => $products];
        }

        /**
         * @param int[] $ids
         * @param int|null $limit
         * @return array
         * @throws GuzzleException
         */
        public function getAllInventoryItems(
            array $ids,
            ?int  $limit = 250, // Max: 250,
        ): array
        {
            return $this->getInventoryItemsAll($ids, $limit);
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $pageInfo
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getAllCustomers(
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?array      $fields = null,
            ?array      $ids = null,
            ?int        $limit = 250, // Max: 250,
            ?int        $sinceId = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            ?string     $pageInfo = null,
            SortOptions $sort = SortOptions::idAsc,
        ): array
        {
            $customers = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getCustomers(
                    pageInfo: $pageInfo,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    fields: $fields,
                    ids: $ids,
                    limit: $limit,
                    sinceId: $sinceId,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['customers'])) {
                    $customers = [...$customers, ...$response['body']['customers']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['customers' => $customers];
        }

        /**
         * @param int|null $priceRuleId
         * @param string|null $pageInfo
         * @param int|null $limit
         * @param bool $includeHeaders
         * @return array
         * @throws GuzzleException
         */
        public function getDiscountCodes(
            ?int    $priceRuleId,
            ?string $pageInfo = null,
            ?int    $limit = 250, // Max: 250,
            bool    $includeHeaders = false,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "price_rules/".$priceRuleId."/discount_codes.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'discount_codes'),
                ];
            }

            return $this->decodeRestCollection($response, 'discount_codes');
        }

        /**
         * @param int|null $priceRuleId
         * @param int|null $limit
         * @param string|null $pageInfo
         * @return array
         * @throws GuzzleException
         */
        public function getAllDiscountCodes(
            ?int    $priceRuleId,
            ?int    $limit = 250, // Max: 250,
            ?string $pageInfo = null,
        ): array
        {
            $discount_codes = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getDiscountCodes(
                    priceRuleId: $priceRuleId,
                    pageInfo: $pageInfo,
                    limit: $limit,
                    includeHeaders: true,
                );
                if (!empty($response['body']['discount_codes'])) {
                    $discount_codes = [...$discount_codes, ...$response['body']['discount_codes']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['discount_codes' => $discount_codes];
        }

        /**
         * @param string|null $pageInfo
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param string|null $endsAtMin
         * @param string|null $endsAtMax
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $startsAtMin
         * @param string|null $startsAtMax
         * @param int|null $timesUsed
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param bool $includeHeaders
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getPriceRules(
            ?string     $pageInfo = null,
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?string     $endsAtMin = null,
            ?string     $endsAtMax = null,
            ?int        $limit = 250, // Max: 250,
            ?int        $sinceId = null,
            ?string     $startsAtMin = null,
            ?string     $startsAtMax = null,
            ?int        $timesUsed = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            bool        $includeHeaders = false,
            SortOptions $sort = SortOptions::idAsc,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($createdAtMin) {
                    $query["created_at_min"] = $createdAtMin;
                }
                if ($createdAtMax) {
                    $query["created_at_max"] = $createdAtMax;
                }
                if ($endsAtMin) {
                    $query["ends_at_min"] = $endsAtMin;
                }
                if ($endsAtMax) {
                    $query["ends_at_max"] = $endsAtMax;
                }
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
                if ($startsAtMin) {
                    $query["starts_at_min"] = $startsAtMin;
                }
                if ($startsAtMax) {
                    $query["starts_at_max"] = $startsAtMax;
                }
                if ($timesUsed !== null) {
                    $query["times_used"] = $timesUsed;
                }
                if ($updatedAtMin) {
                    $query["updated_at_min"] = $updatedAtMin;
                }
                if ($updatedAtMax) {
                    $query["updated_at_max"] = $updatedAtMax;
                }
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "price_rules.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'price_rules'),
                ];
            }

            return $this->decodeRestCollection($response, 'price_rules');
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param string|null $endsAtMin
         * @param string|null $endsAtMax
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $startsAtMin
         * @param string|null $startsAtMax
         * @param int|null $timesUsed
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $pageInfo
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getAllPriceRules(
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?string     $endsAtMin = null,
            ?string     $endsAtMax = null,
            ?int        $limit = 250, // Max: 250,
            ?int        $sinceId = null,
            ?string     $startsAtMin = null,
            ?string     $startsAtMax = null,
            ?int        $timesUsed = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            ?string     $pageInfo = null,
            SortOptions $sort = SortOptions::idAsc,
        ): array
        {
            $price_rules = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getPriceRules(
                    pageInfo: $pageInfo,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    endsAtMin: $endsAtMin,
                    endsAtMax: $endsAtMax,
                    limit: $limit,
                    sinceId: $sinceId,
                    startsAtMin: $startsAtMin,
                    startsAtMax: $startsAtMax,
                    timesUsed: $timesUsed,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['price_rules'])) {
                    $price_rules = [...$price_rules, ...$response['body']['price_rules']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['price_rules' => $price_rules];
        }

        /**
         * @param string|null $pageInfo
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $productId
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param CollectionPublishedStatus|null $publishedStatus
         * @param int|null $sinceId
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param bool $includeHeaders
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getCustomCollections(
            ?string                    $pageInfo = null,
            ?array                     $fields = null,
            ?array                     $handle = null,
            ?array                     $ids = null,
            ?int                       $limit = 250, // Max: 250,
            ?int                       $productId = null,
            ?string                    $publishedAtMin = null,
            ?string                    $publishedAtMax = null,
            ?CollectionPublishedStatus $publishedStatus = null,
            ?int                       $sinceId = null,
            ?string                    $title = null,
            ?string                    $updatedAtMin = null,
            ?string                    $updatedAtMax = null,
            bool                       $includeHeaders = false,
            SortOptions                $sort = SortOptions::idAsc,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($handle) {
                    $query["handle"] = implode(",", $handle);
                }
                if ($ids) {
                    $query["ids"] = implode(",", $ids);
                }
                if ($productId) {
                    $query["product_id"] = $productId;
                }
                if ($publishedAtMin) {
                    $query["published_at_min"] = $publishedAtMin;
                }
                if ($publishedAtMax) {
                    $query["published_at_max"] = $publishedAtMax;
                }
                if ($publishedStatus) {
                    $query["published_status"] = $publishedStatus->value;
                }
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
                if ($title) {
                    $query["title"] = $title;
                }
                if ($updatedAtMin) {
                    $query["updated_at_min"] = $updatedAtMin;
                }
                if ($updatedAtMax) {
                    $query["updated_at_max"] = $updatedAtMax;
                }
                if ($sort) {
                    $query["order"] = $sort->value;
                }
            }
            if ($fields) {
                $query["fields"] = implode(",", $fields);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "custom_collections.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'custom_collections'),
                ];
            }

            return $this->decodeRestCollection($response, 'custom_collections');
        }

        /**
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $productId
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param CollectionPublishedStatus|null $publishedStatus
         * @param int|null $sinceId
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $pageInfo
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getAllCustomCollections(
            ?array                     $fields = null,
            ?array                     $handle = null,
            ?array                     $ids = null,
            ?int                       $limit = 250, // Max: 250,
            ?int                       $productId = null,
            ?string                    $publishedAtMin = null,
            ?string                    $publishedAtMax = null,
            ?CollectionPublishedStatus $publishedStatus = null,
            ?int                       $sinceId = null,
            ?string                    $title = null,
            ?string                    $updatedAtMin = null,
            ?string                    $updatedAtMax = null,
            ?string                    $pageInfo = null,
            SortOptions                $sort = SortOptions::idAsc,
        ): array
        {
            $custom_collections = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getCustomCollections(
                    pageInfo: $pageInfo,
                    fields: $fields,
                    handle: $handle,
                    ids: $ids,
                    limit: $limit,
                    productId: $productId,
                    publishedAtMin: $publishedAtMin,
                    publishedAtMax: $publishedAtMax,
                    publishedStatus: $publishedStatus,
                    sinceId: $sinceId,
                    title: $title,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['custom_collections'])) {
                    $custom_collections = [...$custom_collections, ...$response['body']['custom_collections']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['custom_collections' => $custom_collections];
        }

        /**
         * @param string|null $pageInfo
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $productId
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param CollectionPublishedStatus|null $publishedStatus
         * @param int|null $sinceId
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param bool $includeHeaders
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getSmartCollections(
            ?string                    $pageInfo = null,
            ?array                     $fields = null,
            ?array                     $handle = null,
            ?array                     $ids = null,
            ?int                       $limit = 250, // Max: 250,
            ?int                       $productId = null,
            ?string                    $publishedAtMin = null,
            ?string                    $publishedAtMax = null,
            ?CollectionPublishedStatus $publishedStatus = null,
            ?int                       $sinceId = null,
            ?string                    $title = null,
            ?string                    $updatedAtMin = null,
            ?string                    $updatedAtMax = null,
            bool                       $includeHeaders = false,
            SortOptions                $sort = SortOptions::idAsc,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($handle) {
                    $query["handle"] = implode(",", $handle);
                }
                if ($ids) {
                    $query["ids"] = implode(",", $ids);
                }
                if ($productId) {
                    $query["product_id"] = $productId;
                }
                if ($publishedAtMin) {
                    $query["published_at_min"] = $publishedAtMin;
                }
                if ($publishedAtMax) {
                    $query["published_at_max"] = $publishedAtMax;
                }
                if ($publishedStatus) {
                    $query["published_status"] = $publishedStatus->value;
                }
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
                if ($title) {
                    $query["title"] = $title;
                }
                if ($updatedAtMin) {
                    $query["updated_at_min"] = $updatedAtMin;
                }
                if ($updatedAtMax) {
                    $query["updated_at_max"] = $updatedAtMax;
                }
                if ($sort) {
                    $query["order"] = $sort->value;
                }
            }
            if ($fields) {
                $query["fields"] = implode(",", $fields);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "smart_collections.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'smart_collections'),
                ];
            }

            return $this->decodeRestCollection($response, 'smart_collections');
        }

        /**
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $productId
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param CollectionPublishedStatus|null $publishedStatus
         * @param int|null $sinceId
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $pageInfo
         * @param SortOptions $sort
         * @return array
         * @throws GuzzleException
         */
        public function getAllSmartCollections(
            ?array                     $fields = null,
            ?array                     $handle = null,
            ?array                     $ids = null,
            ?int                       $limit = 250, // Max: 250,
            ?int                       $productId = null,
            ?string                    $publishedAtMin = null,
            ?string                    $publishedAtMax = null,
            ?CollectionPublishedStatus $publishedStatus = null,
            ?int                       $sinceId = null,
            ?string                    $title = null,
            ?string                    $updatedAtMin = null,
            ?string                    $updatedAtMax = null,
            ?string                    $pageInfo = null,
            SortOptions                $sort = SortOptions::idAsc,
        ): array
        {
            $smart_collections = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getSmartCollections(
                    pageInfo: $pageInfo,
                    fields: $fields,
                    handle: $handle,
                    ids: $ids,
                    limit: $limit,
                    productId: $productId,
                    publishedAtMin: $publishedAtMin,
                    publishedAtMax: $publishedAtMax,
                    publishedStatus: $publishedStatus,
                    sinceId: $sinceId,
                    title: $title,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['smart_collections'])) {
                    $smart_collections = [...$smart_collections, ...$response['body']['smart_collections']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['smart_collections' => $smart_collections];
        }

        /**
         * @param string|null $pageInfo
         * @param int|null $limit
         * @param int|null $collectionId
         * @param bool $includeHeaders
         * @return array
         * @throws GuzzleException
         */
        public function getProductsForCollection(
            ?string $pageInfo = null,
            ?int    $limit = 250, // Max: 250,
            ?int    $collectionId = null,
            bool    $includeHeaders = false,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "collections/".$collectionId."/products.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'products'),
                ];
            }

            return $this->decodeRestCollection($response, 'products');
        }

        /**
         * @param int|null $limit
         * @param int|null $collectionId
         * @param string|null $pageInfo
         * @return array
         * @throws GuzzleException
         */
        public function getAllProductsForCollection(
            ?int    $limit = 250, // Max: 250,
            ?int    $collectionId = null,
            ?string $pageInfo = null,
        ): array
        {
            $products = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getProductsForCollection(
                    pageInfo: $pageInfo,
                    limit: $limit,
                    collectionId: $collectionId,
                    includeHeaders: true,
                );
                if (!empty($response['body']['products'])) {
                    $products = [...$products, ...$response['body']['products']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['products' => $products];
        }

        /**
         * @param string|null $pageInfo
         * @param array|null $fields
         * @param int|null $limit
         * @param int|null $sinceId
         * @param bool $includeHeaders
         * @return array
         * @throws GuzzleException
         */
        public function getCollects(
            ?string $pageInfo = null,
            ?array  $fields = null,
            ?int    $limit = 250, // Max: 250,
            ?int    $sinceId = null,
            bool    $includeHeaders = false,
        ): array
        {
            $query = [];

            if ($pageInfo) {
                $query["page_info"] = $pageInfo;
            } else {
                if ($sinceId) {
                    $query["since_id"] = $sinceId;
                }
            }
            if ($fields) {
                $query["fields"] = implode(",", $fields);
            }
            if ($limit) {
                $query["limit"] = $limit;
            }

            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "GET",
                endpoint: "collects.json",
                query: $query,
            );
            // Return response
            if ($includeHeaders) {
                return [
                    "headers" => $response->getHeaders(),
                    "body"    => $this->decodeRestCollection($response, 'collects'),
                ];
            }

            return $this->decodeRestCollection($response, 'collects');
        }

        /**
         * @param array|null $fields
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $pageInfo
         * @return array
         * @throws GuzzleException
         */
        public function getAllCollects(
            ?array  $fields = null,
            ?int    $limit = 250, // Max: 250,
            ?int    $sinceId = null,
            ?string $pageInfo = null,
        ): array
        {
            $collects = [];

            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getCollects(
                    pageInfo: $pageInfo,
                    fields: $fields,
                    limit: $limit,
                    sinceId: $sinceId,
                    includeHeaders: true,
                );
                if (!empty($response['body']['collects'])) {
                    $collects = [...$collects, ...$response['body']['collects']];
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));

            return ['collects' => $collects];
        }

        /**
         * @param string $link
         * @return string|null
         */
        public function getCursorFromUrl(string $link): string|null
        {
            $urlArray = explode(",", $link);
            $pageInfo = null;
            foreach ($urlArray as $url) {
                if (str_contains($url, 'rel="next"')) {
                    $pageInfo = explode(";", $url)[0];
                    $pageInfo = trim($pageInfo);
                    $pageInfo = str_replace(["<", ">"], "", $pageInfo);
                    break;
                }
            }
            if (!$pageInfo) {
                return null;
            }
            $query = parse_url($pageInfo, PHP_URL_QUERY);
            if (!is_string($query)) return null;
            parse_str($query, $params);

            return $params['page_info'] ?? null;
        }

        protected function getNextCursorLink(array $headers): ?string
        {
            foreach ($headers as $name => $values) {
                if (strcasecmp((string) $name, 'link') === 0) {
                    foreach ((array) $values as $value) {
                        $cursor = $this->getCursorFromUrl((string) $value);
                        if ($cursor !== null) return $cursor;
                    }
                }
            }
            return null;
        }

        /**
         * @param array $shopifyQl
         * @param string $source
         * @return array
         * @throws GuzzleException
         * @throws Exception
         * @link https://shopify.dev/docs/api/shopifyql/shopifyql-reference ShopifyQL reference.
         */
        public function getQuery(
            array  $shopifyQl,
            string $source = 'marketing-kpi-total-sales',
        ): array
        {
            $body = [
                'request_metadata[path]' => '/store/'.$this->storeName.'/marketing',
                'source'                 => $source,
                'token'                  => $this->analyticsToken,
            ];
            $body['q'] = $shopifyQl;
            // Request the spreadsheet data
            $response = $this->performRequest(
                method: "POST",
                endpoint: "queries",
                query: [
                    'beta'     => 'true',
                    'dataOnly' => 'true',
                ],
                form_params: $body,
                baseUrl: 'https://analytics.shopify.com/',
                headers: [
                    'Accept'          => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-Type'    => 'application/x-www-form-urlencoded;charset=UTF-8',
                ],
                customErrors: [
                    '403' => 'body',
                ],
                ignoreAuth: true,
            );
            // Return response
            $contents = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            if (isset($contents['message'])) {
                throw new Exception($contents['message']);
            }

            return $contents;
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param FinancialStatus|null $financialStatus
         * @param FulfillmentStatus|null $fulfillmentStatus
         * @param array|null $ids
         * @param int|null $limit
         * @param string|null $processedAtMin
         * @param string|null $processedAtMax
         * @param int|null $sinceId
         * @param Status|null $status
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param SortOptions $sort
         * @param callable|null $callback
         * @return void
         * @throws GuzzleException
         */
        public function getAllOrdersAndProcess(
            ?string            $createdAtMin = null,
            ?string            $createdAtMax = null,
            ?array             $fields = null,
            ?FinancialStatus   $financialStatus = null,
            ?FulfillmentStatus $fulfillmentStatus = null,
            ?array             $ids = null,
            ?int               $limit = 250,
            ?string            $processedAtMin = null,
            ?string            $processedAtMax = null,
            ?int               $sinceId = null,
            ?Status            $status = Status::any,
            ?string            $updatedAtMin = null,
            ?string            $updatedAtMax = null,
            SortOptions        $sort = SortOptions::idAsc,
            ?callable          $callback = null
        ): void
        {
            $pageInfo = null;
            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getOrders(
                    pageInfo: $pageInfo,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    fields: $fields,
                    financialStatus: $financialStatus,
                    fulfillmentStatus: $fulfillmentStatus,
                    ids: $ids,
                    limit: $limit,
                    processedAtMin: $processedAtMin,
                    processedAtMax: $processedAtMax,
                    sinceId: $sinceId,
                    status: $status,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['orders']) && $callback) {
                    $callback($response['body']['orders']);
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $ids
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param SortOptions $sort
         * @param callable|null $callback
         * @return void
         * @throws GuzzleException
         */
        public function getAllCustomersAndProcess(
            ?string     $createdAtMin = null,
            ?string     $createdAtMax = null,
            ?array      $fields = null,
            ?array      $ids = null,
            ?int        $limit = 250,
            ?int        $sinceId = null,
            ?string     $updatedAtMin = null,
            ?string     $updatedAtMax = null,
            SortOptions $sort = SortOptions::idAsc,
            ?callable   $callback = null
        ): void
        {
            $pageInfo = null;
            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getCustomers(
                    pageInfo: $pageInfo,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    fields: $fields,
                    ids: $ids,
                    limit: $limit,
                    sinceId: $sinceId,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['customers']) && $callback) {
                    $callback($response['body']['customers']);
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));
        }

        /**
         * @param string|null $collectionId
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param array|null $fields
         * @param array|null $handle
         * @param array|null $ids
         * @param int|null $limit
         * @param array|null $presentmentCurrencies
         * @param string|null $productType
         * @param string|null $publishedAtMin
         * @param string|null $publishedAtMax
         * @param int|null $sinceId
         * @param PublishedStatus|null $status
         * @param string|null $title
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $vendor
         * @param SortOptions $sort
         * @param callable|null $callback
         * @return void
         * @throws GuzzleException
         */
        public function getAllProductsAndProcess(
            ?string          $collectionId = null,
            ?string          $createdAtMin = null,
            ?string          $createdAtMax = null,
            ?array           $fields = null,
            ?array           $handle = null,
            ?array           $ids = null,
            ?int             $limit = 250,
            ?array           $presentmentCurrencies = null,
            ?string          $productType = null,
            ?string          $publishedAtMin = null,
            ?string          $publishedAtMax = null,
            ?int             $sinceId = null,
            ?PublishedStatus $status = null,
            ?string          $title = null,
            ?string          $updatedAtMin = null,
            ?string          $updatedAtMax = null,
            ?string          $vendor = null,
            SortOptions      $sort = SortOptions::idAsc,
            ?callable        $callback = null
        ): void
        {
            $pageInfo = null;
            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getProducts(
                    pageInfo: $pageInfo,
                    collectionId: $collectionId,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    fields: $fields,
                    handle: $handle,
                    ids: $ids,
                    limit: $limit,
                    presentmentCurrencies: $presentmentCurrencies,
                    productType: $productType,
                    publishedAtMin: $publishedAtMin,
                    publishedAtMax: $publishedAtMax,
                    sinceId: $sinceId,
                    status: $status,
                    title: $title,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    vendor: $vendor,
                    includeHeaders: true,
                    sort: $sort,
                );
                if (!empty($response['body']['products']) && $callback) {
                    $callback($response['body']['products']);
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));
        }

        /**
         * @param string|null $createdAtMin
         * @param string|null $createdAtMax
         * @param string|null $endsAtMin
         * @param string|null $endsAtMax
         * @param int|null $limit
         * @param int|null $sinceId
         * @param string|null $startsAtMin
         * @param string|null $startsAtMax
         * @param int|null $timesUsed
         * @param string|null $updatedAtMin
         * @param string|null $updatedAtMax
         * @param string|null $pageInfo
         * @param callable|null $callback
         * @return void
         * @throws GuzzleException
         */
        public function getAllPriceRulesAndProcess(
            ?string   $createdAtMin = null,
            ?string   $createdAtMax = null,
            ?string   $endsAtMin = null,
            ?string   $endsAtMax = null,
            ?int      $limit = 250,
            ?int      $sinceId = null,
            ?string   $startsAtMin = null,
            ?string   $startsAtMax = null,
            ?int      $timesUsed = null,
            ?string   $updatedAtMin = null,
            ?string   $updatedAtMax = null,
            ?string   $pageInfo = null,
            ?callable $callback = null
        ): void
        {
            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getPriceRules(
                    pageInfo: $pageInfo,
                    createdAtMin: $createdAtMin,
                    createdAtMax: $createdAtMax,
                    endsAtMin: $endsAtMin,
                    endsAtMax: $endsAtMax,
                    limit: $limit,
                    sinceId: $sinceId,
                    startsAtMin: $startsAtMin,
                    startsAtMax: $startsAtMax,
                    timesUsed: $timesUsed,
                    updatedAtMin: $updatedAtMin,
                    updatedAtMax: $updatedAtMax,
                    includeHeaders: true
                );
                if (!empty($response['body']['price_rules']) && $callback) {
                    $callback($response['body']['price_rules']);
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));
        }

        /**
         * @param string|int $priceRuleId
         * @param string|null $pageInfo
         * @param callable|null $callback
         * @return void
         * @throws GuzzleException
         */
        public function getAllDiscountCodesAndProcess(
            string|int $priceRuleId,
            ?string    $pageInfo = null,
            ?callable  $callback = null
        ): void
        {
            $seenCursors = $pageInfo !== null ? [$pageInfo => true] : [];
            do {
                $response = $this->getDiscountCodes(
                    priceRuleId: $priceRuleId,
                    pageInfo: $pageInfo,
                    includeHeaders: true
                );
                if (!empty($response['body']['discount_codes']) && $callback) {
                    $callback($response['body']['discount_codes']);
                }
            } while (isset($response['headers']) && ($pageInfo = $this->guardCursor($this->getNextCursorLink($response['headers']), $seenCursors)));
        }
    }
