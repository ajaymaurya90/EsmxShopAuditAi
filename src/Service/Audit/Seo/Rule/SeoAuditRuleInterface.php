<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Audit\Seo\Rule;

use Shopware\Core\Framework\Context;

interface SeoAuditRuleInterface
{
    public function getCode(): string;

    public function getTitle(): string;

    public function getSeverity(): string;

    public function getEntity(): string;

    public function isEnabled(): bool;

    public function audit(Context $context): array;
}