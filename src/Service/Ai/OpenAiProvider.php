<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiProvider implements AiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function generate(AiRequest $request): AiResponse
    {
        $apiKey = trim((string) ($this->systemConfigService->get('EsmxShopAuditAi.config.openAiApiKey') ?? ''));

        if ($apiKey === '') {
            throw new AiProviderException('OpenAI API key is not configured.');
        }

        $baseUrl = rtrim((string) ($request->getBaseUrl() ?: self::DEFAULT_BASE_URL), '/');
        $url = $baseUrl . '/chat/completions';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'auth_bearer' => $apiKey,
                'timeout' => $request->getTimeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $request->getModel(),
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $request->getPrompt(),
                        ],
                    ],
                    'max_tokens' => $request->getMaxTokens(),
                    'temperature' => $request->getTemperature(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new AiProviderException('OpenAI request failed: ' . $exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            throw new AiProviderException('OpenAI response could not be read.', 0, $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $payload['error']['message'] ?? 'OpenAI request failed.';

            throw new AiProviderException(\is_string($message) ? $message : 'OpenAI request failed.');
        }

        $text = $payload['choices'][0]['message']['content'] ?? $payload['choices'][0]['text'] ?? '';

        if (!\is_string($text) || trim($text) === '') {
            throw new AiProviderException('OpenAI returned an empty response.');
        }

        return new AiResponse($this->getName(), $request->getModel(), trim($text));
    }
}
