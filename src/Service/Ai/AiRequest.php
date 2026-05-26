<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

class AiRequest
{
    public function __construct(
        private readonly string $prompt,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly float $temperature,
        private readonly int $timeout,
        private readonly ?string $baseUrl = null
    ) {
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }
}
