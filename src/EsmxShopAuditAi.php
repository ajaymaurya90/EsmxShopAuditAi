<?php declare(strict_types=1);

namespace EsmxShopAuditAi;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class EsmxShopAuditAi extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        // Do stuff such as creating a new payment method
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $logger = $this->container->get(LoggerInterface::class);

        try {
            $connection->executeStatement(
                'DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix',
                ['prefix' => 'EsmxShopAuditAi.config.%']
            );

            $connection->executeStatement('DROP TABLE IF EXISTS `esmx_shop_audit_task`;');
            $connection->executeStatement('DROP TABLE IF EXISTS `esmx_shop_audit_finding`;');
            $connection->executeStatement('DROP TABLE IF EXISTS `esmx_shop_audit_scan`;');

        } catch (\Throwable $e) {
            $logger->error('EsmxShopAuditAi uninstall cleanup failed', [
                'exception' => $e,
            ]);
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Activate entities, such as a new payment method
        // Or create new entities here, because now your plugin is installed and active for sure
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    public function update(UpdateContext $updateContext): void
    {
        // Update necessary stuff, mostly non-database related
    }

}
