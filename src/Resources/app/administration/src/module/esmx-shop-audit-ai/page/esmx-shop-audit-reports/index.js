import template from './esmx-shop-audit-reports.html.twig';

Shopware.Component.register('esmx-shop-audit-reports', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            reports: [],
            selectedReport: null,
            selectedFindings: [],
            selectedTasks: [],
            loadError: null,
            scanError: null,
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.reports.pageTitle');
        },

        reportColumns() {
            return [
                {
                    property: 'createdAt',
                    label: this.$tc('esmx-shop-audit-ai.reports.columns.createdAt'),
                    primary: true
                },
                {
                    property: 'status',
                    label: this.$tc('esmx-shop-audit-ai.reports.columns.status')
                },
                {
                    property: 'scannedProducts',
                    label: this.$tc('esmx-shop-audit-ai.reports.columns.scannedProducts')
                },
                {
                    property: 'totalFindings',
                    label: this.$tc('esmx-shop-audit-ai.reports.columns.totalFindings')
                },
                {
                    property: 'highPriorityFindings',
                    label: this.$tc('esmx-shop-audit-ai.reports.columns.highPriorityFindings')
                }
            ];
        },

        findingColumns() {
            return [
                {
                    property: 'title',
                    label: this.$tc('esmx-shop-audit-ai.findings.columns.title'),
                    primary: true
                },
                {
                    property: 'severity',
                    label: this.$tc('esmx-shop-audit-ai.findings.columns.severity')
                },
                {
                    property: 'affectedCount',
                    label: this.$tc('esmx-shop-audit-ai.findings.columns.affectedCount')
                }
            ];
        },

        taskColumns() {
            return [
                {
                    property: 'title',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.title'),
                    primary: true
                },
                {
                    property: 'priority',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.priority')
                },
                {
                    property: 'affectedCount',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.affectedCount')
                },
                {
                    property: 'status',
                    label: this.$tc('esmx-shop-audit-ai.tasks.columns.status')
                }
            ];
        }
    },

    created() {
        this.loadReports();
    },

    methods: {
        loadReports() {
            this.isLoading = true;
            this.loadError = null;

            this.esmxShopAuditApiService.getReports()
                .then((response) => {
                    this.reports = response.reports ?? [];

                    if (this.reports.length && !this.selectedReport) {
                        this.loadReportDetail(this.reports[0].id);
                    }
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi reports error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.reports.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        loadReportDetail(reportId) {
            this.isLoading = true;

            this.esmxShopAuditApiService.getReportDetail(reportId)
                .then((response) => {
                    this.selectedReport = response.report;
                    this.selectedFindings = response.findings ?? [];
                    this.selectedTasks = response.tasks ?? [];
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi report detail error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.reports.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        runScan() {
            this.isRunningScan = true;
            this.scanError = null;

            this.esmxShopAuditApiService.runScan()
                .then(() => this.loadReports())
                .catch((error) => {
                    console.error('EsmxShopAuditAi reports run scan error:', error);
                    this.scanError = this.$tc('esmx-shop-audit-ai.dashboard.runScanError');
                })
                .finally(() => {
                    this.isRunningScan = false;
                });
        },

        goToDashboard() {
            this.$router.push({ name: 'esmx.shop.audit.ai.index' });
        },

        goToFindings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
        },

        goToTasks() {
            this.$router.push({ name: 'esmx.shop.audit.ai.tasks' });
        },

        goToSettings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.settings' });
        },
    }
});