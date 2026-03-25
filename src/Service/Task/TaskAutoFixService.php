<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Task;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TaskAutoFixService
{
    public function __construct(
        private readonly EntityRepository $taskRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $productRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPreview(string $taskId, string $itemId, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);
        $item = $this->loadTaskItem($task, $itemId, $context);

        $preview = match ($task->getCode()) {
            'review_product_names' => $this->buildProductNamePreview($item, $context),
            'review_product_descriptions' => $this->buildProductDescriptionPreview($item, $context),
            'review_product_meta_titles' => $this->buildMetaTitlePreview($item, $context),
            'review_product_meta_descriptions' => $this->buildMetaDescriptionPreview($item, $context),
            default => throw new BadRequestHttpException('Auto fix is not supported for this task.'),
        };

        $this->logger->info('EsmxShopAuditAi auto-fix preview generated', [
            'taskId' => $taskId,
            'itemId' => $itemId,
            'taskCode' => $task->getCode(),
            'field' => $preview['field'] ?? null,
            'willChange' => $preview['willChange'] ?? null,
        ]);

        return $preview;
    }

    public function apply(string $taskId, string $itemId, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);
        $item = $this->loadTaskItem($task, $itemId, $context);

        $result = match ($task->getCode()) {
            'review_product_names' => $this->applyProductNameFix($item, $context),
            'review_product_descriptions' => $this->applyProductDescriptionFix($item, $context),
            'review_product_meta_titles' => $this->applyMetaTitleFix($item, $context),
            'review_product_meta_descriptions' => $this->applyMetaDescriptionFix($item, $context),
            default => throw new BadRequestHttpException('Auto fix is not supported for this task.'),
        };

        if (!($result['changed'] ?? false)) {
            $this->logger->warning('EsmxShopAuditAi auto-fix skipped because no change was needed', [
                'taskId' => $taskId,
                'itemId' => $itemId,
                'taskCode' => $task->getCode(),
                'message' => $result['message'] ?? null,
            ]);

            return $result;
        }

        $remainingCount = $this->updateFindingAfterFix($task, $itemId, $context);
        $this->updateTaskAfterFix($task, $remainingCount, $context);

        $response = [
            ...$result,
            'remaining' => $remainingCount,
            'taskCompleted' => $remainingCount === 0,
        ];

        $this->logger->info('EsmxShopAuditAi auto-fix applied', [
            'taskId' => $taskId,
            'itemId' => $itemId,
            'taskCode' => $task->getCode(),
            'field' => $result['field'] ?? null,
            'remaining' => $remainingCount,
            'taskCompleted' => $remainingCount === 0,
        ]);

        return $response;
    }

    public function applyAll(string $taskId, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);

        $finding = $this->loadFindingForTask($task, $context);
        $items = $this->extractFindingPayloadItems($finding->getPayloadJson() ?? []);

        $processed = 0;
        $changed = 0;
        $failed = 0;

        foreach ($items as $item) {
            $itemId = (string) ($item['id'] ?? '');

            if ($itemId === '') {
                $failed++;

                $this->logger->warning('EsmxShopAuditAi batch auto-fix item skipped because item id was missing', [
                    'taskId' => $taskId,
                    'taskCode' => $task->getCode(),
                ]);

                continue;
            }

            try {
                $result = $this->apply($taskId, $itemId, $context);
                $processed++;

                if ($result['changed'] ?? false) {
                    $changed++;
                }
            } catch (\Throwable $exception) {
                $failed++;

                $this->logger->warning('EsmxShopAuditAi batch auto-fix item failed', [
                    'taskId' => $taskId,
                    'itemId' => $itemId,
                    'taskCode' => $task->getCode(),
                    'exception' => $exception,
                ]);
            }
        }

        $summary = [
            'success' => true,
            'processed' => $processed,
            'changed' => $changed,
            'failed' => $failed,
        ];

        $this->logger->info('EsmxShopAuditAi batch auto-fix completed', [
            'taskId' => $taskId,
            'taskCode' => $task->getCode(),
            'processed' => $processed,
            'changed' => $changed,
            'failed' => $failed,
        ]);

        return $summary;
    }

    private function loadTask(string $taskId, Context $context): TaskEntity
    {
        $criteria = new Criteria([$taskId]);

        /** @var ?TaskEntity $task */
        $task = $this->taskRepository->search($criteria, $context)->first();

        if ($task === null) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
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

    private function buildProductNamePreview(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $currentName = trim((string) ($translated['name'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));
        $suggestedValue = $this->generateProductNameSuggestion($product, $currentName, $reason);

        return [
            'supported' => true,
            'type' => 'product_name',
            'entityId' => $product->getId(),
            'itemName' => $currentName !== '' ? $currentName : 'Unnamed product',
            'field' => 'name',
            'fieldLabel' => 'Product name',
            'reason' => $reason,
            'currentValue' => $currentName !== '' ? $currentName : null,
            'suggestedValue' => $suggestedValue,
            'willChange' => $currentName !== $suggestedValue,
        ];
    }

    private function applyProductNameFix(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $currentName = trim((string) ($translated['name'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));
        $suggestedValue = $this->generateProductNameSuggestion($product, $currentName, $reason);

        if ($currentName === $suggestedValue) {
            return [
                'success' => true,
                'changed' => false,
                'message' => 'Product name already matches the suggested value.',
            ];
        }

        $this->productRepository->update([
            [
                'id' => $product->getId(),
                'name' => $suggestedValue,
            ],
        ], $context);

        return [
            'success' => true,
            'changed' => true,
            'entityId' => $product->getId(),
            'field' => 'name',
            'newValue' => $suggestedValue,
        ];
    }

    private function buildProductDescriptionPreview(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentDescription = trim((string) ($translated['description'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));
        $suggestedValue = $this->generateProductDescriptionSuggestion($productName, $currentDescription, $reason);

        return [
            'supported' => true,
            'type' => 'product_description',
            'entityId' => $product->getId(),
            'itemName' => $productName !== '' ? $productName : 'Unnamed product',
            'field' => 'description',
            'fieldLabel' => 'Product description',
            'reason' => $reason,
            'currentValue' => $currentDescription !== '' ? $currentDescription : null,
            'suggestedValue' => $suggestedValue,
            'willChange' => $currentDescription !== $suggestedValue,
        ];
    }

    private function applyProductDescriptionFix(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentDescription = trim((string) ($translated['description'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));
        $suggestedValue = $this->generateProductDescriptionSuggestion($productName, $currentDescription, $reason);

        if ($currentDescription === $suggestedValue) {
            return [
                'success' => true,
                'changed' => false,
                'message' => 'Product description already matches the suggested value.',
            ];
        }

        $this->productRepository->update([
            [
                'id' => $product->getId(),
                'description' => $suggestedValue,
            ],
        ], $context);

        return [
            'success' => true,
            'changed' => true,
            'entityId' => $product->getId(),
            'field' => 'description',
            'newValue' => $suggestedValue,
        ];
    }

    private function buildMetaTitlePreview(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaTitle = trim((string) ($translated['metaTitle'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta title.');
        }

        $suggestedValue = $this->generateMetaTitleSuggestion($productName, $currentMetaTitle, $reason);

        return [
            'supported' => true,
            'type' => 'product_meta_title',
            'entityId' => $product->getId(),
            'itemName' => $productName,
            'field' => 'metaTitle',
            'fieldLabel' => 'SEO meta title',
            'reason' => $reason,
            'currentValue' => $currentMetaTitle !== '' ? $currentMetaTitle : null,
            'suggestedValue' => $suggestedValue,
            'willChange' => $currentMetaTitle !== $suggestedValue,
        ];
    }

    private function applyMetaTitleFix(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaTitle = trim((string) ($translated['metaTitle'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta title.');
        }

        $suggestedValue = $this->generateMetaTitleSuggestion($productName, $currentMetaTitle, $reason);

        if ($currentMetaTitle === $suggestedValue) {
            return [
                'success' => true,
                'changed' => false,
                'message' => 'Meta title already matches the suggested value.',
            ];
        }

        $this->productRepository->update([
            [
                'id' => $product->getId(),
                'metaTitle' => $suggestedValue,
            ],
        ], $context);

        return [
            'success' => true,
            'changed' => true,
            'entityId' => $product->getId(),
            'field' => 'metaTitle',
            'newValue' => $suggestedValue,
        ];
    }

    private function buildMetaDescriptionPreview(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaDescription = trim((string) ($translated['metaDescription'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta description.');
        }

        $suggestedValue = $this->generateMetaDescriptionSuggestion($productName, $currentMetaDescription, $reason);

        return [
            'supported' => true,
            'type' => 'product_meta_description',
            'entityId' => $product->getId(),
            'itemName' => $productName,
            'field' => 'metaDescription',
            'fieldLabel' => 'SEO meta description',
            'reason' => $reason,
            'currentValue' => $currentMetaDescription !== '' ? $currentMetaDescription : null,
            'suggestedValue' => $suggestedValue,
            'willChange' => $currentMetaDescription !== $suggestedValue,
        ];
    }

    private function applyMetaDescriptionFix(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaDescription = trim((string) ($translated['metaDescription'] ?? ''));
        $reason = trim((string) ($item['issue'] ?? $item['reason'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta description.');
        }

        $suggestedValue = $this->generateMetaDescriptionSuggestion($productName, $currentMetaDescription, $reason);

        if ($currentMetaDescription === $suggestedValue) {
            return [
                'success' => true,
                'changed' => false,
                'message' => 'Meta description already matches the suggested value.',
            ];
        }

        $this->productRepository->update([
            [
                'id' => $product->getId(),
                'metaDescription' => $suggestedValue,
            ],
        ], $context);

        return [
            'success' => true,
            'changed' => true,
            'entityId' => $product->getId(),
            'field' => 'metaDescription',
            'newValue' => $suggestedValue,
        ];
    }

    private function loadProduct(string $productId, Context $context): ProductEntity
    {
        $criteria = new Criteria([$productId]);

        /** @var ?ProductEntity $product */
        $product = $this->productRepository->search($criteria, $context)->first();

        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        return $product;
    }

    private function updateFindingAfterFix(TaskEntity $task, string $itemId, Context $context): int
    {
        $finding = $this->loadFindingForTask($task, $context);

        $payload = $finding->getPayloadJson() ?? [];
        $items = $this->extractFindingPayloadItems($payload);

        $updatedItems = array_values(array_filter($items, function ($item) use ($itemId) {
            return ($item['id'] ?? null) !== $itemId;
        }));

        $newPayload = $payload;
        $newPayload['items'] = $updatedItems;

        $updatedCount = \count($updatedItems);

        $this->findingRepository->update([
            [
                'id' => $finding->getId(),
                'payloadJson' => $newPayload,
                'affectedCount' => $updatedCount,
            ],
        ], $context);

        return $updatedCount;
    }

    private function loadFindingForTask(TaskEntity $task, Context $context): FindingEntity
    {
        $taskPayload = $task->getPayloadJson() ?? [];
        $findingCode = $taskPayload['findingCode'] ?? null;

        if (!$findingCode) {
            throw new BadRequestHttpException('Task does not reference a finding.');
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('scanId', $task->getScanId()));
        $criteria->addFilter(new EqualsFilter('code', $findingCode));

        /** @var ?FindingEntity $finding */
        $finding = $this->findingRepository->search($criteria, $context)->first();

        if ($finding === null) {
            throw new NotFoundHttpException('Related finding not found.');
        }

        return $finding;
    }

    private function updateTaskAfterFix(TaskEntity $task, int $remainingCount, Context $context): void
    {
        $this->taskRepository->update([
            [
                'id' => $task->getId(),
                'affectedCount' => $remainingCount,
                'status' => $remainingCount === 0 ? 'done' : 'open',
            ],
        ], $context);
    }

    private function generateProductName(string $currentName): string
    {
        $value = trim($currentName);

        if ($value === '') {
            return 'New product';
        }

        return $value;
    }

    private function generateProductDescription(string $productName, string $currentDescription): string
    {
        $currentDescription = trim($currentDescription);

        if ($currentDescription !== '') {
            return $currentDescription;
        }

        $name = trim($productName) !== '' ? trim($productName) : 'this product';

        $text = sprintf(
            '%s is available in our store. Discover the key features and benefits of %s.',
            $name,
            $name
        );

        return $this->truncateText($text, 500);
    }

    private function generateMetaTitle(string $productName, string $currentMetaTitle): string
    {
        $currentMetaTitle = trim($currentMetaTitle);

        if ($currentMetaTitle !== '') {
            return $currentMetaTitle;
        }

        return $this->truncateText($productName, 70);
    }

    private function generateMetaDescription(string $productName, string $currentMetaDescription): string
    {
        $currentMetaDescription = trim($currentMetaDescription);

        if ($currentMetaDescription !== '') {
            return $currentMetaDescription;
        }

        $text = sprintf('Discover %s in our store.', $productName);

        return $this->truncateText($text, 160);
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 3)) . '...';
    }

    private function generateProductNameSuggestion(ProductEntity $product, string $currentName, string $reason): string
    {
        $currentName = trim($currentName);
        $productNumber = trim((string) $product->getProductNumber());

        if ($currentName === '') {
            return $productNumber !== '' ? sprintf('Product %s', $productNumber) : 'New product';
        }

        if ($this->containsText($reason, ['too short', 'short'])) {
            return $this->truncateText($currentName . ' - Premium Quality', 120);
        }

        if ($this->containsText($reason, ['too long'])) {
            return $this->truncateText($currentName, 120);
        }

        if ($this->containsText($reason, ['needs improvement', 'weak', 'generic', 'low quality'])) {
            return $this->truncateText($currentName . ' - Best Choice', 120);
        }

        return $this->truncateText($currentName . ' - Best Choice', 120);
    }

    private function generateProductDescriptionSuggestion(string $productName, string $currentDescription, string $reason): string
    {
        $productName = trim($productName);
        $currentDescription = trim(strip_tags($currentDescription));

        if ($currentDescription === '') {
            $text = sprintf(
                '%s is designed to deliver reliable quality and everyday usability. Explore the key features, benefits, and essential details before making your purchase.',
                $productName !== '' ? $productName : 'This product'
            );

            return $this->truncateText($text, 500);
        }

        if ($this->containsText($reason, ['too short', 'short'])) {
            $text = sprintf(
                '%s %s Discover the important features, practical benefits, and product details that help customers make a confident buying decision.',
                rtrim($currentDescription, '. ') . '.',
                $productName !== '' ? $productName . ' is built for quality and usability.' : 'This product is built for quality and usability.'
            );

            return $this->truncateText($text, 500);
        }

        if ($this->containsText($reason, ['too long'])) {
            return $this->truncateText($currentDescription, 500);
        }

        if ($this->containsText($reason, ['needs improvement', 'weak', 'generic', 'low quality'])) {
            $text = sprintf(
                '%s %s Learn more about its features, value, and everyday benefits.',
                rtrim($currentDescription, '. ') . '.',
                $productName !== '' ? $productName . ' offers a practical solution for your needs.' : 'This product offers a practical solution for your needs.'
            );

            return $this->truncateText($text, 500);
        }

        $text = sprintf(
            '%s %s',
            rtrim($currentDescription, '. ') . '.',
            'Explore its features, benefits, and practical use cases.'
        );

        return $this->truncateText($text, 500);
    }

    private function generateMetaTitleSuggestion(string $productName, string $currentMetaTitle, string $reason): string
    {
        $productName = trim($productName);
        $currentMetaTitle = trim($currentMetaTitle);

        if ($currentMetaTitle === '') {
            return $this->truncateText($productName . ' | Buy Online', 70);
        }

        if ($this->containsText($reason, ['too short', 'short'])) {
            return $this->truncateText($currentMetaTitle . ' | Buy Online', 70);
        }

        if ($this->containsText($reason, ['too long'])) {
            return $this->truncateText($currentMetaTitle, 70);
        }

        if ($this->containsText($reason, ['needs improvement', 'weak', 'generic', 'low quality'])) {
            return $this->truncateText($productName . ' | Best Price Online', 70);
        }

        return $this->truncateText($productName . ' | Best Price Online', 70);
    }

    private function generateMetaDescriptionSuggestion(string $productName, string $currentMetaDescription, string $reason): string
    {
        $productName = trim($productName);
        $currentMetaDescription = trim(strip_tags($currentMetaDescription));

        if ($currentMetaDescription === '') {
            $text = sprintf(
                'Discover %s in our store. Explore features, benefits, pricing, and important product details before you buy.',
                $productName !== '' ? $productName : 'this product'
            );

            return $this->truncateText($text, 160);
        }

        if ($this->containsText($reason, ['too short', 'short'])) {
            $text = sprintf(
                '%s Explore features, benefits, and important product details for %s.',
                rtrim($currentMetaDescription, '. ') . '.',
                $productName !== '' ? $productName : 'this product'
            );

            return $this->truncateText($text, 160);
        }

        if ($this->containsText($reason, ['too long'])) {
            return $this->truncateText($currentMetaDescription, 160);
        }

        if ($this->containsText($reason, ['needs improvement', 'weak', 'generic', 'low quality'])) {
            $text = sprintf(
                'Buy %s online. Discover features, benefits, pricing, and key product details in our store.',
                $productName !== '' ? $productName : 'this product'
            );

            return $this->truncateText($text, 160);
        }

        $text = sprintf(
            'Shop %s today. Discover features, benefits, pricing, and important details before purchase.',
            $productName !== '' ? $productName : 'this product'
        );

        return $this->truncateText($text, 160);
    }

    private function containsText(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower(trim($haystack));

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}