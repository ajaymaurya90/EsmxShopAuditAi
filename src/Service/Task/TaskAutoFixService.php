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
            'add_meta_titles' => $this->buildMetaTitlePreview($item, $context),
            'add_meta_descriptions' => $this->buildMetaDescriptionPreview($item, $context),
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
            'add_meta_titles' => $this->applyMetaTitleFix($item, $context),
            'add_meta_descriptions' => $this->applyMetaDescriptionFix($item, $context),
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

        $processed = 0;     // for total items attempted with valid id
        $changed = 0;       // for actual successful changes
        $failed = 0;        // for failed attempts

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

    // Extracts normalized item arrays from finding payloads that may be wrapped or flat.
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

    // Loads the finding referenced by the task payload within the same scan.
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

    // Builds a safe deterministic fallback meta description for auto-fix use.
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