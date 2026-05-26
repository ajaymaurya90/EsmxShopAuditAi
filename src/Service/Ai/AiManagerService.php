<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class AiManagerService
{
    private const DEFAULT_PROVIDER = 'gemini';
    private const DEFAULT_OPENAI_MODEL = 'gpt-4.1-mini';
    private const DEFAULT_OPENAI_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_GEMINI_MODEL = 'gemini-2.0-flash';
    private const DEFAULT_GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
    private const DEFAULT_MAX_TOKENS = 600;
    private const DEFAULT_TEMPERATURE = 0.2;
    private const DEFAULT_TIMEOUT = 20;

    /**
     * @var array<string, AiProviderInterface>
     */
    private readonly array $providers;

    /**
     * @param iterable<AiProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
        $indexedProviders = [];

        foreach ($providers as $provider) {
            $indexedProviders[$provider->getName()] = $provider;
        }

        $this->providers = $indexedProviders;
    }

    public function testConnection(): array
    {
        return $this->generateConfiguredText('Reply with OK only.', 'AI connection successful.', false);
    }

    public function generateText(string $prompt, string $successMessage = 'AI generation successful.', int $minimumMaxTokens = 0): array
    {
        return $this->generateConfiguredText($prompt, $successMessage, true, $minimumMaxTokens);
    }

    private function generateConfiguredText(string $prompt, string $successMessage, bool $includeText, int $minimumMaxTokens = 0): array
    {
        $providerName = $this->getStringConfig('aiProvider', self::DEFAULT_PROVIDER);
        $providerConfig = $this->resolveProviderConfig($providerName);
        $model = $providerConfig['model'];
        $testedAt = (new \DateTimeImmutable())->format(DATE_ATOM);

        if (!$this->getBoolConfig('enableAi', false)) {
            return $this->buildResult(false, $providerName, $model, 'AI is disabled in plugin settings.', $testedAt);
        }

        if (!\in_array($providerName, ['openai', 'gemini'], true)) {
            return $this->buildResult(false, $providerName, $model, 'Selected AI provider is not implemented yet.', $testedAt);
        }

        $provider = $this->providers[$providerName] ?? null;

        if (!$provider instanceof AiProviderInterface) {
            return $this->buildResult(false, $providerName, $model, 'Selected AI provider is unavailable.', $testedAt);
        }

        if ($this->getStringConfig($providerConfig['apiKeyConfigKey'], '') === '') {
            return $this->buildResult(false, $providerName, $model, $providerConfig['missingApiKeyMessage'], $testedAt);
        }

        $configuredMaxTokens = $this->getPositiveIntConfig('aiMaxTokens', self::DEFAULT_MAX_TOKENS);
        $effectiveMaxTokens = max($configuredMaxTokens, $minimumMaxTokens);

        $request = new AiRequest(
            prompt: $prompt,
            model: $model,
            maxTokens: $effectiveMaxTokens,
            temperature: $this->getFloatConfig('aiTemperature', self::DEFAULT_TEMPERATURE),
            timeout: $this->getPositiveIntConfig('aiTimeout', self::DEFAULT_TIMEOUT),
            baseUrl: $providerConfig['baseUrl']
        );

        try {
            $response = $provider->generate($request);

            return $this->buildResult(
                trim($response->getText()) !== '',
                $response->getProvider(),
                $response->getModel(),
                $successMessage,
                $testedAt,
                $includeText ? $response->getText() : null
            );
        } catch (AiProviderException $exception) {
            $this->logger->warning('EsmxShopAuditAi AI connection test failed', [
                'provider' => $providerName,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return $this->buildResult(false, $providerName, $model, $exception->getMessage(), $testedAt);
        } catch (\Throwable $exception) {
            $this->logger->error('EsmxShopAuditAi AI connection test failed unexpectedly', [
                'provider' => $providerName,
                'model' => $model,
                'exception' => $exception,
            ]);

            return $this->buildResult(false, $providerName, $model, 'AI connection failed.', $testedAt);
        }
    }

    private function buildResult(bool $success, string $provider, string $model, string $message, string $testedAt, ?string $text = null): array
    {
        $result = [
            'success' => $success,
            'provider' => $provider,
            'model' => $model,
            'message' => $message,
            'testedAt' => $testedAt,
        ];

        if ($text !== null) {
            $result['text'] = $text;
        }

        return $result;
    }

    /**
     * @return array{model: string, baseUrl: string, apiKeyConfigKey: string, missingApiKeyMessage: string}
     */
    private function resolveProviderConfig(string $providerName): array
    {
        if ($providerName === 'gemini') {
            return [
                'model' => $this->getStringConfig('geminiModel', self::DEFAULT_GEMINI_MODEL),
                'baseUrl' => $this->getStringConfig('geminiBaseUrl', self::DEFAULT_GEMINI_BASE_URL),
                'apiKeyConfigKey' => 'geminiApiKey',
                'missingApiKeyMessage' => 'Gemini API key is not configured.',
            ];
        }

        return [
            'model' => $this->getStringConfig('openAiModel', self::DEFAULT_OPENAI_MODEL),
            'baseUrl' => $this->getStringConfig('openAiBaseUrl', self::DEFAULT_OPENAI_BASE_URL),
            'apiKeyConfigKey' => 'openAiApiKey',
            'missingApiKeyMessage' => 'OpenAI API key is not configured.',
        ];
    }

    private function getBoolConfig(string $key, bool $default): bool
    {
        $value = $this->systemConfigService->get('EsmxShopAuditAi.config.' . $key);

        return $value === null ? $default : (bool) $value;
    }

    private function getStringConfig(string $key, string $default): string
    {
        $value = $this->systemConfigService->get('EsmxShopAuditAi.config.' . $key);

        if (!\is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    private function getPositiveIntConfig(string $key, int $default): int
    {
        $value = (int) ($this->systemConfigService->get('EsmxShopAuditAi.config.' . $key) ?? $default);

        return $value > 0 ? $value : $default;
    }

    private function getFloatConfig(string $key, float $default): float
    {
        $value = $this->systemConfigService->get('EsmxShopAuditAi.config.' . $key);

        return is_numeric($value) ? (float) $value : $default;
    }
}
