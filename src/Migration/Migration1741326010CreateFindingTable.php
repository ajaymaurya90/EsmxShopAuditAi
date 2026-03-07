<?php declare(strict_types=1);

namespace EsmxShopAuditAi\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1741326010CreateFindingTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1741326010;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `esmx_shop_audit_finding` (
                `id` BINARY(16) NOT NULL,
                `scan_id` BINARY(16) NOT NULL,
                `code` VARCHAR(64) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `severity` VARCHAR(32) NOT NULL,
                `entity` VARCHAR(64) NOT NULL,
                `affected_count` INT NOT NULL DEFAULT 0,
                `payload_json` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.esmx_shop_audit_finding.scan_id` (`scan_id`),
                CONSTRAINT `fk.esmx_shop_audit_finding.scan_id`
                    FOREIGN KEY (`scan_id`)
                    REFERENCES `esmx_shop_audit_scan` (`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,
                CONSTRAINT `json.esmx_shop_audit_finding.payload_json` CHECK (JSON_VALID(`payload_json`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}