import EsmxShopAuditApiService from './esmx-shop-audit-api.service';

const { Application } = Shopware;

Application.addServiceProvider('esmxShopAuditApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new EsmxShopAuditApiService(
        initContainer.httpClient,
        container.loginService
    );
});