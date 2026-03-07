import template from './esmx-shop-audit-dashboard.html.twig';
import './esmx-shop-audit-dashboard.scss';

Shopware.Component.register('esmx-shop-audit-dashboard', {
    template,

    inject: ['esmxShopAuditApiService'],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            dashboard: null,
            latestScan: null,
            loadError: null,
            scanError: null,
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

        latestScanSummary() {
            return this.latestScan?.summaryJson ?? {};
        },

        summaryCards() {
            return [
                {
                    key: 'missingDescription',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingDescription'),
                    count: this.totals.missingDescription || 0,
                    target: 'audit-section-missing-description',
                    severity: 'medium',
                },
                {
                    key: 'missingCoverImage',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingCoverImage'),
                    count: this.totals.missingCoverImage || 0,
                    target: 'audit-section-missing-cover-image',
                    severity: 'low',
                },
                {
                    key: 'inactiveProducts',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.inactiveProducts'),
                    count: this.totals.inactiveProducts || 0,
                    target: 'audit-section-inactive-products',
                    severity: 'high',
                },
                {
                    key: 'outOfStockProducts',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.outOfStockProducts'),
                    count: this.totals.outOfStockProducts || 0,
                    target: 'audit-section-out-of-stock-products',
                    severity: 'critical',
                },
                {
                    key: 'missingMetaTitle',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingMetaTitle'),
                    count: this.totals.missingMetaTitle || 0,
                    target: 'audit-section-missing-meta-title',
                    severity: 'low',
                },
                {
                    key: 'missingCategory',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingCategory'),
                    count: this.totals.missingCategory || 0,
                    target: 'audit-section-missing-category',
                    severity: 'medium',
                },
                {
                    key: 'missingManufacturer',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingManufacturer'),
                    count: this.totals.missingManufacturer || 0,
                    target: 'audit-section-missing-manufacturer',
                    severity: 'medium',
                },
                {
                    key: 'missingPrice',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingPrice'),
                    count: this.totals.missingPrice || 0,
                    target: 'audit-section-missing-price',
                    severity: 'high',
                },
                {
                    key: 'missingTranslation',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.missingTranslation'),
                    count: this.totals.missingTranslation || 0,
                    target: 'audit-section-missing-translation',
                    severity: 'medium',
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
        },

        translationGridColumns() {
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
                    property: 'missingLanguages',
                    label: this.$tc('esmx-shop-audit-ai.grid.missingLanguages')
                }
            ];
        }
    },

    created() {
        this.initializeDashboard();
    },

    methods: {
        initializeDashboard() {
            this.loadError = null;
            this.scanError = null;

            Promise.all([
                this.loadDashboard(),
                this.loadLatestScan(),
            ]).catch(() => {
                // handled in individual methods
            });
        },

        loadDashboard() {
            this.isLoading = true;

            return this.esmxShopAuditApiService.getDashboard()
                .then((response) => {
                    this.dashboard = response;
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi dashboard error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.dashboard.loadError');
                    throw error;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        loadLatestScan() {
            return this.esmxShopAuditApiService.getLatestScan()
                .then((response) => {
                    this.latestScan = response.scan;
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi latest scan error:', error);
                    throw error;
                });
        },

        runScan() {
            this.isRunningScan = true;
            this.scanError = null;

            this.esmxShopAuditApiService.runScan()
                .then(() => {
                    return Promise.all([
                        this.loadDashboard(),
                        this.loadLatestScan(),
                    ]);
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi run scan error:', error);
                    this.scanError = this.$tc('esmx-shop-audit-ai.dashboard.runScanError');
                })
                .finally(() => {
                    this.isRunningScan = false;
                });
        },

        goToFindings() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
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

        scrollToSection(sectionId) {
            const section = document.getElementById(sectionId);

            if (!section) {
                return;
            }

            section.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        },

        getSeverityClass(severity) {
            return `esmx-shop-audit-dashboard__metric-card--${severity}`;
        },

        getSeverityLabel(severity) {
            return this.$tc(`esmx-shop-audit-ai.severity.${severity}`);
        }
    }
});