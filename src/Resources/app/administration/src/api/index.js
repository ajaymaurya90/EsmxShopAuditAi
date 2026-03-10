// Import the custom API service class used to communicate with the backend audit endpoints
import EsmxShopAuditApiService from './esmx-shop-audit-api.service';

const { Application } = Shopware;

// Register a new service provider in the Shopware Administration DI container
Application.addServiceProvider('esmxShopAuditApiService', (container) => {
    const initContainer = Application.getContainer('init');

    // Return an instance of our API service with required dependencies injected
    return new EsmxShopAuditApiService(
        initContainer.httpClient,
        container.loginService
    );
});