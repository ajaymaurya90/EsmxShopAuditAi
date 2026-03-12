import template from './esmx-shop-audit-findings.html.twig';
import './esmx-shop-audit-findings.scss';

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

        activeCodeFilter() {
            return this.$route.query.code || null;
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
                    console.log('finding codes', this.findings.map((finding) => finding.code));
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

        clearFilters() {
            this.$router.push({ name: 'esmx.shop.audit.ai.findings' });
        },

        scrollToFindingSection(code) {
            if (!code) {
                return;
            }

            console.log('scroll target code', code);
            console.log('target element', document.getElementById(`finding-section-${code}`));

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

        getSeverityLabel(severity) {
            return this.$tc(`esmx-shop-audit-ai.severity.${severity}`);
        },

        getSeverityClass(severity) {
            return `severity-${severity}`;
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
        }
    }
});