<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Controller\Admin;

use EsmxShopAuditAi\Service\Ai\AiManagerService;
use EsmxShopAuditAi\Service\Ai\AiExecutiveSummaryService;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class AiController extends AbstractController
{
    public function __construct(
        private readonly AiManagerService $aiManagerService,
        private readonly AiExecutiveSummaryService $aiExecutiveSummaryService
    ) {
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/ai/test-connection',
        name: 'api.action.esmx-shop-audit-ai.ai.test-connection',
        methods: ['POST']
    )]
    public function testConnection(): JsonResponse
    {
        return new JsonResponse($this->aiManagerService->testConnection());
    }

    #[Route(
        path: '/api/_action/esmx-shop-audit-ai/ai/executive-summary',
        name: 'api.action.esmx-shop-audit-ai.ai.executive-summary',
        methods: ['POST']
    )]
    public function generateExecutiveSummary(Request $request, Context $context): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $scanId = \is_array($payload) && \is_string($payload['scanId'] ?? null)
            ? trim($payload['scanId'])
            : null;
        $forceRegenerate = \is_array($payload) && ($payload['forceRegenerate'] ?? false) === true;

        return new JsonResponse($this->aiExecutiveSummaryService->generate($scanId, $context, $forceRegenerate));
    }
}
