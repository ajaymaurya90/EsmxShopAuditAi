<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

interface AiProviderInterface
{
    public function getName(): string;

    public function generate(AiRequest $request): AiResponse;
}
