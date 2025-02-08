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
     * @throws GuzzleException
     */
    public function __construct(
        string $apiKey,
        string $shopName,
        string $version = "2023-01",
        string $analyticsToken = "",
        string $accountType = 'standard',
    ) {
        $this->analyticsToken = $analyticsToken;
        $this->storeName = $shopName;
        $this->accountType = AccountType::from($accountType);
        return parent::__construct(
            baseUrl: "https://".$shopName.".myshopify.com/admin/api/".$version."/",
            apiKey: $apiKey,
            authSettings: [
                "location" => "header",
                "name" => "X-Shopify-Access-Token",
            ],
        );
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
     * @return Response
     * @throws GuzzleException
     */
    public function performRequest(
        string $method,
        string $endpoint,
        array $query = [],
        string|array $body = "",
        array $form_params = [],
        string $baseUrl = "",
        array $headers = [],
        array $additionalHeaders = [], // Ex: ["Amazon-Advertising-API-Scope" => 'profileId'];
        ?CookieJar $cookies = null,
        bool $verify = false,
        bool $allowNewToken = true,
        string $pathToSave = "",
        bool $stream = null,
        ?array $errorMessageNesting = null, // Ex: ['error' => ['message']]
        int $sleep = 0,
        array $customErrors = [], // Ex: ['403' => 'body'] or ['500' => 'code'] or ['404' => 'message']
        bool $ignoreAuth = false,
    ): Response {

        $sleep = match($this->accountType) {
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
        );
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
        ?string $pageInfo = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?FinancialStatus $financialStatus = null,
        ?FulfillmentStatus $fulfillmentStatus = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?string $processedAtMin = null,
        ?string $processedAtMax = null,
        ?int $sinceId = null,
        ?Status $status = Status::any,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        bool $includeHeaders = false,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?string $pageInfo = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        bool $includeHeaders = false,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?string $pageInfo = null,
        ?string $collectionId = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?array $presentmentCurrencies = null,
        ?string $productType = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?int $sinceId = null,
        ?PublishedStatus $status = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $vendor = null,
        bool $includeHeaders = false,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        array $ids,
        ?string $pageInfo = null,
        ?int $limit = 250, // Max: 250,
        bool $includeHeaders = false,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?FinancialStatus $financialStatus = null,
        ?FulfillmentStatus $fulfillmentStatus = null,
        ?Status $status = Status::any,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
    ): array {
        $query =[];

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
        return json_decode($response->getBody()->getContents(), true);
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
    ): array {
        $query =[];

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
        return json_decode($response->getBody()->getContents(), true);
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
        ?string $collectionId = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?string $productType = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?PublishedStatus $status = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $vendor = null,
    ): array {
        $query =[];

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
        return json_decode($response->getBody()->getContents(), true);
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
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?FinancialStatus $financialStatus = null,
        ?FulfillmentStatus $fulfillmentStatus = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?string $processedAtMin = null,
        ?string $processedAtMax = null,
        ?int $sinceId = null,
        ?Status $status = Status::any,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $orders = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?string $collectionId = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?array $presentmentCurrencies = null,
        ?string $productType = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?int $sinceId = null,
        ?PublishedStatus $status = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $vendor = null,
        ?string $pageInfo = null,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $products = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?int $limit = 250, // Max: 250,
    ): array {
        $inventoryItems = [];

        $idsChunks = array_chunk($ids, 250);
        $page = 0;

        do {
            $response = $this->getInventoryItems(
                ids: $idsChunks[$page],
                limit: $limit,
            );
            if (!empty($response['inventory_items'])) {
                $inventoryItems = [...$inventoryItems, ...$response['inventory_items']];
            }
            $page++;
        } while ($page < count($idsChunks));

        return ['inventory_items' => $inventoryItems];
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
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?array $fields = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $customers = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?int $priceRuleId,
        ?string $pageInfo = null,
        ?int $limit = 250, // Max: 250,
        bool $includeHeaders = false,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param int|null $priceRuleId
     * @param int|null $limit
     * @param string|null $pageInfo
     * @return array
     * @throws GuzzleException
     */
    public function getAllDiscountCodes(
        ?int $priceRuleId,
        ?int $limit = 250, // Max: 250,
        ?string $pageInfo = null,
    ): array {
        $discount_codes = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?string $pageInfo = null,
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?string $endsAtMin = null,
        ?string $endsAtMax = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $startsAtMin = null,
        ?string $startsAtMax = null,
        ?int $timesUsed = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        bool $includeHeaders = false,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $query =[];

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
            if ($timesUsed) {
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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?string $createdAtMin = null,
        ?string $createdAtMax = null,
        ?string $endsAtMin = null,
        ?string $endsAtMax = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $startsAtMin = null,
        ?string $startsAtMax = null,
        ?int $timesUsed = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $price_rules = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?string $pageInfo = null,
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $productId = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?CollectionPublishedStatus $publishedStatus = null,
        ?int $sinceId = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        bool $includeHeaders = false,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $productId = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?CollectionPublishedStatus $publishedStatus = null,
        ?int $sinceId = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $custom_collections = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?string $pageInfo = null,
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $productId = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?CollectionPublishedStatus $publishedStatus = null,
        ?int $sinceId = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        bool $includeHeaders = false,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?array $fields = null,
        ?array $handle = null,
        ?array $ids = null,
        ?int $limit = 250, // Max: 250,
        ?int $productId = null,
        ?string $publishedAtMin = null,
        ?string $publishedAtMax = null,
        ?CollectionPublishedStatus $publishedStatus = null,
        ?int $sinceId = null,
        ?string $title = null,
        ?string $updatedAtMin = null,
        ?string $updatedAtMax = null,
        ?string $pageInfo = null,
        SortOptions $sort = SortOptions::idAsc,
    ): array {
        $smart_collections = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?int $limit = 250, // Max: 250,
        ?int $collectionId = null,
        bool $includeHeaders = false,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param int|null $limit
     * @param int|null $collectionId
     * @param string|null $pageInfo
     * @return array
     * @throws GuzzleException
     */
    public function getAllProductsForCollection(
        ?int $limit = 250, // Max: 250,
        ?int $collectionId = null,
        ?string $pageInfo = null,
    ): array {
        $products = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        ?array $fields = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        bool $includeHeaders = false,
    ): array {
        $query =[];

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
                "body" => json_decode($response->getBody()->getContents(), true),
            ];
        }
        return json_decode($response->getBody()->getContents(), true);
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
        ?array $fields = null,
        ?int $limit = 250, // Max: 250,
        ?int $sinceId = null,
        ?string $pageInfo = null,
    ): array {
        $collects = [];

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
        } while (isset($response['headers']) && ($pageInfo = $this->getNextCursorLink($response['headers'])));

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
        parse_str($query, $params);
        return $params['page_info'] ?? null;
    }

    protected function getNextCursorLink(array $headers): ?string
    {
        if (
            isset($headers['link']) &&
            isset($headers['link'][0]) &&
            $headers['link'][0] &&
            ($headers['link'][0] != "null") &&
            $pageInfo = $this->getCursorFromUrl($headers['link'][0])
        ) {
            return $pageInfo;
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
        array $shopifyQl,
        string $source = 'marketing-kpi-total-sales',
    ): array {
        $headers = $this->headers;
        $this->headers = [];
        $body = [
            'request_metadata[path]' => '/store/'.$this->storeName.'/marketing',
            'source' => $source,
            'token' => $this->analyticsToken,
        ];
        foreach($shopifyQl as $value) {
            $body = [...$body, 'q[]' => $value];
        }
        // Request the spreadsheet data
        $response = $this->performRequest(
            method: "POST",
            endpoint: "queries",
            query: [
                'beta' => 'true',
                'dataOnly' => 'true',
            ],
            form_params: $body,
            baseUrl: 'https://analytics.shopify.com/',
            headers: [
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            ],
            customErrors: [
                '403' => 'body',
            ],
        );
        $this->headers = $headers;
        // Return response
        $contents = json_decode($response->getBody()->getContents(), true);
        if (isset($contents['message'])) {
            throw new Exception($contents['message']);
        }
        return $contents;
    }
}
