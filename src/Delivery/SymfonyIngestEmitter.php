<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

use Armature\McpAnalytics\Contract\Json;
use Armature\McpAnalytics\Version;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SymfonyIngestEmitter implements EmitterInterface
{
    public const MAX_ATTEMPTS = 2;
    public const RETRY_DELAY_MS = 100;

    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly string $endpointUrl,
        private readonly string $apiKey,
        private readonly int $timeoutMs = 5_000,
        ?HttpClientInterface $client = null,
        private readonly mixed $delay = null,
    ) {
        if ('' === \trim($this->apiKey)) {
            throw new \InvalidArgumentException('An analytics API key is required.');
        }
        if ($this->timeoutMs < 1) {
            throw new \InvalidArgumentException('timeoutMs must be at least 1.');
        }
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * @param array{schema_version: 1, events: list<array<string, mixed>>} $batch
     */
    public function emit(array $batch): void
    {
        $attempt = 1;
        while (true) {
            try {
                $response = $this->client->request('POST', $this->endpointUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'armature-mcp-analytics-php/' . Version::current(),
                    ],
                    'body' => Json::encode($batch),
                    'timeout' => $this->timeoutMs / 1_000,
                    'max_duration' => $this->timeoutMs / 1_000,
                ]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                if ($status >= 200 && $status < 300) {
                    $rejection = IngestResponse::rejection($body, \count($batch['events']), $attempt);
                    if (null !== $rejection) {
                        throw $rejection;
                    }

                    return;
                }

                $retryable = 429 === $status || $status >= 500;
                $error = new DeliveryError(
                    IngestResponse::httpErrorCode($body, $status),
                    $status,
                    $retryable,
                    $attempt,
                );
                if (!$retryable || self::MAX_ATTEMPTS === $attempt) {
                    throw $error;
                }
            } catch (DeliveryError $error) {
                if (!$error->retryable || self::MAX_ATTEMPTS === $attempt) {
                    throw $error;
                }
            } catch (TransportExceptionInterface $error) {
                if (self::MAX_ATTEMPTS === $attempt) {
                    throw new DeliveryError('ingest_transport_error', null, true, $attempt, $error);
                }
            }

            $this->wait();
            ++$attempt;
        }
    }

    private function wait(): void
    {
        if (\is_callable($this->delay)) {
            ($this->delay)(self::RETRY_DELAY_MS);

            return;
        }
        \usleep(self::RETRY_DELAY_MS * 1_000);
    }
}
