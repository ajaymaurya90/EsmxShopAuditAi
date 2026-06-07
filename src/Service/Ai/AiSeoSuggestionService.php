<?php

declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Ai;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use EsmxShopAuditAi\Core\Content\Scan\ScanEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AiSeoSuggestionService
{
    private const SUPPORTED_TASKS = [
        'review_product_names' => [
            'type' => 'product_name',
            'field' => 'name',
            'label' => 'Product name',
            'maxLength' => 120,
        ],
        'review_product_descriptions' => [
            'type' => 'product_description',
            'field' => 'description',
            'label' => 'Product description',
            'maxLength' => 500,
        ],
        'review_product_meta_titles' => [
            'type' => 'product_meta_title',
            'field' => 'metaTitle',
            'label' => 'SEO meta title',
            'maxLength' => 65,
        ],
        'review_product_meta_descriptions' => [
            'type' => 'product_meta_description',
            'field' => 'metaDescription',
            'label' => 'SEO meta description',
            'maxLength' => 160,
        ],
    ];

    public function __construct(
        private readonly AiManagerService $aiManagerService,
        private readonly EntityRepository $taskRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $scanRepository,
        private readonly EntityRepository $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function generate(string $taskId, string $itemId, ?string $currentSuggestedValue, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);
        $definition = $this->getSupportedTaskDefinition($task);

        $this->assertScanIsNotRunning($task, $context);

        $item = $this->loadTaskItem($task, $itemId, $context);
        $product = $this->loadProduct((string) ($item['id'] ?? ''), $context);
        $translated = $product->getTranslated();
        $field = $definition['field'];
        $currentValue = trim((string) ($translated[$field] ?? ''));
        $sanitizedSuggestedValue = $currentSuggestedValue !== null
            ? $this->normalizeInputValue($currentSuggestedValue, (int) $definition['maxLength'], (string) $field)
            : '';

        $payload = $this->buildSanitizedPromptPayload(
            $task,
            $item,
            $product,
            $definition,
            $currentValue,
            $sanitizedSuggestedValue
        );
        $prompt = $this->buildPrompt($payload, $definition);
        $result = $this->aiManagerService->generateText(
            $prompt,
            'AI SEO suggestion generated.',
            $this->resolveMinimumTokens((string) $definition['field'])
        );

        if (($result['success'] ?? false) !== true) {
            $message = $this->sanitizeFailureMessage((string) ($result['message'] ?? 'AI SEO suggestion failed.'));

            $this->logger->warning('EsmxShopAuditAi AI SEO suggestion failed', [
                'taskId' => $taskId,
                'itemId' => $itemId,
                'taskCode' => $task->getCode(),
                'field' => $definition['field'],
                'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
                'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        $suggestedValue = $this->normalizeAiOutput(
            (string) ($result['text'] ?? ''),
            (int) $definition['maxLength'],
            (string) $definition['field']
        );

        if ($suggestedValue === '') {
            return [
                'success' => false,
                'message' => 'AI SEO suggestion response was empty.',
            ];
        }

        $this->logger->info('EsmxShopAuditAi AI SEO suggestion generated', [
            'taskId' => $taskId,
            'itemId' => $itemId,
            'taskCode' => $task->getCode(),
            'field' => $definition['field'],
            'provider' => \is_string($result['provider'] ?? null) ? $result['provider'] : null,
            'model' => \is_string($result['model'] ?? null) ? $result['model'] : null,
            'suggestedValueLength' => mb_strlen($suggestedValue),
        ]);

        return [
            'success' => true,
            'suggestedValue' => $suggestedValue,
            'field' => $definition['field'],
            'type' => $definition['type'],
            'provider' => (string) ($result['provider'] ?? ''),
            'model' => (string) ($result['model'] ?? ''),
            'message' => 'AI SEO suggestion generated.',
        ];
    }

    private function loadTask(string $taskId, Context $context): TaskEntity
    {
        $criteria = new Criteria([$taskId]);

        /** @var ?TaskEntity $task */
        $task = $this->taskRepository->search($criteria, $context)->first();

        if (!$task instanceof TaskEntity) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
    }

    /**
     * @return array{type: string, field: string, label: string, maxLength: int}
     */
    private function getSupportedTaskDefinition(TaskEntity $task): array
    {
        $taskCode = $task->getCode();

        if (!isset(self::SUPPORTED_TASKS[$taskCode])) {
            throw new BadRequestHttpException('AI SEO suggestion is not supported for this task.');
        }

        return self::SUPPORTED_TASKS[$taskCode];
    }

    private function assertScanIsNotRunning(TaskEntity $task, Context $context): void
    {
        $criteria = new Criteria([$task->getScanId()]);

        /** @var ?ScanEntity $scan */
        $scan = $this->scanRepository->search($criteria, $context)->first();

        if ($scan instanceof ScanEntity && $scan->getStatus() === 'running') {
            throw new BadRequestHttpException('AI SEO suggestion cannot be generated while the related scan is running.');
        }
    }

    private function loadTaskItem(TaskEntity $task, string $itemId, Context $context): array
    {
        $finding = $this->loadFindingForTask($task, $context);
        $items = $this->extractFindingPayloadItems($finding->getPayloadJson() ?? []);

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            if (($item['id'] ?? null) === $itemId) {
                return $item;
            }
        }

        throw new NotFoundHttpException('Affected item not found.');
    }

    private function loadFindingForTask(TaskEntity $task, Context $context): FindingEntity
    {
        $taskPayload = $task->getPayloadJson() ?? [];
        $findingCode = $taskPayload['findingCode'] ?? null;

        if (!\is_string($findingCode) || trim($findingCode) === '') {
            throw new BadRequestHttpException('Task does not reference a finding.');
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $task->getScanId()));
        $criteria->addFilter(new EqualsFilter('code', $findingCode));

        /** @var ?FindingEntity $finding */
        $finding = $this->findingRepository->search($criteria, $context)->first();

        if (!$finding instanceof FindingEntity) {
            throw new NotFoundHttpException('Related finding not found.');
        }

        return $finding;
    }

    private function loadProduct(string $productId, Context $context): ProductEntity
    {
        if ($productId === '') {
            throw new BadRequestHttpException('Product id is missing.');
        }

        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('categories');

        /** @var ?ProductEntity $product */
        $product = $this->productRepository->search($criteria, $context)->first();

        if (!$product instanceof ProductEntity) {
            throw new NotFoundHttpException('Product not found.');
        }

        return $product;
    }

    private function extractFindingPayloadItems(array $payload): array
    {
        if (isset($payload['items']) && \is_array($payload['items'])) {
            return $payload['items'];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        return [];
    }

    private function buildSanitizedPromptPayload(
        TaskEntity $task,
        array $item,
        ProductEntity $product,
        array $definition,
        string $currentValue,
        string $currentSuggestedValue
    ): array {
        $translated = $product->getTranslated();
        $description = trim(strip_tags((string) ($translated['description'] ?? '')));

        return [
            'taskCode' => $task->getCode(),
            'fieldType' => $definition['field'],
            'fieldLabel' => $definition['label'],
            'targetMaxLength' => $definition['maxLength'],
            'productName' => $this->truncateText((string) ($translated['name'] ?? ''), 160),
            'productNumber' => $this->truncateText((string) ($product->getProductNumber() ?? ''), 80),
            'currentValue' => $this->truncateText($currentValue, (int) $definition['maxLength']),
            'currentRuleSuggestion' => $this->truncateText($currentSuggestedValue, (int) $definition['maxLength']),
            'descriptionExcerpt' => $this->truncateText($description, 500),
            'metaTitle' => $this->truncateText((string) ($translated['metaTitle'] ?? ''), 120),
            'metaDescription' => $this->truncateText((string) ($translated['metaDescription'] ?? ''), 220),
            'manufacturerName' => $this->truncateText($this->extractManufacturerName($product), 120),
            'categoryName' => $this->truncateText($this->extractPrimaryCategoryName($product), 120),
            'reason' => $this->truncateText((string) ($item['reason'] ?? $item['issue'] ?? ''), 180),
            'seoScore' => $item['seoScore'] ?? null,
            'overallSeoScore' => $item['overallSeoScore'] ?? null,
            'metaTitleScore' => $item['metaTitleScore'] ?? null,
            'metaDescriptionScore' => $item['metaDescriptionScore'] ?? null,
            'descriptionScore' => $item['descriptionScore'] ?? null,
            'qualityLevel' => \is_scalar($item['qualityLevel'] ?? null) ? (string) $item['qualityLevel'] : '',
            'language' => \is_scalar($item['languageName'] ?? null) ? (string) $item['languageName'] : '',
        ];
    }

    private function buildPrompt(array $payload, array $definition): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $json = \is_string($json) ? $json : '{}';

        return sprintf(
            "Generate exactly one Shopware product SEO field value.\n\nRules:\n- Return only the suggested value.\n- No markdown, no quotes, no labels, no explanation.\n- Do not invent facts, specifications, materials, dimensions, prices, discounts, shipping, stock, availability, or compatibility.\n- Use only the provided product, category, manufacturer, and current SEO context.\n- Make the value natural, specific, and useful for ecommerce SEO.\n- Keep it at or below %d characters.\n- For meta title and meta description, return one line without line breaks.\n- Target field: %s.\n\nSafe context:\n%s",
            (int) $definition['maxLength'],
            (string) $definition['label'],
            $json
        );
    }

    private function normalizeAiOutput(string $value, int $maxLength, string $field): string
    {
        $value = $this->removeCodeFences($value);
        $value = trim($value);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = preg_replace('/^\s*(suggested value|suggestion|answer)\s*:\s*/iu', '', $value) ?? $value;
        $value = str_replace(['**', '__', '*', '`'], '', $value);
        $value = trim(strip_tags($value));

        if (\in_array($field, ['name', 'metaTitle', 'metaDescription'], true)) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        return $this->truncateText($value, $maxLength);
    }

    private function normalizeInputValue(string $value, int $maxLength, string $field): string
    {
        $value = trim(strip_tags($value));

        if (\in_array($field, ['name', 'metaTitle', 'metaDescription'], true)) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        return $this->truncateText($value, $maxLength);
    }

    private function removeCodeFences(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^```[a-zA-Z0-9_-]*\s*(.*?)\s*```$/su', $value, $matches) === 1) {
            return (string) $matches[1];
        }

        return $value;
    }

    private function extractManufacturerName(ProductEntity $product): string
    {
        $manufacturer = $product->getManufacturer();

        if (!$manufacturer instanceof ProductManufacturerEntity) {
            return '';
        }

        $translatedName = $manufacturer->getTranslation('name');

        if (\is_string($translatedName) && trim($translatedName) !== '') {
            return trim($translatedName);
        }

        return trim((string) ($manufacturer->getName() ?? ''));
    }

    private function extractPrimaryCategoryName(ProductEntity $product): string
    {
        $categories = $product->getCategories();

        if ($categories === null || $categories->count() === 0) {
            return '';
        }

        $category = $categories->first();

        if (!$category instanceof CategoryEntity) {
            return '';
        }

        $translatedName = $category->getTranslation('name');

        if (\is_string($translatedName) && trim($translatedName) !== '') {
            return trim($translatedName);
        }

        return trim((string) ($category->getName() ?? ''));
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $maxLength - 3)), " \t\n\r\0\x0B.,;:-") . '...';
    }

    private function resolveMinimumTokens(string $field): int
    {
        return $field === 'description' ? 350 : 120;
    }

    private function sanitizeFailureMessage(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'AI SEO suggestion failed.';
        }

        return $this->truncateText($message, 240);
    }
}
