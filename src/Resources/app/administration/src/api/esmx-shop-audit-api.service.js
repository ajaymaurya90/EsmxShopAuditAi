const { ApiService } = Shopware.Classes;

/**
 * API service for EsmxShopAuditAi administration module
 * @extends ApiService
 */
export default class EsmxShopAuditApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/esmx-shop-audit-ai') {
        super(httpClient, loginService, apiEndpoint);
    }

    // Fetch dashboard summary data (stats, scan status, overview widgets)
    getDashboard() {
        const apiRoute = `${this.getApiBasePath()}/dashboard`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Trigger a new shop audit scan from the backend
    runScan() {
        const apiRoute = `${this.getApiBasePath()}/run-scan`;

        return this.httpClient
            .post(apiRoute, {}, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Retrieve the latest completed scan metadata
    getLatestScan() {
        const apiRoute = `${this.getApiBasePath()}/latest-scan`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Fetch findings (issues/problems) detected in the latest scan
    getLatestFindings() {
        const apiRoute = `${this.getApiBasePath()}/latest-findings`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Fetch tasks/recommendations generated from the latest scan
    getLatestTasks() {
        const apiRoute = `${this.getApiBasePath()}/latest-tasks`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Retrieve list of historical audit reports
    getReports() {
        const apiRoute = `${this.getApiBasePath()}/reports`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Fetch detailed information of a specific audit report by reportId
    getReportDetail(reportId) {
        const apiRoute = `${this.getApiBasePath()}/report-detail/${reportId}`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    // Fetch detailed affected items for one task
    getTaskDetail(taskId) {
        const apiRoute = `${this.getApiBasePath()}/task-detail/${taskId}`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    getTaskAutoFixPreview(taskId, itemId) {
        const apiRoute = `${this.getApiBasePath()}/task-auto-fix-preview/${taskId}/${itemId}`;

        return this.httpClient
            .get(apiRoute, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    applyTaskAutoFix(taskId, itemId) {
        const apiRoute = `${this.getApiBasePath()}/task-auto-fix-apply/${taskId}/${itemId}`;

        return this.httpClient
            .post(apiRoute, {}, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }

    applyTaskAutoFixAll(taskId) {
        const apiRoute = `${this.getApiBasePath()}/task-auto-fix-apply-all/${taskId}`;

        return this.httpClient
            .post(apiRoute, {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    deleteReports(reportIds) {
        const apiRoute = `${this.getApiBasePath()}/reports/delete`;

        return this.httpClient
            .post(apiRoute, { reportIds }, {
                headers: this.getBasicHeaders(),
            })
            .then((response) => ApiService.handleResponse(response));
    }
}