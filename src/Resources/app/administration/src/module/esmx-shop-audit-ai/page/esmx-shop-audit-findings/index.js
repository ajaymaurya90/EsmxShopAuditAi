import template from './esmx-shop-audit-findings.html.twig';
import '../../shared/esmx-shop-audit-shared.scss';
import './esmx-shop-audit-findings.scss';
import { getFindingImpact } from './constants/finding-impact.constant';

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
            activeImpactCode: null,
            activeWidgetTooltip: null,
            sortBy: 'severity',
            sortDirection: 'desc',
            selectedSeverityFilters: [],
            isFilterMenuOpen: false,
            detailPageSize: 10,
            detailCurrentPages: {},
        };
    },

    watch: {
        '$route.query.code'(newCode) {
            if (!newCode) {
                return;
            }

            this.$nextTick(() => {
                window.setTimeout(() => {
                    this.scrollToFindingSection(newCode);
                }, 150);
            });
        }
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.findings.pageTitle');
        },

        findingsBySeverity() {
            return {
                critical: this.findings.filter((item) => item.severity === 'critical').length,
                high: this.findings.filter((item) => item.severity === 'high').length,
                medium: this.findings.filter((item) => item.severity === 'medium').length,
                low: this.findings.filter((item) => item.severity === 'low').length,
            };
        },

        totalAffectedCount() {
            return this.findings.reduce((sum, item) => sum + (item.affectedCount || 0), 0);
        },

        activeCodeFilter() {
            return this.$route.query.code || null;
        },

        severityFilters() {
            return ['critical', 'high', 'medium', 'low'].map((severity) => {
                const count = this.findings.filter((finding) => {
                    return String(finding.severity || '').toLowerCase().trim() === severity;
                }).length;

                return {
                    key: severity,
                    label: this.$tc(`esmx-shop-audit-ai.severity.${severity}`),
                    count,
                    disabled: count === 0,
                };
            });
        },

        filteredFindings() {
            if (!this.selectedSeverityFilters.length) {
                return this.findings;
            }

            return this.findings.filter((finding) => {
                const severity = String(finding.severity || '').toLowerCase().trim();

                return this.selectedSeverityFilters.includes(severity);
            });
        },

        filteredAffectedItemsCount() {
            return this.filteredFindings.reduce((sum, finding) => {
                return sum + Number(finding.affectedCount || 0);
            }, 0);
        },

        filteredIssueGroupsCount() {
            return this.filteredFindings.length;
        },

        hasActiveFilters() {
            return this.selectedSeverityFilters.length > 0;
        },

        sortedFindings() {
            const severityWeight = {
                critical: 4,
                high: 3,
                medium: 2,
                low: 1,
            };

            const items = [...this.filteredFindings];

            items.sort((a, b) => {
                let result = 0;

                switch (this.sortBy) {
                    case 'title':
                        result = String(a.title || '').localeCompare(String(b.title || ''));
                        break;

                    case 'category':
                        result = this.getCategoryLabel(a.entity).localeCompare(this.getCategoryLabel(b.entity));
                        break;

                    case 'severity': {
                        const severityA = String(a.severity || '').toLowerCase().trim();
                        const severityB = String(b.severity || '').toLowerCase().trim();

                        result = (severityWeight[severityA] || 0) - (severityWeight[severityB] || 0);
                        break;
                    }

                    case 'count':
                        result = Number(a.affectedCount || 0) - Number(b.affectedCount || 0);
                        break;

                    default:
                        result = 0;
                }

                return this.sortDirection === 'asc' ? result : -result;
            });

            return items;
        },

        sortIconMap() {
            return {
                asc: 'regular-chevron-up-s',
                desc: 'regular-chevron-down-s',
            };
        },

        resultsSummaryText() {
            const items = this.filteredAffectedItemsCount;
            const groups = this.filteredIssueGroupsCount;

            if (!this.selectedSeverityFilters.length) {
                return `${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryPrefix')} ${items} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryMiddle')} ${groups} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummarySuffix')}`;
            }

            if (this.selectedSeverityFilters.length === 1) {
                const severityLabel = this.$tc(`esmx-shop-audit-ai.severity.${this.selectedSeverityFilters[0]}`).toLowerCase();

                return `${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryPrefix')}
                ${items} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryMiddle')}
                ${groups} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummarySuffix')} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryWithSeverityPrefix')}
                ${severityLabel} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryWithSeveritySuffix')}`;
            }

            return `${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryPrefix')} ${items} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryMiddle')} ${groups} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummarySuffix')} ${this.$tc('esmx-shop-audit-ai.findings.resultsSummaryWithSelectedFilters')}`;
        },
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
                    this.detailCurrentPages = {};
                })
                .then(() => {
                    this.$nextTick(() => {
                        if (this.activeCodeFilter) {
                            window.setTimeout(() => {
                                this.scrollToFindingSection(this.activeCodeFilter);
                            }, 150);
                        }
                    });
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

        formatDate(value) {
            if (!value) {
                return '';
            }

            try {
                return Shopware.Utils.format.date(value, {
                    hour: '2-digit',
                    minute: '2-digit',
                    year: 'numeric',
                    month: 'short',
                    day: '2-digit',
                });
            } catch (e) {
                return value;
            }
        },

        toggleFilterMenu() {
            this.isFilterMenuOpen = !this.isFilterMenuOpen;
        },

        toggleSeverityFilter(severity) {
            const normalized = String(severity).toLowerCase().trim();

            const filter = this.severityFilters.find((item) => item.key === normalized);

            if (filter?.disabled) {
                return;
            }

            if (this.selectedSeverityFilters.includes(normalized)) {
                this.selectedSeverityFilters = this.selectedSeverityFilters.filter(
                    (item) => item !== normalized
                );
                return;
            }

            this.selectedSeverityFilters = [...this.selectedSeverityFilters, normalized];
        },

        clearSeverityFilters() {
            this.selectedSeverityFilters = [];
            this.isFilterMenuOpen = false;
        },

        isSeveritySelected(severity) {
            return this.selectedSeverityFilters.includes(String(severity).toLowerCase().trim());
        },

        toggleSort(column) {
            if (this.sortBy === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
                return;
            }

            this.sortBy = column;

            if (column === 'title' || column === 'category') {
                this.sortDirection = 'asc';
                return;
            }

            this.sortDirection = 'desc';
        },

        isSortedBy(column) {
            return this.sortBy === column;
        },

        getCategoryLabel(entity) {
            if (!entity) {
                return this.$tc('esmx-shop-audit-ai.findings.unknownCategory');
            }

            const normalized = String(entity).toLowerCase();

            const labels = {
                product: this.$tc('esmx-shop-audit-ai.findings.categoryProduct'),
                category: this.$tc('esmx-shop-audit-ai.findings.categoryCategory'),
                customer: this.$tc('esmx-shop-audit-ai.findings.categoryCustomer'),
                order: this.$tc('esmx-shop-audit-ai.findings.categoryOrder'),
            };

            return labels[normalized] || entity;
        },

        getImpactLabel(code) {
            return getFindingImpact(this.$tc.bind(this), code);
        },

        getSeverityLabel(severity) {
            const normalized = String(severity || '').toLowerCase().trim();

            return this.$tc(`esmx-shop-audit-ai.severity.${normalized}`);
        },

        getSeverityClass(severity) {
            const normalized = String(severity || '').toLowerCase().trim();

            return `severity-${normalized}`;
        },

        scrollToFindingSection(code) {
            if (!code) {
                return;
            }

            const tryScroll = () => {
                const section = document.getElementById(`finding-section-${code}`);

                if (!section) {
                    return false;
                }

                section.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });

                return true;
            };

            if (tryScroll()) {
                return;
            }

            window.setTimeout(() => {
                tryScroll();
            }, 200);
        },

        goToFindingDetailSection(finding) {
            if (!finding?.code) {
                return;
            }

            this.scrollToFindingSection(finding.code);
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

        getFindingItems(finding) {
            return finding?.items ?? [];
        },

        getDetailCurrentPage(findingId) {
            return this.detailCurrentPages[findingId] ?? 1;
        },

        getDetailTotalPages(finding) {
            const items = this.getFindingItems(finding);
            return Math.max(1, Math.ceil(items.length / this.detailPageSize));
        },

        getPaginatedFindingItems(finding) {
            const items = this.getFindingItems(finding);
            const currentPage = this.getDetailCurrentPage(finding.id);
            const start = (currentPage - 1) * this.detailPageSize;
            const end = start + this.detailPageSize;

            return items.slice(start, end);
        },

        setDetailPage(findingId, page) {
            const safePage = Math.max(1, page);
            this.detailCurrentPages = {
                ...this.detailCurrentPages,
                [findingId]: safePage,
            };
        },

        goToDetailPrevPage(finding) {
            const currentPage = this.getDetailCurrentPage(finding.id);

            if (currentPage <= 1) {
                return;
            }

            this.setDetailPage(finding.id, currentPage - 1);
        },

        goToDetailNextPage(finding) {
            const currentPage = this.getDetailCurrentPage(finding.id);
            const totalPages = this.getDetailTotalPages(finding);

            if (currentPage >= totalPages) {
                return;
            }

            this.setDetailPage(finding.id, currentPage + 1);
        },

        openProductDetailInNewTab(item) {
            const productId = item?.id || item?.productId;

            if (!productId) {
                return;
            }

            const resolved = this.$router.resolve({
                name: 'sw.product.detail',
                params: {
                    id: productId,
                },
            });

            if (resolved?.href) {
                window.open(resolved.href, '_blank');
            }
        },
    }
});