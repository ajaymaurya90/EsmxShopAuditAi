<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

class AiResponse
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $text
    ) {
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
