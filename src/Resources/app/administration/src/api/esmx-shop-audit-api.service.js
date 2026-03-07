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
            .then((response) => ApiService.handleResponse(response));
    }

    runScan() {
        const apiRoute = `${this.getApiBasePath()}/run-scan`;

        return this.httpClient
            .post(apiRoute, {}, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    getLatestScan() {
        const apiRoute = `${this.getApiBasePath()}/latest-scan`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    getLatestFindings() {
        const apiRoute = `${this.getApiBasePath()}/latest-findings`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    getLatestTasks() {
        const apiRoute = `${this.getApiBasePath()}/latest-tasks`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }
}