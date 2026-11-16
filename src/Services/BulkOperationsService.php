<?php

declare(strict_types=1);

namespace Anibalealvarezs\ShopifyApi\Services;

use Anibalealvarezs\ShopifyApi\ShopifyApi;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\StreamWrapper;

final class BulkOperationsService
{
    public function __construct(private ShopifyApi $client, private ?Client $downloadClient = null) {}

    public function start(string $query): array
    {
        $response = $this->client->graphql(
            'mutation BulkQuery($query: String!) { bulkOperationRunQuery(query: $query) { bulkOperation { id status } userErrors { field message } } }',
            ['query' => $query],
        );
        $result = $response['data']['bulkOperationRunQuery'] ?? null;
        if (!is_array($result) || !empty($result['userErrors']) || empty($result['bulkOperation']['id'])) {
            throw new \RuntimeException('Shopify bulk operation could not be started: '.json_encode($result['userErrors'] ?? []));
        }
        return $result['bulkOperation'];
    }

    public function status(string $id): array
    {
        $response = $this->client->graphql(
            'query BulkStatus($id: ID!) { node(id: $id) { ... on BulkOperation { id status errorCode objectCount url partialDataUrl } } }',
            ['id' => $id],
        );
        $operation = $response['data']['node'] ?? null;
        if (!is_array($operation) || empty($operation['status'])) {
            throw new \UnexpectedValueException('Shopify bulk operation was not found.');
        }
        return $operation;
    }

    /** Poll with a bounded number of attempts; cancellation and failure are explicit. */
    public function wait(string $id, int $maxAttempts = 60, int $intervalMilliseconds = 1000, ?callable $shouldContinue = null): array
    {
        if ($maxAttempts < 1 || $intervalMilliseconds < 0) {
            throw new \InvalidArgumentException('Invalid bulk polling limits.');
        }
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($shouldContinue !== null && !$shouldContinue()) {
                throw new \RuntimeException('Shopify bulk operation polling cancelled.');
            }
            $operation = $this->status($id);
            if ($operation['status'] === 'COMPLETED') {
                return $operation;
            }
            if (in_array($operation['status'], ['FAILED', 'CANCELED', 'EXPIRED'], true)) {
                throw new \RuntimeException('Shopify bulk operation ended with status '.$operation['status'].'.');
            }
            if ($attempt + 1 < $maxAttempts && $intervalMilliseconds > 0) {
                usleep($intervalMilliseconds * 1000);
            }
        }
        throw new \RuntimeException('Shopify bulk operation polling timed out.');
    }

    /** Download a completed operation's signed result URL without forwarding API credentials. */
    public function download(array $operation, callable $callback, ?callable $shouldContinue = null): void
    {
        if (($operation['status'] ?? null) !== 'COMPLETED') {
            throw new \InvalidArgumentException('Bulk operation is not complete.');
        }
        $url = $operation['url'] ?? null;
        if ($url === null && (string) ($operation['objectCount'] ?? '') === '0') {
            return;
        }
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \UnexpectedValueException('Invalid Shopify bulk result URL.');
        }
        $client = $this->downloadClient ?? new Client();
        try {
            $response = $client->request('GET', $url, ['stream' => true, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 120, 'connect_timeout' => 15]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new \RuntimeException('Shopify bulk result download failed due to a network error.');
        }
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Shopify bulk result download failed with HTTP '.$response->getStatusCode().'.');
        }
        $stream = StreamWrapper::getResource($response->getBody());
        try {
            $this->processStream($stream, $callback, $shouldContinue);
        } finally {
            fclose($stream);
        }
    }

    /** Consume a caller-owned readable JSONL stream without buffering the result. */
    public function processStream($stream, callable $callback, ?callable $shouldContinue = null): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected a readable stream.');
        }
        while ($shouldContinue === null || $shouldContinue()) {
            $line = fgets($stream);
            if ($line === false) {
                if (!feof($stream)) {
                    throw new \RuntimeException('Failed reading Shopify bulk data.');
                }
                return;
            }
            if (trim($line) === '') {
                continue;
            }
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($record)) {
                throw new \UnexpectedValueException('Invalid Shopify bulk record.');
            }
            $callback($record);
        }
        throw new \RuntimeException('Shopify bulk data processing cancelled.');
    }
}
