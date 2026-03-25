import template from './esmx-shop-audit-reports.html.twig';
import '../../shared/esmx-shop-audit-shared.scss';
import './esmx-shop-audit-reports.scss';

Shopware.Component.register('esmx-shop-audit-reports', {
    template,

    inject: ['esmxShopAuditApiService'],

    mixins: [
        Shopware.Mixin.getByName('notification'),
    ],

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
            activeWidgetTooltip: null,
            historyPage: 1,
            historyPageSize: 5,
            selectedFindingsPage: 1,
            selectedTasksPage: 1,
            selectedDetailPageSize: 8,
            selectedReportIds: [],
            isDeletingReports: false,
            showDeleteConfirmModal: false,
        };
    },

    computed: {
        pageTitle() {
            return this.$tc('esmx-shop-audit-ai.reports.pageTitle');
        },

        totalReports() {
            return this.reports.length;
        },

        completedReportsCount() {
            return this.reports.filter((report) => report.status === 'completed').length;
        },

        failedReportsCount() {
            return this.reports.filter((report) => report.status === 'failed').length;
        },

        latestReport() {
            return this.reports.length ? this.reports[0] : null;
        },

        previousReport() {
            return this.reports.length > 1 ? this.reports[1] : null;
        },

        latestReportDate() {
            if (!this.latestReport) {
                return null;
            }

            return this.latestReport.finishedAt || this.latestReport.startedAt || null;
        },

        latestFindingsCount() {
            return this.latestReport?.totalFindings ?? 0;
        },

        latestHighPriorityCount() {
            return this.latestReport?.highPriorityFindings ?? 0;
        },

        latestFindingsDelta() {
            if (!this.latestReport || !this.previousReport) {
                return null;
            }

            return Number(this.latestReport.totalFindings || 0) - Number(this.previousReport.totalFindings || 0);
        },

        latestHighPriorityDelta() {
            if (!this.latestReport || !this.previousReport) {
                return null;
            }

            return Number(this.latestReport.highPriorityFindings || 0) - Number(this.previousReport.highPriorityFindings || 0);
        },

        averageFindingsPerScan() {
            if (!this.reports.length) {
                return 0;
            }

            const total = this.reports.reduce((sum, report) => sum + Number(report.totalFindings || 0), 0);

            return Math.round(total / this.reports.length);
        },

        selectedReportTaskCount() {
            return this.selectedTasks.length;
        },

        selectedReportFindingGroupsCount() {
            return this.selectedFindings.length;
        },

        historyTotalPages() {
            if (!this.reports.length) {
                return 1;
            }

            return Math.max(1, Math.ceil(this.reports.length / this.historyPageSize));
        },

        paginatedReports() {
            const start = (this.historyPage - 1) * this.historyPageSize;
            const end = start + this.historyPageSize;

            return this.reports.slice(start, end);
        },

        historySummaryText() {
            if (!this.reports.length) {
                return this.$tc('esmx-shop-audit-ai.reports.historySummaryEmpty');
            }

            const start = (this.historyPage - 1) * this.historyPageSize + 1;
            const end = Math.min(start + this.historyPageSize - 1, this.reports.length);

            return this.$tc('esmx-shop-audit-ai.reports.historySummary', 0, {
                start,
                end,
                total: this.reports.length,
            });
        },

        selectedFindingsTotalPages() {
            return Math.max(1, Math.ceil(this.selectedFindings.length / this.selectedDetailPageSize));
        },

        paginatedSelectedFindings() {
            const start = (this.selectedFindingsPage - 1) * this.selectedDetailPageSize;
            const end = start + this.selectedDetailPageSize;

            return this.selectedFindings.slice(start, end);
        },

        selectedTasksTotalPages() {
            return Math.max(1, Math.ceil(this.selectedTasks.length / this.selectedDetailPageSize));
        },

        paginatedSelectedTasks() {
            const start = (this.selectedTasksPage - 1) * this.selectedDetailPageSize;
            const end = start + this.selectedDetailPageSize;

            return this.selectedTasks.slice(start, end);
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
        },

        hasSelectedReports() {
            return this.selectedReportIds.length > 0;
        },

        allVisibleReportsSelected() {
            if (!this.paginatedReports.length) {
                return false;
            }

            return this.paginatedReports.every((report) => this.selectedReportIds.includes(report.id));
        },

        selectedReports() {
            return this.reports.filter((report) => this.selectedReportIds.includes(report.id));
        },

        selectedReportsCount() {
            return this.selectedReports.length;
        },

        selectedReportsDateRangeLabel() {
            if (!this.selectedReports.length) {
                return '';
            }

            const sortedDates = this.selectedReports
                .map((report) => report.finishedAt || report.startedAt)
                .filter(Boolean)
                .sort();

            if (!sortedDates.length) {
                return '';
            }

            const first = this.formatDate(sortedDates[0]);
            const last = this.formatDate(sortedDates[sortedDates.length - 1]);

            if (first === last) {
                return first;
            }

            return `${first} – ${last}`;
        },
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
                    this.historyPage = 1;

                    if (this.reports.length) {
                        const shouldLoadFirst =
                            !this.selectedReport ||
                            !this.reports.some((report) => report.id === this.selectedReport.id);

                        if (shouldLoadFirst) {
                            return this.loadReportDetail(this.reports[0].id, false, false);
                        }
                    }

                    if (!this.reports.length) {
                        this.selectedReport = null;
                        this.selectedFindings = [];
                        this.selectedTasks = [];
                    }

                    return null;
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi reports error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.reports.loadError');
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        loadReportDetail(reportId, withLoading = true, scrollToDetail = false) {
            if (withLoading) {
                this.isLoading = true;
            }

            return this.esmxShopAuditApiService.getReportDetail(reportId)
                .then((response) => {
                    this.selectedReport = response.report;
                    this.selectedFindings = response.findings ?? [];
                    this.selectedTasks = response.tasks ?? [];
                    this.selectedFindingsPage = 1;
                    this.selectedTasksPage = 1;
                })
                .then(() => {
                    if (!scrollToDetail) {
                        return;
                    }

                    this.$nextTick(() => {
                        window.setTimeout(() => {
                            this.scrollToSelectedDetail();
                        }, 120);
                    });
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi report detail error:', error);
                    this.loadError = this.$tc('esmx-shop-audit-ai.reports.loadError');
                })
                .finally(() => {
                    if (withLoading) {
                        this.isLoading = false;
                    }
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

        getStatusVariant(status) {
            switch (status) {
                case 'completed':
                    return 'success';
                case 'failed':
                    return 'danger';
                case 'running':
                    return 'warning';
                default:
                    return 'neutral';
            }
        },

        formatDelta(value) {
            if (value === null || value === undefined) {
                return 'No previous scan';
            }

            if (value > 0) {
                return `+${value}`;
            }

            return `${value}`;
        },

        getDeltaClass(value) {
            if (value === null || value === undefined) {
                return 'is-neutral';
            }

            if (value < 0) {
                return 'is-positive';
            }

            if (value > 0) {
                return 'is-negative';
            }

            return 'is-neutral';
        },

        onClickReportRow(report) {
            if (!report?.id) {
                return;
            }

            this.loadReportDetail(report.id, true, true);
        },

        isSelectedReport(report) {
            return !!(report?.id && this.selectedReport?.id === report.id);
        },

        goToHistoryPrevPage() {
            if (this.historyPage <= 1) {
                return;
            }

            this.historyPage -= 1;
        },

        goToHistoryNextPage() {
            if (this.historyPage >= this.historyTotalPages) {
                return;
            }

            this.historyPage += 1;
        },

        goToSelectedFindingsPrevPage() {
            if (this.selectedFindingsPage <= 1) {
                return;
            }

            this.selectedFindingsPage -= 1;
        },

        goToSelectedFindingsNextPage() {
            if (this.selectedFindingsPage >= this.selectedFindingsTotalPages) {
                return;
            }

            this.selectedFindingsPage += 1;
        },

        goToSelectedTasksPrevPage() {
            if (this.selectedTasksPage <= 1) {
                return;
            }

            this.selectedTasksPage -= 1;
        },

        goToSelectedTasksNextPage() {
            if (this.selectedTasksPage >= this.selectedTasksTotalPages) {
                return;
            }

            this.selectedTasksPage += 1;
        },

        scrollToSelectedDetail() {
            const element = document.getElementById('selected-report-detail');

            if (!element) {
                return;
            }

            element.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
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

        getFindingTitleByCode(code, fallbackTitle = '') {
            if (!code) {
                return fallbackTitle || '-';
            }

            const key = `esmx-shop-audit-ai.findingTitles.${code}`;
            const translated = this.$tc(key);

            return translated !== key ? translated : (fallbackTitle || code);
        },

        getDynamicTaskTitle(task) {
            if (!task) {
                return '';
            }

            const key = `esmx-shop-audit-ai.taskTitles.${task.code}`;
            const translated = this.$tc(key, task.affectedCount || 0, {
                count: task.affectedCount || 0,
            });

            if (translated !== key) {
                return translated;
            }

            return task.title || task.code || '-';
        },

        getPriorityLabel(priority) {
            const key = `esmx-shop-audit-ai.taskPriority.${priority}`;
            const translated = this.$tc(key);

            return translated !== key ? translated : (priority || '-');
        },

        getStatusLabel(status) {
            const key = `esmx-shop-audit-ai.status.${status}`;
            const translated = this.$tc(key);

            return translated !== key ? translated : (status || '-');
        },

        getFindingSeverityLabel(severity) {
            const key = `esmx-shop-audit-ai.severity.${severity}`;
            const translated = this.$tc(key);

            return translated !== key ? translated : (severity || '-');
        },

        isReportSelected(reportId) {
            return this.selectedReportIds.includes(reportId);
        },

        toggleReportSelection(reportId) {
            if (!reportId) {
                return;
            }

            if (this.selectedReportIds.includes(reportId)) {
                this.selectedReportIds = this.selectedReportIds.filter((id) => id !== reportId);
                return;
            }

            this.selectedReportIds = [...this.selectedReportIds, reportId];
        },

        toggleSelectAllVisibleReports() {
            const visibleIds = this.paginatedReports.map((report) => report.id);

            if (!visibleIds.length) {
                return;
            }

            if (this.allVisibleReportsSelected) {
                this.selectedReportIds = this.selectedReportIds.filter((id) => !visibleIds.includes(id));
                return;
            }

            this.selectedReportIds = [...new Set([...this.selectedReportIds, ...visibleIds])];
        },

        openDeleteReportsModal() {
            console.log('openDeleteReportsModal called', this.selectedReports.length);

            if (!this.selectedReports.length) {
                return;
            }

            this.showDeleteConfirmModal = true;
        },

        closeDeleteReportsModal() {
            this.showDeleteConfirmModal = false;
        },

        confirmDeleteReports() {
            if (!this.selectedReportIds.length) {
                return;
            }

            this.isDeletingReports = true;

            this.esmxShopAuditApiService.deleteReports(this.selectedReportIds)
                .then((response) => {
                    const deletedCount = response.deletedCount ?? this.selectedReportIds.length;

                    this.createNotificationSuccess({
                        message: this.$tc('esmx-shop-audit-ai.reports.delete.success', deletedCount, {
                            count: deletedCount,
                        }),
                    });

                    const deletedIds = [...this.selectedReportIds];

                    this.selectedReportIds = [];
                    this.showDeleteConfirmModal = false;

                    if (this.selectedReport?.id && deletedIds.includes(this.selectedReport.id)) {
                        this.selectedReport = null;
                        this.selectedFindings = [];
                        this.selectedTasks = [];
                    }

                    return this.loadReports();
                })
                .catch((error) => {
                    console.error('EsmxShopAuditAi delete reports error:', error);

                    this.createNotificationError({
                        message: this.$tc('esmx-shop-audit-ai.reports.delete.error'),
                    });
                })
                .finally(() => {
                    this.isDeletingReports = false;
                });
        },
    }
});