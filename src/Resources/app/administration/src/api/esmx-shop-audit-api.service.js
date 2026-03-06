const { ApiService } = Shopware.Classes;

export default class EsmxShopAuditApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/esmx-shop-audit-ai') {
        super(httpClient, loginService, apiEndpoint);
    }

    getDashboard() {
        const apiRoute = `${this.getApiBasePath()}/dashboard`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}