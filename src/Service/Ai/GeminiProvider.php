<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiProvider implements AiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function generate(AiRequest $request): AiResponse
    {
        $apiKey = trim((string) ($this->systemConfigService->get('EsmxShopAuditAi.config.geminiApiKey') ?? ''));

        if ($apiKey === '') {
            throw new AiProviderException('Gemini API key is not configured.');
        }

        $model = trim($request->getModel());

        if ($model === '') {
            throw new AiProviderException('Gemini model is not configured.');
        }

        $baseUrl = rtrim((string) ($request->getBaseUrl() ?: self::DEFAULT_BASE_URL), '/');
        $url = sprintf('%s/models/%s:generateContent', $baseUrl, rawurlencode($model));

        $this->logger->debug('Gemini executive summary request started', [
            'provider' => $this->getName(),
            'model' => $model,
            'maxOutputTokens' => $request->getMaxTokens(),
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'query' => [
                    'key' => $apiKey,
                ],
                'timeout' => $request->getTimeout(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                [
                                    'text' => $request->getPrompt(),
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => $request->getMaxTokens(),
                        'temperature' => $request->getTemperature(),
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Gemini executive summary failed: transport error', [
                'provider' => $this->getName(),
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            throw new AiProviderException('Gemini request failed.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->logger->warning('Gemini executive summary failed: response parse error', [
                'provider' => $this->getName(),
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            throw new AiProviderException('Gemini response could not be read.', 0, $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $payload['error']['message'] ?? 'Gemini request failed.';
            $safeMessage = \is_string($message) ? $message : 'Gemini request failed.';

            $this->logger->warning('Gemini executive summary failed: ' . $safeMessage, [
                'provider' => $this->getName(),
                'model' => $model,
                'statusCode' => $statusCode,
            ]);

            throw new AiProviderException($safeMessage);
        }

        $text = $this->extractText($payload, $model);

        if (trim($text) === '') {
            $this->logger->warning('Gemini executive summary returned empty text', [
                'provider' => $this->getName(),
                'model' => $model,
            ]);

            throw new AiProviderException('Gemini returned an empty response.');
        }

        return new AiResponse($this->getName(), $model, trim($text));
    }

    private function extractText(array $payload, string $model): string
    {
        $candidates = $payload['candidates'] ?? [];
        $candidateCount = \is_array($candidates) ? \count($candidates) : 0;
        $firstCandidate = $candidateCount > 0 && \is_array($candidates[0] ?? null) ? $candidates[0] : [];
        $finishReason = \is_string($firstCandidate['finishReason'] ?? null) ? $firstCandidate['finishReason'] : null;

        $this->logger->debug('Gemini response candidates count: ' . $candidateCount, [
            'provider' => $this->getName(),
            'model' => $model,
            'hasCandidates' => $candidateCount > 0,
        ]);

        $this->logger->debug('Gemini finishReason: ' . ($finishReason ?? 'none'), [
            'provider' => $this->getName(),
            'model' => $model,
            'finishReason' => $finishReason,
        ]);

        if ($candidateCount === 0) {
            $blockReason = $payload['promptFeedback']['blockReason'] ?? null;

            if (\is_string($blockReason) && $blockReason !== '') {
                throw new AiProviderException('Gemini blocked the response: ' . $blockReason . '.');
            }

            throw new AiProviderException('Gemini returned no candidates.');
        }

        $textParts = [];

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }

            $parts = $candidate['content']['parts'] ?? [];

            if (!\is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                if (!\is_array($part)) {
                    continue;
                }

                $text = $part['text'] ?? null;

                if (\is_string($text) && trim($text) !== '') {
                    $textParts[] = trim($text);
                }
            }
        }

        $text = trim(implode("\n\n", $textParts));
        $textLength = mb_strlen($text);

        $this->logger->debug('Gemini extracted summary length: ' . $textLength, [
            'provider' => $this->getName(),
            'model' => $model,
            'finishReason' => $finishReason,
            'generatedTextLength' => $textLength,
            'parsedSummaryEmpty' => $text === '',
        ]);

        if ($text === '') {
            if (\in_array($finishReason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
                throw new AiProviderException('Gemini blocked the response because of safety settings.');
            }

            if ($finishReason !== null && $finishReason !== 'STOP') {
                throw new AiProviderException('Gemini returned no text. Finish reason: ' . $finishReason . '.');
            }
        }

        if ($finishReason === 'MAX_TOKENS') {
            throw new AiProviderException('Gemini stopped because the configured max output tokens were reached. Increase Max tokens and retry.');
        }

        if ($finishReason !== null && !\in_array($finishReason, ['STOP', 'FINISH_REASON_UNSPECIFIED'], true)) {
            throw new AiProviderException('Gemini response was incomplete. Finish reason: ' . $finishReason . '.');
        }

        return $text;
    }
}
