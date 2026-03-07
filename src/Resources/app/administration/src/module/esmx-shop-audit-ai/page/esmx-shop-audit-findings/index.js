import template from './esmx-shop-audit-findings.html.twig';

Shopware.Component.register('esmx-shop-audit-findings', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            latestScan: null,
            findings: [],
            loadError: null,
            scanError: null,
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.findings.pageTitle');
        },

        columns() {
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
                    property: 'entity',
                    label: this.$tc('esmx-shop-audit-ai.findings.columns.entity')
                },
                {
                    property: 'affectedCount',
                    label: this.$tc('esmx-shop-audit-ai.findings.columns.affectedCount')
                }
            ];
        }
    },

    created() {
        this.loadPageData();
    },

    methods: {
        loadPageData() {
            this.isLoading = true;
            this.loadError = null;

            this.esmxShopAuditApiService.getLatestFindings()
                .then((response) => {
                    this.latestScan = response.scan;
                    this.findings = response.findings ?? [];
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi findings error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.findings.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        runScan() {
            this.isRunningScan = true;
            this.scanError = null;

            this.esmxShopAuditApiService.runScan()
                .then(() => this.loadPageData())
                .catch((error) => {
                    console.error('EsmxShopAuditAi findings run scan error:', error);
                    this.scanError = this.$tc('esmx-shop-audit-ai.dashboard.runScanError');
                })
                .finally(() => {
                    this.isRunningScan = false;
                });
        },

        goToDashboard() {
            this.$router.push({ name: 'esmx.shop.audit.ai.index' });
        },

        goToTasks() {
            this.$router.push({ name: 'esmx.shop.audit.ai.tasks' });
        },

        goToReports() {
            this.$router.push({ name: 'esmx.shop.audit.ai.reports' });
        },

        goToSettings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.settings' });
        },
    }
});