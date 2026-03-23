import template from './esmx-shop-audit-dashboard.html.twig';
import './esmx-shop-audit-dashboard.scss';
import '../../shared/esmx-shop-audit-shared.scss';
import { buildSummaryCards } from './constants/summary-cards.constant';

Shopware.Component.register('esmx-shop-audit-dashboard', {
    template,

    inject: ['esmxShopAuditApiService'],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: false,
            isRunningScan: false,
            dashboard: null,
            latestScan: null,
            loadError: null,
            scanError: null,
            activeImpactKey: null,
            activeWidgetTooltip: null,
            affectedProducts: 0,
            animatedHealthScore: 0,
        };
    },

    computed: {
        totals() {
            return this.dashboard?.liveAudit?.totals ?? {};
        },

        meta() {
            return this.dashboard?.liveAudit?.meta ?? {};
        },

        insights() {
            return this.dashboard?.insights ?? {};
        },

        topTasks() {
            return this.insights.topTasks ?? [];
        },

        openTaskCount() {
            return this.insights.openTaskCount ?? 0;
        },

        scanOverviewStats() {
            return [
                {
                    key: 'scannedProducts',
                    label: this.$tc('esmx-shop-audit-ai.dashboardInsights.scannedProducts'),
                    value: this.latestScan?.scannedProducts || 0,
                },
                {
                    key: 'affectedProducts',
                    label: this.$tc('esmx-shop-audit-ai.dashboardInsights.affectedProducts'),
                    value: this.affectedProducts,
                },
                {
                    key: 'totalIssues',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.totalIssues'),
                    value: this.totals.totalIssues || 0,
                },
                {
                    key: 'criticalIssues',
                    label: this.$tc('esmx-shop-audit-ai.dashboardInsights.criticalIssues'),
                    value: this.criticalIssuesCount
                },
                {
                    key: 'issueGroups',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.issueGroups'),
                    value: this.activeSummaryCards.length,
                },
                {
                    key: 'openTasks',
                    label: this.$tc('esmx-shop-audit-ai.dashboardInsights.openTasks'),
                    value: this.openTaskCount,
                },
            ];
        },

        summaryCards() {
            return buildSummaryCards(this.$tc.bind(this), this.totals);
        },

        salesInsights() {
            return this.dashboard?.salesInsights ?? {};
        },

        salesKpis() {
            return {
                revenue: Number(this.salesInsights?.kpis?.revenue ?? 0),
                orders: Number(this.salesInsights?.kpis?.orders ?? 0),
                revenueChange: Number(this.salesInsights?.kpis?.revenueChange ?? 0),
                ordersChange: Number(this.salesInsights?.kpis?.ordersChange ?? 0),
            };
        },

        topSellingProducts() {
            return this.salesInsights?.topProducts ?? [];
        },

        lowStockProducts() {
            return this.salesInsights?.lowStockBestSellers ?? [];
        },

        formattedLatestScanDate() {
            const scanDate = this.latestScan?.finishedAt || this.latestScan?.startedAt || null;

            if (!scanDate) {
                return null;
            }

            return new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            }).format(new Date(scanDate));
        },

        activeSummaryCards() {
            return this.summaryCards.filter((card) => (card.count || 0) > 0);
        },

        sortedSummaryCards() {
            const severityWeight = {
                critical: 4,
                high: 3,
                medium: 2,
                low: 1,
            };

            return [...this.activeSummaryCards].sort((a, b) => {
                const severityDiff = (severityWeight[b.severity] || 0) - (severityWeight[a.severity] || 0);

                if (severityDiff !== 0) {
                    return severityDiff;
                }

                return (b.count || 0) - (a.count || 0);
            });
        },

        criticalIssuesCount() {
            return this.activeSummaryCards
                .filter((card) => card.severity === 'critical')
                .reduce((sum, card) => sum + (card.count || 0), 0);
        },

        dashboardHeadline() {
            const outOfStock = this.totals.outOfStockProducts || 0;
            const missingDescription = this.totals.missingDescription || 0;
            const missingMetaTitle = this.totals.missingMetaTitle || 0;
            const missingPrice = this.totals.missingPrice || 0;

            if (outOfStock > 0) {
                return this.$tc('esmx-shop-audit-ai.dashboard.headlineOutOfStock', outOfStock, {
                    count: outOfStock,
                });
            }

            if (missingPrice > 0) {
                return this.$tc('esmx-shop-audit-ai.dashboard.headlineMissingPrice', missingPrice, {
                    count: missingPrice,
                });
            }

            if (missingDescription > 0) {
                return this.$tc('esmx-shop-audit-ai.dashboard.headlineMissingDescription', missingDescription, {
                    count: missingDescription,
                });
            }

            if (missingMetaTitle > 0) {
                return this.$tc('esmx-shop-audit-ai.dashboard.headlineMissingMetaTitle', missingMetaTitle, {
                    count: missingMetaTitle,
                });
            }

            return this.$tc('esmx-shop-audit-ai.dashboard.healthyHeadline');
        },

        nextBestAction() {
            if (!this.activeSummaryCards.length) {
                return null;
            }

            const bestCard = [...this.activeSummaryCards]
                .map((card) => ({
                    ...card,
                    priorityScore: this.getCardPriorityScore(card),
                }))
                .sort((a, b) => b.priorityScore - a.priorityScore)[0];

            if (!bestCard) {
                return null;
            }

            return {
                label: this.getNextBestActionLabel(bestCard),
                code: bestCard.key,
            };
        },

        getNextBestActionLabel(card) {
            const map = {
                outOfStockProducts: this.$tc('esmx-shop-audit-ai.dashboard.nextActionRestock'),
                missingPrice: this.$tc('esmx-shop-audit-ai.dashboard.nextActionPrice'),
                missingDescription: this.$tc('esmx-shop-audit-ai.dashboard.nextActionDescriptions'),
                missingMetaTitle: this.$tc('esmx-shop-audit-ai.dashboard.nextActionSeo'),
            };

            return map[card.key] || card.label;
        },

        healthScore() {
            return this.dashboard?.health?.score ?? 0;
        },

        healthStatus() {
            if (this.healthScore >= 85) {
                return 'good';
            }

            if (this.healthScore >= 60) {
                return 'warning';
            }

            return 'critical';
        },

        healthStatusLabel() {
            return this.$tc(`esmx-shop-audit-ai.dashboard.healthStatus.${this.healthStatus}`);
        },

        healthSummaryText() {
            if (this.healthStatus === 'good') {
                return this.$tc('esmx-shop-audit-ai.dashboard.healthSummaryGood');
            }

            if (this.healthStatus === 'warning') {
                return this.$tc('esmx-shop-audit-ai.dashboard.healthSummaryWarning');
            }

            return this.$tc('esmx-shop-audit-ai.dashboard.healthSummaryCritical');
        },

        healthRingStyle() {
            const score = Math.max(0, Math.min(this.animatedHealthScore, 100));
            let angle = Math.round((score / 100) * 360);

            if (score === 0) {
                angle = 6;
            }

            let color = '#10b981';

            if (this.healthStatus === 'warning') {
                color = '#f59e0b';
            }

            if (this.healthStatus === 'critical') {
                color = '#ef4444';
            }

            return {
                background: `conic-gradient(${color} 0deg ${angle}deg, #e5e7eb ${angle}deg 360deg)`,
            };
        },

        seoMeta() {
            return this.dashboard?.liveAudit?.meta?.seo || {
                totalProducts: 0,
                productsNeedingImprovement: 0,
                averageOverallScore: 0,
                improvementThreshold: 0,
                improvementRate: 0,
            };
        },

        seoKpiCards() {
            return [
                {
                    key: 'averageOverallScore',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.seoAverageScore'),
                    value: `${this.seoMeta.averageOverallScore || 0}/100`,
                    hint: this.$tc('esmx-shop-audit-ai.dashboard.seoAverageScoreHint'),
                },
                {
                    key: 'productsNeedingImprovement',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.seoNeedsImprovement'),
                    value: `${this.seoMeta.productsNeedingImprovement || 0} / ${this.seoMeta.totalProducts || 0}`,
                    hint: this.$tc('esmx-shop-audit-ai.dashboard.seoNeedsImprovementHint', 0, {
                        threshold: this.seoMeta.improvementThreshold || 0,
                    }),
                },
                {
                    key: 'improvementRate',
                    label: this.$tc('esmx-shop-audit-ai.dashboard.seoImprovementRate'),
                    value: this.formatPercent(this.seoMeta.improvementRate),
                    hint: this.$tc('esmx-shop-audit-ai.dashboard.seoImprovementRateHint'),
                },
            ];
        },
    },

    created() {
        this.initializeDashboard();
    },

    methods: {
        initializeDashboard() {
            this.loadError = null;
            this.scanError = null;

            this.refreshDashboard().catch(() => {
                // handled below
            });
        },

        refreshDashboard() {
            return Promise.all([
                this.loadDashboard(),
                this.loadLatestScan(),
            ]);
        },

        loadDashboard() {
            this.isLoading = true;

            return this.esmxShopAuditApiService.getDashboard()
                .then((response) => {
                    this.dashboard = response;
                    this.affectedProducts = response?.insights?.affectedProducts || 0;
                    //this.criticalIssues = response?.insights?.criticalIssues || 0;

                    this.$nextTick(() => {
                        this.animateHealthScore();
                    });
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
                    this.latestScan = response?.scan || null;
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
                .then(() => this.refreshDashboard())
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('esmx-shop-audit-ai.dashboard.runScanSuccess'),
                    });
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi run scan error:', error);
                    this.scanError = this.$tc('esmx-shop-audit-ai.dashboard.runScanError');

                    this.createNotificationError({
                        message: this.$tc('esmx-shop-audit-ai.dashboard.runScanError'),
                    });
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

        goToTaskFilter(task) {
            this.$router.push({
                name: 'esmx.shop.audit.ai.tasks',
                query: {
                    priority: task.priority,
                    code: task.code,
                }
            });
        },

        getSeverityClass(severity) {
            return `esmx-shop-audit-dashboard__metric-card--${severity}`;
        },

        getSeverityLabel(severity) {
            return this.$tc(`esmx-shop-audit-ai.severity.${severity}`);
        },

        getCardPriorityScore(card) {
            const severityWeight = {
                critical: 4,
                high: 3,
                medium: 2,
                low: 1,
            };

            const weight = severityWeight[card.severity] || 1;

            return (card.count || 0) * weight;
        },

        handleNextBestAction() {
            if (!this.nextBestAction?.code) {
                return;
            }

            this.$router.push({
                name: 'esmx.shop.audit.ai.findings',
                query: {
                    code: this.nextBestAction.code,
                },
            });
        },

        goToProductDetail(productId) {
            if (!productId) {
                return;
            }

            this.$router.push({
                name: 'sw.product.detail',
                params: {
                    id: productId,
                },
            });
        },

        formatCurrency(value) {
            if (value === null || value === undefined) {
                return Number(0).toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            return Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },

        goToFindingFromCard(card) {
            if (!card?.code) {
                return;
            }

            this.$router.push({
                name: 'esmx.shop.audit.ai.findings',
                query: {
                    code: card.code,
                },
            });
        },

        animateHealthScore() {
            const target = Math.max(0, Math.min(this.healthScore, 100));
            const duration = 900;
            const start = this.animatedHealthScore;
            const startTime = performance.now();

            const animate = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const easedProgress = 1 - Math.pow(1 - progress, 3);

                this.animatedHealthScore = Math.round(start + ((target - start) * easedProgress));

                if (progress < 1) {
                    window.requestAnimationFrame(animate);
                    return;
                }

                this.animatedHealthScore = target;
            };

            window.requestAnimationFrame(animate);
        },

        getHealthLabel(key) {
            const map = {
                outOfStockProducts: this.$tc('esmx-shop-audit-ai.dashboard.outOfStockProducts'),
                missingPrice: this.$tc('esmx-shop-audit-ai.dashboard.missingPrice'),
                inactiveProducts: this.$tc('esmx-shop-audit-ai.dashboard.inactiveProducts'),
                missingCoverImage: this.$tc('esmx-shop-audit-ai.dashboard.missingCoverImage'),
                missingCategory: this.$tc('esmx-shop-audit-ai.dashboard.missingCategory'),
                missingManufacturer: this.$tc('esmx-shop-audit-ai.dashboard.missingManufacturer'),
                missingTranslation: this.$tc('esmx-shop-audit-ai.dashboard.missingTranslation'),
                product_name: this.$tc('esmx-shop-audit-ai.dashboard.productName'),
                product_description: this.$tc('esmx-shop-audit-ai.dashboard.productDescription'),
                product_meta_title: this.$tc('esmx-shop-audit-ai.dashboard.productMetaTitle'),
                product_meta_description: this.$tc('esmx-shop-audit-ai.dashboard.productMetaDescription'),
                criticalIssues: this.$tc('esmx-shop-audit-ai.dashboard.criticalIssues'),
            };

            return map[key] || key;
        },

        formatPercent(value) {
            return `${Number(value || 0).toFixed(2)}%`;
        },
    }
});