import template from './esmx-shop-audit-dashboard.html.twig';
import './esmx-shop-audit-dashboard.scss';

Shopware.Component.register('esmx-shop-audit-dashboard', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            dashboard: null,
            loadError: null,
        };
    },

    computed: {
        cardTitle() {
            return this.$tc('esmx-shop-audit-ai.dashboard.cardTitle');
        },

        totals() {
            return this.dashboard?.totals ?? {};
        },

        meta() {
            return this.dashboard?.meta ?? {};
        },

        issues() {
            return this.dashboard?.issues ?? {};
        },

        summaryCards() {
            return [
                {
                    key: 'missingDescription',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingDescription'),
                    count: this.totals.missingDescription || 0,
                    target: 'audit-section-missing-description',
                },
                {
                    key: 'missingCoverImage',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingCoverImage'),
                    count: this.totals.missingCoverImage || 0,
                    target: 'audit-section-missing-cover-image',
                },
                {
                    key: 'inactiveProducts',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.inactiveProducts'),
                    count: this.totals.inactiveProducts || 0,
                    target: 'audit-section-inactive-products',
                },
                {
                    key: 'outOfStockProducts',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.outOfStockProducts'),
                    count: this.totals.outOfStockProducts || 0,
                    target: 'audit-section-out-of-stock-products',
                },
                {
                    key: 'missingMetaTitle',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingMetaTitle'),
                    count: this.totals.missingMetaTitle || 0,
                    target: 'audit-section-missing-meta-title',
                }
            ];
        },

        gridColumns() {
            return [
                {
                    property: 'name',
                    label: this.$tc('esmx-shop-audit-ai.grid.productName'),
                    routerLink: 'sw.product.detail',
                    primary: true
                },
                {
                    property: 'productNumber',
                    label: this.$tc('esmx-shop-audit-ai.grid.productNumber')
                },
                {
                    property: 'stock',
                    label: this.$tc('esmx-shop-audit-ai.grid.stock')
                }
            ];
        }
    },

    created() {
        this.loadDashboard();
    },

    methods: {
        loadDashboard() {
            this.isLoading = true;
            this.loadError = null;

            this.esmxShopAuditApiService.getDashboard()
                .then((response) => {
                    this.dashboard = response;
                })
                .catch((error) => {
                    // eslint-disable-next-line no-console
                    console.error('EsmxShopAuditAi dashboard error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.dashboard.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        scrollToSection(sectionId) {
            const section = document.getElementById(sectionId);

            if (!section) {
                return;
            }

            section.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }
    }
});