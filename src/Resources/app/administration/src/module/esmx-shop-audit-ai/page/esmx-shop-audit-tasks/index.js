import template from './esmx-shop-audit-tasks.html.twig';

Shopware.Component.register('esmx-shop-audit-tasks', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            latestScan: null,
            tasks: [],
            loadError: null,
            scanError: null,
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.tasks.pageTitle');
        },

        activePriorityFilter() {
            return this.$route.query.priority || null;
        },

        activeCodeFilter() {
            return this.$route.query.code || null;
        },

        filteredTasks() {
            return this.tasks.filter((item) => {
                const priorityMatch = !this.activePriorityFilter || item.priority === this.activePriorityFilter;
                const codeMatch = !this.activeCodeFilter || item.code === this.activeCodeFilter;

                return priorityMatch && codeMatch;
            });
        },

        columns() {
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
        this.loadPageData();
    },

    methods: {
        loadPageData() {
            this.isLoading = true;
            this.loadError = null;

            this.esmxShopAuditApiService.getLatestTasks()
                .then((response) => {
                    this.latestScan = response.scan;
                    this.tasks = response.tasks ?? [];
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi tasks error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.tasks.loadError');
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
                    console.error('EsmxShopAuditAi tasks run scan error:', error);
                    this.scanError = this.$tc('esmx-shop-audit-ai.dashboard.runScanError');
                })
                .finally(() => {
                    this.isRunningScan = false;
                });
        },

        clearFilters() {
            this.$router.push({ name: 'esmx.shop.audit.ai.tasks' });
        },

        goToDashboard() {
            this.$router.push({ name: 'esmx.shop.audit.ai.index' });
        },

        goToFindings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
        },

        goToReports() {
            this.$router.push({ name: 'esmx.shop.audit.ai.reports' });
        },

        goToSettings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.settings' });
        }
    }
});