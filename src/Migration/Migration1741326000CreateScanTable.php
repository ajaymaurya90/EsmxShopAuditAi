<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1741326000CreateScanTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1741326000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `esmx_shop_audit_scan` (
                `id` BINARY(16) NOT NULL,
                `status` VARCHAR(32) NOT NULL,
                `started_at` DATETIME(3) NULL,
                `finished_at` DATETIME(3) NULL,
                `scanned_products` INT NOT NULL DEFAULT 0,
                `total_findings` INT NOT NULL DEFAULT 0,
                `high_priority_findings` INT NOT NULL DEFAULT 0,
                `summary_json` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `json.esmx_shop_audit_scan.summary_json` CHECK (JSON_VALID(`summary_json`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}