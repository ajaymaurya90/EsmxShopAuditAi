<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Service\Task;

use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Finding\FindingEntity;
use EsmxShopAuditAi\Core\Content\Scan\Aggregate\Task\TaskEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TaskAutoFixService
{
    public function __construct(
        private readonly EntityRepository $taskRepository,
        private readonly EntityRepository $findingRepository,
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function getPreview(string $taskId, string $itemId, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);
        $item = $this->loadTaskItem($task, $itemId, $context);

        return match ($task->getCode()) {
            'add_meta_titles' => $this->buildMetaTitlePreview($item, $context),
            'add_meta_descriptions' => $this->buildMetaDescriptionPreview($item, $context),
            default => throw new BadRequestHttpException('Auto fix is not supported for this task.'),
        };
    }

    public function apply(string $taskId, string $itemId, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);
        $item = $this->loadTaskItem($task, $itemId, $context);

        $result = match ($task->getCode()) {
            'add_meta_titles' => $this->applyMetaTitleFix($item, $context),
            'add_meta_descriptions' => $this->applyMetaDescriptionFix($item, $context),
            default => throw new BadRequestHttpException('Auto fix is not supported for this task.'),
        };

        if (!($result['changed'] ?? false)) {
            return $result;
        }

        $remainingCount = $this->updateFindingAfterFix($task, $itemId, $context);
        $this->updateTaskAfterFix($task, $remainingCount, $context);

        return [
            ...$result,
            'remaining' => $remainingCount,
            'taskCompleted' => $remainingCount === 0,
        ];
    }

    public function applyAll(string $taskId, Context $context): array
    {
        $task = $this->loadTask($taskId, $context);

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

        if (!$finding) {
            throw new NotFoundHttpException('Finding not found.');
        }

        $payload = $finding->getPayloadJson() ?? [];
        $items = $payload['items'] ?? [];

        $processed = 0;
        $changed = 0;

        foreach ($items as $item) {
            try {
                $result = $this->apply($taskId, (string) ($item['id'] ?? ''), $context);

                $processed++;

                if ($result['changed'] ?? false) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                // skip errors, continue batch
            }
        }

        return [
            'success' => true,
            'processed' => $processed,
            'changed' => $changed,
        ];
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
        $taskPayload = $task->getPayloadJson() ?? [];
        $findingCode = $taskPayload['findingCode'] ?? null;

        if (!$findingCode) {
            throw new BadRequestHttpException('Task does not reference a finding.');
        }

        $findingCriteria = new Criteria();
        $findingCriteria->addFilter(new EqualsFilter('scanId', $task->getScanId()));
        $findingCriteria->addFilter(new EqualsFilter('code', $findingCode));

        /** @var ?FindingEntity $finding */
        $finding = $this->findingRepository->search($findingCriteria, $context)->first();

        if ($finding === null) {
            throw new NotFoundHttpException('Related finding not found.');
        }

        $payload = $finding->getPayloadJson() ?? [];
        $items = [];

        if (isset($payload['items']) && \is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (array_is_list($payload)) {
            $items = $payload;
        }

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

    private function buildMetaTitlePreview(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaTitle = trim((string) ($translated['metaTitle'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta title.');
        }

        return [
            'supported' => true,
            'type' => 'product_meta_title',
            'entityId' => $product->getId(),
            'itemName' => $productName,
            'field' => 'metaTitle',
            'fieldLabel' => 'SEO meta title',
            'currentValue' => $currentMetaTitle !== '' ? $currentMetaTitle : null,
            'suggestedValue' => $productName,
            'willChange' => $currentMetaTitle === '',
        ];
    }

    private function applyMetaTitleFix(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaTitle = trim((string) ($translated['metaTitle'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta title.');
        }

        if ($currentMetaTitle !== '') {
            return [
                'success' => true,
                'changed' => false,
                'message' => 'Meta title already exists.',
            ];
        }

        $this->productRepository->update([
            [
                'id' => $product->getId(),
                'metaTitle' => $productName,
            ],
        ], $context);

        return [
            'success' => true,
            'changed' => true,
            'entityId' => $product->getId(),
            'field' => 'metaTitle',
            'newValue' => $productName,
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
            throw new NotFoundHttpException('Finding not found.');
        }

        $payload = $finding->getPayloadJson() ?? [];
        $items = [];

        if (isset($payload['items']) && \is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (array_is_list($payload)) {
            $items = $payload;
        }

        $updatedItems = array_values(array_filter($items, function ($item) use ($itemId) {
            return ($item['id'] ?? null) !== $itemId;
        }));

        $newPayload = $payload;
        $newPayload['items'] = $updatedItems;

        $this->findingRepository->update([
            [
                'id' => $finding->getId(),
                'payloadJson' => $newPayload,
                'affectedCount' => count($updatedItems),
            ],
        ], $context);

        return count($updatedItems);
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

    private function buildMetaDescriptionPreview(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaDescription = trim((string) ($translated['metaDescription'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta description.');
        }

        $suggestedValue = $this->generateMetaDescription($productName);

        return [
            'supported' => true,
            'type' => 'product_meta_description',
            'entityId' => $product->getId(),
            'itemName' => $productName,
            'field' => 'metaDescription',
            'fieldLabel' => 'SEO meta description',
            'currentValue' => $currentMetaDescription !== '' ? $currentMetaDescription : null,
            'suggestedValue' => $suggestedValue,
            'willChange' => $currentMetaDescription === '',
        ];
    }

    private function applyMetaDescriptionFix(array $item, Context $context): array
    {
        $product = $this->loadProduct((string) $item['id'], $context);

        $translated = $product->getTranslated();
        $productName = trim((string) ($translated['name'] ?? ''));
        $currentMetaDescription = trim((string) ($translated['metaDescription'] ?? ''));

        if ($productName === '') {
            throw new BadRequestHttpException('Product name is empty, cannot generate meta description.');
        }

        if ($currentMetaDescription !== '') {
            return [
                'success' => true,
                'changed' => false,
                'message' => 'Meta description already exists.',
            ];
        }

        $suggestedValue = $this->generateMetaDescription($productName);

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

    private function generateMetaDescription(string $productName): string
    {
        $text = sprintf('Discover %s in our store.', $productName);
        $text = trim($text);

        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 157)) . '...';
    }
}