import template from './esmx-shop-audit-settings.html.twig';

Shopware.Component.register('esmx-shop-audit-settings', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            scanError: null,
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.settings.pageTitle');
        }
    },

    methods: {
        refreshPage() {
            this.isLoading = true;

            setTimeout(() => {
                this.isLoading = false;
            }, 200);
        },

        runScan() {
            this.isRunningScan = true;
            this.scanError = null;

            this.esmxShopAuditApiService.runScan()
                .catch((error) => {
                    console.error('EsmxShopAuditAi settings run scan error:', error);
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

        goToReports() {
            this.$router.push({ name: 'esmx.shop.audit.ai.reports' });
        }
    }
});