<?php

    declare(strict_types=1);

    namespace Anibalealvarezs\ShopifyApi\Support;

    use Exception;
    use GuzzleHttp\Exception\RequestException;

    final class ShopifyErrorClassifier
    {
        /**
         * @param mixed $input
         * @return array<string, mixed>
         */
        public static function normalize(mixed $input): array
        {
            $payload = self::extractPayload($input);
            $errors = $payload['errors'] ?? null;

            $message = null;
            if (is_string($errors) || is_numeric($errors)) {
                $message = trim((string)$errors);
            } elseif (is_array($errors)) {
                $message = trim((string)json_encode($errors));
            }

            return [
                'message' => $message !== '' ? $message : self::extractMessageFallback($input),
                'status'  => self::extractStatusCode($input),
                'raw'     => $errors,
            ];
        }

        /**
         * @param mixed $input
         * @return array<string, mixed>
         */
        public static function classify(mixed $input): array
        {
            $normalized = self::normalize($input);
            $message = strtolower((string)($normalized['message'] ?? ''));
            $status = $normalized['status'];

            if (
                in_array($status, [429, 430], true)
                || str_contains($message, 'rate limit')
                || str_contains($message, 'too many requests')
                || str_contains($message, 'throttled')
                || str_contains($message, 'exceeded 2 calls per second')
            ) {
                return [
                    'category'     => 'retryable',
                    'reason'       => 'shopify_rate_limit',
                    'should_retry' => true,
                    'delay_ms'     => 1000,
                ];
            }

            return [
                'category'     => 'unknown',
                'reason'       => 'shopify_unknown',
                'should_retry' => false,
                'delay_ms'     => 0,
            ];
        }

        public static function isRetryable(mixed $input): bool
        {
            return self::classify($input)['should_retry'] === true;
        }

        /**
         * @param mixed $input
         * @return array<string, mixed>
         */
        private static function extractPayload(mixed $input): array
        {
            if (is_array($input)) {
                return $input;
            }

            if ($input instanceof RequestException && $input->hasResponse()) {
                $body = $input->getResponse()->getBody();
                $body->rewind();
                $contents = json_decode($body->getContents(), true);
                $body->rewind();

                return is_array($contents) ? $contents : [];
            }

            if ($input instanceof Exception) {
                $prev = $input->getPrevious();
                if ($prev instanceof RequestException && $prev->hasResponse()) {
                    return self::extractPayload($prev);
                }

                $fromMessage = json_decode($input->getMessage(), true);

                return is_array($fromMessage) ? $fromMessage : [];
            }

            if (is_string($input)) {
                $contents = json_decode($input, true);

                return is_array($contents) ? $contents : [];
            }

            return [];
        }

        private static function extractMessageFallback(mixed $input): ?string
        {
            if ($input instanceof Exception) {
                return $input->getMessage();
            }

            if (is_string($input) || is_numeric($input)) {
                $normalized = trim((string)$input);

                return $normalized === '' ? null : $normalized;
            }

            return null;
        }

        private static function extractStatusCode(mixed $input): ?int
        {
            if ($input instanceof RequestException && $input->hasResponse()) {
                return $input->getResponse()->getStatusCode();
            }

            if ($input instanceof Exception && is_numeric($input->getCode()) && $input->getCode() > 0) {
                return (int)$input->getCode();
            }

            if (is_array($input) && isset($input['status']) && is_numeric($input['status'])) {
                return (int)$input['status'];
            }

            return null;
        }
    }

