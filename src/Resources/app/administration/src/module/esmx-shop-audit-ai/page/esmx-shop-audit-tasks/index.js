import template from './esmx-shop-audit-tasks.html.twig';

Shopware.Component.register('esmx-shop-audit-tasks', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            latestScan: null,
            tasks: [],
            loadError: null,
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.tasks.pageTitle');
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

        goToDashboard() {
            this.$router.push({ name: 'esmx.shop.audit.ai.index' });
        },

        goToFindings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
        }
    }
});